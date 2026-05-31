<?php
// FILE: api/download.php  [FIXED]
// BUG FIX: Replaced file_get_contents('php://input') with $GLOBALS['_RAW_INPUT']
//           because db_config.php already reads the stream on include.
//           Also added verifyAdminToken() — download.php had NO authentication.

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json');

// Include db_config FIRST — it reads php://input into $GLOBALS['_RAW_INPUT']
require_once 'db_config.php';

// BUG FIX: download.php previously had NO authentication.
// Anyone could call this to trigger server-side ZIP creation.
// The client gallery download path uses stream_zip.php instead.
verifyAdminToken();

// BUG FIX: Use $GLOBALS['_RAW_INPUT'] — the stream is already consumed above.
// Old code: $data = json_decode(file_get_contents('php://input'), true); // returns ''
$data    = json_decode($GLOBALS['_RAW_INPUT'] ?? '', true);
$files   = $data['files']   ?? [];
$quality = $data['quality'] ?? 'high';

if (empty($files) || !is_array($files)) {
    echo json_encode(['status' => 'error', 'message' => 'No files selected']);
    exit;
}

$zipDir = __DIR__ . '/temp_zips/';
if (!is_dir($zipDir)) @mkdir($zipDir, 0777, true);

// Auto-cleanup ZIPs older than 2 hours
foreach (glob($zipDir . '*.zip') as $oldZip) {
    if (time() - filemtime($oldZip) > 7200) @unlink($oldZip);
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
    $cleanDbPath = ltrim(
        str_replace(
            ['https://supriyadigitals.store/', 'http://supriyadigitals.store/'],
            '', $dbPath
        ), '/'
    );

    $truePath = '/volume1/' . $cleanDbPath;
    if (!file_exists($truePath)) $truePath = __DIR__ . '/../' . $cleanDbPath;
    if (!file_exists($truePath)) continue;

    $fileName = basename($truePath);

    if ($quality === 'low') {
        $mtime     = filemtime($truePath);
        $etag      = md5($truePath . $mtime . 1200);
        $cacheFile = __DIR__ . '/cache/lb_' . $etag . '.jpg';
        if (!file_exists($cacheFile)) {
            shell_exec("convert -define jpeg:size=1200x1200 -limit thread 1 -limit memory 256MiB " .
                escapeshellarg($truePath) . " -auto-orient -resize 1200x1200\\> -quality 88 " .
                escapeshellarg($cacheFile));
        }
        if (file_exists($cacheFile)) $zip->addFile($cacheFile, $fileName);
    } else {
        $zip->addFile($truePath, $fileName);
    }

    $fileCount++;
    if ($fileCount % 15 === 0) { $zip->close(); $zip->open($zipPath, ZipArchive::APPEND); }
}

$zip->close();

if ($fileCount === 0) {
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
