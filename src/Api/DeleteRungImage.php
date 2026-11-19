<?php

namespace Ernestdefoe\Ladder\Api;

use Ernestdefoe\Ladder\Rung;
use Ernestdefoe\Ladder\RungImages;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * DELETE /api/ladder/rungs/{id}/image: the card goes back to drawing the
 * rank's icon. The other half of the upload handler.
 */
class DeleteRungImage implements RequestHandlerInterface
{
    public function __construct(private RungImages $images)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $rung = Rung::query()->findOrFail((int) Arr::get($request->getQueryParams(), 'id'));
        $old = $rung->image_path;

        $rung->image_path = null;
        $rung->save();
        $this->images->forget($old);

        return new EmptyResponse(204);
    }
}
