<?php

namespace Ernestdefoe\Ladder\Console;

use Ernestdefoe\Ladder\Ladder;
use Flarum\Console\AbstractCommand;

/**
 * php flarum ladder:sync — re-ranks every member from a terminal.
 *
 * The admin page does the same thing from the browser, which is the only
 * option on hosting without a shell. This is for forums large enough that a
 * browser tab is the wrong tool.
 */
class SyncCommand extends AbstractCommand
{
    public function __construct(private Ladder $ladder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('ladder:sync')
            ->setDescription('Put every member on the rung their post count has earned.');
    }

    protected function fire(): int
    {
        if ($this->ladder->rungs()->isEmpty()) {
            $this->info('The ladder has no rungs yet. Nothing to do.');

            return 0;
        }

        $after = 0;
        $processed = 0;
        $changed = 0;

        do {
            $result = $this->ladder->syncChunk($after, 1000);
            $after = $result['lastId'];
            $processed += $result['processed'];
            $changed += $result['changed'];
        } while (! $result['done']);

        $this->info("Checked {$processed} members; {$changed} changed rank.");

        return 0;
    }
}
