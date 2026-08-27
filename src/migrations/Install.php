<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\migrations;

use craft\commerce\db\Table as CommerceTable;
use craft\db\Migration;
use craft\db\Table as CraftTable;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\boomerang\db\Table;
use justinholtweb\boomerang\enums\ItemCondition;
use justinholtweb\boomerang\enums\StateKind;

/**
 * Boomerang install migration.
 *
 * Seeds a working state machine and a set of reasons, because a returns plugin that does nothing
 * until somebody configures it is a returns plugin nobody evaluates past the install screen.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();
        $this->seed();

        return true;
    }

    public function safeDown(): bool
    {
        // Children first: every foreign key here is declared on the child side.
        $this->dropTableIfExists(Table::CREDITENTRIES);
        $this->dropTableIfExists(Table::CREDITLOTS);
        $this->dropTableIfExists(Table::LABELS);
        $this->dropTableIfExists(Table::EVENTS);
        $this->dropTableIfExists(Table::RETURNITEMS);
        $this->dropTableIfExists(Table::RETURNS);
        $this->dropTableIfExists(Table::REASONS);
        $this->dropTableIfExists(Table::STATES);

        $this->delete(CraftTable::ELEMENTS, ['type' => \justinholtweb\boomerang\elements\ReturnRequest::class]);

        return true;
    }

    private function createTables(): void
    {
        $this->createTable(Table::STATES, [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull(),
            'handle' => $this->string(255)->notNull(),
            // What the state *means*. Every branch in the plugin reads this, never the name.
            'kind' => $this->string(32)->notNull()->defaultValue(StateKind::Requested->value),
            'color' => $this->string(32),
            'description' => $this->text(),
            'sortOrder' => $this->integer()->notNull()->defaultValue(0),
            'isDefault' => $this->boolean()->notNull()->defaultValue(false),
            'notifyCustomer' => $this->boolean()->notNull()->defaultValue(false),
            'restockOnEnter' => $this->boolean()->notNull()->defaultValue(false),
            'resolveOnEnter' => $this->boolean()->notNull()->defaultValue(false),
            'offerLabel' => $this->boolean()->notNull()->defaultValue(false),
            'emailSubject' => $this->string(255),
            'emailBody' => $this->text(),
            // JSON list of state UIDs. Empty means anywhere.
            'transitions' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::REASONS, [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull(),
            'handle' => $this->string(255)->notNull(),
            'description' => $this->text(),
            'sortOrder' => $this->integer()->notNull()->defaultValue(0),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'customerSelectable' => $this->boolean()->notNull()->defaultValue(true),
            'requiresComment' => $this->boolean()->notNull()->defaultValue(false),
            'requiresPhoto' => $this->boolean()->notNull()->defaultValue(false),
            // The difference between "we got it wrong" and "they bought two sizes on purpose".
            'isFault' => $this->boolean()->notNull()->defaultValue(false),
            'autoApprove' => $this->boolean()->notNull()->defaultValue(false),
            'freeReturnShipping' => $this->boolean()->notNull()->defaultValue(false),
            'defaultCondition' => $this->string(32)->notNull()->defaultValue(ItemCondition::Inspect->value),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::RETURNS, [
            'id' => $this->integer()->notNull(),
            'storeId' => $this->integer(),
            // SET NULL, with the order's identity copied below: an RMA has to stay answerable
            // after its order is gone, which in several jurisdictions is not optional.
            'orderId' => $this->integer(),
            'orderNumber' => $this->string(32),
            'orderReference' => $this->string(255),
            'orderDate' => $this->dateTime(),
            'customerId' => $this->integer(),
            'email' => $this->string(255)->notNull(),
            // Not `siteId`: `craft\base\ElementTrait` already declares that property, and a
            // selected column of the same name silently reassigns the element's site.
            'submittedSiteId' => $this->integer(),
            'stateId' => $this->integer(),
            // Human-facing, unique, and the number a customer quotes on the phone.
            'sequence' => $this->integer()->notNull(),
            'reference' => $this->string(64)->notNull(),
            'resolution' => $this->string(32),
            'currency' => $this->char(3),
            // What the resolution is worth, before any fee.
            'resolutionAmount' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'feeAmount' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'refundedAmount' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'creditedAmount' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'exchangeOrderId' => $this->integer(),
            'inventoryLocationId' => $this->integer(),
            // The customer's own key to their status page. Never called `token` — Craft reserves
            // that query parameter and rejects the request before any controller runs.
            'portalToken' => $this->char(32),
            'customerNote' => $this->text(),
            'staffNote' => $this->text(),
            'isArchived' => $this->boolean()->notNull()->defaultValue(false),
            'requestedAt' => $this->dateTime(),
            'stateChangedAt' => $this->dateTime(),
            'approvedAt' => $this->dateTime(),
            'receivedAt' => $this->dateTime(),
            'resolvedAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createTable(Table::RETURNITEMS, [
            'id' => $this->primaryKey(),
            'returnId' => $this->integer()->notNull(),
            'lineItemId' => $this->integer(),
            'purchasableId' => $this->integer(),
            'sku' => $this->string(255),
            'description' => $this->string(255),
            'options' => $this->text(),
            'qtyRequested' => $this->integer()->notNull()->defaultValue(1),
            'qtyApproved' => $this->integer()->notNull()->defaultValue(0),
            'qtyReceived' => $this->integer()->notNull()->defaultValue(0),
            // The idempotency guard for restocking: a merchant clicking "Received" twice must not
            // double the stock.
            'qtyRestocked' => $this->integer()->notNull()->defaultValue(0),
            'reasonId' => $this->integer(),
            'reasonName' => $this->string(255),
            'customerComment' => $this->text(),
            'staffNote' => $this->text(),
            'condition' => $this->string(32)->notNull()->defaultValue(ItemCondition::Inspect->value),
            // Snapshots. A refund worked out six weeks later from a live line item is a refund for
            // a number nobody agreed to.
            'unitPrice' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'unitTax' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'unitShipping' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'photoIds' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::EVENTS, [
            'id' => $this->primaryKey(),
            'returnId' => $this->integer()->notNull(),
            'type' => $this->string(32)->notNull(),
            'userId' => $this->integer(),
            'byCustomer' => $this->boolean()->notNull()->defaultValue(false),
            'message' => $this->text(),
            'data' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::LABELS, [
            'id' => $this->primaryKey(),
            'returnId' => $this->integer()->notNull(),
            'provider' => $this->string(64)->notNull()->defaultValue('manual'),
            'providerLabelId' => $this->string(255),
            'carrier' => $this->string(255),
            'service' => $this->string(255),
            'trackingNumber' => $this->string(255),
            'trackingUrl' => $this->text(),
            'cost' => $this->decimal(14, 4),
            'currency' => $this->char(3),
            'labelUrl' => $this->text(),
            'assetId' => $this->integer(),
            'paidByStore' => $this->boolean()->notNull()->defaultValue(true),
            'rawResponse' => $this->mediumText(),
            'voidedAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::CREDITLOTS, [
            'id' => $this->primaryKey(),
            'customerId' => $this->integer()->notNull(),
            'storeId' => $this->integer(),
            'currency' => $this->char(3)->notNull(),
            'amountIssued' => $this->decimal(14, 4)->notNull(),
            // A cache of the entries against this lot, written only inside the same transaction
            // that writes them, under a row lock. It exists so a balance is one indexed sum.
            'amountRemaining' => $this->decimal(14, 4)->notNull(),
            'expiresAt' => $this->dateTime(),
            'returnId' => $this->integer(),
            'issuedBy' => $this->integer(),
            'note' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::CREDITENTRIES, [
            'id' => $this->primaryKey(),
            'lotId' => $this->integer(),
            'customerId' => $this->integer()->notNull(),
            'storeId' => $this->integer(),
            'currency' => $this->char(3)->notNull(),
            'type' => $this->string(32)->notNull(),
            'amount' => $this->decimal(14, 4)->notNull(),
            'orderId' => $this->integer(),
            'transactionId' => $this->integer(),
            // Commerce generates a transaction's hash in its constructor and its ID only when it
            // is saved — which happens *after* the gateway has run. The hash is therefore the only
            // identifier a wallet movement can be written against at the moment it is made, and
            // it is what a later refund is matched on. `transactionId` is backfilled when known.
            'transactionHash' => $this->string(64),
            'returnId' => $this->integer(),
            'userId' => $this->integer(),
            // Unique. This is why a retried checkout, a double-clicked button and a queue job that
            // runs twice all move the money exactly once.
            'idempotencyKey' => $this->string(255)->notNull(),
            'note' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        $this->createIndex(null, Table::STATES, ['handle'], true);
        $this->createIndex(null, Table::STATES, ['kind'], false);
        $this->createIndex(null, Table::REASONS, ['handle'], true);

        $this->createIndex(null, Table::RETURNS, ['reference'], true);
        $this->createIndex(null, Table::RETURNS, ['sequence'], true);
        $this->createIndex(null, Table::RETURNS, ['portalToken'], true);
        $this->createIndex(null, Table::RETURNS, ['orderId'], false);
        $this->createIndex(null, Table::RETURNS, ['stateId'], false);
        $this->createIndex(null, Table::RETURNS, ['customerId'], false);
        $this->createIndex(null, Table::RETURNS, ['email'], false);
        // The index the returns index actually sorts on.
        $this->createIndex(null, Table::RETURNS, ['isArchived', 'requestedAt'], false);

        $this->createIndex(null, Table::RETURNITEMS, ['returnId'], false);
        $this->createIndex(null, Table::RETURNITEMS, ['lineItemId'], false);
        // "How many of this line are already spoken for" is asked on every eligibility check.
        $this->createIndex(null, Table::RETURNITEMS, ['purchasableId'], false);
        $this->createIndex(null, Table::RETURNITEMS, ['reasonId'], false);

        $this->createIndex(null, Table::EVENTS, ['returnId', 'dateCreated'], false);
        $this->createIndex(null, Table::EVENTS, ['type'], false);

        $this->createIndex(null, Table::LABELS, ['returnId'], false);
        $this->createIndex(null, Table::LABELS, ['trackingNumber'], false);

        // Balances and FIFO allocation both read this, in this order.
        $this->createIndex(null, Table::CREDITLOTS, ['customerId', 'storeId', 'currency'], false);
        $this->createIndex(null, Table::CREDITLOTS, ['expiresAt'], false);
        $this->createIndex(null, Table::CREDITLOTS, ['returnId'], false);

        $this->createIndex(null, Table::CREDITENTRIES, ['idempotencyKey'], true);
        $this->createIndex(null, Table::CREDITENTRIES, ['lotId'], false);
        $this->createIndex(null, Table::CREDITENTRIES, ['customerId'], false);
        $this->createIndex(null, Table::CREDITENTRIES, ['orderId'], false);
        $this->createIndex(null, Table::CREDITENTRIES, ['transactionHash'], false);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, Table::RETURNS, ['id'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::RETURNS, ['orderId'], CraftTable::ELEMENTS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::RETURNS, ['exchangeOrderId'], CraftTable::ELEMENTS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::RETURNS, ['customerId'], CraftTable::USERS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::RETURNS, ['submittedSiteId'], CraftTable::SITES, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::RETURNS, ['stateId'], Table::STATES, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::RETURNITEMS, ['returnId'], Table::RETURNS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::RETURNITEMS, ['reasonId'], Table::REASONS, ['id'], 'SET NULL', null);
        // Commerce hard-deletes line items when an order is edited, and a purchasable is an
        // element; neither may take the RMA's record with it, which is what the snapshot columns
        // are for.
        $this->addForeignKey(null, Table::RETURNITEMS, ['lineItemId'], CommerceTable::LINEITEMS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::RETURNITEMS, ['purchasableId'], CraftTable::ELEMENTS, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::EVENTS, ['returnId'], Table::RETURNS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::EVENTS, ['userId'], CraftTable::USERS, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::LABELS, ['returnId'], Table::RETURNS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::LABELS, ['assetId'], CraftTable::ELEMENTS, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::CREDITLOTS, ['customerId'], CraftTable::USERS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::CREDITLOTS, ['returnId'], Table::RETURNS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::CREDITLOTS, ['issuedBy'], CraftTable::USERS, ['id'], 'SET NULL', null);

        // CASCADE, not RESTRICT. Nothing in the plugin deletes a lot — but deleting the *customer*
        // does, through their own cascade, and a RESTRICT here turns "delete this user" into a
        // foreign-key error nobody can act on. A ledger row is meaningless without its lot anyway.
        $this->addForeignKey(null, Table::CREDITENTRIES, ['lotId'], Table::CREDITLOTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::CREDITENTRIES, ['customerId'], CraftTable::USERS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::CREDITENTRIES, ['orderId'], CraftTable::ELEMENTS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::CREDITENTRIES, ['transactionId'], CommerceTable::TRANSACTIONS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::CREDITENTRIES, ['returnId'], Table::RETURNS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::CREDITENTRIES, ['userId'], CraftTable::USERS, ['id'], 'SET NULL', null);
    }

    /**
     * A working state machine and a set of reasons, out of the box.
     *
     * The transitions are written after the states exist, because a transition is a list of UIDs
     * and the UIDs are not known until the rows are in.
     */
    private function seed(): void
    {
        $now = Db::prepareDateForDb(new DateTime());

        // Handles are camelCase throughout: Craft's HandleValidator rejects hyphens, so a seeded
        // `in-transit` would be a row nobody could ever re-save, import or edit.
        $states = [
            ['requested', 'Requested', StateKind::Requested, ['isDefault' => true, 'notifyCustomer' => true]],
            ['approved', 'Approved', StateKind::Approved, ['notifyCustomer' => true, 'offerLabel' => true]],
            ['inTransit', 'In transit', StateKind::Awaiting, ['offerLabel' => true]],
            ['received', 'Received', StateKind::Received, ['restockOnEnter' => true, 'notifyCustomer' => true]],
            ['resolved', 'Resolved', StateKind::Resolved, ['resolveOnEnter' => true, 'notifyCustomer' => true]],
            ['rejected', 'Rejected', StateKind::Rejected, ['notifyCustomer' => true]],
            ['cancelled', 'Cancelled', StateKind::Cancelled, []],
        ];

        $uids = [];
        $sortOrder = 1;

        foreach ($states as [$handle, $name, $kind, $flags]) {
            $uid = StringHelper::UUID();
            $uids[$handle] = $uid;

            $this->insert(Table::STATES, array_merge([
                'name' => $name,
                'handle' => $handle,
                'kind' => $kind->value,
                'color' => $kind->defaultColor(),
                'sortOrder' => $sortOrder++,
                'isDefault' => false,
                'notifyCustomer' => false,
                'restockOnEnter' => false,
                'resolveOnEnter' => false,
                'offerLabel' => false,
                'transitions' => '[]',
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => $uid,
            ], $flags));
        }

        // The default path forward from each state. Backwards moves are deliberately absent: a
        // merchant who needs one clears the transitions on that state, which is a decision with a
        // screen attached rather than an accident.
        $transitions = [
            'requested' => ['approved', 'rejected', 'cancelled'],
            'approved' => ['inTransit', 'received', 'rejected', 'cancelled'],
            'inTransit' => ['received', 'rejected', 'cancelled'],
            'received' => ['resolved', 'rejected'],
            'resolved' => [],
            'rejected' => [],
            'cancelled' => [],
        ];

        foreach ($transitions as $handle => $targets) {
            $this->update(Table::STATES, [
                'transitions' => Json::encode(array_map(fn(string $target) => $uids[$target], $targets)),
            ], ['handle' => $handle]);
        }

        $reasons = [
            ['wrongSize', 'Doesn’t fit', ItemCondition::Resellable, []],
            ['notAsDescribed', 'Not as described', ItemCondition::Resellable, ['isFault' => true, 'freeReturnShipping' => true, 'requiresComment' => true]],
            ['changedMind', 'Changed my mind', ItemCondition::Resellable, []],
            ['arrivedDamaged', 'Arrived damaged', ItemCondition::Damaged, ['isFault' => true, 'freeReturnShipping' => true, 'requiresPhoto' => true, 'autoApprove' => true]],
            ['faulty', 'Faulty', ItemCondition::Inspect, ['isFault' => true, 'freeReturnShipping' => true, 'requiresComment' => true]],
            ['wrongItem', 'Wrong item sent', ItemCondition::Resellable, ['isFault' => true, 'freeReturnShipping' => true, 'autoApprove' => true]],
            ['arrivedLate', 'Arrived too late', ItemCondition::Resellable, ['isFault' => true, 'freeReturnShipping' => true]],
            ['other', 'Something else', ItemCondition::Inspect, ['requiresComment' => true]],
        ];

        $sortOrder = 1;

        foreach ($reasons as [$handle, $name, $condition, $flags]) {
            $this->insert(Table::REASONS, array_merge([
                'name' => $name,
                'handle' => $handle,
                'sortOrder' => $sortOrder++,
                'enabled' => true,
                'customerSelectable' => true,
                'requiresComment' => false,
                'requiresPhoto' => false,
                'isFault' => false,
                'autoApprove' => false,
                'freeReturnShipping' => false,
                'defaultCondition' => $condition->value,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ], $flags));
        }
    }
}
