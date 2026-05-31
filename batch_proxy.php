<?php
// FILE: api/batch_proxy.php
// PURPOSE: Reads multiple local image files from the ../img/ folder,
//          encodes them as base64 data URIs, and returns them all in
//          a single JSON response for the Admin Panel.

// 1. INCREASE MEMORY — Crucial for base64 encoding large images
ini_set('memory_limit', '512M');
error_reporting(0);

// 2. CORS HEADERS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, ngrok-skip-browser-warning");

// 3. HANDLE PREFLIGHT
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

header("Content-Type: application/json");

require_once 'db_config.php';
verifyAdminToken();

// 4. GET INPUT — list of filenames to encode
// FIXED: Use $GLOBALS['_RAW_INPUT'] — already read by db_config.php.
// Do NOT call file_get_contents('php://input') here again.
$input = [];
if (!empty($GLOBALS['_RAW_INPUT'])) {
    $decoded = json_decode($GLOBALS['_RAW_INPUT'], true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

$files = $input['files'] ?? [];

if (empty($files) || !is_array($files)) {
    echo json_encode(['success' => false, 'message' => 'No files array provided.']);
    exit;
}

// Resolve the img directory once
$imgDir = realpath(__DIR__ . '/../img/');

if ($imgDir === false || !is_dir($imgDir)) {
    echo json_encode(['success' => false, 'message' => 'img directory not found.']);
    exit;
}

// Only serve recognised image MIME types
$allowedMimes = [
    'image/jpeg',
    'image/jpg',
    'image/png',
    'image/webp',
    'image/gif',
    'image/svg+xml'
];

$images = [];

// 5. PROCESS EACH FILE SAFELY
foreach ($files as $file) {
    // basename() strips any path components — prevents ../../etc/passwd
    $cleanName = basename((string)$file);
    if (empty($cleanName)) continue;

    $fullPath = $imgDir . DIRECTORY_SEPARATOR . $cleanName;
    $realPath = realpath($fullPath);

    // Confirm file exists strictly inside the img directory
    if ($realPath === false || strpos($realPath, $imgDir) !== 0) continue;
    if (!is_file($realPath)) continue;

    // Validate MIME type
    $mime = function_exists('mime_content_type') ? @mime_content_type($realPath) : '';
    if (!in_array($mime, $allowedMimes, true)) continue;

    // Direct file read — fastest method, avoids GD Library memory crashes
    $data = file_get_contents($realPath);
    if ($data === false) continue;

    $images[$cleanName] = 'data:' . $mime . ';base64,' . base64_encode($data);
}

// 6. RETURN ALL ENCODED IMAGES IN ONE RESPONSE
echo json_encode([
    'success' => true,
    'count'   => count($images),
    'images'  => $images
]);
?>
