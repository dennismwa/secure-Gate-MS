<?php
require_once 'config/database.php';

header('Content-Type: application/json');

$database = new Database();
$db = $database->getConnection();

try {
    $session = requireAuth($db);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$visitor_id = sanitizeInput($_POST['visitor_id'] ?? '');

if (empty($visitor_id)) {
    echo json_encode(['success' => false, 'message' => 'Visitor ID is required']);
    exit;
}

// Check if visitor exists
$stmt = $db->prepare("SELECT visitor_id FROM visitors WHERE visitor_id = ?");
$stmt->execute([$visitor_id]);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Visitor not found']);
    exit;
}

if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    $error_msg = 'No valid photo uploaded';
    if (isset($_FILES['photo']['error'])) {
        switch($_FILES['photo']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $error_msg = 'File too large';
                break;
            case UPLOAD_ERR_PARTIAL:
                $error_msg = 'File was only partially uploaded';
                break;
            case UPLOAD_ERR_NO_FILE:
                $error_msg = 'No file was uploaded';
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
                $error_msg = 'Missing temporary folder';
                break;
            case UPLOAD_ERR_CANT_WRITE:
                $error_msg = 'Failed to write file to disk';
                break;
            case UPLOAD_ERR_EXTENSION:
                $error_msg = 'File upload stopped by extension';
                break;
            default:
                $error_msg = 'Unknown upload error';
        }
    }
    echo json_encode(['success' => false, 'message' => $error_msg]);
    exit;
}

$file = $_FILES['photo'];
$allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
$max_size = 5 * 1024 * 1024; // 5MB

// Get actual mime type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

// Validate file type
if (!in_array($mime_type, $allowed_types) && !in_array($file['type'], $allowed_types)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, and GIF are allowed']);
    exit;
}

// Validate file size
if ($file['size'] > $max_size) {
    echo json_encode(['success' => false, 'message' => 'File too large. Maximum size is 5MB']);
    exit;
}

// Create upload directory if it doesn't exist
$upload_dir = 'uploads/photos/visitors/';
if (!is_dir($upload_dir)) {
    if (!mkdir($upload_dir, 0755, true)) {
        echo json_encode(['success' => false, 'message' => 'Failed to create upload directory']);
        exit;
    }
}

// Generate unique filename
$file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (empty($file_extension)) {
    // Determine extension from mime type
    switch($mime_type) {
        case 'image/jpeg':
            $file_extension = 'jpg';
            break;
        case 'image/png':
            $file_extension = 'png';
            break;
        case 'image/gif':
            $file_extension = 'gif';
            break;
        default:
            $file_extension = 'jpg';
    }
}

$filename = $visitor_id . '_' . time() . '.' . $file_extension;
$file_path = $upload_dir . $filename;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $file_path)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file']);
    exit;
}

try {
    // Remove old photo if exists
    $stmt = $db->prepare("SELECT photo_path FROM visitors WHERE visitor_id = ?");
    $stmt->execute([$visitor_id]);
    $old_data = $stmt->fetch();
    
    if ($old_data && $old_data['photo_path'] && file_exists($old_data['photo_path'])) {
        unlink($old_data['photo_path']);
    }
    
    // Update visitor with new photo path
    $stmt = $db->prepare("UPDATE visitors SET photo_path = ? WHERE visitor_id = ?");
    $stmt->execute([$file_path, $visitor_id]);
    
    // Log the photo upload in visitor_photos table if it exists
    try {
        $stmt = $db->prepare("INSERT INTO visitor_photos (visitor_id, photo_path, file_size, mime_type, uploaded_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$visitor_id, $file_path, $file['size'], $mime_type, $session['operator_id']]);
    } catch (Exception $e) {
        // Table might not exist, continue anyway
        error_log("Visitor photos table error: " . $e->getMessage());
    }
    
    // Log activity
    logActivity($db, $session['operator_id'], 'photo_upload', "Uploaded photo for visitor: $visitor_id", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Photo uploaded successfully',
        'photo_path' => $file_path,
        'filename' => $filename
    ]);
    
} catch (Exception $e) {
    // Remove uploaded file if database update fails
    if (file_exists($file_path)) {
        unlink($file_path);
    }
    
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>