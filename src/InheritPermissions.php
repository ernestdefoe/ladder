<?php

namespace Ernestdefoe\Ladder;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Ranks are exclusive for display and cumulative for permissions.
 *
 * A member wears one rank badge, but their permission checks also count every
 * rung below the one they hold. Without this, granting "upload images" to
 * Recreativo would take it away again the moment a member climbs to Radio,
 * and an admin would have to tick every permission on every higher rung, then
 * remember to do it again for each new rung.
 *
 * With it, a permission is granted once, on the lowest rank that should have
 * it. Most ladders need no permissions at all: every confirmed account already
 * has the Member group's, so a rank group can be a badge and nothing more.
 *
 * 🚨 This runs on every permission check, so the rung map comes from the cache,
 * not the database. It is cached rather than held in a static so a queue worker
 * that lives for hours still sees a rung the admin added a minute ago; the
 * admin API forgets the key on every ladder change.
 */
class InheritPermissions
{
    public const CACHE_KEY = 'ernestdefoe-ladder.rung-map';
    public const SETTING = 'ernestdefoe-ladder.inherit_permissions';

    public function __construct(
        private Cache $cache,
        private SettingsRepositoryInterface $settings,
    ) {
    }

    /**
     * @param int[] $groupIds
     * @return int[]
     */
    public function __invoke(User $user, array $groupIds): array
    {
        if (! $this->settings->get(self::SETTING)) {
            return $groupIds;
        }

        // group id => min posts, lowest first.
        $map = $this->cache->rememberForever(self::CACHE_KEY, fn () => Rung::query()
            ->orderBy('min_posts')
            ->pluck('min_posts', 'group_id')
            ->map(fn ($n) => (int) $n)
            ->all());

        $held = null;

        foreach ($groupIds as $id) {
            if (isset($map[(int) $id]) && ($held === null || $map[(int) $id] > $held)) {
                $held = $map[(int) $id];
            }
        }

        if ($held === null) {
            return $groupIds;
        }

        foreach ($map as $groupId => $minPosts) {
            if ($minPosts < $held) {
                $groupIds[] = (int) $groupId;
            }
        }

        return array_values(array_unique($groupIds));
    }
}
