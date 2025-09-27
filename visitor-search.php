<?php
require_once 'config/database.php';

header('Content-Type: application/json');

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);

$search = sanitizeInput($_GET['q'] ?? '');

if (strlen($search) < 2) {
    echo json_encode(['visitors' => []]);
    exit;
}

try {
    $stmt = $db->prepare("
        SELECT v.*, 
               (SELECT log_type FROM gate_logs gl WHERE gl.visitor_id = v.visitor_id ORDER BY log_timestamp DESC LIMIT 1) as last_action,
               (SELECT log_timestamp FROM gate_logs gl WHERE gl.visitor_id = v.visitor_id ORDER BY log_timestamp DESC LIMIT 1) as last_activity
        FROM visitors v 
        WHERE (v.full_name LIKE ? OR v.phone LIKE ? OR v.company LIKE ? OR v.vehicle_number LIKE ? OR v.visitor_id LIKE ?) 
        AND v.status = 'active'
        ORDER BY v.full_name ASC 
        LIMIT 10
    ");
    
    $search_param = "%$search%";
    $stmt->execute([$search_param, $search_param, $search_param, $search_param, $search_param]);
    $visitors = $stmt->fetchAll();
    
    $result = [];
    foreach ($visitors as $visitor) {
        $current_status = 'Outside';
        if ($visitor['last_action'] === 'check_in') {
            $current_status = 'Inside';
        }
        
        $result[] = [
            'visitor_id' => $visitor['visitor_id'],
            'full_name' => $visitor['full_name'],
            'phone' => $visitor['phone'],
            'company' => $visitor['company'],
            'vehicle_number' => $visitor['vehicle_number'],
            'current_status' => $current_status,
            'last_activity' => $visitor['last_activity'] ? date('M j, g:i A', strtotime($visitor['last_activity'])) : 'Never visited',
            'qr_code' => $visitor['qr_code']
        ];
    }
    
    echo json_encode(['visitors' => $result]);
    
} catch (Exception $e) {
    error_log("Visitor search error: " . $e->getMessage());
    echo json_encode(['visitors' => [], 'error' => 'Search failed']);
}
?>