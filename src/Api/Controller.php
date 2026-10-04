<?php

namespace Ernestdefoe\Ladder\Api;

use Ernestdefoe\Ladder\Ladder;
use Ernestdefoe\Ladder\Rung;
use Ernestdefoe\Ladder\RungImages;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

abstract class Controller implements RequestHandlerInterface
{
    /** Writes are admin-only; the public ladder overrides this. */
    protected bool $adminOnly = true;

    /** The rung a write just touched, so the admin page can follow up on it. */
    protected ?int $savedId = null;

    public function __construct(protected Ladder $ladder, protected ConnectionInterface $db, protected Cache $cache)
    {
    }

    abstract protected function respond(ServerRequestInterface $request, User $actor): mixed;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // 🚨 RequestUtil, not $request->getAttribute('actor'): that attribute
        // is null in Flarum 2 and every admin call would 500.
        $actor = RequestUtil::getActor($request);

        if ($this->adminOnly) {
            $actor->assertAdmin();
        }

        $result = $this->respond($request, $actor);

        return $result instanceof ResponseInterface ? $result : new JsonResponse($result);
    }

    /** Call after any write to the ladder. */
    protected function changed(): void
    {
        $this->ladder->forget();
        $this->cache->forget(\Ernestdefoe\Ladder\InheritPermissions::CACHE_KEY);
    }

    protected function body(ServerRequestInterface $request): array
    {
        return (array) $request->getParsedBody();
    }

    protected function rung(ServerRequestInterface $request): Rung
    {
        return Rung::query()->with('group')->findOrFail((int) Arr::get($request->getQueryParams(), 'id'));
    }

    /**
     * The ladder as both the admin page and the public Ranks page read it.
     */
    protected function ladderPayload(User $actor): array
    {
        $this->ladder->forget();

        $counts = $this->ladder->memberCounts();
        $images = resolve(RungImages::class);
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
                'imageUrl' => $images->url($rung->image_path),
            ];
        }

        $viewer = null;

        if (! $actor->isGuest()) {
            /*
             * 🚨 Put the reader on the right rung before describing it. A
             * member whose rank was never applied (a ladder built before they
             * last posted, a re-rank that didn't finish) was told they needed
             * "10 more posts to reach your first rank" — naming the rung ABOVE
             * the one they had already earned. Seen on a live forum.
             */
            $this->ladder->syncUser($actor);
            $actor->unsetRelation('groups');

            $all = $actor->groups()->pluck('id')->map(fn ($id) => (int) $id)->all();
            $held = array_values(array_intersect($all, $this->ladder->groupIds()));

            $viewer = [
                'score' => $this->ladder->score($actor),
                'groupId' => $held[0] ?? null,
                // Kept off the ladder on purpose (staff, bots): no rank is
                // coming, so the page must not promise one.
                'exempt' => (bool) array_intersect($all, $this->ladder->exemptGroupIds()),
            ];
        }

        $settings = resolve(SettingsRepositoryInterface::class);

        return [
            'rungs' => $data,
            'demotes' => $this->ladder->demotes(),
            'metric' => $this->ladder->metric(),
            // Only the admin page offers the choice, and only when it exists.
            'leaderboardAvailable' => $actor->isAdmin() && $this->ladder->leaderboardAvailable(),
            'gamificationAvailable' => $actor->isAdmin() && $this->ladder->gamificationAvailable(),
            'viewer' => $viewer,
            'banner' => [
                // Empty means "use the forum's own title", decided by the
                // client so a renamed forum never shows its old name here.
                'title' => (string) $settings->get(Ladder::BANNER_TITLE),
                'tagline' => (string) $settings->get(Ladder::BANNER_TAGLINE),
            ],
            'savedId' => $this->savedId,
        ];
    }
}
