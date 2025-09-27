<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);

// Only admin can reset passwords
if ($session['role'] !== 'admin') {
    setMessage('Access denied. Admin privileges required.', 'error');
    header('Location: operators.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $operator_id = intval($_POST['operator_id']);
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    $errors = [];
    
    if (empty($new_password)) {
        $errors[] = 'New password is required';
    } elseif (strlen($new_password) < 6) {
        $errors[] = 'Password must be at least 6 characters long';
    }
    
    if ($new_password !== $confirm_password) {
        $errors[] = 'Passwords do not match';
    }
    
    // Check if operator exists
    $stmt = $db->prepare("SELECT operator_name FROM gate_operators WHERE id = ?");
    $stmt->execute([$operator_id]);
    $operator = $stmt->fetch();
    
    if (!$operator) {
        $errors[] = 'Operator not found';
    }
    
    if (empty($errors)) {
        try {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            
            $stmt = $db->prepare("UPDATE gate_operators SET password_hash = ?, password_changed_at = NOW(), must_change_password = 0 WHERE id = ?");
            
            if ($stmt->execute([$password_hash, $operator_id])) {
                // Log the password reset
                logActivity($db, $session['operator_id'], 'password_reset', "Reset password for operator: {$operator['operator_name']}", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
                
                setMessage("Password reset successfully for {$operator['operator_name']}", 'success');
            } else {
                setMessage('Failed to reset password', 'error');
            }
        } catch (Exception $e) {
            error_log("Password reset error: " . $e->getMessage());
            setMessage('An error occurred while resetting the password', 'error');
        }
    } else {
        foreach ($errors as $error) {
            setMessage($error, 'error');
            break; // Show only first error
        }
    }
}

header('Location: operators.php');
exit;
?>
