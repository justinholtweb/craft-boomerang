<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use justinholtweb\boomerang\db\Table;
use justinholtweb\boomerang\enums\StateKind;
use justinholtweb\boomerang\models\State;
use justinholtweb\boomerang\records\StateRecord;
use yii\base\Exception;

/**
 * The states an RMA can be in.
 *
 * In the database rather than project config, like Free Ride's rules and Hire's stages, and moved
 * between environments with `boomerang/states export|import`. Ordered config in project config
 * pays for environment sync with a class of coalescing bugs that ordered config is unusually good
 * at hitting.
 *
 * Memoized for the request: `getAllStates()` is called from the element query's `isOpen` filter,
 * from every row of the index, and from the transition guard.
 */
class States extends Component
{
    /** @var State[]|null */
    private ?array $_states = null;

    /**
     * @return State[] In sort order.
     */
    public function getAllStates(): array
    {
        if ($this->_states !== null) {
            return $this->_states;
        }

        $states = [];

        foreach ($this->createQuery()->all() as $row) {
            $states[] = $this->createModel($row);
        }

        return $this->_states = $states;
    }

    public function getStateById(int $id): ?State
    {
        foreach ($this->getAllStates() as $state) {
            if ($state->id === $id) {
                return $state;
            }
        }

        return null;
    }

    public function getStateByHandle(string $handle): ?State
    {
        foreach ($this->getAllStates() as $state) {
            if ($state->handle === $handle) {
                return $state;
            }
        }

        return null;
    }

    public function getStateByUid(string $uid): ?State
    {
        foreach ($this->getAllStates() as $state) {
            if ($state->uid === $uid) {
                return $state;
            }
        }

        return null;
    }

    /**
     * The state a new RMA starts in.
     *
     * Falls back to the first state rather than to null, because an install whose default was
     * deleted should keep taking returns.
     */
    public function getDefaultState(): ?State
    {
        $states = $this->getAllStates();

        foreach ($states as $state) {
            if ($state->isDefault) {
                return $state;
            }
        }

        return $states[0] ?? null;
    }

    /**
     * The first state of a given kind, in sort order.
     *
     * This is how the plugin finds "the approved state" without hard-coding a handle: a merchant
     * may call it "Authorised", and may have two of them.
     */
    public function getStateByKind(StateKind $kind): ?State
    {
        foreach ($this->getAllStates() as $state) {
            if ($state->getKind() === $kind) {
                return $state;
            }
        }

        return null;
    }

    /**
     * @return State[]
     */
    public function getStatesByKind(StateKind $kind): array
    {
        return array_values(array_filter(
            $this->getAllStates(),
            fn(State $state) => $state->getKind() === $kind,
        ));
    }

    /**
     * @return array<int, string> id => name, for a dropdown.
     */
    public function getStatesAsOptions(): array
    {
        $options = [];

        foreach ($this->getAllStates() as $state) {
            $options[(int)$state->id] = $state->name;
        }

        return $options;
    }

    public function saveState(State $state, bool $runValidation = true): bool
    {
        if ($runValidation && !$state->validate()) {
            Craft::info('State not saved due to validation error.', __METHOD__);

            return false;
        }

        $isNew = $state->id === null;
        $record = $isNew ? new StateRecord() : StateRecord::findOne($state->id);

        if ($record === null) {
            throw new Exception("No return state exists with the ID $state->id");
        }

        if ($isNew) {
            $state->uid ??= StringHelper::UUID();
            $record->uid = $state->uid;
            $record->sortOrder = $state->sortOrder ?: ((int)(new Query())->from([Table::STATES])->max('[[sortOrder]]') + 1);
        } else {
            $record->sortOrder = $state->sortOrder;
        }

        $record->name = $state->name;
        $record->handle = $state->handle;
        $record->kind = $state->kind;
        $record->color = $state->color;
        $record->description = $state->description;
        $record->isDefault = $state->isDefault;
        $record->notifyCustomer = $state->notifyCustomer;
        $record->restockOnEnter = $state->restockOnEnter;
        $record->resolveOnEnter = $state->resolveOnEnter;
        $record->offerLabel = $state->offerLabel;
        $record->emailSubject = $state->emailSubject;
        $record->emailBody = $state->emailBody;
        $record->transitions = Json::encode(array_values($state->transitions));

        $record->save(false);

        $state->id = $record->id;
        $state->uid = $record->uid;
        $state->sortOrder = (int)$record->sortOrder;

        if ($state->isDefault) {
            $this->clearOtherDefaults((int)$state->id);
        }

        $this->_states = null;

        return true;
    }

    /**
     * A state cannot be deleted while returns sit in it — that would leave RMAs with no state and
     * therefore, by {@see \justinholtweb\boomerang\elements\db\ReturnRequestQuery}, permanently
     * "open". Callers move them first.
     */
    public function getReturnCount(int $stateId): int
    {
        return (int)(new Query())
            ->from([Table::RETURNS])
            ->where(['stateId' => $stateId])
            ->count();
    }

