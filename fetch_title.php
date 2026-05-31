<?php
// FILE: api/fetch_title.php
// PURPOSE: Fetches a YouTube video's title via YouTube's official
//          oEmbed API (no API key required) and returns it to the
//          Admin Panel so the title field auto-fills when a YouTube
//          URL is pasted.
//
// Bug 12 Fix: This file makes outbound HTTP requests on behalf of the
//             caller. Admin-only to prevent abuse as an open proxy.
//             Also added cURL timeout to prevent the NAS PHP process
//             from hanging indefinitely if YouTube is slow.

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

// Silence errors so they don't contaminate the JSON response
ini_set('display_errors', 0);

$url = trim($_GET['url'] ?? '');

if (empty($url)) {
    echo json_encode(['error' => 'No URL provided', 'title' => '']);
    exit;
}

// Basic sanity check — must look like a YouTube URL before we
// send it to the oEmbed endpoint
if (strpos($url, 'youtube.com') === false && strpos($url, 'youtu.be') === false) {
    echo json_encode(['error' => 'Not a YouTube URL', 'title' => '']);
    exit;
}

// YouTube's official oEmbed endpoint — no API key needed
$oembed_url = 'https://www.youtube.com/oembed?url='
            . urlencode($url)
            . '&format=json';

// ------------------------------------------------------------------
// Fetch via cURL (most reliable on Synology NAS)
// ------------------------------------------------------------------
if (function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,            $oembed_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS,      3);

    // Timeouts: 5s to connect, 10s total
    // Prevents the NAS PHP worker from hanging on a slow YouTube response
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT,        10);

    // Skip SSL certificate verification on local/self-signed NAS setups
    // (same approach as the original — YouTube uses valid certs but
    //  the NAS CA bundle may be outdated)
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (SupriyaDigitalsStudio/2.0)');

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($http_code === 200 && $response) {
        // YouTube returned valid JSON — pass it straight through
        // Response shape: { "title": "Video Name", "author_name": "...", ... }
        echo $response;
    } elseif ($http_code === 404 || $http_code === 401) {
        // Video is private, deleted, or not embeddable
        echo json_encode(['title' => '', 'error' => 'Video not available or private.']);
    } else {
        echo json_encode(['title' => '', 'error' => "oEmbed request failed (HTTP $http_code)."]);
    }

} else {
    // ------------------------------------------------------------------
    // Fallback: file_get_contents (works if cURL is not installed)
    // ------------------------------------------------------------------
    $context = stream_context_create([
        'http' => [
            'timeout'    => 10,
            'user_agent' => 'Mozilla/5.0 (SupriyaDigitalsStudio/2.0)'
        ]
    ]);

    $data = @file_get_contents($oembed_url, false, $context);

    if ($data) {
        echo $data;
    } else {
        echo json_encode(['title' => '', 'error' => 'cURL unavailable and file_get_contents failed.']);
    }
}
?>
