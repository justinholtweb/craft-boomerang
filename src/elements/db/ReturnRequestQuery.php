<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use justinholtweb\boomerang\elements\ReturnRequest;
use justinholtweb\boomerang\enums\StateKind;
use justinholtweb\boomerang\models\State;
use justinholtweb\boomerang\Plugin;

/**
 * Element query for returns.
 *
 * ## The archive is a filter, not a status
 *
 * `isArchived` defaults to **false**, so an unqualified `ReturnRequest::find()` returns the live
 * desk rather than everything ever received. That is the opposite of how most element queries
 * behave, and it is deliberate: a returns screen that silently included three years of settled
 * RMAs is useless by month two, and a plugin whose default query needs a filter to be usable
 * teaches everybody to write the filter by rote and then forget it in the one place it mattered.
 * Pass `isArchived(null)` for everything.
 *
 * @method ReturnRequest[] all($db = null)
 * @method ReturnRequest|null one($db = null)
 * @method ReturnRequest|null nth(int $n, ?\yii\db\Connection $db = null)
 */
class ReturnRequestQuery extends ElementQuery
{
    public mixed $orderId = null;
    public mixed $orderNumber = null;
    public mixed $customerId = null;
    public mixed $email = null;
    public mixed $stateId = null;
    public mixed $storeId = null;
    public mixed $submittedSiteId = null;
    public mixed $reference = null;
    public mixed $sequence = null;
    public mixed $portalToken = null;
    public mixed $resolution = null;
    public mixed $requestedAt = null;
    public mixed $resolvedAt = null;
    public mixed $exchangeOrderId = null;

    /** Null means "either". The default is applied in {@see beforePrepare()}, not here, so that an
     *  explicit `isArchived(null)` is distinguishable from never having asked. */
    public ?bool $isArchived = false;

    /** Not finished with: the state's kind is not a closed one. */
    public ?bool $isOpen = null;

    /** The goods are physically back. */
    public ?bool $isReceived = null;

    /** There is a purchasable on this RMA. Used by the per-product analytics. */
    public mixed $purchasableId = null;

    /** The reason given on any of its items. */
    public mixed $reasonId = null;

    protected array $defaultOrderBy = ['boomerang_returns.requestedAt' => SORT_DESC];

    public function orderId(mixed $value): static
    {
        $this->orderId = $value;

        return $this;
    }

    public function orderNumber(mixed $value): static
    {
        $this->orderNumber = $value;

        return $this;
    }

    public function customerId(mixed $value): static
    {
        $this->customerId = $value;

        return $this;
    }

    public function email(mixed $value): static
    {
        $this->email = $value;

        return $this;
    }

    public function stateId(mixed $value): static
    {
        $this->stateId = $value;

        return $this;
    }

    /**
     * @param State|string|string[]|null $value A state, or one or more handles.
     */
    public function state(mixed $value): static
    {
        if ($value instanceof State) {
            $this->stateId = $value->id;

            return $this;
        }

        if ($value === null) {
            $this->stateId = null;

            return $this;
        }

        $ids = [];

        foreach (is_array($value) ? $value : [$value] as $handle) {
            $state = Plugin::getInstance()->states->getStateByHandle((string)$handle);

            if ($state !== null) {
                $ids[] = $state->id;
            }
        }

        // No match returns nothing, not everything.
        $this->stateId = $ids !== [] ? $ids : false;

        return $this;
    }

    /**
     * Returns whose state is of one or more kinds.
     *
     * @param StateKind|StateKind[]|string|string[] $value
     */
    public function stateKind(mixed $value): static
    {
        $kinds = [];

        foreach (is_array($value) ? $value : [$value] as $kind) {
            $kinds[] = $kind instanceof StateKind ? $kind : StateKind::tryFrom((string)$kind);
        }

        $kinds = array_filter($kinds);
        $ids = [];

        foreach (Plugin::getInstance()->states->getAllStates() as $state) {
            if (in_array($state->getKind(), $kinds, true)) {
                $ids[] = $state->id;
            }
        }

        $this->stateId = $ids !== [] ? $ids : false;

        return $this;
    }

    public function storeId(mixed $value): static
    {
        $this->storeId = $value;

        return $this;
    }

    public function submittedSiteId(mixed $value): static
    {
        $this->submittedSiteId = $value;

        return $this;
    }

    public function reference(mixed $value): static
    {
        $this->reference = $value;

        return $this;
    }

    public function sequence(mixed $value): static
    {
        $this->sequence = $value;

        return $this;
    }

    public function portalToken(mixed $value): static
    {
        $this->portalToken = $value;

        return $this;
    }

    public function resolution(mixed $value): static
    {
        $this->resolution = $value;

        return $this;
    }

    public function requestedAt(mixed $value): static
    {
        $this->requestedAt = $value;

        return $this;
    }

    public function resolvedAt(mixed $value): static
    {
        $this->resolvedAt = $value;

        return $this;
    }

    public function exchangeOrderId(mixed $value): static
    {
        $this->exchangeOrderId = $value;

        return $this;
    }

