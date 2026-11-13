<?php

namespace facilo\rumbo\Craft\console;

use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Json;
use facilo\rumbo\Plugin;
use yii\console\ExitCode;

/** `craft rumbo-maps/move-from-maps [--dry-run] [--field=handle]` (F3). */
class MoveFromMapsController extends Controller
{
    public bool $dryRun = false;
    public ?string $field = null;

    public $defaultAction = 'index';

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'dryRun', 'field'];
    }

    public function actionIndex(): int
    {
        $mover = Plugin::getInstance()->mover;
        if (!$mover->oldTableExists()) {
            $this->stderr("The Maps table is gone. Run the move while Maps is still installed.\n", Console::FG_RED);
            return ExitCode::UNAVAILABLE;
        }
        if ($this->dryRun) {
            $this->stdout(Json::encode($mover->dryRun($this->field), JSON_PRETTY_PRINT) . "\n");
            return ExitCode::OK;
        }
        $failed = false;
        foreach ($mover->fields() as $field) {
            if ($field['moved'] || ($this->field !== null && $field['handle'] !== $this->field)) {
                continue;
            }
            $this->stdout("Moving {$field['handle']}…\n");
            $result = $mover->run($mover->start($field['id']));
            $failed = $failed || $result['status'] !== 'done';
            $this->stdout(Json::encode([$field['handle'] => $result], JSON_PRETTY_PRINT) . "\n", $result['status'] === 'done' ? Console::FG_GREEN : Console::FG_RED);
        }
        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
