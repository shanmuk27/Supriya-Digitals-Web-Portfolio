<?php
// FILE: api/page_settings.php
// PURPOSE: Reads and writes the main site settings (hero, about, contact info, etc.)
//          to a JSON file so the admin panel can edit index.html content without
//          touching the file directly.
//
// GET  → returns current settings JSON
// POST → saves new settings (admin only)
//
// The settings file lives at: api/cache/page_settings.json

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { http_response_code(200); exit(); }

require_once 'db_config.php';
header('Content-Type: application/json');

$settingsFile = __DIR__ . '/cache/page_settings.json';

// Default settings (used when file doesn't exist yet)
$defaults = [
    'hero_tag'       => 'Welcome to Supriya Digitals Studio',
    'hero_title'     => "Capturing Life's Best Moments",
    'hero_subtitle'  => 'Professional photography & cinematography since 2001, based in Mangalagiri, AP.',
    'about_title'    => '20+ Years of Cinematic Excellence',
    'about_text'     => 'Supriya Digitals, established in 2001, is a premier photography studio in Mangalagiri. We specialise in capturing the essence of your weddings, birthdays, and corporate events with state-of-the-art equipment and a creative eye for detail.',
    'stat_years'     => '23',
    'stat_clients'   => '5000',
    'phone'          => '+91 92467 89966',
    'email'          => 'info@supriyadigitals.com',
    'address'        => '8-271 Bhargavpet, Mangalagiri, AP 522503',
    'hours'          => 'Mon–Sat: 9 AM – 8 PM, Sunday: By Appointment',
    'footer_text'    => 'Professional photography services for all your special occasions. We bring your memories to life with passion and perfection since 2001.',
    'wa_number'      => '919246789966',
    'formspree_id'   => 'mqknwbbq',
];

// ── GET: return current settings ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $settings = $defaults;
    if (file_exists($settingsFile)) {
        $saved = json_decode(file_get_contents($settingsFile), true);
        if (is_array($saved)) $settings = array_merge($defaults, $saved);
    }
    echo json_encode(['success' => true, 'settings' => $settings]);
    exit();
}

// ── POST: save settings (admin only) ─────────────────────────
verifyAdminToken();

$body = [];
if (!empty($GLOBALS['_RAW_INPUT'])) {
    $decoded = json_decode($GLOBALS['_RAW_INPUT'], true);
    if (is_array($decoded)) $body = $decoded;
}

// Only accept known keys
$settings = [];
foreach ($defaults as $key => $default) {
    if (isset($body[$key])) {
        $settings[$key] = trim((string)$body[$key]);
    }
}

if (empty($settings)) {
    echo json_encode(['success' => false, 'message' => 'No settings provided.']);
    exit();
}

// Merge with existing
$existing = $defaults;
if (file_exists($settingsFile)) {
    $saved = json_decode(file_get_contents($settingsFile), true);
    if (is_array($saved)) $existing = array_merge($defaults, $saved);
}
$merged = array_merge($existing, $settings);

if (!is_dir(dirname($settingsFile))) @mkdir(dirname($settingsFile), 0755, true);

if (file_put_contents($settingsFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
    echo json_encode(['success' => true, 'message' => 'Settings saved successfully.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Could not write settings file. Check cache/ directory permissions.']);
}
?>
