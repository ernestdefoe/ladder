<?php

namespace Ernestdefoe\Ladder\Middleware;

use Ernestdefoe\Ladder\Ladder;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * In points mode, re-ranks a signed-in member while they use the forum.
 *
 * Leaderboard changes a total straight in its own table and announces nothing,
 * so there is no moment Ladder can hear "this member's points moved". Points
 * also arrive while somebody isn't there: a like on their post, a best answer.
 * Checking when they're next active means the rank is right whenever they (or
 * anyone looking at them mid-conversation) can see it, with no cron job, which
 * keeps Ladder working on shared and free hosting.
 *
 * At most once per member per INTERVAL. `add()` is atomic, so a page firing
 * several API calls at once still runs one check, not one each.
 *
 * Posts mode skips all of this: posting is an event Ladder already hears. So
 * does a Leaderboard new enough to dispatch PointsUpdated (see RankMember).
 */
class RecheckActiveMember implements MiddlewareInterface
{
    public const INTERVAL = 120;

    public function __construct(
        private Ladder $ladder,
        private Cache $cache,
        private LoggerInterface $log,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        if (! $actor->isGuest() && $this->ladder->metric() === 'points'
            && ! class_exists(\HuseyinFiliz\Leaderboard\Event\PointsUpdated::class)
            && $this->cache->add('ladder.recheck.'.$actor->id, 1, self::INTERVAL)) {
            /*
             * 🚨 Never allowed to fail the request. A rank that is a minute
             * late is nothing; a forum that 500s because a rank check threw
             * is an outage.
             */
            try {
                $this->ladder->syncUser($actor);
            } catch (\Throwable $e) {
                $this->log->warning('[ladder] could not re-check member '.$actor->id.': '.$e->getMessage());
            }
        }

        return $handler->handle($request);
    }
}
