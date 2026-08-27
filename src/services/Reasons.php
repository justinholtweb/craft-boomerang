<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\StringHelper;
use justinholtweb\boomerang\db\Table;
use justinholtweb\boomerang\models\Reason;
use justinholtweb\boomerang\records\ReasonRecord;
use yii\base\Exception;

/**
 * Why things come back.
 *
 * Memoized for the request — the portal form, the CP row renderer and the analytics report all
 * ask for the whole list.
 */
class Reasons extends Component
{
    /** @var Reason[]|null */
    private ?array $_reasons = null;

    /**
     * @return Reason[] In sort order, enabled and disabled alike.
     */
    public function getAllReasons(): array
    {
        if ($this->_reasons !== null) {
            return $this->_reasons;
        }

        $reasons = [];

        foreach ($this->createQuery()->all() as $row) {
            $row['id'] = (int)$row['id'];
            $row['sortOrder'] = (int)$row['sortOrder'];

            foreach (['enabled', 'customerSelectable', 'requiresComment', 'requiresPhoto', 'isFault', 'autoApprove', 'freeReturnShipping'] as $flag) {
                $row[$flag] = (bool)$row[$flag];
            }

            $reasons[] = new Reason($row);
        }

        return $this->_reasons = $reasons;
    }

    /**
     * The reasons a customer may pick on the portal.
     *
     * @return Reason[]
     */
    public function getCustomerReasons(): array
    {
        return array_values(array_filter(
            $this->getAllReasons(),
            fn(Reason $reason) => $reason->enabled && $reason->customerSelectable,
        ));
    }

    /**
     * @return Reason[]
     */
    public function getEnabledReasons(): array
    {
        return array_values(array_filter($this->getAllReasons(), fn(Reason $reason) => $reason->enabled));
    }

    public function getReasonById(int $id): ?Reason
    {
        foreach ($this->getAllReasons() as $reason) {
            if ($reason->id === $id) {
                return $reason;
            }
        }

        return null;
    }

    public function getReasonByHandle(string $handle): ?Reason
    {
        foreach ($this->getAllReasons() as $reason) {
            if ($reason->handle === $handle) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function getReasonsAsOptions(bool $customerOnly = false): array
    {
        $options = [];

        foreach ($customerOnly ? $this->getCustomerReasons() : $this->getEnabledReasons() as $reason) {
            $options[(int)$reason->id] = $reason->name;
        }

        return $options;
    }

    public function saveReason(Reason $reason, bool $runValidation = true): bool
    {
        if ($runValidation && !$reason->validate()) {
            Craft::info('Reason not saved due to validation error.', __METHOD__);

            return false;
        }

        $isNew = $reason->id === null;
        $record = $isNew ? new ReasonRecord() : ReasonRecord::findOne($reason->id);

        if ($record === null) {
            throw new Exception("No return reason exists with the ID $reason->id");
        }

        if ($isNew) {
            $reason->uid ??= StringHelper::UUID();
            $record->uid = $reason->uid;
            $record->sortOrder = $reason->sortOrder ?: ((int)(new Query())->from([Table::REASONS])->max('[[sortOrder]]') + 1);
        } else {
            $record->sortOrder = $reason->sortOrder;
        }

        $record->name = $reason->name;
        $record->handle = $reason->handle;
        $record->description = $reason->description;
        $record->enabled = $reason->enabled;
        $record->customerSelectable = $reason->customerSelectable;
        $record->requiresComment = $reason->requiresComment;
        $record->requiresPhoto = $reason->requiresPhoto;
        $record->isFault = $reason->isFault;
        $record->autoApprove = $reason->autoApprove;
        $record->freeReturnShipping = $reason->freeReturnShipping;
        $record->defaultCondition = $reason->defaultCondition;

        $record->save(false);

        $reason->id = $record->id;
        $reason->uid = $record->uid;
        $reason->sortOrder = (int)$record->sortOrder;

        $this->_reasons = null;

        return true;
    }

    /**
     * Deleting a reason leaves `reasonId` null on the items that used it — which is exactly why
     * `reasonName` is copied onto every return item when the RMA is made. The history keeps
     * saying "Arrived damaged" after somebody tidies the list.
     */
    public function deleteReasonById(int $id): bool
    {
        $record = ReasonRecord::findOne($id);

        if ($record === null) {
            return false;
        }

        $record->delete();
        $this->_reasons = null;

        return true;
    }

    /**
     * @param int[] $ids
     */
    public function reorderReasons(array $ids): bool
    {
        $db = Craft::$app->getDb();

        foreach ($ids as $order => $id) {
            $db->createCommand()
                ->update(Table::REASONS, ['sortOrder' => $order + 1], ['id' => $id])
                ->execute();
        }

        $this->_reasons = null;

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function exportReasons(): array
    {
        $export = [];

        foreach ($this->getAllReasons() as $reason) {
            $export[] = [
                'name' => $reason->name,
                'handle' => $reason->handle,
                'description' => $reason->description,
                'sortOrder' => $reason->sortOrder,
                'enabled' => $reason->enabled,
                'customerSelectable' => $reason->customerSelectable,
                'requiresComment' => $reason->requiresComment,
                'requiresPhoto' => $reason->requiresPhoto,
                'isFault' => $reason->isFault,
                'autoApprove' => $reason->autoApprove,
                'freeReturnShipping' => $reason->freeReturnShipping,
                'defaultCondition' => $reason->defaultCondition,
            ];
        }

        return $export;
    }

    /**
     * @param array<int, array<string, mixed>> $data
     * @return array{created: int, updated: int, errors: string[]}
     */
    public function importReasons(array $data): array
    {
        $result = ['created' => 0, 'updated' => 0, 'errors' => []];

        foreach ($data as $row) {
            $handle = (string)($row['handle'] ?? '');

            if ($handle === '') {
                $result['errors'][] = Craft::t('boomerang', 'A reason has no handle.');

                continue;
            }

            $existing = $this->getReasonByHandle($handle);
            $reason = $existing ?? new Reason();

            foreach (['name', 'handle', 'description', 'sortOrder', 'enabled', 'customerSelectable', 'requiresComment', 'requiresPhoto', 'isFault', 'autoApprove', 'freeReturnShipping', 'defaultCondition'] as $attribute) {
                if (array_key_exists($attribute, $row)) {
                    $reason->$attribute = $row[$attribute];
                }
            }

            if (!$this->saveReason($reason)) {
                $result['errors'][] = sprintf('%s: %s', $handle, implode(' ', $reason->getErrorSummary(true)));

                continue;
            }

            $existing !== null ? $result['updated']++ : $result['created']++;
        }

        return $result;
    }

    private function createQuery(): Query
    {
        return (new Query())
            ->select([
                'id', 'name', 'handle', 'description', 'sortOrder', 'enabled', 'customerSelectable',
                'requiresComment', 'requiresPhoto', 'isFault', 'autoApprove', 'freeReturnShipping',
                'defaultCondition', 'dateCreated', 'dateUpdated', 'uid',
            ])
            ->from([Table::REASONS])
            ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC]);
    }
}
