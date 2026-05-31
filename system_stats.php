<?php
// FILE: api/system_stats.php
// PURPOSE: Returns live NAS metrics — CPU, RAM, disk usage, cache size,
//          and active viewer count — for the Admin Panel dashboard.

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db_config.php';

// Bug 12 Fix: System stats expose internal server information.
// Must be admin-only so clients cannot see CPU/RAM/disk details.
verifyAdminToken();

header('Content-Type: application/json');

$cacheDir    = __DIR__ . '/cache/';
$volume_path = '/volume1/';

// ------------------------------------------------------------------
// 1. CACHE SIZE
//    Uses opendir() to avoid loading all filenames into RAM at once
//    (same reason as clear_cache.php — protects against OOM on large
//    caches with tens of thousands of files).
// ------------------------------------------------------------------
$cacheSize = 0;

if (is_dir($cacheDir)) {
    $dir = @opendir($cacheDir);
    if ($dir) {
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;
            $fp = $cacheDir . $file;
            if (is_file($fp)) {
                $cacheSize += filesize($fp);
            }
        }
        closedir($dir);
    }
}

$cacheMB      = $cacheSize / 1048576;
$cacheDisplay = ($cacheMB > 1024)
              ? round($cacheMB / 1024, 2) . ' GB'
              : round($cacheMB, 2) . ' MB';

// ------------------------------------------------------------------
// 2. NAS STORAGE (total disk on /volume1)
// ------------------------------------------------------------------
$diskTotal = @disk_total_space($volume_path);
$diskFree  = @disk_free_space($volume_path);

// Guard against disk_total_space returning false on some Synology versions
if (!$diskTotal || $diskTotal <= 0) {
    $diskPct = 0;
} else {
    $diskPct = round((($diskTotal - $diskFree) / $diskTotal) * 100, 1);
}

// ------------------------------------------------------------------
// 3. CPU LOAD
//    sys_getloadavg() returns 1/5/15 minute averages.
//    We multiply the 1-minute average by 10 as a rough % estimate
//    (single-core NAS: load of 10 ≈ 100% busy).
//    Capped at 100 to avoid showing > 100% on multi-queue systems.
// ------------------------------------------------------------------
$load    = sys_getloadavg();
$cpuLoad = min(round($load[0] * 10, 1), 100);

// ------------------------------------------------------------------
// 4. RAM USAGE from /proc/meminfo
//    MemAvailable is a better metric than MemFree because it
//    accounts for reclaimable cache memory.
// ------------------------------------------------------------------
$memTotal = 1;
$memFree  = 0;

if (is_readable('/proc/meminfo')) {
    $stats = @file_get_contents('/proc/meminfo');
    if ($stats) {
        preg_match('/MemTotal:\s+(\d+) kB/',     $stats, $mt);
        preg_match('/MemAvailable:\s+(\d+) kB/', $stats, $ma);
        $memTotal = isset($mt[1]) ? (int)$mt[1] : 1;
        $memFree  = isset($ma[1]) ? (int)$ma[1] : 0;
    }
}

$ramPct = ($memTotal > 1)
        ? round((($memTotal - $memFree) / $memTotal) * 100, 1)
        : 0;

// ------------------------------------------------------------------
// 5. LIVE TRAFFIC
//    Counts devices that sent a ping in the last 60 seconds.
//    Written by track.php and ping.php.
// ------------------------------------------------------------------
$trackerFile = $cacheDir . 'active_users.json';
$activeCount = 0;

if (file_exists($trackerFile)) {
    $users = json_decode(file_get_contents($trackerFile), true);
    if (is_array($users)) {
        $now = time();
        foreach ($users as $ip => $lastSeen) {
            // Count devices active in last 60 seconds (matches ping.php 120s cleanup window)
            if ($now - $lastSeen < 60) {
                $activeCount++;
            }
        }
    }
}

// ------------------------------------------------------------------
// 6. TRAFFIC MANAGER STATS
// ------------------------------------------------------------------
require_once __DIR__ . '/traffic_manager.php';
$trafficStats = TrafficManager::getStats();

echo json_encode([
    'success'           => true,
    'cpu'               => $cpuLoad,
    'ram'               => $ramPct,
    'disk'              => $diskPct,
    'cache_display'     => $cacheDisplay,
    'traffic'           => $activeCount,
    // Extended traffic stats for admin panel
    'traffic_stats'     => $trafficStats,
]);
?>
