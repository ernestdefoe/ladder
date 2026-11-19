<?php

namespace Ernestdefoe\Ladder\Api;

use Ernestdefoe\Ladder\LadderPayload;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The whole ladder for the Ranks page and the admin editor. Guests can read
 * it: a ladder is something to show a visitor before they join, not after.
 *
 * A plain handler, not a resource endpoint: the answer is one computed view
 * (every rung with its range and member count, and where the reader stands),
 * not a list of records.
 */
class ShowLadder implements RequestHandlerInterface
{
    public function __construct(private LadderPayload $payload)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('viewForum');

        return new JsonResponse($this->payload->for($actor));
    }
}
