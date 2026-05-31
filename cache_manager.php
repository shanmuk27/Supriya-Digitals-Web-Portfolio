<?php
// FILE: api/cache_manager.php
// PURPOSE: Reports how many thumbnails are pre-cached for a given batch_id.
//          Called by view.html before opening a folder to show the
//          optimisation progress screen.
//
// Bug 10 Fix: Path resolution and ETag formula now exactly match
//             serve_image.php so "missing" files are truly missing,
//             not just hashed differently.

header("Access-Control-Allow-Origin: *");
require_once 'db_config.php';

error_reporting(0);
ini_set('display_errors', 0);

$action = $_GET['action'] ?? 'status';

if ($action === 'status') {

    $batch_id = $_GET['batch_id'] ?? '';
    if (!$batch_id) {
        exit(json_encode(['total' => 0, 'cached' => 0, 'missing' => []]));
    }

    $stmt = $pdo->prepare(
        "SELECT file_path FROM media_items
         WHERE batch_id = ? AND type IN ('work', 'link')"
    );
    $stmt->execute([$batch_id]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total   = 0;
    $cached  = 0;
    $missing = [];

    $base_url = "https://supriyadigitals.store/";

    foreach ($files as $f) {
        $dbPath = $f['file_path'];

        // ----------------------------------------------------------
        // Step 1: Build the full public URL (same logic as fetch.php)
        //         This is what the frontend passes to serve_image.php
        // ----------------------------------------------------------
        if (strpos($dbPath, 'http') === false) {
            $raw = ltrim($dbPath, '/');

            if (stripos($raw, 'supriya_studio/') === 0) {
                // Old structure — strip prefix to match fetch.php output
                $relative = preg_replace('/^supriya_studio\//i', '', $raw);
            } else {
                // New Kalyani structure — keep path as-is
                $relative = $raw;
            }

            $fullUrl = $base_url . $relative;
        } else {
            $fullUrl = $dbPath;
        }

        // ----------------------------------------------------------
        // Step 2: Derive the absolute filesystem path
        //         This must match what serve_image.php resolves to
        //         so the ETag hashes will be identical.
        //
        //         serve_image.php strips the domain then prepends
        //         /volume1/ — we do exactly the same here.
        // ----------------------------------------------------------
        $cleanPath = ltrim(
            str_replace(
                ['https://supriyadigitals.store/', 'http://supriyadigitals.store/'],
                '',
                $fullUrl
            ),
            '/'
        );

        // Try /volume1 first, then /volume2 as fallback
        if (file_exists('/volume1/' . $cleanPath)) {
            $truePath = '/volume1/' . $cleanPath;
        } elseif (file_exists('/volume2/' . $cleanPath)) {
            $truePath = '/volume2/' . $cleanPath;
        } else {
            // File not on disk — skip, don't count in total
            continue;
        }

        $total++;
        $mtime = filemtime($truePath);

        // ----------------------------------------------------------
        // Step 3: Build ETag using ABSOLUTE truePath — same formula
        //         as serve_image.php:
        //           md5($file . filemtime($file) . $maxWidth)
        //         where $file = resolved absolute path
        //         Thumbnail maxWidth = 400
        // ----------------------------------------------------------
        $etag     = md5($truePath . $mtime . 400);
        $th_cache = __DIR__ . '/cache/th_' . $etag . '.jpg';

        if (file_exists($th_cache)) {
            $cached++;
        } else {
            // Return the full URL so view.html can request it from
            // serve_image.php to trigger on-demand generation
            $missing[] = $fullUrl;
        }
    }

    echo json_encode([
        'total'   => $total,
        'cached'  => $cached,
        'missing' => $missing
    ]);
    exit;
}
?>
