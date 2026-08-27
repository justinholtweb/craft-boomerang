<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Json;
use justinholtweb\boomerang\labels\ShipStation;
use justinholtweb\boomerang\Plugin;
use yii\console\ExitCode;

/**
 * Return labels, from the command line.
 */
class LabelsController extends Controller
{
    /**
     * Which providers are available, and whether they are configured.
     */
    public function actionProviders(): int
    {
        $rows = [];

        foreach (Plugin::getInstance()->labels->getProviders() as $handle => $provider) {
            $errors = [];
            $configured = $provider->isConfigured($errors);

            $rows[] = [
                $handle,
                $provider::displayName(),
                $provider->isRemote() ? 'yes' : 'no',
                $configured ? 'yes' : 'no',
                implode(' ', $errors),
            ];
        }

        $this->table(['Handle', 'Name', 'Remote', 'Configured', 'Missing'], $rows);

        $active = Plugin::getInstance()->getSettings()->getEffectiveLabelProvider();
        $this->stdout("\nActive: " . ($active ?? 'none (Lite)') . "\n");

        return ExitCode::OK;
    }

    /**
     * Print exactly what would be sent to the carrier for a return, without buying anything.
     *
     * The same trick Shipper uses for its export XML, and for the same reason: a merchant
     * debugging a carrier account needs to see the request, not a description of it.
     */
    public function actionPreview(string $reference): int
    {
        $plugin = Plugin::getInstance();
        $return = $plugin->returns->getReturnByReference($reference);

        if ($return === null) {
            $this->stderr("No return with reference $reference\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $provider = $plugin->labels->getProvider();

        if (!$provider instanceof ShipStation) {
            $this->stdout(sprintf(
                "The active provider is %s, which builds no request to preview.\n",
                $provider !== null ? $provider::displayName() : 'none',
            ));

            return ExitCode::OK;
        }

        $errors = [];

        if (!$provider->isConfigured($errors)) {
            $this->stderr(implode("\n", $errors) . "\n\n", Console::FG_YELLOW);
        }

        $this->stdout(Json::encode($provider->buildRequest($return), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        return ExitCode::OK;
    }

    /**
     * Buy or attach a label for a return.
     */
    public function actionCreate(string $reference): int
    {
        $plugin = Plugin::getInstance();
        $return = $plugin->returns->getReturnByReference($reference);

        if ($return === null) {
            $this->stderr("No return with reference $reference\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        try {
            $label = $plugin->labels->create($return);
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(sprintf(
            "Label created: %s %s %s\n",
            $label->carrier ?? '',
            $label->service ?? '',
            $label->trackingNumber ?? '',
        ), Console::FG_GREEN);

        return ExitCode::OK;
    }
}
