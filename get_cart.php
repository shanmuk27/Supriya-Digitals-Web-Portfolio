<?php
// FILE: api/get_cart.php
// PURPOSE: Retrieves a saved photo selection by its 6-character code.
//          Used by the cart-sharing feature so a selection started on one
//          device can be continued on another.
//
// GET ?code=ABCDEF  → returns { success, files: [...], event, total }

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, ngrok-skip-browser-warning");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { http_response_code(200); exit(); }

require_once 'db_config.php';
error_reporting(0);

$code = strtoupper(trim($_GET['code'] ?? ''));

if (strlen($code) < 4) {
    echo json_encode(['success' => false, 'message' => 'Invalid or missing code.']);
    exit();
}

try {
    // Look up the SELECTION entry by code in action_log
    $stmt = $pdo->prepare(
        "SELECT action_log, event_opened FROM viewer_history
         WHERE action_log LIKE :code
         ORDER BY last_active DESC LIMIT 1"
    );
    $stmt->execute([':code' => '%SELECTION:#' . $code . '%']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Selection not found. Code may have expired or be incorrect.']);
        exit();
    }

    // Parse file list from action_log: Files=url1|url2|url3
    $filesMatch = [];
    preg_match('/Files=(.+)$/', $row['action_log'], $filesMatch);

    if (empty($filesMatch[1])) {
        echo json_encode(['success' => false, 'message' => 'Selection data is incomplete.']);
        exit();
    }

    $files = array_filter(explode('|', $filesMatch[1]));
    $total = count($files);

    // Build per-folder summary
    $byFolder = [];
    foreach ($files as $url) {
        $parts    = explode('/', rtrim($url, '/'));
        $fname    = array_pop($parts);
        $folder   = !empty($parts) ? array_pop($parts) : 'Main';
        $byFolder[$folder][] = $fname;
    }

    echo json_encode([
        'success' => true,
        'code'    => $code,
        'event'   => $row['event_opened'] ?? '',
        'total'   => $total,
        'files'   => array_values($files),
        'folders' => $byFolder,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Lookup failed.']);
}
?>
