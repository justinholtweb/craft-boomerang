<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\elements;

use Craft;
use craft\base\Element;
use craft\commerce\elements\Order;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\models\Site;
use DateTime;
use justinholtweb\boomerang\db\Table;
use justinholtweb\boomerang\elements\actions\SetState;
use justinholtweb\boomerang\elements\db\ReturnRequestQuery;
use justinholtweb\boomerang\enums\Resolution;
use justinholtweb\boomerang\models\Label;
use justinholtweb\boomerang\models\ReturnEvent;
use justinholtweb\boomerang\models\ReturnItem;
use justinholtweb\boomerang\models\State;
use justinholtweb\boomerang\Plugin;
use justinholtweb\boomerang\records\ReturnRecord;
use yii\db\Query;

/**
 * An RMA: a customer asking for something back, and everything that happened next.
 *
 * An element, because a returns desk needs the index, search, the trash, relations, custom fields
 * and permissions, and none of those are worth rewriting. Three deliberate departures from how
 * Craft usually treats elements:
 *
 * **It is not localized.** A return happens once, from one place, in one language. The site it was
 * made from is recorded as an ordinary column (`submittedSiteId`, *not* `siteId` — Craft already
 * owns that name on every element, and a column that shadows it reassigns the RMA to the wrong
 * site on hydration).
 *
 * **It survives its order.** `orderId` is `SET NULL`, and the order's number, reference, date and
 * email are copied on. "What did we do about this return" has to stay answerable after an order
 * has been pruned, and in several jurisdictions it is a question a merchant is required to be able
 * to answer.
 *
 * **It has no element status.** Its state machine is configurable and has seven kinds; squeezing
 * that into Craft's enabled/disabled would mean two sources of truth about the same thing. The
 * index is grouped by state instead.
 *
 * The element's **field layout** is what the merchant's team records about the return — carrier
 * claim number, who inspected it — never anything the customer posts.
 *
 * @property-read Order|null $order
 * @property-read State|null $state
 * @property-read ReturnItem[] $items
 * @property-read ReturnEvent[] $events
 * @property-read Label[] $labels
 */
class ReturnRequest extends Element
{
    public ?int $storeId = null;
    public ?int $orderId = null;
    public ?string $orderNumber = null;
    public ?string $orderReference = null;
    public ?DateTime $orderDate = null;
    public ?int $customerId = null;
    public string $email = '';
    public ?int $submittedSiteId = null;
    public ?int $stateId = null;
    public ?int $sequence = null;
    public ?string $reference = null;
    public ?string $resolution = null;
    public ?string $currency = null;
    public float $resolutionAmount = 0.0;
    public float $feeAmount = 0.0;
    public float $refundedAmount = 0.0;
    public float $creditedAmount = 0.0;
    public ?int $exchangeOrderId = null;
    public ?int $inventoryLocationId = null;
    public ?string $portalToken = null;
    public ?string $customerNote = null;
    public ?string $staffNote = null;
    public bool $isArchived = false;
    public ?DateTime $requestedAt = null;
    public ?DateTime $stateChangedAt = null;
    public ?DateTime $approvedAt = null;
    public ?DateTime $receivedAt = null;
    public ?DateTime $resolvedAt = null;

    private ?Order $_order = null;
    private ?State $_state = null;

    /** @var ReturnItem[]|null */
    private ?array $_items = null;

    /** @var ReturnEvent[]|null */
    private ?array $_events = null;

    /** @var Label[]|null */
    private ?array $_labels = null;

    // Identity
    // -------------------------------------------------------------------------

    public static function displayName(): string
    {
        return Craft::t('boomerang', 'Return');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('boomerang', 'return');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('boomerang', 'Returns');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('boomerang', 'returns');
    }

