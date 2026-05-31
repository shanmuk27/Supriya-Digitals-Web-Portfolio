<?php
// FILE: api/update_order.php
// PURPOSE: Saves the new drag-and-drop order of items within a batch.
//          Receives an array of { id, order } pairs and updates
//          order_index for each item inside a single transaction.

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
// Do NOT call file_get_contents('php://input') here again.
$input = [];
if (!empty($GLOBALS['_RAW_INPUT'])) {
    $decoded = json_decode($GLOBALS['_RAW_INPUT'], true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

if (!isset($input['newOrder'])) {
    echo json_encode(['success' => false, 'message' => 'newOrder array is required.']);
    exit;
}

$newOrder = $input['newOrder'];

if (!is_array($newOrder) || empty($newOrder)) {
    echo json_encode(['success' => false, 'message' => 'newOrder must be a non-empty array.']);
    exit;
}

// Validate every item before touching the database
foreach ($newOrder as $index => $item) {
    if (!isset($item['id']) || !isset($item['order'])) {
        echo json_encode([
            'success' => false,
            'message' => "Item at index $index is missing 'id' or 'order' field."
        ]);
        exit;
    }
    if (!is_numeric($item['id']) || !is_numeric($item['order'])) {
        echo json_encode([
            'success' => false,
            'message' => "Item at index $index has non-numeric 'id' or 'order'."
        ]);
        exit;
    }
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "UPDATE media_items SET order_index = :order WHERE id = :id"
    );

    foreach ($newOrder as $item) {
        $stmt->execute([
            ':order' => (int)$item['order'],
            ':id'    => (int)$item['id']
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'updated' => count($newOrder)
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
