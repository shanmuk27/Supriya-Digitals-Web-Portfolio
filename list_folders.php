<?php
// FILE: api/list_folders.php
// PURPOSE: Returns a list of subfolders inside the Kalyani NAS root,
//          used by the Admin Panel folder browser when linking a folder.
//          Supports drill-down navigation by accepting a relative path.
//
// Bug 12 Fix: This endpoint reveals the internal folder structure of
//             the NAS. Must be admin-only to prevent clients from
//             browsing directories they shouldn't see.

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db_config.php';
verifyAdminToken();

// ------------------------------------------------------------------
// Base directory — all browsing is strictly confined to this root.
// realpath() is called immediately so symlink tricks won't escape it.
// ------------------------------------------------------------------
$baseDir  = '/volume1/Kalyani';
$realBase = realpath($baseDir);

if ($realBase === false || !is_dir($realBase)) {
    echo json_encode(['success' => false, 'message' => 'Base directory not found on NAS.']);
    exit;
}

// ------------------------------------------------------------------
// Build the target path from the requested relative path.
//
// Security: strip ../ and ..\  before joining to prevent path
// traversal. We also verify with realpath() that the resolved
// directory actually lives inside $realBase.
// ------------------------------------------------------------------
$reqPath = isset($_GET['path']) ? $_GET['path'] : '';

// Remove any traversal sequences
$reqPath = str_replace(['../', '..' . DIRECTORY_SEPARATOR, '..\\'], '', $reqPath);
$reqPath = trim($reqPath, '/\\');

$currentDir = $realBase . ($reqPath ? DIRECTORY_SEPARATOR . $reqPath : '');
$realCurrent = realpath($currentDir);

// Reject if path doesn't exist or escapes the Kalyani root
if ($realCurrent === false
    || !is_dir($realCurrent)
    || strpos($realCurrent, $realBase) !== 0) {

    echo json_encode([
        'success' => false,
        'message' => "Invalid or unreadable path: '$reqPath'"
    ]);
    exit;
}

// ------------------------------------------------------------------
// Scan only the current directory level (not recursive).
// GLOB_ONLYDIR means we return folders, not files.
// ------------------------------------------------------------------
$folders     = [];
$rawEntries  = glob($realCurrent . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);

if ($rawEntries) {
    foreach ($rawEntries as $dir) {
        $name = basename($dir);

        // Skip Synology internal system folders
        if ($name === '@eaDir' || $name === '#recycle') continue;

        // Skip hidden folders (start with .)
        if (strpos($name, '.') === 0) continue;

        // Build the relative path back from the base
        // so the frontend can pass it back as ?path=... to drill down
        $relativePath = ltrim(str_replace($realBase, '', $dir), '/\\');

        $folders[] = [
            'name' => $name,
            'path' => $relativePath
        ];
    }
}

// Sort folders alphabetically for consistent display
usort($folders, function($a, $b) {
    return strnatcasecmp($a['name'], $b['name']);
});

echo json_encode([
    'success'      => true,
    'folders'      => $folders,
    'current_path' => $reqPath
]);
?>
