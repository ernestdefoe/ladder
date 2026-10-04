<?php

namespace Ernestdefoe\Ladder;

use Ernestdefoe\Ladder\Notification\PromotedBlueprint;
use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use Flarum\Notification\NotificationSyncer;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Event\GroupsChanged;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * The whole rule, in one place: a member's post count picks a rung, and the
 * member holds that rung's group and no other rung's group.
 *
 * Every path that changes a member's rank comes through here, whether it is
 * one member posting or the admin re-ranking the whole forum, so the two can
 * never disagree about who belongs where.
 *
 * 🚨 Only rung groups are ever touched. Administrators, moderators and any
 * group an admin assigned by hand are invisible to this class. A rank system
 * that could take somebody's moderator group away is not one anybody would
 * dare to switch on.
 */
class Ladder
{
    public const DEMOTE = 'ernestdefoe-ladder.demote';
    public const EXEMPT_GROUPS = 'ernestdefoe-ladder.exempt_groups';
    public const SHOW_NAV = 'ernestdefoe-ladder.show_nav';
    public const BANNER_TITLE = 'ernestdefoe-ladder.banner_title';
    public const BANNER_TAGLINE = 'ernestdefoe-ladder.banner_tagline';
    public const BANNER_ON_INDEX = 'ernestdefoe-ladder.banner_on_index';
    public const METRIC = 'ernestdefoe-ladder.metric';

    /** huseyinfiliz/leaderboard: one row per member, their running points total. */
    private const LEADERBOARD = 'huseyinfiliz-leaderboard';

    /** fof/gamification: a member's points live in `users.votes`. */
    private const GAMIFICATION = 'fof-gamification';
    private const LEADERBOARD_TOTALS = 'leaderboard_user_totals';

    /** @var Collection<int, Rung>|null */
    private ?Collection $rungs = null;

    public function __construct(
        private ConnectionInterface $db,
        private SettingsRepositoryInterface $settings,
        private Dispatcher $events,
        private NotificationSyncer $notifications,
        private ExtensionManager $extensions,
    ) {
    }

    /**
     * What the thresholds count: 'posts' (the default), 'points' from the
     * Leaderboard extension, or 'votes', fof/gamification's points.
     *
     * 🚨 Falls back to posts whenever the chosen extension is not enabled,
     * whatever the setting says. Disabling it must not leave every member
     * scored 0 and, with demotion on, knocked to the bottom rung.
     */
    public function metric(): string
    {
        return match ($this->settings->get(self::METRIC)) {
            'points' => $this->leaderboardAvailable() ? 'points' : 'posts',
            'votes' => $this->gamificationAvailable() ? 'votes' : 'posts',
            default => 'posts',
        };
    }

    public function gamificationAvailable(): bool
    {
        return $this->extensions->isEnabled(self::GAMIFICATION);
    }

    public function leaderboardAvailable(): bool
    {
        return $this->extensions->isEnabled(self::LEADERBOARD);
    }

    /**
     * The number a member's rung is chosen by.
     *
     * Posts are read fresh rather than trusted: core, Approval and this
     * extension all react to the same events, and the order they run in is not
     * something any of them decides. Points are read from Leaderboard's own
     * totals, never recalculated here; a member with no row yet has 0, and a
     * negative total (points taken away) counts as 0.
     */
    public function score(User $user): int
    {
        $metric = $this->metric();

        if ($metric === 'points') {
            $total = $this->db->table(self::LEADERBOARD_TOTALS)->where('user_id', $user->id)->value('points_total');

            return max(0, (int) $total);
        }

        // Read from the table, not the model: gamification updates the column
        // with a query, so the User object in hand can hold the old number.
        if ($metric === 'votes') {
            return max(0, (int) $this->db->table('users')->where('id', $user->id)->value('votes'));
        }

        $user->refreshCommentCount();

        if ($user->isDirty('comment_count')) {
            $user->save();
        }

        return (int) $user->comment_count;
    }

