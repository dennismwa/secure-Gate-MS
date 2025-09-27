<?php
require_once 'config/database.php';

header('Content-Type: application/json');

$database = new Database();
$db = $database->getConnection();

// Check if session is valid
$session = checkSession($db);

if ($session) {
    echo json_encode([
        'valid' => true,
        'operator' => [
            'id' => $session['operator_id'],
            'name' => $session['operator_name'],
            'role' => $session['role']
        ],
        'expires_in' => strtotime($session['expires_at']) - time()
    ]);
} else {
    echo json_encode([
        'valid' => false,
        'redirect' => 'login.php'
    ]);
}
?>