    public function deleteStateById(int $id, ?int $moveToStateId = null): bool
    {
        $record = StateRecord::findOne($id);

        if ($record === null) {
            return false;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            if ($moveToStateId !== null) {
                Craft::$app->getDb()->createCommand()
                    ->update(Table::RETURNS, ['stateId' => $moveToStateId], ['stateId' => $id])
                    ->execute();
            }

            // Every other state's transition list may name this one. Left behind, those UIDs
            // would silently narrow what a merchant can do — a transition list that mentions a
            // state that no longer exists is a transition nobody can take.
            $this->removeFromTransitions((string)$record->uid);

            $record->delete();

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        $this->_states = null;

        return true;
    }

    /**
     * @param int[] $ids
     */
    public function reorderStates(array $ids): bool
    {
        $db = Craft::$app->getDb();

        foreach ($ids as $order => $id) {
            $db->createCommand()
                ->update(Table::STATES, ['sortOrder' => $order + 1], ['id' => $id])
                ->execute();
        }

        $this->_states = null;

        return true;
    }

    /**
     * Everything needed to rebuild the state machine somewhere else.
     *
     * Handles rather than IDs throughout, including inside the transition lists, because IDs do
     * not survive the trip and a transition list of foreign IDs is worse than no transition list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function exportStates(): array
    {
        $byUid = [];

        foreach ($this->getAllStates() as $state) {
            $byUid[(string)$state->uid] = $state->handle;
        }

        $export = [];

        foreach ($this->getAllStates() as $state) {
            $export[] = [
                'name' => $state->name,
                'handle' => $state->handle,
                'kind' => $state->kind,
                'color' => $state->color,
                'description' => $state->description,
                'sortOrder' => $state->sortOrder,
                'isDefault' => $state->isDefault,
                'notifyCustomer' => $state->notifyCustomer,
                'restockOnEnter' => $state->restockOnEnter,
                'resolveOnEnter' => $state->resolveOnEnter,
                'offerLabel' => $state->offerLabel,
                'emailSubject' => $state->emailSubject,
                'emailBody' => $state->emailBody,
                'transitions' => array_values(array_filter(array_map(
                    fn(string $uid) => $byUid[$uid] ?? null,
                    $state->transitions,
                ))),
            ];
        }

        return $export;
    }

    /**
     * @param array<int, array<string, mixed>> $data
     * @return array{created: int, updated: int, errors: string[]}
     */
    public function importStates(array $data): array
    {
        $result = ['created' => 0, 'updated' => 0, 'errors' => []];

        // Two passes: every state has to exist before any transition list can be resolved, because
        // a transition list names states that may come later in the file.
        foreach ($data as $row) {
            $handle = (string)($row['handle'] ?? '');

            if ($handle === '') {
                $result['errors'][] = Craft::t('boomerang', 'A state has no handle.');

                continue;
            }

            $existing = $this->getStateByHandle($handle);
            $state = $existing ?? new State();

            foreach (['name', 'handle', 'kind', 'color', 'description', 'sortOrder', 'isDefault', 'notifyCustomer', 'restockOnEnter', 'resolveOnEnter', 'offerLabel', 'emailSubject', 'emailBody'] as $attribute) {
                if (array_key_exists($attribute, $row)) {
                    $state->$attribute = $row[$attribute];
                }
            }

            // Cleared here and filled in on the second pass.
            $state->transitions = $existing?->transitions ?? [];

            if (!$this->saveState($state)) {
                $result['errors'][] = sprintf('%s: %s', $handle, implode(' ', $state->getErrorSummary(true)));

                continue;
            }

            $existing !== null ? $result['updated']++ : $result['created']++;
        }

        $this->_states = null;

        foreach ($data as $row) {
            $handle = (string)($row['handle'] ?? '');
            $state = $handle !== '' ? $this->getStateByHandle($handle) : null;

            if ($state === null) {
                continue;
            }

            $uids = [];

            foreach ((array)($row['transitions'] ?? []) as $target) {
                $targetState = $this->getStateByHandle((string)$target);

                if ($targetState !== null) {
                    $uids[] = (string)$targetState->uid;
                } else {
                    $result['errors'][] = Craft::t('boomerang', '{handle} allows a transition to “{target}”, which does not exist.', [
                        'handle' => $handle,
                        'target' => $target,
                    ]);
                }
            }

            $state->transitions = $uids;
            $this->saveState($state, false);
        }

        $this->_states = null;

        return $result;
    }

    private function clearOtherDefaults(int $keepId): void
    {
        Craft::$app->getDb()->createCommand()
            ->update(Table::STATES, ['isDefault' => false], ['not', ['id' => $keepId]])
            ->execute();
    }

    private function removeFromTransitions(string $uid): void
    {
        foreach ($this->getAllStates() as $state) {
            if (!in_array($uid, $state->transitions, true)) {
                continue;
            }

            Craft::$app->getDb()->createCommand()
                ->update(
                    Table::STATES,
                    ['transitions' => Json::encode(array_values(array_diff($state->transitions, [$uid])))],
                    ['id' => $state->id],
                )
                ->execute();
        }
    }

    private function createQuery(): Query
    {
        return (new Query())
            ->select([
                'id', 'name', 'handle', 'kind', 'color', 'description', 'sortOrder', 'isDefault',
                'notifyCustomer', 'restockOnEnter', 'resolveOnEnter', 'offerLabel',
                'emailSubject', 'emailBody', 'transitions', 'dateCreated', 'dateUpdated', 'uid',
            ])
            ->from([Table::STATES])
            ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC]);
    }

    private function createModel(array $row): State
    {
        $row['transitions'] = $row['transitions'] ? (array)Json::decodeIfJson($row['transitions']) : [];
        $row['isDefault'] = (bool)$row['isDefault'];
        $row['notifyCustomer'] = (bool)$row['notifyCustomer'];
        $row['restockOnEnter'] = (bool)$row['restockOnEnter'];
        $row['resolveOnEnter'] = (bool)$row['resolveOnEnter'];
        $row['offerLabel'] = (bool)$row['offerLabel'];
        $row['sortOrder'] = (int)$row['sortOrder'];
        $row['id'] = (int)$row['id'];

        // `craft\base\Model::__construct()` normalizes every attribute named by
        // `datetimeAttributes()`, so the raw database strings can go straight in.
        return new State($row);
    }
}
