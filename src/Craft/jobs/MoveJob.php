<?php

namespace facilo\rumbo\Craft\jobs;

use Craft;
use craft\queue\BaseJob;
use facilo\rumbo\Plugin;

/** Moves one Maps field. Resumes from the last saved batch if it was cut off. */
class MoveJob extends BaseJob
{
    public int $moveId;

    public function execute($queue): void
    {
        Plugin::getInstance()->mover->run($this->moveId, function(float $done, string $label) use ($queue) {
            $this->setProgress($queue, $done, $label);
        });
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('rumbo-maps', 'Moving locations from Maps');
    }
}
