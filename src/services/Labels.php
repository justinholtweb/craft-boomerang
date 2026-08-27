<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Asset;
use craft\errors\VolumeException;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\Assets;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use DateTime;
use justinholtweb\boomerang\db\Table;
use justinholtweb\boomerang\elements\ReturnRequest;
use justinholtweb\boomerang\enums\EventType;
use justinholtweb\boomerang\labels\LabelProviderInterface;
use justinholtweb\boomerang\labels\Manual;
use justinholtweb\boomerang\labels\ShipStation;
use justinholtweb\boomerang\models\Label;
use justinholtweb\boomerang\Plugin;
use justinholtweb\boomerang\records\LabelRecord;
use RuntimeException;

/**
 * Return labels.
 *
 * **The invariant: `create()` is the only place a label row is made.** The CP button, the portal
 * request and the console command all go through it, so the provider is chosen once, the PDF is
 * stashed once, and the history says a label exists exactly when one does.
 */
class Labels extends Component
{
    /**
     * @event RegisterComponentTypesEvent Register additional label providers.
     */
    public const EVENT_REGISTER_LABEL_PROVIDERS = 'registerLabelProviders';

    /** @var array<string, LabelProviderInterface>|null */
    private ?array $_providers = null;

    /**
     * @return array<string, LabelProviderInterface> Keyed by handle.
     */
    public function getProviders(): array
    {
        if ($this->_providers !== null) {
            return $this->_providers;
        }

        $event = new RegisterComponentTypesEvent([
            'types' => [Manual::class, ShipStation::class],
        ]);

        $this->trigger(self::EVENT_REGISTER_LABEL_PROVIDERS, $event);

        $providers = [];

        foreach ($event->types as $class) {
            if (!is_subclass_of($class, LabelProviderInterface::class)) {
                continue;
            }

            /** @var LabelProviderInterface $provider */
            $provider = Craft::createObject($class);
            $providers[$provider::handle()] = $provider;
        }

        return $this->_providers = $providers;
    }

    public function getProvider(?string $handle = null): ?LabelProviderInterface
    {
        $handle ??= Plugin::getInstance()->getSettings()->getEffectiveLabelProvider();

        return $handle !== null ? ($this->getProviders()[$handle] ?? null) : null;
    }

    /**
     * @return array<string, string> handle => name, for a dropdown.
     */
    public function getProviderOptions(): array
    {
        $options = [];

        foreach ($this->getProviders() as $handle => $provider) {
            $options[$handle] = $provider::displayName();
        }

        return $options;
    }

    /**
     * Obtain a label for a return.
     */
    public function create(ReturnRequest $return, array $params = [], ?string $providerHandle = null): Label
    {
        if (!Plugin::getInstance()->isPro()) {
            throw new RuntimeException(Craft::t('boomerang', 'Return labels need Boomerang Pro.'));
        }

        $provider = $this->getProvider($providerHandle);

        if ($provider === null) {
            throw new RuntimeException(Craft::t('boomerang', 'No return label provider is configured.'));
        }

        $label = $provider->createLabel($return, $params);
        $label->returnId = $return->id;

        // Stashed before the row is saved, so a label row never points at a link that was already
        // dead by the time anybody clicked it.
        $this->storeLabelAsset($label, $return);

        if (!$this->saveLabel($label)) {
            throw new RuntimeException(Craft::t('boomerang', 'The label was obtained but could not be saved.'));
        }

        Plugin::getInstance()->returns->addEvent($return, EventType::LabelCreated, null, [
            'labelId' => $label->id,
            'provider' => $label->provider,
            'carrier' => $label->carrier,
            'tracking' => $label->trackingNumber,
            'cost' => $label->cost,
        ]);

        return $label;
    }

    /**
     * Void a label, locally whatever the provider says.
     *
     * A provider that cannot void is not a failure: the point of voiding is that nobody sends the
     * customer a label the store no longer intends to honour, and that is a local fact.
     */
    public function void(Label $label): bool
    {
        $provider = $this->getProvider($label->provider);
        $remoteVoided = $provider?->voidLabel($label) ?? false;

        $label->voidedAt = new DateTime();
        $this->saveLabel($label);

        $return = $label->returnId !== null
            ? Craft::$app->getElements()->getElementById($label->returnId, ReturnRequest::class)
            : null;

        if ($return !== null) {
            Plugin::getInstance()->returns->addEvent($return, EventType::LabelVoided, null, [
                'labelId' => $label->id,
                'remoteVoided' => $remoteVoided,
            ]);
        }

        return true;
    }

