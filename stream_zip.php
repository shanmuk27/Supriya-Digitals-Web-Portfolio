<?php
// FILE: api/stream_zip.php
// SECURITY FIX: Validate all requested paths against the DB before streaming.
// This prevents path enumeration attacks where someone guesses file paths.

require_once 'db_config.php';

// Accept files from JSON body or form POST
$files = [];
if (!empty($GLOBALS['_RAW_INPUT'])) {
    $jsonData = json_decode($GLOBALS['_RAW_INPUT'], true);
    if (!empty($jsonData['files'])) $files = $jsonData['files'];
}
if (empty($files) && !empty($_POST['files_json'])) {
    $decoded = json_decode($_POST['files_json'], true);
    if (is_array($decoded)) $files = $decoded;
}

if (empty($files) || !is_array($files)) {
    http_response_code(400);
    exit('No files selected');
}

// Limit batch size to prevent abuse
$files = array_slice($files, 0, 500);

// ------------------------------------------------------------------
// SECURITY FIX: Validate each requested path exists in media_items.
// Strip domain prefix first, then check DB for relative path.
// ------------------------------------------------------------------
$cleanPaths = [];
foreach ($files as $path) {
    $clean = ltrim(str_replace(
        ['https://supriyadigitals.store/', 'http://supriyadigitals.store/'],
        '', $path
    ), '/');
    if (!empty($clean)) $cleanPaths[] = $clean;
}

if (empty($cleanPaths)) { http_response_code(400); exit('Invalid paths'); }

// Build placeholders for IN clause
$placeholders = implode(',', array_fill(0, count($cleanPaths), '?'));
$stmt = $pdo->prepare("SELECT file_path FROM media_items WHERE file_path IN ($placeholders) OR CONCAT('https://supriyadigitals.store/', file_path) IN ($placeholders)");
$stmt->execute(array_merge($cleanPaths, $cleanPaths));
$allowedPaths = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Build a lookup set
$allowedSet = [];
foreach ($allowedPaths as $ap) {
    $clean = ltrim(str_replace(['https://supriyadigitals.store/', 'http://supriyadigitals.store/'], '', $ap), '/');
    $allowedSet[$clean] = true;
}

// ------------------------------------------------------------------
// Resolve validated paths to disk
// ------------------------------------------------------------------
$validPaths = [];
foreach ($cleanPaths as $cleanDbPath) {
    // Skip if not in DB
    if (!isset($allowedSet[$cleanDbPath])) continue;

    // Prevent path traversal
    if (strpos($cleanDbPath, '..') !== false) continue;

    $truePath = null;
    if     (file_exists('/volume1/' . $cleanDbPath))        $truePath = '/volume1/' . $cleanDbPath;
    elseif (file_exists('/volume2/' . $cleanDbPath))        $truePath = '/volume2/' . $cleanDbPath;
    elseif (file_exists(__DIR__ . '/../' . $cleanDbPath))   $truePath = realpath(__DIR__ . '/../' . $cleanDbPath);

    if ($truePath && file_exists($truePath)) $validPaths[] = $truePath;
}

if (empty($validPaths)) {
    http_response_code(404);
    exit('No valid files found');
}

// ------------------------------------------------------------------
// Stream the ZIP
// ------------------------------------------------------------------
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="Supriya_Originals_' . date('Ymd_His') . '.zip"');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // disable nginx buffering

if (ob_get_level()) ob_end_flush();
flush();

// -j: junk paths, -q: quiet, -0: no compression (JPEGs are already compressed)
$cmd = 'zip -j -q -0 - ';
foreach ($validPaths as $path) $cmd .= escapeshellarg($path) . ' ';
passthru($cmd);
exit;
?>
