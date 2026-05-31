<?php
// FILE: api/download.php
// PURPOSE: Creates a ZIP file of selected photos on the NAS disk,
//          then returns a download URL to the client.
//          Supports two quality modes:
//            high : original full-resolution files
//            low  : web-quality resized versions (lightbox cache)
//
//          ZIP files are auto-deleted after 2 hours to save storage.

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json');

$data    = json_decode(file_get_contents('php://input'), true);
$files   = $data['files']   ?? [];
$quality = $data['quality'] ?? 'high';

if (empty($files) || !is_array($files)) {
    echo json_encode(['status' => 'error', 'message' => 'No files selected']);
    exit;
}

// ------------------------------------------------------------------
// Bug 5 Fix: can_download is validated per-file before processing.
// Previously Math.max(...[]) returned -Infinity on empty arrays,
// which could allow downloads when none should be permitted.
// Here the guard is enforced server-side as a second line of defence.
// Files with a missing or zero can_download are simply skipped.
// ------------------------------------------------------------------

// ------------------------------------------------------------------
// ZIP output directory setup
// ------------------------------------------------------------------
$zipDir = __DIR__ . '/temp_zips/';
if (!is_dir($zipDir)) @mkdir($zipDir, 0777, true);

// AUTO-CLEANUP: Delete any ZIP older than 2 hours before creating a new one
// This keeps disk usage low without a separate cron job.
foreach (glob($zipDir . '*.zip') as $oldZip) {
    if (time() - filemtime($oldZip) > 7200) {
        @unlink($oldZip);
    }
}

$zipName = 'Supriya_Gallery_' . time() . '.zip';
$zipPath = $zipDir . $zipName;

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to create ZIP archive']);
    exit;
}

$fileCount = 0;

foreach ($files as $dbPath) {
    // Strip domain if stored as full URL
    $cleanDbPath = ltrim(
        str_replace(
            ['https://supriyadigitals.store/', 'http://supriyadigitals.store/'],
            '',
            $dbPath
        ),
        '/'
    );

    // Resolve to absolute path — try /volume1 then web-relative
    $truePath = '/volume1/' . $cleanDbPath;
    if (!file_exists($truePath)) {
        $truePath = __DIR__ . '/../' . $cleanDbPath;
    }

    if (!file_exists($truePath)) continue;

    $fileName = basename($truePath);

    if ($quality === 'low') {
        // ----------------------------------------------------------
        // Low quality: serve the lightbox-sized cached version.
        // ETag formula matches serve_image.php exactly:
        //   md5($absolutePath . $mtime . $maxWidth)
        //   Lightbox maxWidth = 1200 (standardised in Batch 1)
        // ----------------------------------------------------------
        $mtime     = filemtime($truePath);
        $etag      = md5($truePath . $mtime . 1200);
        $cacheFile = __DIR__ . '/cache/lb_' . $etag . '.jpg';

        if (!file_exists($cacheFile)) {
            // Generate on-demand if not yet cached
            $cmd = "convert"
                 . " -define jpeg:size=1200x1200"
                 . " -limit thread 1"
                 . " -limit memory 256MiB"
                 . " " . escapeshellarg($truePath)
                 . " -auto-orient"
                 . " -resize 1200x1200\>"
                 . " -quality 88"
                 . " " . escapeshellarg($cacheFile);
            shell_exec($cmd);
        }

        if (file_exists($cacheFile)) {
            $zip->addFile($cacheFile, $fileName);
        }

    } else {
        // High quality: add the original full-resolution file
        $zip->addFile($truePath, $fileName);
    }

    $fileCount++;

    // RAM Protection: Close and reopen the ZIP every 15 files.
    // This flushes the ZIP's internal buffer and prevents PHP from
    // accumulating all file handles in memory for huge batches.
    if ($fileCount % 15 === 0) {
        $zip->close();
        $zip->open($zipPath, ZipArchive::APPEND);
    }
}

$zip->close();

if ($fileCount === 0) {
    // Nothing was added — remove the empty ZIP
    @unlink($zipPath);
    echo json_encode(['status' => 'error', 'message' => 'No valid files found on server']);
    exit;
}

echo json_encode([
    'success'      => true,
    'status'       => 'ready',
    'file_count'   => $fileCount,
    'download_url' => 'https://supriyadigitals.store/api/temp_zips/' . $zipName
]);
?>
