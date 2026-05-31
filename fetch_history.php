<?php
// FILE: api/fetch_history.php
// PURPOSE: Returns viewer activity history for the Admin Panel.
//          Shows who opened what event, for how long, and what they downloaded.
//          Also returns photo selections saved by save_selection.php.
//
// Bug 12 Fix: Viewer history contains device IDs, browser info, and
//             private photo selections. Must be admin-only.

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db_config.php';
verifyAdminToken();

header('Content-Type: application/json');
error_reporting(0);

// ------------------------------------------------------------------
// Optional filters passed as GET parameters:
//   ?limit=300       How many rows to return (default 300, max 1000)
//   ?search=TEXT     Filter action_log or event_opened by keyword
//   ?type=selection  Return only SELECTION entries (from save_selection.php)
//   ?type=download   Return only download entries
//   ?type=all        Return everything (default)
// ------------------------------------------------------------------
$limit  = min((int)($_GET['limit'] ?? 300), 1000);
$search = trim($_GET['search'] ?? '');
$type   = trim($_GET['type']   ?? 'all');

try {
    $sql    = "SELECT * FROM viewer_history WHERE 1=1";
    $params = [];

    // Keyword search across action_log and event name
    if (!empty($search)) {
        $sql .= " AND (action_log LIKE :search OR event_opened LIKE :search2)";
        $params[':search']  = '%' . $search . '%';
        $params[':search2'] = '%' . $search . '%';
    }

    // Type filter
    if ($type === 'selection') {
        // Only show entries saved by save_selection.php
        $sql .= " AND action_log LIKE :type_filter";
        $params[':type_filter'] = 'SELECTION:%';
    } elseif ($type === 'download') {
        $sql .= " AND action_log LIKE :type_filter";
        $params[':type_filter'] = 'Download%';
    }
    // 'all' — no additional filter

    $sql .= " ORDER BY last_active DESC LIMIT :lim";

    $stmt = $pdo->prepare($sql);

    // Bind the limit separately because PDO requires integer binding for LIMIT
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);

    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'count'   => count($data),
        'data'    => $data
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Query failed: ' . $e->getMessage()]);
}
?>
