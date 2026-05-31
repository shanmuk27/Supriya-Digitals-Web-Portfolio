<?php
// FILE: api/image_proxy.php
// PURPOSE: Serves image files from the local ../img/ folder with
//          CORS headers. Used by the Admin Panel to display static
//          UI images that would otherwise be blocked cross-origin.
//
// Bug 12 Fix: Without a token check this was an open file server —
//             any file in the img folder could be read by anyone.
//             Added admin token requirement.
//             Also added basename() sanitisation (already present)
//             and MIME type validation so only image files are served.

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning");
header("Access-Control-Allow-Methods: GET, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'db_config.php';
verifyAdminToken();

// Get the requested filename
$file = trim($_GET['f'] ?? '');

if (empty($file)) {
    http_response_code(400);
    exit('No filename provided.');
}

// basename() strips any directory components so  ../../etc/passwd
// becomes just  passwd  which won't exist in the img folder.
$file = basename($file);

$path = __DIR__ . '/../img/' . $file;

// Resolve symlinks to confirm the file truly lives in the img directory
$realPath    = realpath($path);
$realImgDir  = realpath(__DIR__ . '/../img/');

if ($realPath === false
    || $realImgDir === false
    || strpos($realPath, $realImgDir) !== 0) {
    http_response_code(404);
    exit('File not found.');
}

if (!is_file($realPath)) {
    http_response_code(404);
    exit('Not a file.');
}

// Only serve recognised image MIME types
// Prevents the proxy from serving .php, .json or other sensitive files
// even if someone somehow got a non-image filename past basename()
$mime          = function_exists('mime_content_type') ? @mime_content_type($realPath) : '';
$allowedMimes  = [
    'image/jpeg',
    'image/jpg',
    'image/png',
    'image/webp',
    'image/gif',
    'image/svg+xml'
];

if (!in_array($mime, $allowedMimes, true)) {
    http_response_code(403);
    exit('File type not allowed.');
}

header('Content-Type: '   . $mime);
header('Content-Length: ' . filesize($realPath));
header('Cache-Control: public, max-age=86400'); // 1 day browser cache

readfile($realPath);
?>