    public function isArchived(?bool $value = true): static
    {
        $this->isArchived = $value;

        return $this;
    }

    public function isOpen(?bool $value = true): static
    {
        $this->isOpen = $value;

        return $this;
    }

    public function isReceived(?bool $value = true): static
    {
        $this->isReceived = $value;

        return $this;
    }

    public function purchasableId(mixed $value): static
    {
        $this->purchasableId = $value;

        return $this;
    }

    public function reasonId(mixed $value): static
    {
        $this->reasonId = $value;

        return $this;
    }

    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        $this->joinElementTable('boomerang_returns');

        $this->query->addSelect([
            'boomerang_returns.storeId',
            'boomerang_returns.orderId',
            'boomerang_returns.orderNumber',
            'boomerang_returns.orderReference',
            'boomerang_returns.orderDate',
            'boomerang_returns.customerId',
            'boomerang_returns.email',
            'boomerang_returns.submittedSiteId',
            'boomerang_returns.stateId',
            'boomerang_returns.sequence',
            'boomerang_returns.reference',
            'boomerang_returns.resolution',
            'boomerang_returns.currency',
            'boomerang_returns.resolutionAmount',
            'boomerang_returns.feeAmount',
            'boomerang_returns.refundedAmount',
            'boomerang_returns.creditedAmount',
            'boomerang_returns.exchangeOrderId',
            'boomerang_returns.inventoryLocationId',
            'boomerang_returns.portalToken',
            'boomerang_returns.customerNote',
            'boomerang_returns.staffNote',
            'boomerang_returns.isArchived',
            'boomerang_returns.requestedAt',
            'boomerang_returns.stateChangedAt',
            'boomerang_returns.approvedAt',
            'boomerang_returns.receivedAt',
            'boomerang_returns.resolvedAt',
        ]);

        foreach ([
            'orderId' => $this->orderId,
            'orderNumber' => $this->orderNumber,
            'customerId' => $this->customerId,
            'email' => $this->email,
            'stateId' => $this->stateId,
            'storeId' => $this->storeId,
            'submittedSiteId' => $this->submittedSiteId,
            'reference' => $this->reference,
            'sequence' => $this->sequence,
            'portalToken' => $this->portalToken,
            'resolution' => $this->resolution,
            'exchangeOrderId' => $this->exchangeOrderId,
        ] as $column => $value) {
            if ($value !== null) {
                $this->subQuery->andWhere(Db::parseParam("boomerang_returns.$column", $value));
            }
        }

        foreach (['requestedAt' => $this->requestedAt, 'resolvedAt' => $this->resolvedAt] as $column => $value) {
            if ($value !== null) {
                $this->subQuery->andWhere(Db::parseDateParam("boomerang_returns.$column", $value));
            }
        }

        if ($this->isArchived !== null) {
            $this->subQuery->andWhere(['boomerang_returns.isArchived' => $this->isArchived]);
        }

        if ($this->isReceived !== null) {
            $this->subQuery->andWhere($this->isReceived
                ? ['not', ['boomerang_returns.receivedAt' => null]]
                : ['boomerang_returns.receivedAt' => null]);
        }

        $this->applyOpenParam();
        $this->applyItemParams();

        return true;
    }

    /**
     * "Still on somebody's desk", resolved to the states whose kind is not closed.
     *
     * Resolved to a list of IDs rather than joined against the states table, because there are
     * never many states and they are already in memory — a join here would be a second
     * implementation of what {@see StateKind::isClosed()} already answers.
     */
    private function applyOpenParam(): void
    {
        if ($this->isOpen === null) {
            return;
        }

        $ids = [];

        foreach (Plugin::getInstance()->states->getAllStates() as $state) {
            if ($state->isClosed() !== $this->isOpen) {
                $ids[] = $state->id;
            }
        }

        if ($ids === []) {
            $this->subQuery->andWhere('1=0');

            return;
        }

        $condition = ['boomerang_returns.stateId' => $ids];

        // An RMA with no state at all — one whose state was deleted — counts as open. Nobody
        // decided anything about it, which is exactly what "open" means.
        $this->subQuery->andWhere($this->isOpen
            ? ['or', $condition, ['boomerang_returns.stateId' => null]]
            : $condition);
    }

    /**
     * Filters that live on the items rather than on the return.
     *
     * An `EXISTS` rather than a join: a return with three items matching the filter must appear
     * once, and a join would return it three times and quietly inflate every count built on top
     * of this query.
     */
    private function applyItemParams(): void
    {
        foreach (['purchasableId' => $this->purchasableId, 'reasonId' => $this->reasonId] as $column => $value) {
            if ($value === null) {
                continue;
            }

            $this->subQuery->andWhere([
                'exists',
                (new \craft\db\Query())
                    ->from(['bri' => '{{%boomerang_returnitems}}'])
                    ->where('[[bri.returnId]] = [[boomerang_returns.id]]')
                    ->andWhere(Db::parseParam("bri.$column", $value)),
            ]);
        }
    }
}
