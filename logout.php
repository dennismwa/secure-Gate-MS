<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Log activity if session exists
if (isset($_SESSION['operator_id']) && isset($_SESSION['session_token'])) {
    // Get operator info for logging
    $stmt = $db->prepare("SELECT operator_name FROM gate_operators WHERE id = ?");
    $stmt->execute([$_SESSION['operator_id']]);
    $operator = $stmt->fetch();
    
    if ($operator) {
        logActivity($db, $_SESSION['operator_id'], 'logout', 'Operator logged out', $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
    }
    
    // Remove session from database
    $stmt = $db->prepare("DELETE FROM operator_sessions WHERE session_token = ?");
    $stmt->execute([$_SESSION['session_token']]);
}

// Destroy session
session_destroy();

// Redirect to login
header('Location: login.php');
exit;
?>