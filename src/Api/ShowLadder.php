<?php

namespace Ernestdefoe\Ladder\Api;

use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The public ladder, for the Ranks page. Guests can read it: a ladder is
 * something to show a visitor before they join, not after.
 */
class ShowLadder extends Controller
{
    protected bool $adminOnly = false;

    protected function respond(ServerRequestInterface $request, User $actor): mixed
    {
        $actor->assertCan('viewForum');

        return $this->ladderPayload($actor);
    }
}