    /**
     * @return Label[] Newest first.
     */
    public function getLabelsForReturn(int $returnId): array
    {
        $rows = $this->labelQuery()->where(['returnId' => $returnId])->all();

        return array_map(fn(array $row) => $this->createModel($row), $rows);
    }

    public function getLabelById(int $id): ?Label
    {
        $row = $this->labelQuery()->where(['id' => $id])->one();

        return $row ? $this->createModel($row) : null;
    }

    public function saveLabel(Label $label): bool
    {
        $record = $label->id !== null ? LabelRecord::findOne($label->id) : new LabelRecord();

        if ($record === null) {
            return false;
        }

        $record->returnId = $label->returnId;
        $record->provider = $label->provider;
        $record->providerLabelId = $label->providerLabelId;
        $record->carrier = $label->carrier;
        $record->service = $label->service;
        $record->trackingNumber = $label->trackingNumber;
        $record->trackingUrl = $label->trackingUrl;
        $record->cost = $label->cost;
        $record->currency = $label->currency;
        $record->labelUrl = $label->labelUrl;
        $record->assetId = $label->assetId;
        $record->paidByStore = $label->paidByStore;
        $record->rawResponse = $label->rawResponse;
        $record->voidedAt = Db::prepareDateForDb($label->voidedAt);

        if (!$record->save(false)) {
            return false;
        }

        $label->id = (int)$record->id;
        $label->uid = (string)$record->uid;

        return true;
    }

    public function deleteLabelById(int $id): bool
    {
        $record = LabelRecord::findOne($id);

        return $record !== null && $record->delete() !== false;
    }

    /**
     * Copy a provider's PDF into a Craft volume.
     *
     * Providers expire their download links — ShipStation's are short-lived — and a customer who
     * opens their status page a fortnight later needs the label, not a 403. Failure here is
     * logged, never fatal: a label with a working provider link is worth far more than an
     * exception.
     */
    private function storeLabelAsset(Label $label, ReturnRequest $return): void
    {
        $volumeUid = trim(Plugin::getInstance()->getSettings()->labelVolumeUid);

        if ($volumeUid === '' || $label->labelUrl === null || $label->assetId !== null) {
            return;
        }

        $volume = Craft::$app->getVolumes()->getVolumeByUid($volumeUid);

        if ($volume === null) {
            Craft::warning('Boomerang label volume no longer exists.', __METHOD__);

            return;
        }

        $tempPath = null;

        try {
            $body = (string)Craft::createGuzzleClient(['timeout' => 30])
                ->get($label->labelUrl)
                ->getBody();

            if ($body === '') {
                return;
            }

            $tempPath = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR
                . sprintf('boomerang-label-%s.pdf', $label->providerLabelId ?: uniqid('', true));

            FileHelper::writeToFile($tempPath, $body);

            $asset = new Asset();
            $asset->tempFilePath = $tempPath;
            $asset->setFilename(Assets::prepareAssetName(sprintf(
                'return-label-%s.pdf',
                $return->reference ?: (string)$return->id,
            )));
            $asset->newFolderId = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id)?->id;
            $asset->setVolumeId($volume->id);
            $asset->avoidFilenameConflicts = true;
            $asset->setScenario(Asset::SCENARIO_CREATE);

            if (Craft::$app->getElements()->saveElement($asset)) {
                $label->assetId = (int)$asset->id;
            }
        } catch (VolumeException|\Throwable $e) {
            Craft::warning('Could not store the return label: ' . $e->getMessage(), __METHOD__);
        } finally {
            if ($tempPath !== null && is_file($tempPath)) {
                FileHelper::unlink($tempPath);
            }
        }
    }

    private function labelQuery(): Query
    {
        return (new Query())
            ->select([
                'id', 'returnId', 'provider', 'providerLabelId', 'carrier', 'service',
                'trackingNumber', 'trackingUrl', 'cost', 'currency', 'labelUrl', 'assetId',
                'paidByStore', 'rawResponse', 'voidedAt', 'dateCreated', 'dateUpdated', 'uid',
            ])
            ->from([Table::LABELS])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC]);
    }

    private function createModel(array $row): Label
    {
        $row['id'] = (int)$row['id'];
        $row['returnId'] = (int)$row['returnId'];
        $row['assetId'] = $row['assetId'] !== null ? (int)$row['assetId'] : null;
        $row['cost'] = $row['cost'] !== null ? (float)$row['cost'] : null;
        $row['paidByStore'] = (bool)$row['paidByStore'];

        return new Label($row);
    }
}
