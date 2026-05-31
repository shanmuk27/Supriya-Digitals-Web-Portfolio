<?php
// FILE: api/update_title.php
// PURPOSE: Renames an event by updating the title field for all items
//          that share the same old title.
//
// Bug 8 Fix: Before renaming, counts how many distinct batches share
//            the old title. If more than one batch would be affected,
//            returns a warning requiring force=true to proceed.
//            This prevents silently renaming two different clients'
//            events that happen to have the same name.

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
$data = [];
if (!empty($GLOBALS['_RAW_INPUT'])) {
    $decoded = json_decode($GLOBALS['_RAW_INPUT'], true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}

$action    = $data['action']    ?? '';
$new_title = trim($data['new_title'] ?? '');
$old_title = $data['old_title'] ?? '';

// force=true must be explicitly sent to allow renames that span
// multiple separate events (Bug 8 protection)
$force = !empty($data['force']) && $data['force'] === true;

if (empty($new_title) || empty($old_title)) {
    echo json_encode(['success' => false, 'message' => 'Title cannot be empty.']);
    exit;
}

if ($new_title === $old_title) {
    echo json_encode(['success' => false, 'message' => 'New title is the same as the old title.']);
    exit;
}

try {
    if ($action === 'rename_event') {

        // How many rows share this title?
        $count_stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM media_items WHERE title = ?"
        );
        $count_stmt->execute([$old_title]);
        $affected = (int) $count_stmt->fetchColumn();

        if ($affected === 0) {
            echo json_encode(['success' => false, 'message' => 'No items found with that title.']);
            exit;
        }

        // How many distinct batches/events share this title?
        $batch_check = $pdo->prepare(
            "SELECT COUNT(DISTINCT COALESCE(batch_id, id)) AS batch_count
             FROM media_items WHERE title = ?"
        );
        $batch_check->execute([$old_title]);
        $batch_count = (int) $batch_check->fetchColumn();

        // Safety gate: warn before touching multiple separate events
        if ($batch_count > 1 && !$force) {
            echo json_encode([
                'success'         => false,
                'confirm_needed'  => true,
                'affected_items'  => $affected,
                'affected_events' => $batch_count,
                'message'         => "Warning: $affected items across $batch_count separate events share the title \"$old_title\". "
                                   . "Send force:true to rename all of them, or cancel and rename each event individually."
            ]);
            exit;
        }

        // All clear — perform the rename
        $stmt = $pdo->prepare(
            "UPDATE media_items SET title = ? WHERE title = ?"
        );
        $stmt->execute([$new_title, $old_title]);

        echo json_encode([
            'success'        => true,
            'message'        => "Event renamed successfully. $affected items updated.",
            'affected_items' => $affected
        ]);

    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
}
?>
