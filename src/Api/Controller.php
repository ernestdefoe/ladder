<?php

namespace Ernestdefoe\Ladder\Api;

use Ernestdefoe\Ladder\Ladder;
use Ernestdefoe\Ladder\Rung;
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
            ];
        }

        $viewer = null;

        if (! $actor->isGuest()) {
            $held = array_values(array_intersect(
                $actor->groups->pluck('id')->map(fn ($id) => (int) $id)->all(),
                $this->ladder->groupIds(),
            ));

            $viewer = [
                'posts' => (int) $actor->comment_count,
                'groupId' => $held[0] ?? null,
            ];
        }

        return [
            'rungs' => $data,
            'demotes' => $this->ladder->demotes(),
            'viewer' => $viewer,
        ];
    }
}
