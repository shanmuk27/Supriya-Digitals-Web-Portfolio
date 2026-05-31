<?php
// FILE: api/upload.php

// 1. REQUIRED CORS HEADERS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db_config.php';

// Admin endpoints require token — upload is an admin action
verifyAdminToken();

header('Content-Type: application/json');

ini_set('display_errors', 0);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;

$title      = $_POST['title']      ?? '';
$type       = $_POST['type']       ?? '';
$visibility = $_POST['visibility'] ?? 'public';

// Accept the link from the Admin panel, fall back to older field names
$link_data    = $_POST['file_path']      ?? $_POST['external_link'] ?? '';
$pdf_link     = $_POST['pdf_link']       ?? $link_data;
$youtube_link = $_POST['youtube_link']   ?? $link_data;

$frontend_batch_id = $_POST['batch_id'] ?? null;

// NAS upload root — physical destination on disk
$web_root   = '/volume1/Kalyani/';
$target_dir = $web_root . 'Uploads/' . date('Y-m') . '/';

if (!file_exists($target_dir)) @mkdir($target_dir, 0777, true);

try {
    $pdo->beginTransaction();

    $uploaded_files = $_FILES['media_file']   ?? null;
    // Bug 3 Fix: separate field for album cover image
    $cover_file     = $_FILES['cover_image']  ?? null;

    // Determine batch ID
    if (!empty($frontend_batch_id)) {
        $batch_id = $frontend_batch_id;
    } else {
        $batch_id = ($type === 'work'
                     && isset($uploaded_files['name'])
                     && is_array($uploaded_files['name']))
                  ? uniqid('batch_')
                  : null;
    }

    $files_to_process = [];

    // -------------------------------------------------------
    // 1. HANDLE IMAGES (type = work)
    // -------------------------------------------------------
    if ($type === 'work') {
        if (isset($uploaded_files['name']) && is_array($uploaded_files['name'])) {
            foreach ($uploaded_files['name'] as $i => $name) {
                if ($uploaded_files['error'][$i] === UPLOAD_ERR_OK) {
                    $files_to_process[] = [
                        'name'     => $name,
                        'tmp_name' => $uploaded_files['tmp_name'][$i],
                        'title'    => $title
                    ];
                }
            }
        } elseif (isset($uploaded_files['name']) && !is_array($uploaded_files['name'])) {
            // Single file upload
            if ($uploaded_files['error'] === UPLOAD_ERR_OK) {
                $files_to_process[] = [
                    'name'     => $uploaded_files['name'],
                    'tmp_name' => $uploaded_files['tmp_name'],
                    'title'    => $title
                ];
            }
        }

    // -------------------------------------------------------
    // 2. HANDLE ALBUMS (type = album)
    //    Bug 3 Fix: Accept an optional cover image file upload
    //    separately from the PDF/Drive URL.
    //
    //    Scenarios:
    //      A) Cover image uploaded + PDF URL provided
    //         → file_path = cover image path (for thumbnail)
    //         → pdf_link  = the Google Drive / PDF URL
    //      B) No cover image, only PDF URL
    //         → file_path = pdf_link (thumbnail will just show
    //                        the PDF icon via serve_image fallback)
    //         → pdf_link  = the Google Drive / PDF URL
    // -------------------------------------------------------
    } elseif ($type === 'album') {

        $cover_path = null;

        // Try to save a physical cover image if one was uploaded
        if (!empty($cover_file['tmp_name'])
            && $cover_file['error'] === UPLOAD_ERR_OK) {

            $ext          = strtolower(pathinfo($cover_file['name'], PATHINFO_EXTENSION));
            $allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];

            if (in_array($ext, $allowed_exts)) {
                $coverFileName = time() . '_albumcover_' . basename($cover_file['name']);
                $coverDestPath = $target_dir . $coverFileName;

                if (move_uploaded_file($cover_file['tmp_name'], $coverDestPath)) {
                    // Store as relative path — matches how work images are stored
                    $cover_path = 'Kalyani/Uploads/' . date('Y-m') . '/' . $coverFileName;
                }
            }
        }

        if (!empty($pdf_link)) {
            $files_to_process[] = [
                // If a cover was uploaded use it as the display thumbnail,
                // otherwise fall back to the PDF/Drive URL itself
                'file_path' => $cover_path ?? $pdf_link,
                'pdf_link'  => $pdf_link,
                'title'     => $title
            ];
        } elseif (!empty($cover_path)) {
            // Cover uploaded but no PDF link — store cover as both paths
            $files_to_process[] = [
                'file_path' => $cover_path,
                'pdf_link'  => null,
                'title'     => $title
            ];
        } else {
            // Nothing provided
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Please provide a PDF link or cover image for the album.']);
            exit();
        }

    // -------------------------------------------------------
    // 3. HANDLE YOUTUBE VIDEOS (type = teaser or live)
    // -------------------------------------------------------
    } elseif ($type === 'teaser' || $type === 'live') {

        $video_id = null;

        // Method 1: Standard watch URL  youtube.com/watch?v=ID
        parse_str(parse_url($youtube_link, PHP_URL_QUERY), $params);
        if (isset($params['v'])) {
            $video_id = $params['v'];
        }

        // Method 2: Short URL  youtu.be/ID
        if (!$video_id && strpos($youtube_link, 'youtu.be') !== false) {
            $path     = parse_url($youtube_link, PHP_URL_PATH);
            $video_id = trim($path, '/');
        }

        // Method 3: Embed / Live / Shorts URL
        if (!$video_id) {
            if (preg_match('/(?:embed|live|shorts)\/([a-zA-Z0-9_-]{11})/', $youtube_link, $matches)) {
                $video_id = $matches[1];
            }
        }

        if (!empty($video_id)) {
            $embed_url = 'https://www.youtube.com/embed/' . $video_id;
            $files_to_process[] = [
                'file_path' => $embed_url,
                'title'     => $title
            ];
        } else {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Invalid YouTube Link. Please check the URL and try again.']);
            exit();
        }
    }

    // -------------------------------------------------------
    // 4. SAVE ALL PROCESSED ITEMS TO DATABASE
    // -------------------------------------------------------
    $count = 0;

    foreach ($files_to_process as $file_data) {
        $file_path          = $file_data['file_path'] ?? null;
        $title_from_filename = null;

        // Physical file upload: move to NAS and build DB path
        if (isset($file_data['tmp_name'])) {
            $fileName = time() . '_' . basename($file_data['name']);
            if (move_uploaded_file($file_data['tmp_name'], $target_dir . $fileName)) {
                $file_path           = 'Kalyani/Uploads/' . date('Y-m') . '/' . $fileName;
                $title_from_filename = $file_data['name'];
            } else {
                // Skip files that couldn't be moved
                continue;
            }
        }

        if (empty($file_path)) continue;

        // Bug 1 Fix: Include video_category in INSERT so it's not lost.
        // For teaser/live types, read from POST field. For all others, NULL.
        $video_category = null;
        if ($type === 'teaser' || $type === 'live') {
            $raw_cat = trim($_POST['video_category'] ?? '');
            $video_category = in_array($raw_cat, ['Teaser', 'Live']) ? $raw_cat : 'Teaser';
        }

        $stmt = $pdo->prepare(
            "INSERT INTO media_items
                (title, type, file_path, pdf_link, batch_id, order_index,
                 title_from_filename, visibility, can_download, video_category)
             VALUES
                (:title, :type, :path, :pdf, :batch, 0, :t_filename, :vis, 1, :vcat)"
        );

        $stmt->execute([
            ':title'      => $file_data['title'],
            ':type'       => $type,
            ':path'       => $file_path,
            ':pdf'        => $file_data['pdf_link'] ?? (($type === 'album') ? $file_path : null),
            ':batch'      => $batch_id,
            ':t_filename' => $title_from_filename,
            ':vis'        => $visibility,
            ':vcat'       => $video_category,
        ]);

        $count++;
    }

    $pdo->commit();
    echo json_encode([
        'success'  => true,
        'message'  => "Successfully processed $count item(s)!",
        'batch_id' => $batch_id
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
