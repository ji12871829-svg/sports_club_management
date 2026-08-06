<?php
// ============================================================
//  includes/image_processor.php
//  Server-side image processing: resize, compress, WebP,
//  thumbnail generation, and lazy-load helpers.
// ============================================================

require_once __DIR__ . '/object_storage.php';

define('IMG_UPLOAD_DIR', __DIR__ . '/../uploads/images');
define('IMG_CACHE_DIR', sys_get_temp_dir() . '/apex_images');
define('IMG_MAX_WIDTH', 800);
define('IMG_MAX_HEIGHT', 800);
define('IMG_THUMB_SIZE', 150);
define('IMG_QUALITY', 82);

/**
 * Process an uploaded image: resize, compress, optionally convert to WebP.
 * Returns the file path relative to the site root.
 */
function process_uploadedImage(array $file, string $subdir = 'profile'): ?string
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime, $allowed, true)) {
        return null;
    }

    $ext = match($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        default      => 'jpg',
    };

    $destDir = IMG_UPLOAD_DIR . '/' . $subdir;
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }

    $filename = uniqid('img_', true) . '.' . $ext;
    $filepath = $destDir . '/' . $filename;

    // Resize and compress
    $result = resize_image($file['tmp_name'], $filepath, IMG_MAX_WIDTH, IMG_MAX_HEIGHT, IMG_QUALITY, $mime);
    if (!$result) {
        return null;
    }

    // Generate WebP version if GD supports it
    if (function_exists('imageWebp')) {
        $webpPath = preg_replace('/\.\w+$/', '.webp', $filepath);
        $webpSource = ($ext === 'webp') ? $filepath : $file['tmp_name'];
        if ($ext !== 'webp') {
            create_webp($file['tmp_name'], $webpPath, $mime);
        }
    }

    // Generate thumbnail
    $thumbPath = preg_replace('/\.\w+$/', '_thumb.' . $ext, $filepath);
    resize_image($file['tmp_name'], $thumbPath, IMG_THUMB_SIZE, IMG_THUMB_SIZE, IMG_QUALITY, $mime, true);

    // Return relative path for web access (works for both drivers — the
    // DB stores this string; rendering resolves it via asc_storage_url())
    return 'uploads/images/' . $subdir . '/' . $filename;
}

/**
 * Store an uploaded image through the object storage layer.
 *
 * Processes/resizes the file locally, then persists it via AscStorage so
 * the configured driver (local filesystem or S3-compatible store) decides
 * where the bytes actually live. Returns the storage key (relative path)
 * on success, or null on failure.
 */
function store_uploaded_image(array $file, string $subdir = 'profile'): ?string
{
    $relPath = process_uploadedImage($file, $subdir);
    if ($relPath === null) {
        return null;
    }

    if (AscStorage::isS3()) {
        // Push the processed file (and its webp/thumb siblings) to object storage.
        $local = __DIR__ . '/../' . $relPath;
        if (!AscStorage::put($relPath, $local)) {
            // Fall back: keep the local copy as-is so uploads never silently vanish.
            return $relPath;
        }
        // Clean up the now-remote local copy
        @unlink($local);
    }

    return $relPath;
}

/**
 * Resize an image to fit within max dimensions.
 */
function resize_image(
    string $source,
    string $dest,
    int $maxWidth,
    int $maxHeight,
    int $quality,
    string $mime,
    bool $crop = false
): bool {
    $imageInfo = @getimagesize($source);
    if ($imageInfo === false) {
        return false;
    }

    [$origWidth, $origHeight, $type] = $imageInfo;

    // Create source image resource
    $src = match($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
        IMAGETYPE_PNG  => @imagecreatefrompng($source),
        IMAGETYPE_GIF  => @imagecreatefromgif($source),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : null,
        default => null,
    };

    if (!$src) {
        return false;
    }

    if ($crop) {
        // Center crop to square
        $ratio = max($maxWidth / $origWidth, $maxHeight / $origHeight);
        $newWidth = (int)($origWidth * $ratio);
        $newHeight = (int)($origHeight * $ratio);
        $cropX = (int)(($newWidth - $maxWidth) / 2);
        $cropY = (int)(($newHeight - $maxHeight) / 2);

        $dst = imagecreatetruecolor($maxWidth, $maxHeight);
        imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, $maxWidth, $maxHeight, $maxWidth, $maxHeight);
    } else {
        // Fit within dimensions
        $ratio = min($maxWidth / $origWidth, $maxHeight / $origHeight, 1);
        $newWidth = (int)($origWidth * $ratio);
        $newHeight = (int)($origHeight * $ratio);

        $dst = imagecreatetruecolor($newWidth, $newHeight);

        // Preserve transparency for PNG/GIF
        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_GIF) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $newWidth, $newHeight, $transparent);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
    }

    // Output
    $result = match($type) {
        IMAGETYPE_JPEG => imagejpeg($dst, $dest, $quality),
        IMAGETYPE_PNG  => imagepng($dst, $dest, (int)(9 - ($quality / 11))),
        IMAGETYPE_GIF  => imagegif($dst, $dest),
        IMAGETYPE_WEBP => imagewebp($dst, $dest, $quality),
        default => imagejpeg($dst, $dest, $quality),
    };

    imagedestroy($src);
    imagedestroy($dst);

    return $result;
}

