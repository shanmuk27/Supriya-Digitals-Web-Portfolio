<?php
// FILE: api/find_duplicates.php
// PURPOSE: Scans the cache folder for duplicate image files using a
//          two-pass algorithm (size first, then MD5) to find wasted space.
//          Output is plain text for easy reading in the browser or terminal.
//
// Bug 12 Fix: This file reads all cache filenames and sizes.
//             Must be admin-only to prevent information disclosure.

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db_config.php';
verifyAdminToken();

header('Content-Type: text/plain');

ini_set('memory_limit', '512M');
set_time_limit(300);

$cacheDir = __DIR__ . '/cache/';

if (!is_dir($cacheDir)) {
    echo "Cache folder not found.\n";
    exit;
}

echo "Scanning for duplicate image content (Ultra-Fast Mode)...\n";
echo "============================================================\n\n";

// ------------------------------------------------------------------
// Pass 1: Group all cache files by their exact byte size.
//         Files with unique sizes are guaranteed to be unique —
//         no need to hash them at all. Only groups with 2+ files
//         at the same size get hashed in Pass 2.
// ------------------------------------------------------------------
$filesBySize = [];

$dir = opendir($cacheDir);
while (($file = readdir($dir)) !== false) {
    if ($file === '.' || $file === '..') continue;

    $path = $cacheDir . $file;

    if (!is_file($path)) continue;

    $size = filesize($path);
    $filesBySize[$size][] = $file;
}
closedir($dir);

$duplicateCount = 0;
$wastedSpace    = 0;
$groupsChecked  = 0;

// ------------------------------------------------------------------
// Pass 2: For each size group with more than one file, compute MD5
//         hashes and find files with identical content.
// ------------------------------------------------------------------
foreach ($filesBySize as $size => $files) {
    if (count($files) <= 1) continue;

    $groupsChecked++;
    $hashes = [];

    foreach ($files as $file) {
        $hash           = md5_file($cacheDir . $file);
        $hashes[$hash][] = $file;
    }

    foreach ($hashes as $hash => $dupes) {
        if (count($dupes) > 1) {
            $duplicateCount += (count($dupes) - 1);
            $wastedSpace    += $size * (count($dupes) - 1);

            echo "Identical copies found (" . round($size / 1024, 2) . " KB each):\n";
            foreach ($dupes as $d) {
                echo "  -> $d\n";
            }
            echo "\n";
        }
    }
}

echo "============================================================\n";
echo "Size groups checked  : $groupsChecked\n";
echo "Total Duplicate Files: $duplicateCount\n";
echo "Total Wasted Storage : " . round($wastedSpace / 1048576, 2) . " MB\n";
?>
