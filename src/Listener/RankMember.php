<?php

namespace Ernestdefoe\Ladder\Listener;

use Ernestdefoe\Ladder\Job\SyncLadder;
use Ernestdefoe\Ladder\Ladder;
use Flarum\Post\Event\Deleted as PostDeleted;
use Flarum\Post\Event\Posted;
use Flarum\User\Event\Registered;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Queue;

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

        // Leaderboard announces each change once it ships PointsUpdated
        // (offered upstream). With it, points mode is instant and the
        // active-member re-check stands down; older versions still rely on it.
        if (class_exists(\HuseyinFiliz\Leaderboard\Event\PointsUpdated::class)) {
            $events->listen(
                \HuseyinFiliz\Leaderboard\Event\PointsUpdated::class,
                fn ($event) => $this->ladder->metric() === 'points' ? $this->rank($event->user) : null
            );
        }

        // A full recalculation can move anyone, so re-rank everyone, quietly,
        // as the admin's own re-rank does. That walks every member, so it goes
        // on the queue (run on the spot when the forum has no worker).
        if (class_exists(\HuseyinFiliz\Leaderboard\Event\PointsRecalculated::class)) {
            $events->listen(
                \HuseyinFiliz\Leaderboard\Event\PointsRecalculated::class,
                fn () => $this->ladder->metric() === 'points' ? $this->queueSync() : null
            );
        }

        // fof/gamification announces every change to a member's points, so in
        // that mode the rank follows a vote instantly. (Leaderboard announces
        // nothing; see RecheckActiveMember.)
        if (class_exists(\FoF\Gamification\Events\UserPointsUpdated::class)) {
            $events->listen(
                \FoF\Gamification\Events\UserPointsUpdated::class,
                fn ($event) => $this->rank($event->user)
            );
        }

        // Money announces every change to a balance (its own rewards, an admin's
        // edit, and add-ons such as Engagement Money that dispatch the same
        // event), so in that mode the rank follows the balance instantly.
        if (class_exists(\AntoineFr\Money\Event\MoneyUpdated::class)) {
            $events->listen(
                \AntoineFr\Money\Event\MoneyUpdated::class,
                fn ($event) => $event->user && $this->ladder->metric() === 'money' ? $this->rank($event->user) : null
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

    private function queueSync(): void
    {
        /** @var Queue $queue */
        $queue = resolve(Queue::class);
        $queue->push(new SyncLadder());
    }

    private function rank(?User $user, bool $notify = true): void
    {
        if ($user) {
            $this->ladder->syncUser($user, $notify);
        }
    }
}
