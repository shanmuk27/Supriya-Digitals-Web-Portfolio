<?php
// FILE: api/update_permission.php
// PURPOSE: Updates the can_download permission flag for a single item
//          or an entire batch of items.
//
//          can_download values:
//            0 = Downloads completely disabled
//            1 = Both web quality and original quality allowed
//            2 = Original quality only (no web/compressed version)

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db_config.php';

// Admin-only operation
verifyAdminToken();

header('Content-Type: application/json');

// FIXED: Use $GLOBALS['_RAW_INPUT'] — already read by db_config.php.
// Do NOT call file_get_contents('php://input') here again — the stream
// was already consumed when db_config.php read it into the global.
$data = [];
if (!empty($GLOBALS['_RAW_INPUT'])) {
    $decoded = json_decode($GLOBALS['_RAW_INPUT'], true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}

if (empty($data)) {
    echo json_encode(['success' => false, 'message' => 'Invalid or empty JSON body.']);
    exit;
}

$id           = $data['id']           ?? null;
$batch_id     = $data['batch_id']     ?? null;
$can_download = isset($data['can_download']) ? (int)$data['can_download'] : null;

// Validate that a target was provided
if (!$id && !$batch_id) {
    echo json_encode(['success' => false, 'message' => 'No id or batch_id provided.']);
    exit;
}

// Validate can_download value — must be 0, 1, or 2
if ($can_download === null || !in_array($can_download, [0, 1, 2], true)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid can_download value. Must be 0 (off), 1 (both), or 2 (original only).'
    ]);
    exit;
}

try {
    if ($batch_id) {
        $stmt = $pdo->prepare(
            "UPDATE media_items SET can_download = ? WHERE batch_id = ?"
        );
        $stmt->execute([$can_download, $batch_id]);
        $affected = $stmt->rowCount();
    } else {
        $stmt = $pdo->prepare(
            "UPDATE media_items SET can_download = ? WHERE id = ?"
        );
        $stmt->execute([$can_download, $id]);
        $affected = $stmt->rowCount();
    }

    echo json_encode([
        'success'  => true,
        'affected' => $affected
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
