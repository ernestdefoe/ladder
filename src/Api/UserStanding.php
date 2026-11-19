<?php

namespace Ernestdefoe\Ladder\Api;

use Ernestdefoe\Ladder\Ladder;
use Flarum\Api\Context;
use Flarum\Api\Resource\UserResource;
use Flarum\Api\Schema;
use Flarum\User\User;

/**
 * A member's standing, for their profile, and their score in a rank's
 * member list.
 */
class UserStanding
{
    public function __construct(private Ladder $ladder)
    {
    }

    public function __invoke(): array
    {
        return [
            // Only on the profile's own request: computing it for every user in
            // a list would be a query per member on every discussion page.
            Schema\Arr::make('ladderStanding')
                ->visible(fn (User $user, Context $context) => $context->showing(UserResource::class))
                ->get(fn (User $user, Context $context) => $this->ladder->standing($user, $context->getActor())),
            // Selected by the ladderRung filter (RungFilter), so no query of its own.
            Schema\Integer::make('ladderScore')
                ->visible(fn (User $user) => array_key_exists('ladder_score', $user->getAttributes()))
                ->get(fn (User $user) => max(0, (int) $user->getAttribute('ladder_score'))),
        ];
    }
}
