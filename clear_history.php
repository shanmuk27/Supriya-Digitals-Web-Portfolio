<?php
// FILE: api/clear_history.php
// PURPOSE: Truncates the viewer_history table to clear all tracking data.
//          Optional keep_selections=true preserves photo selection
//          records (saved by save_selection.php) while clearing the
//          rest of the activity log.

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db_config.php';

// Admin-only destructive operation
verifyAdminToken();

header('Content-Type: application/json');

// FIXED: Use $GLOBALS['_RAW_INPUT'] — already read by db_config.php.
// Do NOT call file_get_contents('php://input') here again.
$body = [];
if (!empty($GLOBALS['_RAW_INPUT'])) {
    $decoded = json_decode($GLOBALS['_RAW_INPUT'], true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}

// keep_selections=true: clear activity logs but keep WhatsApp
// selection codes saved by save_selection.php
$keepSelections = !empty($body['keep_selections']) && $body['keep_selections'] === true;

try {
    if ($keepSelections) {
        // Delete everything EXCEPT SELECTION entries
        $stmt = $pdo->prepare(
            "DELETE FROM viewer_history WHERE action_log NOT LIKE 'SELECTION:%'"
        );
        $stmt->execute();
        $deleted = $stmt->rowCount();

        echo json_encode([
            'success' => true,
            'message' => "Cleared $deleted activity entries. Photo selections kept."
        ]);
    } else {
        // Default: wipe the entire table fast
        $pdo->query("TRUNCATE TABLE viewer_history");

        echo json_encode([
            'success' => true,
            'message' => 'All viewer history cleared successfully.'
        ]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Failed: ' . $e->getMessage()]);
}
?>
