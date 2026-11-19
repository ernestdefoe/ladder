<?php

namespace Ernestdefoe\Ladder;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * The ladder as both the admin page and the public Ranks page read it: every
 * rung with its range and member count, and where the reader stands.
 */
class LadderPayload
{
    public function __construct(
        private Ladder $ladder,
        private RungImages $images,
        private SettingsRepositoryInterface $settings,
    ) {
    }

    public function for(User $actor): array
    {
        $this->ladder->forget();

        return [
            'rungs' => $this->rungs($actor),
            'demotes' => $this->ladder->demotes(),
            // Each rank opens a list of who holds it, for those allowed to
            // list members at all.
            'canListMembers' => $actor->can('searchUsers'),
            'metric' => $this->ladder->metric(),
            // Only the admin page offers the choice, and only when it exists.
            'leaderboardAvailable' => $actor->isAdmin() && $this->ladder->leaderboardAvailable(),
            'gamificationAvailable' => $actor->isAdmin() && $this->ladder->gamificationAvailable(),
            'moneyAvailable' => $actor->isAdmin() && $this->ladder->moneyAvailable(),
            'renownAvailable' => $actor->isAdmin() && $this->ladder->renownAvailable(),
            'viewer' => $actor->isGuest() ? null : $this->viewer($actor),
            'banner' => [
                // Empty means "use the forum's own title", decided by the
                // client so a renamed forum never shows its old name here.
                'title' => (string) $this->settings->get(Ladder::BANNER_TITLE),
                'tagline' => (string) $this->settings->get(Ladder::BANNER_TAGLINE),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rungs(User $actor): array
    {
        $counts = $this->ladder->memberCounts();
        $rungs = $this->ladder->rungs()->values();
        $data = [];

        foreach ($rungs as $i => $rung) {
            $group = $rung->group;

            // A rung whose group an admin has hidden is hidden from the public
            // page too; the admin still sees it, so it can be fixed.
            if ($group->is_hidden && ! $actor->isAdmin()) {
                continue;
            }

            $next = $rungs->get($i + 1);

            $data[] = [
                'id' => $rung->id,
                'groupId' => $group->id,
                'name' => $group->name_singular,
                'namePlural' => $group->name_plural,
                'icon' => $group->icon,
                'color' => $group->color,
                'isHidden' => (bool) $group->is_hidden,
                'minPosts' => $rung->min_posts,
                // Inclusive top of the range, or null for the top rung.
                'maxPosts' => $next ? $next->min_posts - 1 : null,
                'description' => $rung->description,
                'ownsGroup' => $rung->owns_group,
                'memberCount' => $counts[$group->id] ?? 0,
                'imageUrl' => $this->images->url($rung->image_path),
            ];
        }

        return $data;
    }

    /** @return array{score: int, groupId: ?int, exempt: bool} */
    private function viewer(User $actor): array
    {
        /*
         * 🚨 Put the reader on the right rung before describing it. A member
         * whose rank was never applied (a ladder built before they last posted,
         * a re-rank that didn't finish) was told they needed "10 more posts to
         * reach your first rank", naming the rung ABOVE the one they had
         * already earned. Seen on a live forum.
         */
        $this->ladder->syncUser($actor);
        $actor->unsetRelation('groups');

        $all = $actor->groups()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $held = array_values(array_intersect($all, $this->ladder->groupIds()));

        return [
            'score' => $this->ladder->score($actor),
            'groupId' => $held[0] ?? null,
            // Kept off the ladder on purpose (staff, bots): no rank is coming,
            // so the page must not promise one.
            'exempt' => (bool) array_intersect($all, $this->ladder->exemptGroupIds()),
        ];
    }
}