/**
 * Create a WebP version of an image.
 */
function create_webp(string $source, string $dest, string $mime): bool
{
    if (!function_exists('imagecreatefromwebp') && !function_exists('imageWebp')) {
        return false;
    }

    $src = match($mime) {
        'image/jpeg' => @imagecreatefromjpeg($source),
        'image/png'  => @imagecreatefrompng($source),
        'image/gif'  => @imagecreatefromgif($source),
        'image/webp' => @imagecreatefromwebp($source),
        default => null,
    };

    if (!$src) {
        return false;
    }

    $result = imageWebp($src, $dest, IMG_QUALITY);
    imagedestroy($src);
    return $result;
}

/**
 * Get an <img> tag with lazy loading, WebP srcset, and responsive sizing.
 */
function responsive_image(
    string $path,
    string $alt = '',
    string $class = 'img-fluid',
    array $sizes = [150, 400, 800]
): string {
    $safeKey = AscStorage::sanitizeKey($path);
    $src     = asc_storage_url($safeKey !== '' ? $safeKey : $path);
    $isLocal = !AscStorage::isS3();

    $webpPath = preg_replace('/\.\w+$/', '.webp', $path);
    $hasWebp  = $isLocal && file_exists(__DIR__ . '/../' . $webpPath);

    $srcset = '';
    if ($hasWebp) {
        $srcsetParts = [];
        foreach ($sizes as $size) {
            $thumbPath = preg_replace('/\.\w+$/', "_{$size}w.webp", $path);
            $thumbFull = __DIR__ . '/../' . $thumbPath;
            if (file_exists($thumbFull)) {
                $srcsetParts[] = asc_storage_url($thumbPath) . " {$size}w";
            }
        }
        if ($srcsetParts) {
            $srcset = ' srcset="' . htmlspecialchars(implode(', ', $srcsetParts)) . '" sizes="(max-width: 600px) 100vw, (max-width: 1024px) 50vw, ' . end($sizes) . 'px"';
        }
    }

    return '<img src="' . htmlspecialchars($src) . '"'
        . ' alt="' . htmlspecialchars($alt) . '"'
        . ' class="' . htmlspecialchars($class) . '"'
        . ' loading="lazy"'
        . ' decoding="async"'
        . $srcset
        . ' width="' . end($sizes) . '" height="' . end($sizes) . '">';
}

/**
 * Get a profile image with fallback to initials avatar.
 * Works for both local files and remote (S3) objects.
 */
function profile_image(string $path, string $name, string $class = 'rounded-circle'): string
{
    $safeKey = AscStorage::sanitizeKey($path);
    $present = false;
    if ($safeKey !== '') {
        if (AscStorage::isS3()) {
            $present = true; // remote objects are assumed present if the path is set
        } else {
            $present = file_exists(__DIR__ . '/../' . $safeKey);
        }
    }
    if ($present) {
        return responsive_image($safeKey, $name, $class, [50, 150, 300]);
    }

    // Fallback: initials avatar
    $initials = '';
    $parts = explode(' ', $name);
    foreach ($parts as $part) {
        $initials .= mb_strtoupper(mb_substr(trim($part), 0, 1));
    }
    $initials = mb_substr($initials, 0, 2);

    $bgColors = ['#14497a', '#1a5a8c', '#1d5c8f', '#0891b2', '#059669', '#d97706', '#dc2626'];
    $colorIndex = crc32($name) % count($bgColors);

    return '<div class="' . htmlspecialchars($class) . ' d-inline-flex align-items-center justify-content-center"'
        . ' style="width:2.5rem;height:2.5rem;background:' . $bgColors[$colorIndex] . ';color:#fff;font-weight:700;font-size:0.85rem;">'
        . htmlspecialchars($initials) . '</div>';
}
