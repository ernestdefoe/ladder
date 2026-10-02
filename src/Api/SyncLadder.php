<?php

namespace Ernestdefoe\Ladder\Api;

use Flarum\User\User;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Re-ranks one slice of the membership. The admin page calls this in a loop
 * and draws a progress bar from the answers.
 *
 * 🚨 Driven from the browser, not a queue. A forum on shared hosting often has
 * no queue worker and no cron at all, and a re-rank waiting for one would sit
 * at 0% forever. Slices keep each request well inside a host's time limit.
 */
class SyncLadder extends Controller
{
    protected function respond(ServerRequestInterface $request, User $actor): mixed
    {
        $after = max(0, (int) Arr::get($this->body($request), 'after', 0));
        $result = $this->ladder->syncChunk($after, 500);

        if ($after === 0) {
            $result['total'] = User::query()->count();
        }

        return $result;
    }
}
