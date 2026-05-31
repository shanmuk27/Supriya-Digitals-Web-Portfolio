<?php
// FILE: api/ping.php
// PURPOSE: Lightweight endpoint called every 10 seconds by both
//          index.html and view.html to keep the active_users.json
//          counter up to date for the Admin Panel live traffic display.

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

error_reporting(0);
ini_set('display_errors', 0);

$cacheDir = __DIR__ . '/cache/';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);

$deviceId = trim($_GET['device'] ?? 'unknown');
$deviceId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $deviceId);
if (empty($deviceId)) $deviceId = 'unknown';

$trackerFile = $cacheDir . 'active_users.json';
$users       = [];

if (file_exists($trackerFile)) {
    $raw = @file_get_contents($trackerFile);
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $users = $decoded;
        }
    }
}

// Update this device's last-seen timestamp
$users[$deviceId] = time();

// FIX: Clean up devices inactive for more than 120 seconds.
// system_stats.php uses a 60-second window for the live count,
// so we use 120s here to give a generous buffer and avoid
// devices dropping out of the count between pings.
$now = time();
foreach ($users as $id => $lastSeen) {
    if ($now - $lastSeen > 120) {
        unset($users[$id]);
    }
}

@file_put_contents($trackerFile, json_encode($users), LOCK_EX);

echo json_encode(['status' => 'ok', 'active' => count($users)]);
?>
