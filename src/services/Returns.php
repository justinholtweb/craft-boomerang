<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use DateTime;
use justinholtweb\boomerang\db\Table;
use justinholtweb\boomerang\elements\ReturnRequest;
use justinholtweb\boomerang\enums\EventType;
use justinholtweb\boomerang\enums\ItemCondition;
use justinholtweb\boomerang\enums\Resolution;
use justinholtweb\boomerang\enums\StateKind;
use justinholtweb\boomerang\events\ReturnStateEvent;
use justinholtweb\boomerang\models\ReturnEvent;
use justinholtweb\boomerang\models\ReturnItem;
use justinholtweb\boomerang\models\State;
use justinholtweb\boomerang\Plugin;
use justinholtweb\boomerang\records\EventRecord;
use justinholtweb\boomerang\records\ReturnItemRecord;
use yii\db\IntegrityException;

/**
 * Returns: creating them, and moving them.
 *
 * **The invariant: `transition()` is the only place an RMA changes state.** The portal, the CP,
 * the bulk action, the console and every convenience method on this class end up there, which is
 * why the guard, the event log, the restock, the resolution and the notification happen once each
 * and always in the same order. There is no second path, and adding one is how a plugin ends up
 * with returns that were refunded twice and RMAs whose history does not explain their balance.
 *
 * ## The order side effects run in
 *
 * Restock, then resolution, then the state write. Both side effects run *before* the state is
 * recorded, so that a refund the gateway refuses leaves the RMA exactly where it was rather than
 * marked resolved with no money having moved. Restock is idempotent per item, so the retry that
 * follows a failed refund cannot double the stock.
 */
class Returns extends Component
{
    /**
     * @event ReturnStateEvent Raised before an RMA changes state. Setting `$isValid = false`
     *        refuses the transition, which is how another plugin can hold a return back.
     */
    public const EVENT_BEFORE_TRANSITION = 'beforeTransition';

    /**
     * @event ReturnStateEvent Raised after the state has been written and its side effects have
     *        run.
     */
    public const EVENT_AFTER_TRANSITION = 'afterTransition';

    // Creating
    // -------------------------------------------------------------------------

