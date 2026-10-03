<?php

namespace Ernestdefoe\Ladder\Api;

use Ernestdefoe\Ladder\RungImages;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * POST /api/ladder/rungs/{id}/image uploads a rung's artwork; DELETE removes
 * it, and the card goes back to drawing the rank's icon.
 */
class RungImage extends Controller
{
    protected function respond(ServerRequestInterface $request, User $actor): mixed
    {
        $rung = $this->rung($request);
        $images = resolve(RungImages::class);

        if ($request->getMethod() === 'DELETE') {
            $images->forget($rung->id);
            $rung->image_path = null;
            $rung->save();

            return $this->ladderPayload($actor);
        }

        $file = Arr::get($request->getUploadedFiles(), 'image');

        if (! $file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            $this->fail('image_missing');
        }

        if ($file->getSize() > RungImages::MAX_BYTES) {
            $this->fail('image_too_large');
        }

        // The sniffed type, never the one the browser claims.
        $type = (string) @mime_content_type($file->getStream()->getMetadata('uri'));

        if (! in_array($type, RungImages::ALLOWED, true)) {
            $this->fail('image_type');
        }

        $rung->image_path = $images->put($rung->id, $file);
        $rung->save();

        return $this->ladderPayload($actor);
    }

    private function fail(string $key): never
    {
        throw new ValidationException([
            'image' => resolve(TranslatorInterface::class)->trans('ernestdefoe-ladder.lib.errors.'.$key),
        ]);
    }
}
