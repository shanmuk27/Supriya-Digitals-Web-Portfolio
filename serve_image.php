<?php
// FILE: api/serve_image.php
// PURPOSE: Resolves an image path, generates a resized/cached version,
//          and serves it with proper HTTP caching headers.
//
// Size standardisation (matches cache_worker.php exactly):
//   Thumbnail : 400px wide/tall max, quality 80
//   Lightbox  : 1200px wide/tall max, quality 88
//
// ETag formula (all files must use this EXACT formula):
//   md5($resolvedAbsolutePath . filemtime($resolvedAbsolutePath) . $maxWidth)

error_reporting(0);
ini_set('display_errors', 0);
ini_set('memory_limit', '512M');

// ── Traffic protection ──────────────────────────────────
require_once __DIR__ . '/traffic_manager.php';
TrafficManager::check(true); // skip concurrency gate on image requests (fast path)
// ───────────────────────────────────────────────────────

$file            = isset($_GET['file']) ? urldecode($_GET['file']) : '';
$isFastDownload  = isset($_GET['dl'])    && $_GET['dl']    == '1';
$isThumb         = isset($_GET['thumb']) && $_GET['thumb'] == '1';

// Strip domain prefix if a full URL was passed
$file = str_replace(
    ['https://supriyadigitals.store/', 'http://supriyadigitals.store/'],
    '',
    $file
);
$file = ltrim($file, '/');

// ------------------------------------------------------------------
// Resolve the relative path to an absolute filesystem path.
// Try locations in the same order every time so the ETag is stable.
// ------------------------------------------------------------------
$resolvedFile = $file;

if (!file_exists($resolvedFile)) {
    if (file_exists(__DIR__ . '/../' . $file)) {
        $resolvedFile = realpath(__DIR__ . '/../' . $file);
    } elseif (file_exists('/volume1/' . $file)) {
        $resolvedFile = '/volume1/' . $file;
    } elseif (file_exists('/volume2/' . $file)) {
        $resolvedFile = '/volume2/' . $file;
    }
}
$file = $resolvedFile;

// Guard: file must exist and be a real image (>10 bytes)
if (!$file || !file_exists($file) || filesize($file) < 10) {
    http_response_code(404);
    exit;
}

// ------------------------------------------------------------------
// Size / quality settings — standardised to match cache_worker.php
// ------------------------------------------------------------------
$maxWidth = $isThumb ? 400  : 1200;
$quality  = $isThumb ? 80   : 88;
$prefix   = $isThumb ? 'th_': 'lb_';

// ------------------------------------------------------------------
// ETag — uses absolute resolved $file path and $maxWidth.
// cache_worker.php and cache_manager.php use the same formula.
// ------------------------------------------------------------------
$etag = md5($file . filemtime($file) . $maxWidth);

// Return 304 Not Modified if browser already has this version
if (!$isFastDownload
    && isset($_SERVER['HTTP_IF_NONE_MATCH'])
    && trim($_SERVER['HTTP_IF_NONE_MATCH']) === '"' . $etag . '"') {
    header("HTTP/1.1 304 Not Modified");
    exit;
}

// ------------------------------------------------------------------
// Helper: serve a file with correct headers
// ------------------------------------------------------------------
function serve_file($filePath, $isFastDownload, $etag) {
    $mime = function_exists('mime_content_type')
          ? (@mime_content_type($filePath) ?: 'image/jpeg')
          : 'image/jpeg';

    header('Content-Type: '   . $mime);
    header('Content-Length: ' . filesize($filePath));

    if ($isFastDownload) {
        header('Content-Disposition: attachment; filename="DOWNLOAD_' . basename($filePath) . '"');
    } else {
        header('Cache-Control: public, max-age=2592000'); // 30 days
        header('ETag: "' . $etag . '"');
    }

    if (ob_get_level()) ob_clean();
    flush();
    readfile($filePath);
    exit;
}

// ------------------------------------------------------------------
// Cache directory setup
// ------------------------------------------------------------------
$cacheDir  = __DIR__ . '/cache/';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);

$cacheFile = $cacheDir . $prefix . $etag . '.jpg';

// Serve immediately if already cached
if (file_exists($cacheFile)) {
    serve_file($cacheFile, $isFastDownload, $etag);
}

// ------------------------------------------------------------------
// Cache miss — generate the resized version.
//
// 1. STRICT 3-WORKER QUEUE
//    Prevents more than 3 simultaneous ImageMagick processes,
//    which would saturate the NAS CPU and RAM.
// ------------------------------------------------------------------
$lockFile = $cacheFile . '.lock';
$fpLock   = fopen($lockFile, 'w');

