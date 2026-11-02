<?php

namespace Ernestdefoe\Ladder\Job;

use Ernestdefoe\Ladder\Ladder;
use Flarum\Queue\AbstractJob;

/**
 * Re-ranks every member, quietly, after Leaderboard rebuilds all totals.
 *
 * Queued so a big forum with a queue worker does the work off the admin's
 * request. A forum without one runs Flarum's default sync queue, which
 * handles the job on the spot, exactly as before.
 */
class SyncLadder extends AbstractJob
{
    public function handle(Ladder $ladder): void
    {
        if ($ladder->metric() === 'points') {
            $ladder->syncAll();
        }
    }
}
