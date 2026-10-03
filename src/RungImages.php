<?php

namespace Ernestdefoe\Ladder;

use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Intervention\Image\ImageManager;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The artwork on each rung's banner card.
 *
 * Files live on the `flarum-assets` disk beside the forum logo, so the web
 * server serves them directly and a cache clear never touches them. Every
 * upload is re-encoded to WebP at card size: an admin dropping in a 5MB PNG
 * straight from an image generator should not make the Ranks page a 60MB
 * download.
 */
class RungImages
{
    public const QUALITY = 82;

    /** A card is drawn about 160px wide; twice that covers high-DPI screens. */
    public const MAX_WIDTH = 360;

    public const MAX_BYTES = 8 * 1024 * 1024;

    public const ALLOWED = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    private Filesystem $disk;

    public function __construct(Factory $filesystem, private ImageManager $images)
    {
        $this->disk = $filesystem->disk('flarum-assets');
    }

    /** @return string the stored file name */
    public function put(int $rungId, UploadedFileInterface $file): string
    {
        $encoded = (string) $this->images
            ->read($file->getStream()->getContents())
            ->scaleDown(width: self::MAX_WIDTH)
            ->toWebp(self::QUALITY);

        // A fresh name per upload, so a browser holding the old picture in
        // its cache fetches the new one instead of showing it for a week.
        $name = $this->prefix($rungId).bin2hex(random_bytes(4)).'.webp';

        $this->forget($rungId);
        $this->disk->put($name, $encoded);

        return $name;
    }

    /**
     * Remove every image belonging to a rung.
     *
     * Matched by prefix, not by the recorded path, so an upload that wrote its
     * file and then failed before the row saved is swept up by the next one.
     */
    public function forget(int $rungId): void
    {
        $prefix = $this->prefix($rungId);

        foreach ($this->disk->files() as $file) {
            if (str_starts_with(basename($file), $prefix)) {
                $this->disk->delete($file);
            }
        }
    }

    public function url(?string $path): ?string
    {
        return $path ? $this->disk->url($path) : null;
    }

    private function prefix(int $rungId): string
    {
        return 'ladder-rung-'.$rungId.'-';
    }
}