    /**
     * Build an RMA for an order from a set of requested lines.
     *
     * `$lines` is keyed by line item ID:
     * `[42 => ['qty' => 1, 'reasonId' => 3, 'comment' => '…', 'photoIds' => [12]]]`
     *
     * Nothing is saved: the caller validates, decides whether to auto-approve, and saves. That is
     * deliberate — the portal and the CP disagree about what to do with a request that fails
     * eligibility, and a `create()` that saved would have to decide for both of them.
     *
     * @param array<int, array<string, mixed>> $lines
     */
    public function create(Order $order, array $lines, array $attributes = []): ReturnRequest
    {
        $return = new ReturnRequest();
        $return->setOrder($order);
        $return->storeId = $order->storeId;
        $return->orderNumber = $order->number;
        $return->orderReference = $order->reference;
        $return->orderDate = $order->dateOrdered ?? $order->dateCreated;
        $return->customerId = $order->getCustomerId();
        $return->email = (string)$order->email;
        $return->currency = $order->currency;
        $return->submittedSiteId = $order->orderSiteId ?? Craft::$app->getSites()->getCurrentSite()->id;

        Craft::configure($return, $attributes);

        $verdict = Plugin::getInstance()->eligibility->evaluate($order);
        $items = [];

        foreach ($lines as $lineItemId => $line) {
            $lineItemId = (int)$lineItemId;
            $qty = (int)($line['qty'] ?? 0);

            if ($qty < 1) {
                continue;
            }

            $eligible = $verdict->getLine($lineItemId);

            if ($eligible === null) {
                continue;
            }

            $items[] = $this->buildItem($eligible, $qty, $line);
        }

        $return->setItems($items);
        $return->resolutionAmount = $this->calculateResolutionAmount($return);
        $return->feeAmount = $this->calculateFee($return);

        return $return;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function buildItem(\justinholtweb\boomerang\models\EligibleLine $eligible, int $qty, array $line): ReturnItem
    {
        $reasonId = isset($line['reasonId']) ? (int)$line['reasonId'] : null;
        $reason = $reasonId !== null ? Plugin::getInstance()->reasons->getReasonById($reasonId) : null;

        $item = new ReturnItem();
        $item->lineItemId = $eligible->lineItemId;
        $item->purchasableId = $eligible->purchasableId;
        $item->sku = $eligible->sku;
        $item->description = $eligible->description;
        $item->options = $eligible->options;
        // Never more than is actually available, whatever was posted.
        $item->qtyRequested = min($qty, max(1, $eligible->qtyAvailable));
        $item->reasonId = $reason?->id;
        // Copied, so a tidied-up reason list does not erase why this came back.
        $item->reasonName = $reason?->name;
        $item->customerComment = isset($line['comment']) ? trim((string)$line['comment']) : null;
        $item->condition = ($reason?->getDefaultCondition() ?? ItemCondition::Inspect)->value;
        $item->unitPrice = $eligible->unitPrice;
        $item->unitTax = $eligible->unitTax;
        $item->unitShipping = $eligible->unitShipping;
        $item->photoIds = array_values(array_filter(array_map('intval', (array)($line['photoIds'] ?? []))));

        return $item;
    }

    /**
     * Save an RMA, retrying once if the sequence collided.
     *
     * Both `sequence` and `reference` are unique, so two requests handed the same number by
     * `ReturnRequest::nextReference()` produce an integrity error rather than two RMAs called
     * RMA-00042. One retry is enough: the second attempt reads the number the winner just wrote.
     */
    public function save(ReturnRequest $return, bool $runValidation = true): bool
    {
        try {
            return Craft::$app->getElements()->saveElement($return, $runValidation);
        } catch (IntegrityException $e) {
            if ($return->id !== null) {
                throw $e;
            }

            $return->sequence = null;
            $return->reference = null;

            return Craft::$app->getElements()->saveElement($return, $runValidation);
        }
    }

    /**
     * Create, save and log an RMA in one call, applying any auto-approval.
     */
    public function submit(ReturnRequest $return, bool $byCustomer = false): bool
    {
        // The portal already checks each line, but auto-approval turns a customer's RMA into a
        // refund with nobody looking, so the rule lives here too: a customer can only send back
        // what the eligibility verdict says is still returnable. Staff can override that from the
        // CP, which is why it applies only when the customer is the one submitting.
        if ($byCustomer && !$this->customerLinesAreReturnable($return)) {
            return false;
        }

        if (!$this->save($return)) {
            return false;
        }

        $this->addEvent($return, EventType::Created, null, [
            'items' => count($return->getItems()),
            'value' => $return->resolutionAmount,
        ], $byCustomer);

        Plugin::getInstance()->notifications->returnCreated($return);

        $approved = $this->autoApprovalState($return);

        if ($approved !== null) {
            $this->transition($return, $approved, [
                'message' => Craft::t('boomerang', 'Approved automatically.'),
                'force' => true,
            ]);
        }

        return true;
    }

    private function customerLinesAreReturnable(ReturnRequest $return): bool
    {
        $order = $return->getOrder();

        if ($order === null || $return->getItems() === []) {
            $return->addError('items', Craft::t('boomerang', 'Choose at least one item to send back.'));

            return false;
        }

        $verdict = Plugin::getInstance()->eligibility->evaluate($order);

        foreach ($return->getItems() as $item) {
            if ($item->qtyRequested > $verdict->availableQty((int)$item->lineItemId)) {
                $return->addError('items', Craft::t('boomerang', '“{item}” can’t be returned.', ['item' => $item->description]));

                return false;
            }
        }

        return true;
    }

    /**
     * The state an RMA should skip straight to, if any.
     *
     * Three separate levers, because merchants use them differently: approve everything, approve
     * anything cheap enough not to be worth a human, or approve particular reasons (a damaged
     * parcel does not need a discussion).
     */
    private function autoApprovalState(ReturnRequest $return): ?State
    {
        $settings = Plugin::getInstance()->getSettings();

        $auto = $settings->autoApprove
            || ($settings->autoApproveUnder > 0 && $return->resolutionAmount <= $settings->autoApproveUnder);

        if (!$auto) {
            foreach ($return->getItems() as $item) {
                if ($item->getReason()?->autoApprove) {
                    $auto = true;

                    break;
                }
            }
        }

        if (!$auto) {
            return null;
        }

        $state = Plugin::getInstance()->states->getStateByKind(StateKind::Approved);

        return ($state !== null && $state->id !== $return->stateId) ? $state : null;
    }

    // The transition
    // -------------------------------------------------------------------------

    /**
     * Move an RMA to another state, doing everything that entails.
     *
     * Options:
     * - `force` (bool) — skip the transition guard. The CP's "move anyway" and the auto-approval
     *   both use it; nothing customer-facing does.
     * - `message` (string) — a note for the history.
     * - `byCustomer` (bool) — the customer did it, from the portal.
     * - `notify` (bool|null) — override the state's own `notifyCustomer`.
     * - `skipSideEffects` (bool) — move the state and log it, but do not restock, resolve or
     *   notify. For imports and for a merchant correcting a mistake.
     *
     * @return bool False when the transition was refused or a side effect failed. The RMA is
     *              unchanged in both cases, and the reason is on the RMA's errors and in its
     *              history.
     */
    public function transition(ReturnRequest $return, State $to, array $options = []): bool
    {
        $force = (bool)($options['force'] ?? false);
        $byCustomer = (bool)($options['byCustomer'] ?? false);
        $skipSideEffects = (bool)($options['skipSideEffects'] ?? false);
        $from = $return->getState();

        if ($from !== null && $from->id === $to->id) {
            return true;
        }

        if (!$force && $from !== null && !$from->allowsTransitionTo($to)) {
            $return->addError('stateId', Craft::t('boomerang', 'A return in “{from}” can’t move to “{to}”.', [
                'from' => $from->name,
                'to' => $to->name,
            ]));

            return false;
        }

        $event = new ReturnStateEvent([
            'return' => $return,
            'fromState' => $from,
            'toState' => $to,
            'byCustomer' => $byCustomer,
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_TRANSITION)) {
            $this->trigger(self::EVENT_BEFORE_TRANSITION, $event);

            if (!$event->isValid) {
                $return->addError('stateId', Craft::t('boomerang', 'The transition was refused.'));

                return false;
            }
        }

        $now = new DateTime();

        // Quantities are filled in before the side effects run, because both of them are worked
        // out from quantities: an RMA moved to "Received" without anybody typing numbers means
        // everything that was approved arrived, which is what a merchant clicking one button
        // means by it.
        $this->fillQuantities($return, $to);

        if (!$skipSideEffects) {
            if ($to->restockOnEnter && !$this->runRestock($return)) {
                return false;
            }

            if ($to->resolveOnEnter && !$this->runResolution($return)) {
                return false;
            }
        }

        $return->setState($to);
        $return->stateChangedAt = $now;

        if ($to->isApproved() && $return->approvedAt === null) {
            $return->approvedAt = $now;
        }

        if ($to->isReceived() && $return->receivedAt === null) {
            $return->receivedAt = $now;
        }

        if ($to->isResolved() && $return->resolvedAt === null) {
            $return->resolvedAt = $now;
        }

        if (!$this->save($return, false)) {
            return false;
        }

        $this->addEvent($return, EventType::StateChanged, $options['message'] ?? null, [
            'from' => $from?->handle,
            'to' => $to->handle,
        ], $byCustomer);

        $notify = $options['notify'] ?? $to->notifyCustomer;

        if (!$skipSideEffects && $notify) {
            Plugin::getInstance()->notifications->stateChanged($return, $to);
        }

        if ($this->hasEventHandlers(self::EVENT_AFTER_TRANSITION)) {
            $this->trigger(self::EVENT_AFTER_TRANSITION, $event);
        }

        return true;
    }

    /**
     * Move to the first state of a kind, if the install has one.
     */
    public function transitionToKind(ReturnRequest $return, StateKind $kind, array $options = []): bool
    {
        $state = Plugin::getInstance()->states->getStateByKind($kind);

        if ($state === null) {
            $return->addError('stateId', Craft::t('boomerang', 'This site has no “{kind}” state.', [
                'kind' => $kind->label(),
            ]));

            return false;
        }

        return $this->transition($return, $state, $options);
    }

    public function approve(ReturnRequest $return, array $options = []): bool
    {
        return $this->transitionToKind($return, StateKind::Approved, $options);
    }

    public function reject(ReturnRequest $return, ?string $reason = null, array $options = []): bool
    {
        return $this->transitionToKind($return, StateKind::Rejected, $options + ['message' => $reason]);
    }

    public function cancel(ReturnRequest $return, array $options = []): bool
    {
        return $this->transitionToKind($return, StateKind::Cancelled, $options);
    }

    /**
     * Record what actually turned up, then move the RMA to a received state.
     *
     * `$received` is keyed by return item ID: `[7 => ['qty' => 2, 'condition' => 'damaged']]`.
     */
    public function receive(ReturnRequest $return, array $received, array $options = []): bool
    {
        $items = $return->getItems();

        foreach ($items as $item) {
            if (!isset($received[$item->id])) {
                continue;
            }

            $row = $received[$item->id];

            if (isset($row['qty'])) {
                $item->qtyReceived = max(0, min((int)$row['qty'], $item->getResolvableQty()));
            }

            if (!empty($row['condition']) && ItemCondition::tryFrom((string)$row['condition']) !== null) {
                $item->condition = (string)$row['condition'];
            }

            if (isset($row['staffNote'])) {
                $item->staffNote = trim((string)$row['staffNote']) ?: null;
            }
        }

        $return->setItems($items);

        if (!$this->save($return, false)) {
            return false;
        }

        // Recalculated from what arrived rather than from what was asked for: a customer who sent
        // back two of the three they asked to gets paid for two.
        $return->resolutionAmount = $this->calculateResolutionAmount($return);
        $return->feeAmount = $this->calculateFee($return);

        return $this->transitionToKind($return, StateKind::Received, $options);
    }

    /**
     * Defaults for quantities nobody has typed in.
     */
    private function fillQuantities(ReturnRequest $return, State $to): void
    {
        $items = $return->getItems();
        $changed = false;

        foreach ($items as $item) {
            if ($to->isApproved() && $item->qtyApproved === 0) {
                $item->qtyApproved = $item->qtyRequested;
                $changed = true;
            }

            if ($to->isReceived() && $item->qtyReceived === 0) {
                $item->qtyReceived = $item->getResolvableQty();
                $changed = true;
            }
        }

        if ($changed) {
            $return->setItems($items);
        }
    }

    private function runRestock(ReturnRequest $return): bool
    {
        if (!Plugin::getInstance()->getSettings()->getEffectiveRestockEnabled()) {
            return true;
        }

        try {
            Plugin::getInstance()->restock->apply($return);
        } catch (\Throwable $e) {
            $this->fail($return, Craft::t('boomerang', 'Restock failed: {message}', ['message' => $e->getMessage()]));

            return false;
        }

        return true;
    }

    private function runResolution(ReturnRequest $return): bool
    {
        $resolution = $return->getResolution();

        if (!$resolution->isTransacting()) {
            return true;
        }

        $plugin = Plugin::getInstance();

        if ($resolution->isPro() && !$plugin->isPro()) {
            $this->fail($return, Craft::t('boomerang', '{resolution} needs Boomerang Pro.', [
                'resolution' => $resolution->label(),
            ]));

            return false;
        }

        // Recalculated at the moment of resolution: quantities may have changed since the request,
        // and the amount that is paid out must be the amount the RMA says it is.
        $return->resolutionAmount = $this->calculateResolutionAmount($return);
        $return->feeAmount = $this->calculateFee($return);

        try {
            match ($resolution) {
                Resolution::Refund => $plugin->refunds->issue($return),
                Resolution::Credit => $plugin->wallet->issueForReturn($return),
                Resolution::Exchange, Resolution::Replacement => $plugin->exchanges->create($return),
                Resolution::None => null,
            };
        } catch (\Throwable $e) {
            $this->fail($return, Craft::t('boomerang', '{resolution} failed: {message}', [
                'resolution' => $resolution->label(),
                'message' => $e->getMessage(),
            ]));

            return false;
        }

        return true;
    }

    /**
     * Record a failure on the RMA, both as an error a controller can show and as a history entry
     * somebody can find next week.
     */
    private function fail(ReturnRequest $return, string $message): void
    {
        $return->addError('resolution', $message);

        Craft::error($message, __METHOD__);

        if ($return->id !== null) {
            $this->addEvent($return, EventType::Failed, $message);
        }
    }

    // Money
    // -------------------------------------------------------------------------

    /**
     * What the customer gets, after any restocking fee.
     */
    public function calculateResolutionAmount(ReturnRequest $return): float
    {
        return round(max(0.0, $return->getItemsValue() - $this->calculateFee($return)), 4);
    }

    /**
     * The restocking fee held back, capped at the value of the goods.
     */
    public function calculateFee(ReturnRequest $return): float
    {
        $settings = Plugin::getInstance()->getSettings();
        $value = $return->getItemsValue();

        if ($value <= 0) {
            return 0.0;
        }

        // Charging a customer for the store's own mistake is how a return becomes a chargeback.
        if ($settings->waiveFeeOnFault && $return->getIsFault()) {
            return 0.0;
        }

        $fee = ($value * $settings->restockingFeePercent / 100) + $settings->restockingFeeFlat;

        return round(min($fee, $value), 4);
    }

    // Items
    // -------------------------------------------------------------------------

    /**
     * @return ReturnItem[]
     */
    public function getItemsForReturn(int $returnId): array
    {
        $items = [];

        foreach ($this->itemQuery()->where(['returnId' => $returnId])->all() as $row) {
            $items[] = $this->createItem($row);
        }

        return $items;
    }

    public function getItemById(int $id): ?ReturnItem
    {
        $row = $this->itemQuery()->where(['id' => $id])->one();

        return $row ? $this->createItem($row) : null;
    }

    /**
     * Replace an RMA's items with the given set.
     *
     * Rows the caller no longer holds are deleted, which is the only way a merchant can take a
     * line off a return. Everything happens in one transaction: an RMA that half-saved its lines
     * would refund an amount that matches nothing.
     *
     * @param ReturnItem[] $items
     */
    public function saveItems(ReturnRequest $return, array $items): void
    {
        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $keptIds = [];

            foreach ($items as $item) {
                $item->returnId = $return->id;
                $record = $item->id !== null ? ReturnItemRecord::findOne($item->id) : null;

                if ($record === null) {
                    $record = new ReturnItemRecord();
                }

                $record->returnId = $item->returnId;
                $record->lineItemId = $item->lineItemId;
                $record->purchasableId = $item->purchasableId;
                $record->sku = $item->sku;
                $record->description = $item->description;
                $record->options = $item->options === [] ? null : Json::encode($item->options);
                $record->qtyRequested = $item->qtyRequested;
                $record->qtyApproved = $item->qtyApproved;
                $record->qtyReceived = $item->qtyReceived;
                $record->qtyRestocked = $item->qtyRestocked;
                $record->reasonId = $item->reasonId;
                $record->reasonName = $item->reasonName;
                $record->customerComment = $item->customerComment;
                $record->staffNote = $item->staffNote;
                $record->condition = $item->condition;
                $record->unitPrice = $item->unitPrice;
                $record->unitTax = $item->unitTax;
                $record->unitShipping = $item->unitShipping;
                $record->photoIds = $item->photoIds === [] ? null : Json::encode($item->photoIds);
                $record->save(false);

                $item->id = (int)$record->id;
                $item->uid = (string)$record->uid;
                $keptIds[] = $item->id;
            }

            $condition = ['returnId' => $return->id];

            if ($keptIds !== []) {
                $condition = ['and', $condition, ['not', ['id' => $keptIds]]];
            }

            $db->createCommand()->delete(Table::RETURNITEMS, $condition)->execute();

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }
    }