    /**
     * Lowest threshold first.
     *
     * @return Collection<int, Rung>
     */
    public function rungs(): Collection
    {
        return $this->rungs ??= Rung::query()
            ->with('group')
            ->orderBy('min_posts')
            ->orderBy('id')
            ->get()
            ->filter(fn (Rung $rung) => $rung->group !== null)
            ->values();
    }

    /**
     * Forget the cached ladder after it has been edited, so a re-rank in the
     * same request uses the new thresholds.
     */
    public function forget(): void
    {
        $this->rungs = null;
    }

    /** @return int[] */
    public function groupIds(): array
    {
        return $this->rungs()->pluck('group_id')->all();
    }

    /**
     * The rung a score earns on its own: the highest threshold it meets.
     */
    public function earned(int $posts): ?Rung
    {
        return $this->rungs()->last(fn (Rung $rung) => $rung->min_posts <= $posts);
    }

    /**
     * Which rung group a member should hold, given their post count and the
     * rung groups they hold right now.
     *
     * With demotion off (the default) a member never moves down. Deleting a
     * thread full of somebody's replies, or a moderator pruning spam, should
     * not quietly strip a rank they earned in front of everybody.
     *
     * @param int[] $heldGroupIds
     * @param int[] $allGroupIds every group the member is in, for exemptions
     */
    public function target(int $posts, array $heldGroupIds, array $allGroupIds = []): ?int
    {
        if (array_intersect($allGroupIds, $this->exemptGroupIds())) {
            return null;
        }

        $earned = $this->earned($posts);

        if (! $this->demotes()) {
            $held = $this->highestOf($heldGroupIds);

            if ($held && (! $earned || $held->min_posts > $earned->min_posts)) {
                return $held->group_id;
            }
        }

        return $earned?->group_id;
    }

    /**
     * Put one member on the right rung. Used whenever that member's post count
     * may have changed.
     */
    public function syncUser(User $user, bool $notify = true): void
    {
        if (! $user->exists || $this->rungs()->isEmpty()) {
            return;
        }

        $score = $this->score($user);

        $user->unsetRelation('groups');

        $oldGroups = $user->groups()->get();
        $allIds = $oldGroups->pluck('id')->map(fn ($id) => (int) $id)->all();
        $ladderIds = $this->groupIds();
        $held = array_values(array_intersect($allIds, $ladderIds));

        $target = $this->target($score, $held, $allIds);
        $wanted = $target === null ? [] : [$target];

        if ($held == $wanted) {
            return;
        }

        $this->db->transaction(function () use ($user, $held, $wanted) {
            $drop = array_values(array_diff($held, $wanted));
            $add = array_values(array_diff($wanted, $held));

            if ($drop) {
                $user->groups()->detach($drop);
            }

            if ($add) {
                $user->groups()->attach($add);
            }
        });

        $user->unsetRelation('groups');

        $this->events->dispatch(new GroupsChanged($user, $oldGroups->all()));

        if ($notify && $target !== null && $this->isPromotion($held, $target)) {
            $group = Group::query()->find($target);

            if ($group) {
                $this->notifications->sync(new PromotedBlueprint($group), [$user]);
            }
        }
    }

