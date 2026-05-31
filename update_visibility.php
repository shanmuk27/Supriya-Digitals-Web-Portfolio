<?php
// FILE: api/update_visibility.php
// PURPOSE: Toggles the visibility of a single item or an entire batch
//          between 'public' and 'private'.
//
//          public  = visible on the main website portfolio
//          private = hidden; only accessible via direct view.html link

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

if (empty($input)) {
    echo json_encode(['success' => false, 'message' => 'Invalid or empty JSON body.']);
    exit;
}

$id         = $input['id']         ?? null;
$batch_id   = $input['batch_id']   ?? null;
$visibility = $input['visibility'] ?? null;

// Validate that a target was provided
if (!$id && !$batch_id) {
    echo json_encode(['success' => false, 'message' => 'No id or batch_id provided.']);
    exit;
}

// Validate visibility value — matches the enum in media_items table
if (!in_array($visibility, ['public', 'private'], true)) {
    echo json_encode([
        'success' => false,
        'message' => "Invalid visibility value. Must be 'public' or 'private'."
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    if ($id) {
        $stmt = $pdo->prepare(
            "UPDATE media_items SET visibility = :vis WHERE id = :id"
        );
        $stmt->execute([':vis' => $visibility, ':id' => $id]);
        $affected = $stmt->rowCount();

    } elseif ($batch_id) {
        $stmt = $pdo->prepare(
            "UPDATE media_items SET visibility = :vis WHERE batch_id = :bid"
        );
        $stmt->execute([':vis' => $visibility, ':bid' => $batch_id]);
        $affected = $stmt->rowCount();
    }

    $pdo->commit();

    echo json_encode([
        'success'  => true,
        'affected' => $affected ?? 0
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
