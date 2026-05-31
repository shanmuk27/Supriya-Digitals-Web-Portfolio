<?php
// FILE: api/delete.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db_config.php';

// Delete is an admin-only operation
verifyAdminToken();

header('Content-Type: application/json');

$id       = $_POST['id']       ?? null;
$batch_id = $_POST['batch_id'] ?? null;

if (!$id && !$batch_id) {
    exit(json_encode(['success' => false, 'message' => 'No ID provided']));
}

try {
    // ----------------------------------------------------------
    // 1. FIND ALL ITEMS THAT ARE ABOUT TO BE DELETED
    // ----------------------------------------------------------
    if ($batch_id) {
        $query = "SELECT file_path FROM media_items WHERE batch_id = ?";
        $param = $batch_id;
    } else {
        $query = "SELECT file_path FROM media_items WHERE id = ?";
        $param = $id;
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute([$param]);
    $filesToDelete = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ----------------------------------------------------------
    // 2. DELETE THEIR CACHE FILES
    //
    //    Bug 7 Fix: The ETag MUST be built using the same
    //    absolute resolved path that serve_image.php uses.
    //    Previously $file (relative) was used — it never matched
    //    the cache filenames and nothing was ever cleaned up.
    //
    //    Correct formula (mirrors serve_image.php exactly):
    //      $etag = md5($resolvedAbsolutePath . $mtime . $maxWidth)
    // ----------------------------------------------------------
    foreach ($filesToDelete as $row) {
        // Strip domain if a full URL was stored
        $file = ltrim(
            str_replace(
                ['https://supriyadigitals.store/', 'http://supriyadigitals.store/'],
                '',
                $row['file_path']
            ),
            '/'
        );

        // Resolve to absolute path — try all possible locations
        // in the same order serve_image.php does
        $resolvedFile = null;

        if (file_exists($file)) {
            $resolvedFile = $file;
        } elseif (file_exists(__DIR__ . '/../' . $file)) {
            $resolvedFile = realpath(__DIR__ . '/../' . $file);
        } elseif (file_exists('/volume1/' . $file)) {
            $resolvedFile = '/volume1/' . $file;
        } elseif (file_exists('/volume2/' . $file)) {
            $resolvedFile = '/volume2/' . $file;
        }

        if ($resolvedFile && file_exists($resolvedFile)) {
            $mtime = filemtime($resolvedFile);

            // Build ETags using the ABSOLUTE resolved path — same as serve_image.php
            $thumbEtag    = md5($resolvedFile . $mtime . 400);   // thumb  size
            $lightboxEtag = md5($resolvedFile . $mtime . 1200);  // lightbox size (standardised to 1200)

            @unlink(__DIR__ . '/cache/th_' . $thumbEtag    . '.jpg');
            @unlink(__DIR__ . '/cache/lb_' . $lightboxEtag . '.jpg');
        }
    }

    // ----------------------------------------------------------
    // 3. DELETE FROM DATABASE
    // ----------------------------------------------------------
    if ($batch_id) {
        $del = $pdo->prepare("DELETE FROM media_items WHERE batch_id = ?");
        $del->execute([$batch_id]);
    } else {
        $del = $pdo->prepare("DELETE FROM media_items WHERE id = ?");
        $del->execute([$id]);
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
