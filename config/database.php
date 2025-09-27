<?php
// config/database.php
class Database {
    private $host = 'localhost';
    private $db_name = 'vxjtgclw_security';
    private $username = 'vxjtgclw_security';
    private $password = 'nS%?A,O?AO]41!C6';
    public $conn;

    public function getConnection() {
        $this->conn = null;
        
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, 
                                $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            // Set timezone to Kenya (EAT - East Africa Time)
            $this->conn->exec("SET time_zone = '+03:00'");
            
        } catch(PDOException $exception) {
            echo "Connection error: " . $exception->getMessage();
        }
        
        return $this->conn;
    }
}

// Set PHP timezone to Kenya
date_default_timezone_set('Africa/Nairobi');

// Global functions
function generateUniqueId($prefix = '') {
    return $prefix . strtoupper(uniqid());
}

function generateQRCode($data) {
    return hash('sha256', $data . time() . rand(1000, 9999));
}

function sanitizeInput($input) {
    return htmlspecialchars(strip_tags(trim($input)));
}

function validatePhone($phone) {
    return preg_match('/^[+]?[\d\s\-()]{10,15}$/', $phone);
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function logActivity($db, $operator_id, $activity_type, $description, $ip_address = null, $user_agent = null) {
    try {
        $stmt = $db->prepare("INSERT INTO activity_logs (operator_id, activity_type, description, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$operator_id, $activity_type, $description, $ip_address, $user_agent]);
    } catch (Exception $e) {
        error_log("Failed to log activity: " . $e->getMessage());
    }
}

// Session management
session_start();

function checkSession($db) {
    if (!isset($_SESSION['operator_id']) || !isset($_SESSION['session_token'])) {
        return false;
    }
    
    $stmt = $db->prepare("SELECT os.*, go.operator_name, go.role FROM operator_sessions os 
                         JOIN gate_operators go ON os.operator_id = go.id 
                         WHERE os.session_token = ? AND os.expires_at > NOW()");
    $stmt->execute([$_SESSION['session_token']]);
    $session = $stmt->fetch();
    
    if (!$session) {
        session_destroy();
        return false;
    }
    
    // Update session expiry
    $stmt = $db->prepare("UPDATE operator_sessions SET expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE session_token = ?");
    $stmt->execute([$_SESSION['session_token']]);
    
    return $session;
}

function requireAuth($db) {
    $session = checkSession($db);
    if (!$session) {
        header('Location: login.php');
        exit;
    }
    return $session;
}

function createSession($db, $operator_id) {
    // Clean old sessions
    $stmt = $db->prepare("DELETE FROM operator_sessions WHERE operator_id = ? OR expires_at < NOW()");
    $stmt->execute([$operator_id]);
    
    // Create new session
    $session_token = bin2hex(random_bytes(32));
    $stmt = $db->prepare("INSERT INTO operator_sessions (operator_id, session_token, expires_at, created_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())");
    $stmt->execute([$operator_id, $session_token]);
    
    $_SESSION['operator_id'] = $operator_id;
    $_SESSION['session_token'] = $session_token;
    
    return $session_token;
}

function getSettings($db) {
    static $settings = null;
    
    if ($settings === null) {
        $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
        $settings = [];
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    
    return $settings;
}

function getSetting($db, $key, $default = '') {
    $settings = getSettings($db);
    return isset($settings[$key]) ? $settings[$key] : $default;
}

function updateSetting($db, $key, $value) {
    $stmt = $db->prepare("UPDATE settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?");
    $result = $stmt->execute([$value, $key]);
    
    if ($stmt->rowCount() == 0) {
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
        $result = $stmt->execute([$key, $value]);
    }
    
    return $result;
}

// Error handling
function handleError($message, $redirect = true) {
    error_log($message);
    if ($redirect) {
        $_SESSION['error'] = $message;
        header('Location: ' . $_SERVER['HTTP_REFERER']);
        exit;
    }
    return false;
}

function setMessage($message, $type = 'success') {
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $type;
}

function getMessage() {
    if (isset($_SESSION['message'])) {
        $message = $_SESSION['message'];
        $type = $_SESSION['message_type'] ?? 'info';
        unset($_SESSION['message'], $_SESSION['message_type']);
        return ['message' => $message, 'type' => $type];
    }
    return null;
}

// Notification functions
function createNotification($db, $type, $title, $message, $visitor_id = null, $operator_id = null) {
    try {
        $stmt = $db->prepare("INSERT INTO notifications (type, title, message, visitor_id, operator_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        return $stmt->execute([$type, $title, $message, $visitor_id, $operator_id]);
    } catch (Exception $e) {
        error_log("Failed to create notification: " . $e->getMessage());
        return false;
    }
}

function getUnreadNotificationsCount($db) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE is_read = 0");
        $stmt->execute();
        return $stmt->fetch()['count'];
    } catch (Exception $e) {
        return 0;
    }
}

// Time zone helper functions
function getCurrentKenyaTime($format = 'Y-m-d H:i:s') {
    return date($format);
}

function convertToKenyaTime($timestamp, $format = 'Y-m-d H:i:s') {
    $dt = new DateTime($timestamp);
    $dt->setTimezone(new DateTimeZone('Africa/Nairobi'));
    return $dt->format($format);
}

function getKenyaDate($format = 'Y-m-d') {
    return date($format);
}

function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . ' min ago';
    if ($time < 86400) return floor($time/3600) . ' hrs ago';
    if ($time < 2592000) return floor($time/86400) . ' days ago';
    if ($time < 31536000) return floor($time/2592000) . ' months ago';
    return floor($time/31536000) . ' years ago';
}
?>