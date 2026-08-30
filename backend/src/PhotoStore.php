<?php
declare(strict_types=1);

/**
 * Profile pictures for contacts and phones.
 *
 * Uploads are never stored as received. Every image is decoded, cropped square
 * and re-encoded, which:
 *
 *   - strips EXIF, including the GPS coordinates a phone camera embeds. These
 *     are photographs of children; the location they were taken has no business
 *     sitting in a file the app hands back out.
 *   - guarantees the bytes really are an image, so a file that merely claims to
 *     be a JPEG cannot be stored and later served as something executable.
 *   - bounds the size, so one enormous upload can't fill the disk.
 *
 * Files live outside the docroot and are served through the app, so a picture
 * of a child is never reachable without signing in.
 */
final class PhotoStore
{
    /** Stored square, at a size that still looks sharp on a retina screen. */
    private const SIZE = 512;
    private const QUALITY = 82;
    private const MAX_UPLOAD_BYTES = 12 * 1024 * 1024;

    /** Only formats GD can safely decode. */
    private const ALLOWED = [
        IMAGETYPE_JPEG => 'jpeg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF => 'gif',
    ];

    public function path(): string
    {
        return rtrim(getenv('PHOTO_PATH') ?: '/var/lib/twocans/photos', '/');
    }

    /**
     * Take an uploaded file and store it.
     *
     * @param  array $upload one entry from $_FILES
     * @return array{file:?string,error:?string}
     */
    public function store(array $upload): array
    {
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_NO_FILE) {
            return ['file' => null, 'error' => null];        // nothing chosen
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return ['file' => null, 'error' => 'That picture is too big — try one under 12MB.'];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return ['file' => null, 'error' => "That picture didn't upload properly. Try again."];
        }

        $tmp = (string) ($upload['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp)) {
            return ['file' => null, 'error' => 'That upload was not accepted.'];
        }
        if (filesize($tmp) > self::MAX_UPLOAD_BYTES) {
            return ['file' => null, 'error' => 'That picture is too big — try one under 12MB.'];
        }

        // Trust the bytes, not the filename or the browser's content type.
        $info = @getimagesize($tmp);
        if ($info === false || !isset(self::ALLOWED[$info[2]])) {
            return ['file' => null, 'error' => "That doesn't look like a photo. Use a JPEG, PNG or WebP."];
        }

        $image = $this->decode($tmp, $info[2]);
        if ($image === null) {
            return ['file' => null, 'error' => "That picture couldn't be read."];
        }

        $square = $this->squareCrop($image);
        imagedestroy($image);

        $name = bin2hex(random_bytes(16)) . '.jpg';
        $target = $this->path() . '/' . $name;

        // JPEG for everything: one format to serve, and it drops any alpha
        // channel that would otherwise render as black on a coloured avatar.
        $ok = imagejpeg($square, $target, self::QUALITY);
        imagedestroy($square);

        if (!$ok) {
            return ['file' => null, 'error' => "Couldn't save that picture."];
        }

        @chmod($target, 0644);

        return ['file' => $name, 'error' => null];
    }

    private function decode(string $file, int $type): ?GdImage
    {
        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
            IMAGETYPE_PNG => @imagecreatefrompng($file),
            IMAGETYPE_WEBP => @imagecreatefromwebp($file),
            IMAGETYPE_GIF => @imagecreatefromgif($file),
            default => false,
        };

        return $image === false ? null : $image;
    }

    /** Centre-crop to a square, then scale to a fixed size. */
    private function squareCrop(GdImage $source): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);

        // Bias the crop slightly above centre — faces sit high in a portrait,
        // and a dead-centre square tends to cut foreheads off.
        $x = (int) (($width - $side) / 2);
        $y = (int) max(0, (($height - $side) / 2) * 0.6);

        $target = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, $x, $y, self::SIZE, self::SIZE, $side, $side);

        return $target;
    }

    /** Absolute path of a stored photo, or null if it isn't there. */
    public function file(?string $name): ?string
    {
        $name = trim((string) $name);

        // Names are generated here, so anything that isn't in that shape is
        // either stale or someone probing for a path traversal.
        if ($name === '' || !preg_match('/^[a-f0-9]{32}\.jpg$/', $name)) {
            return null;
        }

        $path = $this->path() . '/' . $name;

        return is_readable($path) ? $path : null;
    }

    public function delete(?string $name): void
    {
        $file = $this->file($name);
        if ($file !== null) {
            @unlink($file);
        }
    }
}