    /**
     * Persist a single item's counters without touching the rest of the RMA.
     *
     * Used by the restock service, which must be able to record what it moved even if something
     * later in the same transition fails.
     */
    public function saveItem(ReturnItem $item): bool
    {
        $record = $item->id !== null ? ReturnItemRecord::findOne($item->id) : null;

        if ($record === null) {
            return false;
        }

        $record->qtyApproved = $item->qtyApproved;
        $record->qtyReceived = $item->qtyReceived;
        $record->qtyRestocked = $item->qtyRestocked;
        $record->condition = $item->condition;
        $record->staffNote = $item->staffNote;

        return $record->save(false);
    }

    private function itemQuery(): Query
    {
        return (new Query())
            ->select([
                'id', 'returnId', 'lineItemId', 'purchasableId', 'sku', 'description', 'options',
                'qtyRequested', 'qtyApproved', 'qtyReceived', 'qtyRestocked', 'reasonId',
                'reasonName', 'customerComment', 'staffNote', 'condition', 'unitPrice', 'unitTax',
                'unitShipping', 'photoIds', 'dateCreated', 'dateUpdated', 'uid',
            ])
            ->from([Table::RETURNITEMS])
            ->orderBy(['id' => SORT_ASC]);
    }

    private function createItem(array $row): ReturnItem
    {
        $row['options'] = $row['options'] ? (array)Json::decodeIfJson($row['options']) : [];
        $row['photoIds'] = $row['photoIds'] ? (array)Json::decodeIfJson($row['photoIds']) : [];
        $row['id'] = (int)$row['id'];
        $row['unitPrice'] = (float)$row['unitPrice'];
        $row['unitTax'] = (float)$row['unitTax'];
        $row['unitShipping'] = (float)$row['unitShipping'];

        foreach (['qtyRequested', 'qtyApproved', 'qtyReceived', 'qtyRestocked'] as $attribute) {
            $row[$attribute] = (int)$row[$attribute];
        }

        return new ReturnItem($row);
    }

