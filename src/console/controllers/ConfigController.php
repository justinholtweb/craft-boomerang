<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Json;
use justinholtweb\boomerang\Plugin;
use yii\console\ExitCode;

/**
 * Moving states and reasons between environments.
 *
 * They live in the database rather than project config — ordered configuration in project config
 * pays for environment sync with a class of coalescing bugs that ordered configuration is
 * unusually good at hitting — so this pair of commands is how they travel. Handles rather than IDs
 * throughout, including inside the transition lists, because IDs do not survive the trip.
 */
class ConfigController extends Controller
{
    /** Where to write, or read from. Defaults to standard out / standard in. */
    public ?string $path = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['path']);
    }

    /**
     * Write the states and reasons out as JSON.
     */
    public function actionExport(): int
    {
        $plugin = Plugin::getInstance();

        $json = Json::encode([
            'states' => $plugin->states->exportStates(),
            'reasons' => $plugin->reasons->exportReasons(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($this->path === null) {
            $this->stdout($json . "\n");

            return ExitCode::OK;
        }

        file_put_contents($this->path, $json);
        $this->stdout("Written to $this->path\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Read states and reasons back in, matching on handle.
     */
    public function actionImport(): int
    {
        $json = $this->path !== null ? @file_get_contents($this->path) : stream_get_contents(STDIN);

        if ($json === false || trim((string)$json) === '') {
            $this->stderr("Nothing to import.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $data = Json::decodeIfJson($json);

        if (!is_array($data)) {
            $this->stderr("That is not valid JSON.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $plugin = Plugin::getInstance();
        $errors = [];

        if (isset($data['reasons'])) {
            $result = $plugin->reasons->importReasons((array)$data['reasons']);
            $this->stdout(sprintf("Reasons: %d created, %d updated\n", $result['created'], $result['updated']));
            $errors = array_merge($errors, $result['errors']);
        }

        if (isset($data['states'])) {
            $result = $plugin->states->importStates((array)$data['states']);
            $this->stdout(sprintf("States: %d created, %d updated\n", $result['created'], $result['updated']));
            $errors = array_merge($errors, $result['errors']);
        }

        foreach ($errors as $error) {
            $this->stderr("  $error\n", Console::FG_YELLOW);
        }

        return $errors === [] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
