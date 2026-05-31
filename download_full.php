<?php
// FILE: api/download_full.php
// PURPOSE: Streams an original full-resolution file directly to the
//          client as a download (Content-Disposition: attachment).
//          Used by the lightbox "Original Quality" single-file download.
//
//          Unlike download.php (which creates a ZIP), this streams
//          a single file directly without creating any temp files.

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

error_reporting(0);
ini_set('display_errors', 0);

$file = isset($_GET['file']) ? urldecode($_GET['file']) : '';

if (empty($file)) {
    http_response_code(400);
    exit('No file specified');
}

// ------------------------------------------------------------------
// Strip domain prefix if a full URL was passed
// ------------------------------------------------------------------
$file = str_replace(
    ['https://supriyadigitals.store/', 'http://supriyadigitals.store/'],
    '',
    $file
);
$file = ltrim($file, '/');

// ------------------------------------------------------------------
// Resolve relative path to absolute filesystem path.
// Try all possible locations in the same order as serve_image.php
// so behaviour is consistent across the whole system.
// ------------------------------------------------------------------
$resolvedFile = null;

if (file_exists($file)) {
    $resolvedFile = $file;
} elseif (file_exists(__DIR__ . '/../' . $file)) {
    $resolvedFile = realpath(__DIR__ . '/../' . $file);
} elseif (file_exists('/volume1/' . $file)) {
    $resolvedFile = '/volume1/' . $file;
} elseif (file_exists('/volume2/' . $file)) {
    $resolvedFile = '/volume2/' . $file;
}

if (!$resolvedFile || !file_exists($resolvedFile)) {
    http_response_code(404);
    exit('File not found on NAS.');
}

// ------------------------------------------------------------------
// Safety check: make sure the resolved path is inside /volume1 or
// /volume2 to prevent directory traversal attacks.
// ------------------------------------------------------------------
$realResolved = realpath($resolvedFile);
$allowedRoots = ['/volume1/', '/volume2/'];
$isAllowed    = false;

foreach ($allowedRoots as $root) {
    if (strpos($realResolved, $root) === 0) {
        $isAllowed = true;
        break;
    }
}

if (!$isAllowed) {
    http_response_code(403);
    exit('Access denied.');
}

// ------------------------------------------------------------------
// Stream the file
//    ignore_user_abort(false) : Stop sending if the client disconnects
//                               (saves NAS bandwidth on cancelled downloads)
//    set_time_limit(0)        : No timeout for large files
// ------------------------------------------------------------------
ignore_user_abort(false);
set_time_limit(0);

$fileSize = filesize($resolvedFile);
$fileName = basename($resolvedFile);

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="ORIGINAL_' . $fileName . '"');
header('Content-Length: ' . $fileSize);
header('Cache-Control: no-cache');

// Flush headers before we start reading the file
if (ob_get_level()) ob_end_clean();
flush();

// Stream in 8 KB chunks — small enough not to OOM, large enough
// to keep the transfer efficient.
$fp = fopen($resolvedFile, 'rb');
if (!$fp) {
    http_response_code(500);
    exit('Could not open file.');
}

while (!feof($fp)) {
    if (connection_aborted()) {
        fclose($fp);
        exit;
    }
    echo fread($fp, 8192);
    flush();
}

fclose($fp);
?>
