<?php
// FILE: api/link_folder.php
// PURPOSE: Bulk-registers all image files from a NAS folder into the
//          database as a single batch, without physically moving files.

// 1. REQUIRED CORS HEADERS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db_config.php';

// Linking folders is an admin operation
verifyAdminToken();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;

$title      = $_POST['title']      ?? '';
$folder_path = $_POST['folder_path'] ?? '';
$visibility = $_POST['visibility'] ?? 'public';

// Clean the path (remove leading/trailing slashes)
$folder_path = trim($folder_path, '/');

// Security: Prevent path traversal attacks
if (strpos($folder_path, '..') !== false) {
    echo json_encode(['success' => false, 'message' => 'Invalid path.']);
    exit;
}

// Base root on the NAS — all client folders live under Kalyani
$base_root = '/volume1/Kalyani/';
$full_dir  = rtrim($base_root . $folder_path, '/');

// Verify directory exists and is strictly inside the Kalyani root
// (realpath resolves symlinks so traversal tricks won't work)
$real_base = realpath($base_root);
$real_dir  = realpath($full_dir);

if ($real_dir === false || strpos($real_dir, $real_base) !== 0) {
    echo json_encode(['success' => false, 'message' => "Folder not found or outside Kalyani root: '$full_dir'."]);
    exit;
}

if (!is_dir($real_dir)) {
    echo json_encode(['success' => false, 'message' => "Not a directory: '$full_dir'."]);
    exit;
}

// ------------------------------------------------------------------
// Bug 9 Fix: Extension check only uses lowercase values.
// Previously the array included 'JPG', 'JPEG', 'PNG' which can
// NEVER match because $ext is always lowercased by strtolower().
// Those entries were dead code and have been removed.
// ------------------------------------------------------------------
$allowed_extensions = ['jpg', 'jpeg', 'png', 'webp'];

$files    = scandir($real_dir);
$batch_id = uniqid('batch_');
$count    = 0;

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "INSERT INTO media_items
            (title, type, file_path, batch_id, order_index,
             title_from_filename, visibility, can_download)
         VALUES
            (:title, 'work', :path, :batch, 0, :t_filename, :vis, 1)"
    );

    foreach ($files as $file) {
        // Skip . and .. directory entries
        if ($file === '.' || $file === '..') continue;

        // Skip Synology internal folders and recycle bin
        if ($file === '@eaDir' || $file === '#recycle') continue;

        // Skip hidden files (files starting with .)
        if (strpos($file, '.') === 0) continue;

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        // Only register recognised image types
        if (!in_array($ext, $allowed_extensions)) continue;

        // Build the DB path relative to /volume1/
        // This is what serve_image.php expects to find under /volume1/
        $db_path = 'Kalyani/' . $folder_path . '/' . $file;

        // Clean any accidental double slashes
        $db_path = preg_replace('#/+#', '/', $db_path);

        $stmt->execute([
            ':title'      => $title,
            ':path'       => $db_path,
            ':batch'      => $batch_id,
            ':t_filename' => $file,
            ':vis'        => $visibility
        ]);

        $count++;
    }

    $pdo->commit();

    echo json_encode([
        'success'  => true,
        'message'  => "Successfully linked $count images!",
        'batch_id' => $batch_id
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
}
?>
