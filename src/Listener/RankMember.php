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
 * Re-ranks the one member whose score just moved.
 *
 * 🚨 Synchronous, never queued. Plenty of Flarum forums run on shared or free
 * hosting with no queue worker and no cron, and a queued rank change on one of
 * those simply never happens. The work is two small queries per post.
 *
 * Core's events are subscribed here; the other extensions' events are wired to
 * the methods below in extend.php, only while that extension is enabled.
 */
class RankMember
{
    public function __construct(private Ladder $ladder, private Queue $queue)
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
    }

    /**
     * Leaderboard announces each change once it ships PointsUpdated. With it,
     * points mode is instant and the active-member re-check stands down.
     */
    public function pointsUpdated(object $event): void
    {
        if ($this->ladder->metric() === 'points') {
            $this->rank($event->user);
        }
    }

    /**
     * Leaderboard's full recalculation can move anyone, so re-rank everyone,
     * quietly, as the admin's own re-rank does. That walks every member, so it
     * goes on the queue (run on the spot when the forum has no worker).
     */
    public function pointsRecalculated(): void
    {
        if ($this->ladder->metric() === 'points') {
            $this->queue->push(new SyncLadder());
        }
    }

    /** fof/gamification announces every change to a member's points, so a vote moves the rank at once. */
    public function votesUpdated(object $event): void
    {
        $this->rank($event->user);
    }

    /**
     * Money announces every change to a balance (its own rewards, an admin's
     * edit, and add-ons such as Engagement Money that dispatch the same event).
     */
    public function moneyUpdated(object $event): void
    {
        if ($event->user && $this->ladder->metric() === 'money') {
            $this->rank($event->user);
        }
    }

    /**
     * Renown announces every level change. A quiet one (its history replay, XP
     * taken back) re-ranks without an alert, as the admin's own re-rank does.
     */
    public function levelChanged(object $event): void
    {
        if ($this->ladder->metric() === 'renown') {
            $this->rank($event->user, ! $event->quiet);
        }
    }

    /**
     * With Approval, a held post is private and does not count until a
     * moderator lets it through, so the promotion belongs to that moment.
     */
    public function postApproved(object $event): void
    {
        $this->rank($event->post->user);
    }

    private function rank(?User $user, bool $notify = true): void
    {
        if ($user) {
            $this->ladder->syncUser($user, $notify);
        }
    }
}
