<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);

// Only admin can reset passwords
if ($session['role'] !== 'admin') {
    setMessage('Access denied. Admin privileges required.', 'error');
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $operator_id = intval($_POST['operator_id']);
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validation
    $errors = [];
    if (empty($new_password)) $errors[] = 'New password is required';
    if (strlen($new_password) < 6) $errors[] = 'Password must be at least 6 characters';
    if ($new_password !== $confirm_password) $errors[] = 'Passwords do not match';
    
    // Check if operator exists and is not the current user
    $stmt = $db->prepare("SELECT operator_name FROM gate_operators WHERE id = ? AND id != ?");
    $stmt->execute([$operator_id, $session['operator_id']]);
    $operator = $stmt->fetch();
    
    if (!$operator) {
        $errors[] = 'Invalid operator or cannot reset own password';
    }
    
    if (empty($errors)) {
        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        
        $stmt = $db->prepare("UPDATE gate_operators SET password_hash = ?, updated_at = NOW() WHERE id = ?");
        if ($stmt->execute([$password_hash, $operator_id])) {
            // Invalidate all sessions for this operator
            $stmt = $db->prepare("DELETE FROM operator_sessions WHERE operator_id = ?");
            $stmt->execute([$operator_id]);
            
            logActivity($db, $session['operator_id'], 'password_reset', "Reset password for operator: {$operator['operator_name']}", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
            setMessage("Password reset successfully for {$operator['operator_name']}", 'success');
        } else {
            setMessage('Failed to reset password', 'error');
        }
    } else {
        setMessage(implode(', ', $errors), 'error');
    }
} else {
    setMessage('Invalid request method', 'error');
}

header('Location: operators.php');
exit;
?>