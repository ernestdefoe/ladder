<?php

namespace Ernestdefoe\Ladder\Api;

use Flarum\Http\SlugManager;
use Flarum\Http\UrlGenerator;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/ladder/rungs/{id}/members — who holds a rank, highest score first.
 *
 * 🚨 Gated by `searchUsers`, the same permission that guards Flarum's own
 * member list, so a forum that hides its membership from guests doesn't leak
 * it through the Ranks page. Each member is also filtered by whether the
 * viewer may see them at all.
 */
class RungMembers extends Controller
{
    protected bool $adminOnly = false;

    private const PER_PAGE = 30;

    protected function respond(ServerRequestInterface $request, User $actor): mixed
    {
        $actor->assertCan('searchUsers');

        $rung = $this->rung($request);

        // A hidden rung is hidden here too, as on the Ranks page.
        if ($rung->group->is_hidden && ! $actor->isAdmin()) {
            throw new \Flarum\Http\Exception\RouteNotFoundException();
        }

        $offset = max(0, (int) Arr::get($request->getQueryParams(), 'offset', 0));
        $result = $this->ladder->membersOf($rung, $actor, $offset, self::PER_PAGE);

        $slugs = resolve(SlugManager::class)->forResource(User::class);
        $url = resolve(UrlGenerator::class);

        return [
            'members' => $result['users']->map(fn (User $user) => [
                'id' => $user->id,
                'displayName' => $user->display_name,
                'avatarUrl' => $user->avatar_url,
                'url' => $url->to('forum')->route('user', ['username' => $slugs->toSlug($user)]),
                'score' => max(0, (int) $user->ladder_score),
            ])->values()->all(),
            'total' => $result['total'],
            'nextOffset' => $offset + self::PER_PAGE < $result['total'] ? $offset + self::PER_PAGE : null,
        ];
    }
}
