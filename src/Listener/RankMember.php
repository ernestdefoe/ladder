<?php

namespace Ernestdefoe\Ladder\Listener;

use Ernestdefoe\Ladder\Ladder;
use Flarum\Post\Event\Deleted as PostDeleted;
use Flarum\Post\Event\Posted;
use Flarum\User\Event\Registered;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Re-ranks the one member whose post count just moved.
 *
 * 🚨 Synchronous, never queued. Plenty of Flarum forums run on shared or free
 * hosting with no queue worker and no cron, and a queued rank change on one of
 * those simply never happens. The work is two small queries per post.
 */
class RankMember
{
    public function __construct(private Ladder $ladder)
    {
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Posted::class, fn (Posted $event) => $this->rank($event->post->user));
        $events->listen(PostDeleted::class, fn (PostDeleted $event) => $this->rank($event->post->user, false));

        // A rung that starts at 0 posts is where a new member begins, so they
        // wear a rank from the moment the account exists, not from their
        // first reply.
        $events->listen(Registered::class, fn (Registered $event) => $this->rank($event->user, false));

        // fof/gamification announces every change to a member's points, so in
        // that mode the rank follows a vote instantly. (Leaderboard announces
        // nothing; see RecheckActiveMember.)
        if (class_exists(\FoF\Gamification\Events\UserPointsUpdated::class)) {
            $events->listen(
                \FoF\Gamification\Events\UserPointsUpdated::class,
                fn ($event) => $this->rank($event->user)
            );
        }

        // With Approval, a held post is private and does not count until a
        // moderator lets it through, so the promotion belongs to that moment.
        if (class_exists(\Flarum\Approval\Event\PostWasApproved::class)) {
            $events->listen(
                \Flarum\Approval\Event\PostWasApproved::class,
                fn ($event) => $this->rank($event->post->user)
            );
        }
    }

    private function rank($user, bool $notify = true): void
    {
        if ($user) {
            $this->ladder->syncUser($user, $notify);
        }
    }
}
