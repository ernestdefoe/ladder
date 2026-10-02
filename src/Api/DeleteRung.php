<?php

namespace Ernestdefoe\Ladder\Api;

use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Takes a rung off the ladder.
 *
 * A group Ladder made goes with it, members and all: it only ever existed to
 * be this rank. A group the admin already had stays exactly as it is, members
 * included, because it may mean something else on their forum and that is
 * not this extension's to undo. The admin page asks for a re-rank afterwards,
 * which moves anybody who was on the removed rung to the rung below it.
 */
class DeleteRung extends Controller
{
    protected function respond(ServerRequestInterface $request, User $actor): mixed
    {
        $rung = $this->rung($request);
        $group = $rung->group;

        $rung->delete();

        if ($rung->owns_group && $group) {
            $group->delete();
        }

        $this->changed();

        return $this->ladderPayload($actor);
    }
}