    // History
    // -------------------------------------------------------------------------

    /**
     * @return ReturnEvent[] Newest first.
     */
    public function getEventsForReturn(int $returnId, bool $customerVisibleOnly = false): array
    {
        $rows = (new Query())
            ->select(['id', 'returnId', 'type', 'userId', 'byCustomer', 'message', 'data', 'dateCreated', 'uid'])
            ->from([Table::EVENTS])
            ->where(['returnId' => $returnId])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->all();

        $events = [];

        foreach ($rows as $row) {
            $row['data'] = $row['data'] ? (array)Json::decodeIfJson($row['data']) : [];
            $row['byCustomer'] = (bool)$row['byCustomer'];
            $row['id'] = (int)$row['id'];

            $event = new ReturnEvent($row);

            if ($customerVisibleOnly && !$event->isCustomerVisible()) {
                continue;
            }

            $events[] = $event;
        }

        return $events;
    }

    /**
     * Append to an RMA's history.
     *
     * The only writer. Nothing updates or deletes an event, because the value of a history is
     * entirely in nobody being able to tidy it.
     */
    public function addEvent(
        ReturnRequest $return,
        EventType $type,
        ?string $message = null,
        array $data = [],
        bool $byCustomer = false,
    ): ReturnEvent {
        $event = new ReturnEvent();
        $event->returnId = $return->id;
        $event->type = $type->value;
        $event->message = $message;
        $event->data = $data;
        $event->byCustomer = $byCustomer;
        $event->userId = $byCustomer ? null : Craft::$app->getUser()->getIdentity()?->id;

        $record = new EventRecord();
        $record->returnId = $event->returnId;
        $record->type = $event->type;
        $record->userId = $event->userId;
        $record->byCustomer = $event->byCustomer;
        $record->message = $event->message;
        $record->data = $data === [] ? null : Json::encode($data);
        $record->save(false);

        $event->id = (int)$record->id;
        $event->dateCreated = DateTimeHelper::toDateTime($record->dateCreated) ?: null;

        return $event;
    }

    /**
     * A staff note, which is a history entry rather than a field, so that two people adding one at
     * the same time do not overwrite each other.
     */
    public function addNote(ReturnRequest $return, string $message, bool $byCustomer = false): ReturnEvent
    {
        return $this->addEvent($return, EventType::Note, $message, [], $byCustomer);
    }

    // Housekeeping
    // -------------------------------------------------------------------------

    /**
     * @return ReturnRequest[]
     */
    public function getReturnsForOrder(int $orderId): array
    {
        return ReturnRequest::find()->orderId($orderId)->isArchived(null)->all();
    }

    public function getReturnByToken(string $token): ?ReturnRequest
    {
        if (strlen($token) !== 32) {
            return null;
        }

        return ReturnRequest::find()->portalToken($token)->isArchived(null)->one();
    }

    public function getReturnByReference(string $reference): ?ReturnRequest
    {
        return ReturnRequest::find()->reference($reference)->isArchived(null)->one();
    }
}
