<?php
// FILE: api/track.php
// PURPOSE: Records viewer activity for the Admin Panel history view.

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
require_once 'db_config.php';

error_reporting(0);
ini_set('display_errors', 0);

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ------------------------------------------------------------------
// 1. UPDATE LIVE TRAFFIC COUNT
//    FIXED: Use device_id (same key as ping.php) so both scripts
//    write the SAME key per user — avoiding double-counting.
//    Previously track.php used IP and ping.php used device_id,
//    meaning 1 person was counted TWICE in the live viewer stat.
// ------------------------------------------------------------------
$cacheDir = __DIR__ . '/cache/';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);

// Parse body first so we can get the device ID
$data = [];
if (!empty($GLOBALS['_RAW_INPUT'])) {
    $decoded = json_decode($GLOBALS['_RAW_INPUT'], true);
    if (is_array($decoded)) $data = $decoded;
}

// Use device_id from body; fall back to sanitised IP
$device = substr(trim($data['device'] ?? 'unknown'), 0, 100);
$device = preg_replace('/[^a-zA-Z0-9_\-]/', '', $device);
if (empty($device)) {
    $rawIp  = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $device = 'ip_' . trim(explode(',', $rawIp)[0]);
}

$trackerFile = $cacheDir . 'active_users.json';
$users       = [];

if (file_exists($trackerFile)) {
    $raw = @file_get_contents($trackerFile);
    if ($raw) {
        $decoded2 = json_decode($raw, true);
        if (is_array($decoded2)) $users = $decoded2;
    }
}

$users[$device] = time();

// Remove stale entries older than 120 seconds
$now = time();
foreach ($users as $storedId => $ts) {
    if ($now - $ts > 120) unset($users[$storedId]);
}
@file_put_contents($trackerFile, json_encode($users), LOCK_EX);

// ------------------------------------------------------------------
// 2. SAVE DETAILED HISTORY TO DATABASE
// ------------------------------------------------------------------
if (empty($data)) {
    echo json_encode(['success' => false, 'message' => 'No data']);
    exit;
}

$site      = substr(trim($data['site']      ?? 'Unknown Site'), 0, 200);
$event     = substr(trim($data['event']     ?? 'None'), 0, 200);
$action    = substr(trim($data['action']    ?? 'ping'), 0, 50);
$item_info = substr(trim($data['item_info'] ?? ''), 0, 500);
$browser   = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 500);

try {
    if ($action === 'ping') {
        $stmt = $pdo->prepare(
            "SELECT id FROM viewer_history
             WHERE device_id    = ?
               AND event_opened = ?
               AND action_log   = 'Viewing Gallery'
               AND last_active >= NOW() - INTERVAL 3 MINUTE
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->execute([$device, $event]);
        $row = $stmt->fetch();

        if ($row) {
            $pdo->prepare(
                "UPDATE viewer_history
                 SET time_spent_seconds = time_spent_seconds + 10,
                     last_active        = NOW()
                 WHERE id = ?"
            )->execute([$row['id']]);
        } else {
            $pdo->prepare(
                "INSERT INTO viewer_history
                    (device_id, site_visited, event_opened, action_log, browser_info)
                 VALUES (?, ?, ?, 'Viewing Gallery', ?)"
            )->execute([$device, $site, $event, $browser]);
        }

    } elseif ($action === 'download') {
        $pdo->prepare(
            "INSERT INTO viewer_history
                (device_id, site_visited, event_opened, action_log, browser_info)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$device, $site, $event, 'Downloaded: ' . $item_info, $browser]);

    } else {
        $pdo->prepare(
            "INSERT INTO viewer_history
                (device_id, site_visited, event_opened, action_log, browser_info)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$device, $site, $event, $action . ': ' . $item_info, $browser]);
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false]);
}
?>
