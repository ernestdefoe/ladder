<?php

namespace Ernestdefoe\Ladder\Api;

use Ernestdefoe\Ladder\Ladder;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Re-ranks one slice of the membership: an action, not a record. The admin
 * page calls this in a loop and draws a progress bar from the answers.
 *
 * 🚨 Driven from the browser, not a queue. A forum on shared hosting often has
 * no queue worker and no cron at all, and a re-rank waiting for one would sit
 * at 0% forever. Slices keep each request well inside a host's time limit.
 */
class SyncLadder implements RequestHandlerInterface
{
    public function __construct(private Ladder $ladder)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $after = max(0, (int) Arr::get((array) $request->getParsedBody(), 'after', 0));
        $result = $this->ladder->syncChunk($after, 500);

        if ($after === 0) {
            $result['total'] = User::query()->count();
        }

        return new JsonResponse($result);
    }
}