    /**
     * Re-rank one slice of the membership, ordered by user id.
     *
     * 🚨 Paged by id, never by offset. A member who registers while the admin
     * is re-ranking shifts every offset after them by one, which ranks one
     * member twice and skips another.
     *
     * Deliberately quiet: no notifications and no GroupsChanged events.
     * Turning Ladder on for an established forum would otherwise send a
     * "you have been promoted" alert to every member at once, and write a row
     * per member into any audit log that listens.
     *
     * @return array{processed: int, changed: int, lastId: int, done: bool}
     */
    public function syncChunk(int $afterId, int $limit = 500): array
    {
        /*
         * In points mode the total comes from Leaderboard's table by a join.
         * 🚨 Query builder only: a raw join would skip the forum's table
         * prefix and fail on every forum that has one.
         */
        $metric = $this->metric();

        $users = $metric === 'points'
            ? $this->db->table('users')
                ->leftJoin(self::LEADERBOARD_TOTALS, self::LEADERBOARD_TOTALS.'.user_id', '=', 'users.id')
                ->where('users.id', '>', $afterId)
                ->orderBy('users.id')
                ->limit($limit)
                ->get(['users.id', self::LEADERBOARD_TOTALS.'.points_total as score'])
            : $this->db->table('users')
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit($limit)
                ->get(['id', ($metric === 'votes' ? 'votes' : 'comment_count').' as score']);

        if ($users->isEmpty()) {
            return ['processed' => 0, 'changed' => 0, 'lastId' => $afterId, 'done' => true];
        }

        $ids = $users->pluck('id')->map(fn ($id) => (int) $id)->all();
        $ladderIds = $this->groupIds();

        $memberships = $this->db->table('group_user')
            ->whereIn('user_id', $ids)
            ->get(['user_id', 'group_id'])
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('group_id')->map(fn ($id) => (int) $id)->all());

        $remove = [];
        $insert = [];

        foreach ($users as $user) {
            $all = $memberships->get($user->id, []);
            $held = array_values(array_intersect($all, $ladderIds));

            $target = $ladderIds ? $this->target(max(0, (int) $user->score), $held, $all) : null;
            $wanted = $target === null ? [] : [$target];

            foreach (array_diff($held, $wanted) as $groupId) {
                $remove[$groupId][] = (int) $user->id;
            }

            foreach (array_diff($wanted, $held) as $groupId) {
                $insert[] = ['user_id' => (int) $user->id, 'group_id' => $groupId];
            }
        }

        $changedUsers = count(array_unique(array_merge(
            array_merge([], ...array_values($remove)),
            array_column($insert, 'user_id'),
        )));

        $this->db->transaction(function () use ($remove, $insert) {
            foreach ($remove as $groupId => $userIds) {
                $this->db->table('group_user')
                    ->where('group_id', $groupId)
                    ->whereIn('user_id', $userIds)
                    ->delete();
            }

            if ($insert) {
                $this->db->table('group_user')->insert($insert);
            }
        });

        $lastId = (int) end($ids);

        return [
            'processed' => count($ids),
            'changed' => $changedUsers,
            'lastId' => $lastId,
            'done' => count($ids) < $limit,
        ];
    }

    /**
     * How many members hold each rung right now, keyed by group id.
     *
     * @return array<int, int>
     */
    public function memberCounts(): array
    {
        $ids = $this->groupIds();

        if (! $ids) {
            return [];
        }

        return $this->db->table('group_user')
            ->whereIn('group_id', $ids)
            ->selectRaw('group_id, count(*) as aggregate')
            ->groupBy('group_id')
            ->pluck('aggregate', 'group_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    public function demotes(): bool
    {
        return (bool) $this->settings->get(self::DEMOTE);
    }

    /** @return int[] */
    public function exemptGroupIds(): array
    {
        $ids = json_decode((string) $this->settings->get(self::EXEMPT_GROUPS), true);

        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    /** @param int[] $groupIds */
    private function highestOf(array $groupIds): ?Rung
    {
        return $this->rungs()->last(fn (Rung $rung) => in_array($rung->group_id, $groupIds, true));
    }

    /** @param int[] $held */
    private function isPromotion(array $held, int $target): bool
    {
        $before = $this->highestOf($held);
        $after = $this->rungs()->firstWhere('group_id', $target);

        // A member's first rung is a promotion too, except the bottom rung a
        // new account is placed on at registration: "Welcome, you have
        // reached the rank you start on" is noise.
        if (! $before) {
            return $after && $after->min_posts > 0;
        }

        return $after && $after->min_posts > $before->min_posts;
    }
}
