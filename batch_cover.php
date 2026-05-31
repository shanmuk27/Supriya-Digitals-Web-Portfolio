<?php
// FILE: api/batch_cover.php
// PURPOSE: Store and retrieve admin-selected SINGLE hero cover photos per Event.
//
// GET  ?event_name=xyz  → { success:true, cover: "url" }
// POST { admin_token, event_name, cover_path } → set cover for an event
// POST { admin_token, event_name, clear:true } → clear cover (fall back to random photo)

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db_config.php';

// ── COVER STORE LOCATION ──
// Try multiple locations in order of preference.
// The first writable directory wins. This avoids the
// "Check file permissions" error when the NAS path isn't mounted.
function resolveCoverFile() {
    $candidates = [
        '/volume1/Kalyani/.event_covers.json',
        __DIR__ . '/cache/.event_covers.json',
        sys_get_temp_dir() . '/supriya_event_covers.json',
    ];

    foreach ($candidates as $path) {
        $dir = dirname($path);
        // Ensure the directory exists (for cache/ subdir)
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        // If the file already exists and is writable, use it
        if (file_exists($path) && is_writable($path)) {
            return $path;
        }
        // If the file doesn't exist, check if we can create it in that dir
        if (!file_exists($path) && is_writable($dir)) {
            return $path;
        }
    }

    // Last resort — same directory as this script
    return __DIR__ . '/event_covers_data.json';
}

$coversFile = resolveCoverFile();

function readCovers($file) {
    if (!file_exists($file)) return [];
    $raw  = @file_get_contents($file);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function writeCovers($file, $covers) {
    $result = @file_put_contents($file, json_encode($covers, JSON_PRETTY_PRINT), LOCK_EX);
    if ($result === false) {
        // Try to create/chmod and retry once
        @chmod(dirname($file), 0777);
        $result = @file_put_contents($file, json_encode($covers, JSON_PRETTY_PRINT), LOCK_EX);
    }
    return $result !== false;
}

// ── GET: return cover for the requested Event ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $eventName = trim($_GET['event_name'] ?? '');
    
    if (empty($eventName)) {
        echo json_encode(['success' => false, 'message' => 'event_name required']);
        exit();
    }

    $covers = readCovers($coversFile);
    $result = isset($covers[$eventName]) ? $covers[$eventName] : null;

    echo json_encode(['success' => true, 'cover' => $result]);
    exit();
}

// ── POST: set or clear an event cover (admin only) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyAdminToken();

    $body = json_decode($GLOBALS['_RAW_INPUT'] ?? '', true);
    if (!is_array($body)) {
        $body = $_POST;
    }

    $eventName = trim($body['event_name'] ?? '');
    // Accept either cover_path or cover_url depending on what JS sends
    $coverUrl  = trim($body['cover_path'] ?? $body['cover_url'] ?? '');
    $clear     = !empty($body['clear']);

    if (empty($eventName)) {
        echo json_encode(['success' => false, 'message' => 'event_name required']);
        exit();
    }

    $covers = readCovers($coversFile);

    if ($clear) {
        unset($covers[$eventName]);
        $msg = 'Event cover cleared. A random photo will be used.';
    } else {
        if (empty($coverUrl)) {
            echo json_encode(['success' => false, 'message' => 'cover_path required']);
            exit();
        }
        $covers[$eventName] = $coverUrl;
        $msg = 'Event cover photo saved successfully.';
    }

    if (writeCovers($coversFile, $covers)) {
        echo json_encode(['success' => true, 'message' => $msg]);
    } else {
        // Detailed error to help diagnose
        $dir = dirname($coversFile);
        $dirWritable = is_writable($dir) ? 'writable' : 'NOT writable';
        echo json_encode([
            'success' => false,
            'message' => "Failed to save cover. Directory ($dir) is $dirWritable. Please run: chmod 777 " . $dir
        ]);
    }
    exit();
}

echo json_encode(['success' => false, 'message' => 'Method not allowed']);
?>