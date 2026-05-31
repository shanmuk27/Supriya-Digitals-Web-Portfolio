<?php
// FILE: api/db_config.php
// -------------------------------------------------------
// SECURITY: Move credentials to a file OUTSIDE the web
// root. Create /volume1/private/config.php with:
//
//   <?php
//   define('DB_HOST',     'localhost');
//   define('DB_PORT',     '3306');
//   define('DB_NAME',     'studio_db');
//   define('DB_USER',     'studio_user');   // NOT root
//   define('DB_PASS',     'your-strong-password');
//   define('ADMIN_TOKEN', 'your-secret-token');
//
// Then uncomment the require_once below and remove the
// hardcoded values.
// -------------------------------------------------------

// 1. CORS
$allowed_origin = 'https://supriyadigitals.store';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin === $allowed_origin || strpos($origin, 'localhost') !== false || strpos($origin, '127.0.0.1') !== false) {
    header("Access-Control-Allow-Origin: " . ($origin ?: $allowed_origin));
} else {
    header("Access-Control-Allow-Origin: $allowed_origin");
}
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// 2. HIDE PHP ERRORS
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

// 3. READ php://input ONCE
if (!isset($GLOBALS['_RAW_INPUT'])) {
    $GLOBALS['_RAW_INPUT'] = file_get_contents('php://input');
}

// 4. CREDENTIALS — load from outside web root when ready:
// require_once '/volume1/private/config.php';

if (!defined('ADMIN_TOKEN')) define('ADMIN_TOKEN', 'SupriyaAdmin@2025Secret');
if (!defined('DB_HOST'))     define('DB_HOST',     'localhost');
if (!defined('DB_PORT'))     define('DB_PORT',     '3306');
if (!defined('DB_NAME'))     define('DB_NAME',     'studio_db');
if (!defined('DB_USER'))     define('DB_USER',     'root');
if (!defined('DB_PASS'))     define('DB_PASS',     'Venkat@1973');

// 5. DATABASE CONNECTION
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    error_log('[Studio] DB connection failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Service temporarily unavailable. Please try again.']);
    exit();
}

// 6. ADMIN TOKEN VALIDATOR
function verifyAdminToken() {
    // Accept from Authorization: Bearer header
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
        if (hash_equals(ADMIN_TOKEN, $m[1])) return;
    }

    // Accept from JSON body (already-read stream)
    $json = [];
    if (!empty($GLOBALS['_RAW_INPUT'])) {
        $decoded = json_decode($GLOBALS['_RAW_INPUT'], true);
        if (is_array($decoded)) $json = $decoded;
    }

    $token = $_GET['admin_token'] ?? $_POST['admin_token'] ?? ($json['admin_token'] ?? '');

    if (empty($token) || !hash_equals(ADMIN_TOKEN, $token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
}
?>
