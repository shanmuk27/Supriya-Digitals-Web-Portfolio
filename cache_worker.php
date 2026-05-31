<?php
// FILE: api/cache_worker.php
// PURPOSE: Pre-generates thumbnail and lightbox cache files in the background.
//          Run from Linux CLI only — never call from browser.
// USAGE:   php cache_worker.php <batch_id>
//
// Bug 10 Fix: ETag is now built with $truePath (absolute path) to exactly
//             match the ETag formula in serve_image.php.
//             Previously it used $path (relative), so the worker generated
//             files with wrong names that serve_image.php could never find,
//             causing infinite re-caching on every page load.
//
// Size standardisation: thumbnail=400px, lightbox=1200px to match serve_image.php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/db_config.php';

$batch_id = $argv[1] ?? '';
if (!$batch_id) {
    echo "Usage: php cache_worker.php <batch_id>\n";
    exit(1);
}

$stmt = $pdo->prepare(
    "SELECT file_path FROM media_items WHERE batch_id = ? AND type IN ('work', 'link')"
);
$stmt->execute([$batch_id]);
$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($files)) {
    echo "No files found for batch_id: $batch_id\n";
    exit(0);
}

$cacheDir = __DIR__ . '/cache/';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);

$processed = 0;
$skipped   = 0;
$failed    = 0;

foreach ($files as $f) {
    // Strip domain if stored as full URL
    $rawPath = ltrim(
        str_replace(
            ['https://supriyadigitals.store/', 'http://supriyadigitals.store/'],
            '',
            $f['file_path']
        ),
        '/'
    );

    // Resolve to absolute path on disk
    $truePath = null;
    if (file_exists('/volume1/' . $rawPath)) {
        $truePath = '/volume1/' . $rawPath;
    } elseif (file_exists('/volume2/' . $rawPath)) {
        $truePath = '/volume2/' . $rawPath;
    } elseif (file_exists($rawPath)) {
        $truePath = $rawPath;
    }

    if (!$truePath || !file_exists($truePath)) {
        echo "SKIP (not found): $rawPath\n";
        $skipped++;
        continue;
    }

    $mtime = filemtime($truePath);

    // ------------------------------------------------------------------
    // Bug 10 Fix: Hash uses $truePath (absolute) — matching serve_image.php
    //   serve_image.php formula: md5($file . filemtime($file) . $maxWidth)
    //   where $file = resolved absolute path like /volume1/Kalyani/...
    // Size standardisation:
    //   Thumbnail : 400px, quality 80
    //   Lightbox  : 1200px, quality 88
    // ------------------------------------------------------------------
    $th_etag  = md5($truePath . $mtime . 400);
    $lb_etag  = md5($truePath . $mtime . 1200);
    $th_cache = $cacheDir . 'th_' . $th_etag . '.jpg';
    $lb_cache = $cacheDir . 'lb_' . $lb_etag . '.jpg';

    $generated = false;

    // --- Generate Thumbnail (400px) ---
    if (!file_exists($th_cache)) {
        $cmd = "convert"
             . " -define jpeg:size=400x400"      // RAM hint — only decode enough pixels
             . " -limit thread 1"                // single thread to protect NAS
             . " -limit memory 256MiB"
             . " -limit map 256MiB"
             . " " . escapeshellarg($truePath)
             . " -auto-orient"
             . " -resize 400x400\>"
             . " -quality 80"
             . " " . escapeshellarg($th_cache);
        exec($cmd, $output, $ret);

        if ($ret !== 0 || !file_exists($th_cache)) {
            echo "FAIL thumb: $rawPath\n";
            $failed++;
            continue;
        }
        $generated = true;
    }

    // --- Generate Lightbox (1200px) ---
    if (!file_exists($lb_cache)) {
        $cmd = "convert"
             . " -define jpeg:size=1200x1200"    // RAM hint
             . " -limit thread 1"
             . " -limit memory 256MiB"
             . " -limit map 256MiB"
             . " " . escapeshellarg($truePath)
             . " -auto-orient"
             . " -resize 1200x1200\>"
             . " -quality 88"
             . " " . escapeshellarg($lb_cache);
        exec($cmd, $output, $ret);

        if ($ret !== 0 || !file_exists($lb_cache)) {
            echo "FAIL lightbox: $rawPath\n";
            $failed++;
            continue;
        }
        $generated = true;
    }

    if ($generated) {
        echo "OK: $rawPath\n";
        $processed++;
    } else {
        echo "CACHED: $rawPath\n";
        $skipped++;
    }
}

echo "\n--- Done ---\n";
echo "Processed : $processed\n";
echo "Skipped   : $skipped (already cached)\n";
echo "Failed    : $failed\n";
?>
