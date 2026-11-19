<?php

namespace Ernestdefoe\Ladder\Api;

use Ernestdefoe\Ladder\Rung;
use Ernestdefoe\Ladder\RungImages;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/ladder/rungs/{id}/image: a rung's artwork. A file upload, which a
 * JSON:API resource can't take, so a plain handler.
 */
class UploadRungImage implements RequestHandlerInterface
{
    public function __construct(private RungImages $images, private TranslatorInterface $translator)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $rung = Rung::query()->findOrFail((int) Arr::get($request->getQueryParams(), 'id'));
        $contents = $this->validContents(Arr::get($request->getUploadedFiles(), 'image'));

        // Write the new file, record it, and only then drop the old one, so a
        // failed save never leaves the rung pointing at a deleted picture,
        // and the file it did write is cleaned up before the error goes on.
        $old = $rung->image_path;
        $rung->image_path = $this->images->put($rung->id, $contents);

        try {
            $rung->save();
        } catch (\Throwable $e) {
            $this->images->forget($rung->image_path);

            throw $e;
        }

        $this->images->forget($old);

        return new EmptyResponse(204);
    }

    /** The upload's bytes, once its size and its sniffed type (never the one the browser claims) pass. */
    private function validContents(mixed $file): string
    {
        if (! $file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            $this->fail('image_missing');
        }

        if ($file->getSize() > RungImages::MAX_BYTES) {
            $this->fail('image_too_large');
        }

        $contents = (string) $file->getStream();

        // Flarum's installer requires ext-fileinfo, so finfo is always there.
        if (! in_array((new \finfo(FILEINFO_MIME_TYPE))->buffer($contents), RungImages::ALLOWED, true)) {
            $this->fail('image_type');
        }

        return $contents;
    }

    private function fail(string $key): never
    {
        throw new ValidationException(['image' => $this->translator->trans('ernestdefoe-ladder.lib.errors.'.$key)]);
    }
}
