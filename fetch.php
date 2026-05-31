<?php
// FILE: api/fetch.php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
require_once 'db_config.php';
require_once 'traffic_manager.php';
TrafficManager::check();

error_reporting(0);
ini_set('display_errors', 0);

$mode       = $_GET['mode']     ?? 'public';
$type       = $_GET['type']     ?? 'all';
$batch_id   = $_GET['batch_id'] ?? '';
$event_name = $_GET['event']    ?? '';

$sql    = "SELECT * FROM media_items WHERE 1=1";
$params = [];

// ------------------------------------------------------------------
// Visibility / Mode handling
//   public  = only public items  (index.html portfolio)
//   client  = all items          (view.html client gallery)
//   admin   = all items, admin_token required  (admin.html)
// ------------------------------------------------------------------
if ($mode === 'admin') {
    // Admin sees everything — but must prove they are admin
    verifyAdminToken();
    // No visibility filter — show all items
} elseif ($mode === 'client') {
    // Client gallery: all items (private ones need exact event name)
    // No visibility filter
} else {
    // Default / public: only public items
    $sql .= " AND visibility = 'public'";
}

// Fuzzy Event Search
if (!empty($event_name)) {
    $sql .= " AND title LIKE :evt";
    $params[':evt'] = '%' . trim($event_name) . '%';
}

// Batch Filter
if (!empty($batch_id)) {
    $sql .= " AND batch_id = :bid";
    $params[':bid'] = $batch_id;
}

// Type Filter
if (!empty($type) && $type !== 'all') {
    // Handle comma-separated type list e.g. type=teaser,live
    $types = array_filter(array_map('trim', explode(',', $type)));
    if (count($types) === 1) {
        $sql .= " AND type = :type";
        $params[':type'] = $types[0];
    } elseif (count($types) > 1) {
        $placeholders = [];
        foreach ($types as $i => $t) {
            $key = ':type' . $i;
            $placeholders[] = $key;
            $params[$key] = $t;
        }
        $sql .= " AND type IN (" . implode(',', $placeholders) . ")";
    }
}

$sql .= " ORDER BY order_index ASC, date_added DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $base_url = "https://supriyadigitals.store/";

    foreach ($data as &$item) {
        if (!empty($item['file_path']) && strpos($item['file_path'], 'http') === false) {
            $raw = ltrim($item['file_path'], '/');

            // Bug 11 Fix: unified path normalisation
            if (stripos($raw, 'supriya_studio/') === 0) {
                $relative = preg_replace('/^supriya_studio\//i', '', $raw);
            } else {
                $relative = $raw;
            }

            $item['file_path'] = $base_url . $relative;
        }

        $item['video_category'] = $item['video_category'] ?? 'Teaser';
        $item['can_download']   = $item['can_download']   ?? 1;
    }
    unset($item);

    echo json_encode(['success' => true, 'data' => $data]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Query Failed']);
}
?>
