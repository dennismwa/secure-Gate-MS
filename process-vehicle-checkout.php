<?php
require_once 'config/database.php';

header('Content-Type: application/json');

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);

// Only handle POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get input data
$vehicle_id = sanitizeInput($_POST['vehicle_id'] ?? '');
$action = sanitizeInput($_POST['action'] ?? '');
$location_id = intval($_POST['location_id'] ?? 1);
$notes = sanitizeInput($_POST['notes'] ?? '');

// Validation
if (empty($vehicle_id)) {
    echo json_encode(['success' => false, 'message' => 'Vehicle ID is required']);
    exit;
}

if (!in_array($action, ['quick_checkout', 'quick_checkin'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

try {
    $db->beginTransaction();
    
    // Get vehicle information
    $stmt = $db->prepare("SELECT * FROM vehicles WHERE vehicle_id = ? AND status = 'active'");
    $stmt->execute([$vehicle_id]);
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        throw new Exception('Vehicle not found or inactive');
    }
    
    // Get last activity to determine current status
    $stmt = $db->prepare("SELECT log_type, log_timestamp FROM vehicle_logs WHERE vehicle_id = ? AND location_id = ? ORDER BY log_timestamp DESC LIMIT 1");
    $stmt->execute([$vehicle_id, $location_id]);
    $last_log = $stmt->fetch();
    
    $current_status = 'outside'; // Default status
    if ($last_log) {
        $current_status = $last_log['log_type'] === 'check_in' ? 'inside' : 'outside';
    }
    
    // Determine the appropriate action based on current status
    $log_type = '';
    if ($action === 'quick_checkout') {
        if ($current_status === 'outside') {
            throw new Exception('Vehicle is already checked out');
        }
        $log_type = 'check_out';
    } else { // quick_checkin
        if ($current_status === 'inside') {
            throw new Exception('Vehicle is already checked in');
        }
        $log_type = 'check_in';
    }
    
    // Get location information
    $stmt = $db->prepare("SELECT location_name FROM locations WHERE id = ?");
    $stmt->execute([$location_id]);
    $location = $stmt->fetch();
    $location_name = $location['location_name'] ?? 'Unknown Location';
    
    // Record the vehicle activity
    $stmt = $db->prepare("INSERT INTO vehicle_logs (vehicle_id, log_type, location_id, entry_purpose, operator_id, notes) VALUES (?, ?, ?, ?, ?, ?)");
    $success = $stmt->execute([$vehicle_id, $log_type, $location_id, 'quick_action', $session['operator_id'], $notes]);
    
    if (!$success) {
        throw new Exception('Failed to record vehicle activity');
    }
    
    // Also record in gate_logs for compatibility
    $stmt = $db->prepare("INSERT INTO gate_logs (visitor_id, vehicle_id, log_type, entry_type, location_id, operator_id, vehicle_number, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([null, $vehicle_id, $log_type, 'vehicle', $location_id, $session['operator_id'], $vehicle['license_plate'], $notes]);
    
    // Log the quick action
    $stmt = $db->prepare("INSERT INTO quick_actions_log (entity_type, entity_id, action_type, location_id, operator_id, notes) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute(['vehicle', $vehicle_id, $action, $location_id, $session['operator_id'], $notes]);
    
    // Create notification
    $action_text = $log_type === 'check_in' ? 'checked in' : 'checked out';
    createNotification($db, $log_type, 'Vehicle ' . ucfirst(str_replace('_', ' ', $log_type)), "Vehicle {$vehicle['license_plate']} has $action_text at $location_name", null, $session['operator_id']);
    
    // Log activity
    logActivity($db, $session['operator_id'], 'vehicle_quick_action', "Quick $log_type for vehicle: {$vehicle['license_plate']} at $location_name", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => "Vehicle {$vehicle['license_plate']} successfully " . str_replace('_', ' ', $action_text),
        'vehicle' => [
            'vehicle_id' => $vehicle['vehicle_id'],
            'license_plate' => $vehicle['license_plate'],
            'make' => $vehicle['make'],
            'model' => $vehicle['model']
        ],
        'action' => $log_type,
        'location' => $location_name,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    $db->rollBack();
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>