    public static function refHandle(): ?string
    {
        return 'return';
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasUris(): bool
    {
        return false;
    }

    public static function hasStatuses(): bool
    {
        return false;
    }

    public static function isLocalized(): bool
    {
        return false;
    }

    public static function hasDrafts(): bool
    {
        return false;
    }

    public static function find(): ElementQueryInterface
    {
        return new ReturnRequestQuery(static::class);
    }

    public function datetimeAttributes(): array
    {
        return array_merge(parent::datetimeAttributes(), [
            'orderDate',
            'requestedAt',
            'stateChangedAt',
            'approvedAt',
            'receivedAt',
            'resolvedAt',
        ]);
    }

    public function getFieldLayout(): ?FieldLayout
    {
        return Craft::$app->getFields()->getLayoutByType(self::class);
    }

    protected function cpEditUrl(): ?string
    {
        return UrlHelper::cpUrl('boomerang/returns/' . $this->getCanonicalId());
    }

    public function getPostEditUrl(): ?string
    {
        return UrlHelper::cpUrl('boomerang/returns');
    }

    public function getUiLabel(): string
    {
        return $this->reference ?: Craft::t('boomerang', 'Return');
    }

    // Relations
    // -------------------------------------------------------------------------

    public function getOrder(): ?Order
    {
        if ($this->_order !== null) {
            return $this->_order;
        }

        if ($this->orderId === null) {
            return null;
        }

        return $this->_order = Order::find()->id($this->orderId)->status(null)->one();
    }

    public function setOrder(?Order $order): void
    {
        $this->_order = $order;
        $this->orderId = $order?->id;
    }

    public function getExchangeOrder(): ?Order
    {
        return $this->exchangeOrderId !== null
            ? Order::find()->id($this->exchangeOrderId)->status(null)->one()
            : null;
    }

    public function getCustomer(): ?User
    {
        return $this->customerId !== null ? Craft::$app->getUsers()->getUserById($this->customerId) : null;
    }

    public function getSubmittedSite(): ?Site
    {
        return $this->submittedSiteId !== null
            ? Craft::$app->getSites()->getSiteById($this->submittedSiteId)
            : null;
    }

    public function getState(): ?State
    {
        if ($this->_state !== null) {
            return $this->_state;
        }

        if ($this->stateId === null) {
            return null;
        }

        return $this->_state = Plugin::getInstance()->states->getStateById($this->stateId);
    }

    public function setState(?State $state): void
    {
        $this->_state = $state;
        $this->stateId = $state?->id;
    }

    public function getResolution(): Resolution
    {
        return Resolution::tryFrom((string)$this->resolution) ?? Resolution::None;
    }

    /**
     * @return ReturnItem[]
     */
    public function getItems(): array
    {
        if ($this->_items !== null) {
            return $this->_items;
        }

        if ($this->id === null) {
            return $this->_items = [];
        }

        return $this->_items = Plugin::getInstance()->returns->getItemsForReturn($this->id);
    }

    /**
     * @param ReturnItem[] $items
     */
    public function setItems(array $items): void
    {
        foreach ($items as $item) {
            $item->returnId = $this->id;
        }

        $this->_items = array_values($items);
    }

    /**
     * @return ReturnEvent[]
     */
    public function getEvents(): array
    {
        if ($this->_events !== null) {
            return $this->_events;
        }

        if ($this->id === null) {
            return $this->_events = [];
        }

        return $this->_events = Plugin::getInstance()->returns->getEventsForReturn($this->id);
    }

    /**
     * @return Label[]
     */
    public function getLabels(): array
    {
        if ($this->_labels !== null) {
            return $this->_labels;
        }

        if ($this->id === null) {
            return $this->_labels = [];
        }

        return $this->_labels = Plugin::getInstance()->labels->getLabelsForReturn($this->id);
    }

    /**
     * The label a customer should actually be given: the newest one that has not been voided.
     */
    public function getActiveLabel(): ?Label
    {
        foreach ($this->getLabels() as $label) {
            if (!$label->getIsVoided()) {
                return $label;
            }
        }

        return null;
    }

    // Derived
    // -------------------------------------------------------------------------

    public function getIsClosed(): bool
    {
        return $this->getState()?->isClosed() ?? false;
    }

    public function getIsResolved(): bool
    {
        return $this->getState()?->isResolved() ?? false;
    }

    public function getIsReceived(): bool
    {
        return $this->receivedAt !== null;
    }

    /**
     * Whether the customer may still call it off from the portal.
     */
    public function getIsCustomerCancellable(): bool
    {
        return Plugin::getInstance()->getSettings()->portalAllowsCancel
            && ($this->getState()?->isCustomerCancellable() ?? false);
    }

    public function getTotalQtyRequested(): int
    {
        return array_sum(array_map(fn(ReturnItem $item) => $item->qtyRequested, $this->getItems()));
    }

    public function getTotalQtyReceived(): int
    {
        return array_sum(array_map(fn(ReturnItem $item) => $item->qtyReceived, $this->getItems()));
    }

    /**
     * What the items on this RMA are worth back, before any fee.
     */
    public function getItemsValue(): float
    {
        $settings = Plugin::getInstance()->getSettings();
        $isFullOrder = $this->getIsWholeOrder();

        $includeShipping = $settings->refundIncludesShipping
            || ($isFullOrder && $settings->refundShippingOnFullReturn);

        $total = 0.0;

        foreach ($this->getItems() as $item) {
            $total += $item->getRefundAmount($settings->refundIncludesTax, $includeShipping);
        }

        return round($total, 4);
    }

    /**
     * Whether every unit of every line on the order is on this RMA.
     *
     * Asked because most stores refund the shipping when the whole order goes back and not
     * otherwise, and "the whole order" is a question about quantities rather than line counts.
     */
    public function getIsWholeOrder(): bool
    {
        $order = $this->getOrder();

        if ($order === null) {
            return false;
        }

        $requested = [];

        foreach ($this->getItems() as $item) {
            if ($item->lineItemId === null) {
                return false;
            }

            $requested[$item->lineItemId] = ($requested[$item->lineItemId] ?? 0) + $item->getResolvableQty();
        }

        foreach ($order->getLineItems() as $lineItem) {
            if (($requested[$lineItem->id] ?? 0) < $lineItem->qty) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether any reason given is the store's own fault. Drives fee waiving and free labels.
     */
    public function getIsFault(): bool
    {
        foreach ($this->getItems() as $item) {
            if ($item->getReason()?->isFault) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether return shipping is on the store for this RMA.
     */
    public function getHasFreeReturnShipping(): bool
    {
        foreach ($this->getItems() as $item) {
            if ($item->getReason()?->freeReturnShipping) {
                return true;
            }
        }

        return false;
    }

    /**
     * The customer's own link to the status page.
     */
    public function getPortalUrl(): ?string
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($this->portalToken === null || !$settings->portalEnabled || $settings->portalUriPrefix === '') {
            return null;
        }

        return UrlHelper::siteUrl(
            $settings->portalUriPrefix . '/status',
            ['rma' => $this->portalToken],
            null,
            $this->submittedSiteId,
        );
    }

    // Saving
    // -------------------------------------------------------------------------

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['email'], 'required'];
        $rules[] = [['email'], 'email'];
        $rules[] = [['email', 'orderReference', 'reference'], 'string', 'max' => 255];
        $rules[] = [['orderId', 'customerId', 'stateId', 'storeId', 'exchangeOrderId', 'inventoryLocationId'], 'integer'];
        $rules[] = [['resolutionAmount', 'feeAmount', 'refundedAmount', 'creditedAmount'], 'number'];
        $rules[] = [['resolution'], 'in', 'range' => array_column(Resolution::cases(), 'value'), 'skipOnEmpty' => true];
        $rules[] = [['customerNote', 'staffNote'], 'string'];

        return $rules;
    }

    public function beforeSave(bool $isNew): bool
    {
        if ($isNew) {
            $this->requestedAt ??= new DateTime();
            $this->stateChangedAt ??= $this->requestedAt;
            // A 32-character random string, not a UUID: the column is `char(32)` and a UUID is 36
            // with its hyphens, which MySQL truncates in a way that quietly breaks every status
            // link — and in strict mode refuses the insert outright.
            $this->portalToken ??= StringHelper::randomString(32);
            $this->stateId ??= Plugin::getInstance()->states->getDefaultState()?->id;
            $this->resolution ??= Plugin::getInstance()->getSettings()->getDefaultResolution()->value;
            $this->submittedSiteId ??= Craft::$app->getSites()->getCurrentSite()->id;

            if ($this->sequence === null) {
                [$this->sequence, $this->reference] = $this->nextReference();
            }
        }

        // The element's title is the RMA number, so every generic Craft screen that shows a title
        // — relations, the trash, search results — says which return rather than "Return 412".
        $this->title = $this->reference;

        return parent::beforeSave($isNew);
    }

    /**
     * The next RMA number.
     *
     * Sequential rather than derived from the element ID, because an RMA number is quoted on the
     * phone and "RMA-01043" for the store's first ever return invites a phone call. Both columns
     * are unique, so a collision under concurrency fails the insert rather than issuing the same
     * number twice; {@see \justinholtweb\boomerang\services\Returns::save()} retries.
     *
     * @return array{0: int, 1: string}
     */
    private function nextReference(): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $max = (new Query())
            ->from([Table::RETURNS])
            ->max('[[sequence]]');

        $sequence = max((int)$max + 1, $settings->referenceStartsAt);

        return [
            $sequence,
            $settings->referencePrefix . str_pad((string)$sequence, $settings->referencePadding, '0', STR_PAD_LEFT),
        ];
    }

    public function afterSave(bool $isNew): void
    {
        $record = $isNew ? new ReturnRecord() : ReturnRecord::findOne($this->id);

        if ($record === null) {
            $record = new ReturnRecord();
            $isNew = true;
        }

        if ($isNew) {
            $record->id = $this->id;
        }

        $record->storeId = $this->storeId;
        $record->orderId = $this->orderId;
        $record->orderNumber = $this->orderNumber;
        $record->orderReference = $this->orderReference;
        $record->orderDate = Db::prepareDateForDb($this->orderDate);
        $record->customerId = $this->customerId;
        $record->email = $this->email;
        $record->submittedSiteId = $this->submittedSiteId;
        $record->stateId = $this->stateId;
        $record->sequence = $this->sequence;
        $record->reference = $this->reference;
        $record->resolution = $this->resolution;
        $record->currency = $this->currency;
        $record->resolutionAmount = $this->resolutionAmount;
        $record->feeAmount = $this->feeAmount;
        $record->refundedAmount = $this->refundedAmount;
        $record->creditedAmount = $this->creditedAmount;
        $record->exchangeOrderId = $this->exchangeOrderId;
        $record->inventoryLocationId = $this->inventoryLocationId;
        $record->portalToken = $this->portalToken;
        $record->customerNote = $this->customerNote;
        $record->staffNote = $this->staffNote;
        $record->isArchived = $this->isArchived;
        $record->requestedAt = Db::prepareDateForDb($this->requestedAt);
        $record->stateChangedAt = Db::prepareDateForDb($this->stateChangedAt);
        $record->approvedAt = Db::prepareDateForDb($this->approvedAt);
        $record->receivedAt = Db::prepareDateForDb($this->receivedAt);
        $record->resolvedAt = Db::prepareDateForDb($this->resolvedAt);
        $record->save(false);

        // Items are held on the element between the controller and here, so that a return and its
        // lines are saved in one call and cannot half-exist.
        if ($this->_items !== null) {
            Plugin::getInstance()->returns->saveItems($this, $this->_items);
        }

        parent::afterSave($isNew);
    }

    // Search
    // -------------------------------------------------------------------------

    protected static function defineSearchableAttributes(): array
    {
        return ['reference', 'email', 'orderNumber', 'orderReference', 'customerNote', 'itemText'];
    }

    protected function searchKeywords(string $attribute): string
    {
        if ($attribute === 'itemText') {
            $words = [];

            foreach ($this->getItems() as $item) {
                $words[] = $item->sku;
                $words[] = $item->description;
                $words[] = (string)$item->reasonName;
                $words[] = (string)$item->customerComment;
            }

            return implode(' ', array_filter($words));
        }

        return parent::searchKeywords($attribute);
    }

    // Index
    // -------------------------------------------------------------------------

    protected static function defineSources(string $context): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('boomerang', 'All returns'),
                'criteria' => ['isArchived' => false],
                'defaultSort' => ['requestedAt', 'desc'],
            ],
            [
                'key' => 'open',
                'label' => Craft::t('boomerang', 'Needs attention'),
                'criteria' => ['isArchived' => false, 'isOpen' => true],
                'defaultSort' => ['requestedAt', 'asc'],
            ],
        ];

        $states = Plugin::getInstance()->states->getAllStates();

        if ($states !== []) {
            $sources[] = ['heading' => Craft::t('boomerang', 'State')];

            foreach ($states as $state) {
                $sources[] = [
                    'key' => 'state:' . $state->uid,
                    'label' => $state->name,
                    'criteria' => ['isArchived' => false, 'stateId' => $state->id],
                    'data' => ['handle' => $state->handle, 'color' => $state->getColor()],
                    'defaultSort' => ['requestedAt', 'desc'],
                ];
            }
        }

        $sources[] = ['heading' => Craft::t('boomerang', 'Elsewhere')];
        $sources[] = [
            'key' => 'archived',
            'label' => Craft::t('boomerang', 'Archived'),
            'criteria' => ['isArchived' => true],
            'defaultSort' => ['requestedAt', 'desc'],
        ];

        return $sources;
    }

    protected static function defineActions(string $source): array
    {
        $actions = parent::defineActions($source);

        if (Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE)) {
            $actions[] = SetState::class;
        }

        return $actions;
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'state' => ['label' => Craft::t('boomerang', 'State')],
            'order' => ['label' => Craft::t('boomerang', 'Order')],
            'customer' => ['label' => Craft::t('boomerang', 'Customer')],
            'email' => ['label' => Craft::t('boomerang', 'Email')],
            'items' => ['label' => Craft::t('boomerang', 'Items')],
            'reasons' => ['label' => Craft::t('boomerang', 'Reasons')],
            'resolution' => ['label' => Craft::t('boomerang', 'Resolution')],
            'value' => ['label' => Craft::t('boomerang', 'Value')],
            'requestedAt' => ['label' => Craft::t('boomerang', 'Requested')],
            'resolvedAt' => ['label' => Craft::t('boomerang', 'Resolved')],
            'age' => ['label' => Craft::t('boomerang', 'Age')],
            'submittedSite' => ['label' => Craft::t('app', 'Site')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['state', 'order', 'customer', 'items', 'resolution', 'value', 'requestedAt'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'boomerang_returns.sequence' => Craft::t('boomerang', 'RMA number'),
            'boomerang_returns.requestedAt' => Craft::t('boomerang', 'Requested'),
            'boomerang_returns.resolvedAt' => Craft::t('boomerang', 'Resolved'),
            'boomerang_returns.resolutionAmount' => Craft::t('boomerang', 'Value'),
            'boomerang_returns.email' => Craft::t('boomerang', 'Email'),
        ];
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'state' => $this->stateHtml(),
            'order' => $this->orderHtml(),
            'customer' => $this->customerHtml(),
            'email' => Html::a(Html::encode($this->email), 'mailto:' . $this->email),
            'items' => $this->itemsHtml(),
            'reasons' => $this->reasonsHtml(),
            'resolution' => Html::encode($this->getResolution()->label()),
            'value' => $this->valueHtml(),
            'age' => $this->ageHtml(),
            'submittedSite' => Html::encode((string)$this->getSubmittedSite()?->name),
            default => parent::attributeHtml($attribute),
        };
    }

    private function stateHtml(): string
    {
        $state = $this->getState();

        if ($state === null) {
            return Html::tag('span', '—', ['class' => 'light']);
        }

        // Dot and label as siblings — `.status` is a fixed-size circle, and text inside it wraps
        // one letter per line.
        return Html::tag('span', '', ['class' => ['status', $state->getColor()]])
            . Html::tag('span', Html::encode($state->name));
    }

    private function orderHtml(): string
    {
        $order = $this->getOrder();
        $label = $this->orderReference ?: ($this->orderNumber !== null ? substr($this->orderNumber, 0, 7) : '—');

        if ($order !== null) {
            return Html::a(Html::encode($label), (string)$order->getCpEditUrl());
        }

        // The order is gone; the copied identity is all that is left, and that is the point of it.
        return Html::tag('span', Html::encode($label), ['class' => 'light']);
    }

    private function customerHtml(): string
    {
        $customer = $this->getCustomer();

        if ($customer !== null) {
            return Html::a(Html::encode((string)$customer->getUiLabel()), (string)$customer->getCpEditUrl());
        }

        return Html::tag('span', Craft::t('boomerang', 'Guest'), ['class' => 'light']);
    }

    private function itemsHtml(): string
    {
        $items = $this->getItems();

        if ($items === []) {
            return Html::tag('span', '—', ['class' => 'light']);
        }

        $qty = $this->getTotalQtyRequested();
        $label = Craft::t('boomerang', '{qty, plural, =1{1 item} other{# items}}', ['qty' => $qty]);

        return Html::tag('span', Html::encode($label), [
            'title' => implode(', ', array_map(
                fn(ReturnItem $item) => sprintf('%d × %s', $item->qtyRequested, $item->description),
                $items,
            )),
        ]);
    }

    private function reasonsHtml(): string
    {
        $labels = [];

        foreach ($this->getItems() as $item) {
            $labels[$item->getReasonLabel()] = true;
        }

        if ($labels === []) {
            return Html::tag('span', '—', ['class' => 'light']);
        }

        return Html::encode(implode(', ', array_keys($labels)));
    }

    private function valueHtml(): string
    {
        $currency = $this->currency ?: Craft::$app->getProjectConfig()->get('commerce.defaultCurrency') ?: 'USD';

        return Html::encode(Craft::$app->getFormatter()->asCurrency($this->resolutionAmount, $currency));
    }

    /**
     * How long this has been open, or how long it took.
     *
     * The one number a returns desk is actually judged on, so it is on the index rather than two
     * clicks in.
     */
    private function ageHtml(): string
    {
        if ($this->requestedAt === null) {
            return '';
        }

        $end = $this->resolvedAt ?? new DateTime();
        $days = (int)$this->requestedAt->diff($end)->days;

        $label = Craft::t('boomerang', '{days, plural, =0{today} =1{1 day} other{# days}}', ['days' => $days]);

        // Anything open for more than a fortnight is a complaint waiting to happen.
        $class = ($this->resolvedAt === null && $days > 14) ? 'error' : 'light';

        return Html::tag('span', Html::encode($label), ['class' => $class]);
    }

    // Permissions
    // -------------------------------------------------------------------------

    public function canView(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_VIEW);
    }

    public function canSave(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE);
    }

    public function canDuplicate(User $user): bool
    {
        // Duplicating an RMA would produce a second claim on the same goods with the same
        // snapshot prices, which is a refund waiting to be issued twice.
        return false;
    }

    public function canDelete(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE);
    }
}
