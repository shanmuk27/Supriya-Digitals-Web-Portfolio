<?php
// FILE: api/save_selection.php
// PURPOSE: Saves large photo selections to the DB and returns a short
//          reference code so the WhatsApp message stays within URL limits.
//          Called by view.html sendToStudio() when selection > 80 photos.

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, ngrok-skip-browser-warning");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db_config.php';
require_once 'traffic_manager.php';
TrafficManager::check();
// Note: No verifyAdminToken() here — this endpoint is called by the
// CLIENT gallery (view.html), not the admin panel.

error_reporting(0);
ini_set('display_errors', 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit();
}

// FIXED: Use $GLOBALS['_RAW_INPUT'] — db_config.php already read
// php://input into this global. Calling file_get_contents('php://input')
// again would return empty because the stream is already consumed.
$data = [];
if (!empty($GLOBALS['_RAW_INPUT'])) {
    $decoded = json_decode($GLOBALS['_RAW_INPUT'], true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}

$event  = trim($data['event']  ?? 'Unknown Event');
$device = trim($data['device'] ?? 'unknown');
$files  = $data['files']       ?? [];

if (empty($files) || !is_array($files)) {
    echo json_encode(['success' => false, 'message' => 'No files provided']);
    exit();
}

// Generate a short 6-character readable reference code.
// Uses device ID + microtime so two users saving simultaneously
// always get different codes.
$code = strtoupper(substr(md5($device . microtime(true) . count($files)), 0, 6));

// Build a per-folder summary for the WhatsApp message
$filesByFolder = [];
foreach ($files as $url) {
    $parts      = explode('/', rtrim($url, '/'));
    $filename   = array_pop($parts);
    $foldername = !empty($parts) ? array_pop($parts) : 'Main';
    if (!isset($filesByFolder[$foldername])) {
        $filesByFolder[$foldername] = [];
    }
    $filesByFolder[$foldername][] = $filename;
}

$summaryParts = [];
foreach ($filesByFolder as $folder => $names) {
    $summaryParts[] = $folder . ' (' . count($names) . ')';
}
$summary = implode(', ', $summaryParts);

// Store in viewer_history so admin can look up selection by code
// SELECTION: prefix makes it easy to filter in fetch_history.php
$logEntry = 'SELECTION:#' . $code
    . ' | Total=' . count($files)
    . ' | Folders=' . $summary
    . ' | Files=' . implode('|', $files);

try {
    TrafficManager::runTransaction($pdo, function($pdo) use ($device, $event, $logEntry) {
        $stmt = $pdo->prepare(
            "INSERT INTO viewer_history
                (device_id, site_visited, event_opened, action_log, browser_info)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $device,
            'Selection Save',
            $event,
            $logEntry,
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Browser'
        ]);
    });

    echo json_encode([
        'success' => true,
        'code'    => $code,
        'total'   => count($files),
        'summary' => $summary
    ]);

} catch (Exception $e) {
    // Even if DB write fails, return a code so WhatsApp can open
    echo json_encode([
        'success' => true,
        'code'    => $code,
        'total'   => count($files),
        'summary' => $summary,
        'warning' => 'DB save failed. Selection not logged.'
    ]);
}
?>
