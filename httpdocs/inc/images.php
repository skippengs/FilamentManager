<?php
declare(strict_types=1);
if (!defined('FILAMENT')) { http_response_code(403); exit('Forbidden'); }

/*
 * Photo uploads.
 *
 * A phone camera hands over a 4 MB picture of a spool. What gets stored is a
 * scaled down JPEG plus a small thumbnail for the overview, with the EXIF
 * data dropped along the way - that also removes the GPS tag your phone
 * quietly put in there.
 *
 * Without the GD extension the original file is kept as it came in. The app
 * still works, the uploads folder just fills up faster.
 */

function hasImageLibrary(): bool
{
    return extension_loaded('gd') && function_exists('imagecreatetruecolor');
}

/** Extension per mime type; anything not in here is refused. */
const ALLOWED_IMAGE_TYPES = [
    'image/jpeg' => 'jpg',
    'image/pjpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

function uploadDirReady(): bool
{
    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0755, true);
    }

    return is_dir(UPLOAD_DIR) && is_writable(UPLOAD_DIR);
}

/** Thumbnail name that belongs to a stored photo: abc123.jpg -> abc123_t.jpg */
function thumbName(string $filename): string
{
    $dot = strrpos($filename, '.');
    return $dot === false
        ? $filename . '_t'
        : substr($filename, 0, $dot) . '_t' . substr($filename, $dot);
}

/**
 * The thumbnail if one was made, otherwise the full size picture.
 * Keeps the templates from having to care whether GD was available.
 */
function photoThumbUrl(string $filename): string
{
    $thumb = thumbName($filename);
    return is_file(UPLOAD_DIR . '/' . $thumb)
        ? UPLOAD_URL . '/' . $thumb
        : UPLOAD_URL . '/' . $filename;
}

function photoUrl(string $filename): string
{
    return UPLOAD_URL . '/' . $filename;
}

/** Explains an upload that did not arrive, in words rather than a number. */
function uploadErrorMessage(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That photo is larger than the server accepts.',
        UPLOAD_ERR_PARTIAL                        => 'The photo only arrived halfway. Try again.',
        UPLOAD_ERR_NO_FILE                        => 'No photo was selected.',
        UPLOAD_ERR_NO_TMP_DIR                     => 'The server has no temporary folder for uploads.',
        UPLOAD_ERR_CANT_WRITE                     => 'The server could not write the photo to disk.',
        UPLOAD_ERR_EXTENSION                      => 'A server extension blocked the upload.',
        default                                   => 'The photo could not be uploaded.',
    };
}

/**
 * Takes one entry from $_FILES and stores it.
 *
 * @return string the stored filename
 * @throws RuntimeException when the file is not a usable image
 */
function storeUploadedPhoto(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException(uploadErrorMessage((int)$file['error']));
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('That upload did not come from the form.');
    }

    if ((int)$file['size'] > MAX_UPLOAD_BYTES) {
        throw new RuntimeException(
            'That photo is bigger than ' . round(MAX_UPLOAD_BYTES / 1048576) . ' MB.'
        );
    }

    // Trust what the bytes say, not the name or the browser's mime type.
    $info = @getimagesize($file['tmp_name']);
    if ($info === false || empty($info['mime']) || !isset(ALLOWED_IMAGE_TYPES[$info['mime']])) {
        throw new RuntimeException('Only JPEG, PNG and WebP photos can be uploaded.');
    }

    if (!uploadDirReady()) {
        throw new RuntimeException('The uploads folder is not writable. Give it write permission in Plesk.');
    }

    // A random name: nobody can guess their way through the photos, and two
    // pictures called IMG_0001.jpg never collide.
    $stem = bin2hex(random_bytes(8));

    if (!hasImageLibrary()) {
        $name = $stem . '.' . ALLOWED_IMAGE_TYPES[$info['mime']];
        if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $name)) {
            throw new RuntimeException('The photo could not be saved.');
        }
        return $name;
    }

    $image = loadImage($file['tmp_name'], $info['mime']);
    if ($image === null) {
        throw new RuntimeException('That photo could not be read.');
    }

    $image = applyExifOrientation($image, $file['tmp_name'], $info['mime']);

    $name = $stem . '.jpg';
    $full = resizeToFit($image, PHOTO_MAX_EDGE);
    imagejpeg($full, UPLOAD_DIR . '/' . $name, 82);
    imagedestroy($full);

    $thumb = resizeToFit($image, THUMB_MAX_EDGE);
    imagejpeg($thumb, UPLOAD_DIR . '/' . thumbName($name), 78);
    imagedestroy($thumb);

    imagedestroy($image);

    return $name;
}

function loadImage(string $path, string $mime): ?GdImage
{
    $image = match ($mime) {
        'image/jpeg', 'image/pjpeg' => @imagecreatefromjpeg($path),
        'image/png'                 => @imagecreatefrompng($path),
        'image/webp'                => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default                     => false,
    };

    return $image instanceof GdImage ? $image : null;
}

/**
 * Phones store the picture the way the sensor saw it and add a tag saying
 * how far to turn it. Saving as JPEG drops that tag, so turn it here.
 */
function applyExifOrientation(GdImage $image, string $path, string $mime): GdImage
{
    if ($mime !== 'image/jpeg' && $mime !== 'image/pjpeg') {
        return $image;
    }

    if (!function_exists('exif_read_data')) {
        return $image;
    }

    $exif = @exif_read_data($path);
    $orientation = (int)($exif['Orientation'] ?? 1);

    $rotated = match ($orientation) {
        3       => imagerotate($image, 180, 0),
        6       => imagerotate($image, -90, 0),
        8       => imagerotate($image, 90, 0),
        default => null,
    };

    if ($rotated instanceof GdImage) {
        imagedestroy($image);
        return $rotated;
    }

    return $image;
}

/**
 * Scales down so the longest edge is at most $maxEdge. Pictures that are
 * already smaller are copied as they are rather than blown up.
 */
function resizeToFit(GdImage $image, int $maxEdge): GdImage
{
    $w = imagesx($image);
    $h = imagesy($image);

    $scale = min(1.0, $maxEdge / max($w, $h));
    $newW  = max(1, (int)round($w * $scale));
    $newH  = max(1, (int)round($h * $scale));

    $out = imagecreatetruecolor($newW, $newH);

    // A white floor, so a PNG with transparency does not turn into a black
    // rectangle once it is saved as JPEG.
    imagefilledrectangle($out, 0, 0, $newW, $newH, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $image, 0, 0, 0, 0, $newW, $newH, $w, $h);

    return $out;
}

/** Removes a stored photo and its thumbnail from disk. */
function deletePhotoFiles(string $filename): void
{
    // Only ever touch plain names inside the uploads folder, never a path
    // that walks out of it.
    if ($filename === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $filename) || str_contains($filename, '..')) {
        return;
    }

    foreach ([$filename, thumbName($filename)] as $name) {
        $path = UPLOAD_DIR . '/' . $name;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
