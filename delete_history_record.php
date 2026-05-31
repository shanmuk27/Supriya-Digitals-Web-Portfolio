<?php
// FILE: api/delete_history_record.php
// PURPOSE: Deletes a single row from viewer_history by its primary key.
//          Admin-only — used by the History panel "delete" button.

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db_config.php';
verifyAdminToken();

header('Content-Type: application/json');
error_reporting(0);

// Read ID from JSON body (already read by db_config.php)
$body = [];
if (!empty($GLOBALS['_RAW_INPUT'])) {
    $decoded = json_decode($GLOBALS['_RAW_INPUT'], true);
    if (is_array($decoded)) $body = $decoded;
}

$id = isset($body['id']) ? (int)$body['id'] : 0;

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid or missing record ID.']);
    exit();
}

try {
    $stmt = $pdo->prepare("DELETE FROM viewer_history WHERE id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Record not found or already deleted.']);
    } else {
        echo json_encode(['success' => true, 'message' => 'Record deleted.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()]);
}
?>
