<?php
// FILE: api/clear_cache.php
// PURPOSE: Deletes all generated thumbnail (.jpg) and ZIP (.zip) files
//          from the cache folder to free up NAS storage.
//          Should only be called from the Admin Panel.

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db_config.php';

// Bug 12 Fix: Cache clearing is an admin-only destructive operation.
// Without this, anyone who finds the URL can wipe the entire cache.
verifyAdminToken();

header('Content-Type: application/json');

$cacheDir    = __DIR__ . '/cache/';
$deletedFiles = 0;
$freedSpace  = 0;

if (!is_dir($cacheDir)) {
    echo json_encode(['success' => true, 'message' => 'Cache folder does not exist. Nothing to clear.']);
    exit;
}

// Use opendir() instead of glob() to prevent PHP from loading
// 30,000 filenames into RAM at once, which would crash the NAS.
$dir = opendir($cacheDir);

if (!$dir) {
    echo json_encode(['success' => false, 'message' => 'Could not open cache directory.']);
    exit;
}

while (($file = readdir($dir)) !== false) {
    // Skip . and .. entries
    if ($file === '.' || $file === '..') continue;

    $filePath = $cacheDir . $file;

    if (!is_file($filePath)) continue;

    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    // Only delete generated cache files — jpg thumbnails and zip downloads.
    // Never delete .lock, .token or .json tracking files as those
    // are needed for the worker queue and live traffic tracking.
    if (in_array($ext, ['jpg', 'zip'])) {
        $freedSpace += filesize($filePath);
        unlink($filePath);
        $deletedFiles++;
    }
}

closedir($dir);

// Human-readable freed space display
$freedSpaceMB  = $freedSpace / 1048576;
$displaySpace  = ($freedSpaceMB > 1024)
               ? round($freedSpaceMB / 1024, 2) . ' GB'
               : round($freedSpaceMB, 2) . ' MB';

echo json_encode([
    'success' => true,
    'message' => "Cleaned $deletedFiles files. Freed up $displaySpace of space!"
]);
?>