if (flock($fpLock, LOCK_EX | LOCK_NB)) {

    // Global queue lock so we can count active workers safely
    $globalLock = $cacheDir . 'global_queue.lock';
    $fpGlobal   = fopen($globalLock, 'w');
    flock($fpGlobal, LOCK_EX);

    // Clean up dead worker tokens (older than 20 seconds)
    $tokens = glob($cacheDir . 'worker_*.token');
    foreach ($tokens as $tk) {
        if (time() - filemtime($tk) > 20) @unlink($tk);
    }

    // Wait in line if 3 workers are already running (max 3 parallel)
    while (count(glob($cacheDir . 'worker_*.token')) >= 3) {
        flock($fpGlobal, LOCK_UN);
        sleep(1);
        flock($fpGlobal, LOCK_EX);
    }

    // Register this process as an active worker
    $tokenFile = $cacheDir . 'worker_' . getmypid() . '.token';
    file_put_contents($tokenFile, '1');

    flock($fpGlobal, LOCK_UN);
    fclose($fpGlobal);

    // ------------------------------------------------------------------
    // 2. IMAGEMAGICK — Primary resize method
    //    -define jpeg:size  : RAM optimisation hint, load only as much
    //                         of the JPEG as needed
    //    -limit thread 1    : Prevent multi-threading which can OOM NAS
    //    -limit memory 256M : Hard RAM cap per process
    //    -auto-orient       : Fix EXIF rotation on phone photos
    //    -resize WxH\>      : Shrink only, never upscale
    // ------------------------------------------------------------------
    if (function_exists('exec')
        && (@file_exists('/usr/bin/convert') || @file_exists('/bin/convert'))) {

        $sizeHint = "{$maxWidth}x{$maxWidth}";
        $cmd = "convert"
             . " -define jpeg:size={$sizeHint}"
             . " -limit thread 1"
             . " -limit memory 256MiB"
             . " -limit map 256MiB"
             . " " . escapeshellarg($file)
             . " -auto-orient"
             . " -resize {$sizeHint}\>"
             . " -quality {$quality}"
             . " " . escapeshellarg($cacheFile);
        exec($cmd);
    }

    // ------------------------------------------------------------------
    // 3. PHP GD FALLBACK
    //    Only used if ImageMagick is unavailable or failed.
    //    Skipped for files > 5 MB to protect the NAS from OOM crashes.
    // ------------------------------------------------------------------
    if (!file_exists($cacheFile) && filesize($file) < 5242880) {
        try {
            $ext   = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $image = ($ext === 'png')
                   ? @imagecreatefrompng($file)
                   : @imagecreatefromjpeg($file);

            if ($image) {
                $origWidth  = imagesx($image);
                $origHeight = imagesy($image);

                if ($origWidth > $maxWidth) {
                    $newWidth  = $maxWidth;
                    $newHeight = (int) floor($origHeight * ($maxWidth / $origWidth));
                    $newImage  = imagecreatetruecolor($newWidth, $newHeight);

                    if ($isThumb) {
                        imagecopyresized(
                            $newImage, $image,
                            0, 0, 0, 0,
                            $newWidth, $newHeight, $origWidth, $origHeight
                        );
                    } else {
                        imagecopyresampled(
                            $newImage, $image,
                            0, 0, 0, 0,
                            $newWidth, $newHeight, $origWidth, $origHeight
                        );
                    }
                    @imagejpeg($newImage, $cacheFile, $quality);
                    imagedestroy($newImage);
                } else {
                    // Image is already smaller than maxWidth — save as-is
                    @imagejpeg($image, $cacheFile, $quality);
                }
                imagedestroy($image);
            }
        } catch (Throwable $e) {
            // GD failed — will fall through to serve original below
        }
    }

    @unlink($tokenFile);
    flock($fpLock, LOCK_UN);
    fclose($fpLock);
    @unlink($lockFile);

} else {
    // Another worker is already generating this exact file — wait for it
    fclose($fpLock);
    $attempts = 0;
    while (!file_exists($cacheFile) && $attempts < 15) {
        sleep(1);
        $attempts++;
    }
}

// Serve cached file if generation succeeded, else serve original
if (file_exists($cacheFile)) {
    serve_file($cacheFile, $isFastDownload, $etag);
} else {
    // Last resort: serve the original full-resolution file
    // This protects the gallery from showing broken images
    serve_file($file, $isFastDownload, $etag);
}
?>
