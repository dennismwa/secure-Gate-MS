<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);

// Get parameters
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$report_type = $_GET['report_type'] ?? 'activity';

// Validate dates
$date_from = date('Y-m-d', strtotime($date_from));
$date_to = date('Y-m-d', strtotime($date_to));

// Log export activity
logActivity($db, $session['operator_id'], 'export_report', "Exported $report_type report for $date_from to $date_to", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);

// Set headers for CSV download
$filename = "gate_report_{$report_type}_{$date_from}_to_{$date_to}.csv";
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Expires: 0');

// Open output stream
$output = fopen('php://output', 'w');

if ($report_type === 'activity') {
    // Activity report
    $stmt = $db->prepare("SELECT 
        gl.log_timestamp,
        gl.log_type,
        v.visitor_id,
        v.full_name,
        v.phone,
        v.email,
        v.company,
        v.vehicle_number,
        gl.purpose_of_visit,
        gl.host_name,
        gl.host_department,
        gl.notes,
        go.operator_name,
        gl.gate_location
    FROM gate_logs gl
    JOIN visitors v ON gl.visitor_id = v.visitor_id
    JOIN gate_operators go ON gl.operator_id = go.id
    WHERE DATE(gl.log_timestamp) BETWEEN ? AND ?
    ORDER BY gl.log_timestamp DESC");
    
    $stmt->execute([$date_from, $date_to]);
    
    // CSV headers
    fputcsv($output, [
        'Date & Time',
        'Action',
        'Visitor ID',
        'Full Name',
        'Phone',
        'Email',
        'Company',
        'Vehicle Number',
        'Purpose of Visit',
        'Host Name',
        'Department',
        'Notes',
        'Operator',
        'Gate Location'
    ]);
    
    // Data rows
    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            date('Y-m-d H:i:s', strtotime($row['log_timestamp'])),
            ucfirst(str_replace('_', ' ', $row['log_type'])),
            $row['visitor_id'],
            $row['full_name'],
            $row['phone'],
            $row['email'],
            $row['company'],
            $row['vehicle_number'],
            $row['purpose_of_visit'],
            $row['host_name'],
            $row['host_department'],
            $row['notes'],
            $row['operator_name'],
            $row['gate_location']
        ]);
    }
    
} elseif ($report_type === 'visitors') {
    // Visitors report
    $stmt = $db->prepare("SELECT 
        v.*,
        COUNT(gl.id) as total_visits,
        MAX(CASE WHEN gl.log_type = 'check_in' THEN gl.log_timestamp END) as last_checkin,
        MAX(CASE WHEN gl.log_type = 'check_out' THEN gl.log_timestamp END) as last_checkout,
        MIN(gl.log_timestamp) as first_visit
    FROM visitors v
    LEFT JOIN gate_logs gl ON v.visitor_id = gl.visitor_id AND DATE(gl.log_timestamp) BETWEEN ? AND ?
    WHERE v.created_at >= ? OR gl.id IS NOT NULL
    GROUP BY v.visitor_id
    ORDER BY total_visits DESC, v.created_at DESC");
    
    $stmt->execute([$date_from, $date_to, $date_from]);
    
    // CSV headers
    fputcsv($output, [
        'Visitor ID',
        'Full Name',
        'Phone',
        'Email',
        'ID Number',
        'Company',
        'Vehicle Number',
        'Status',
        'Pre-registered',
        'Registration Date',
        'Total Visits',
        'First Visit',
        'Last Check-in',
        'Last Check-out',
        'Current Status'
    ]);
    
    // Data rows
    while ($row = $stmt->fetch()) {
        $current_status = 'Never Visited';
        if ($row['last_checkin'] && $row['last_checkout']) {
            $current_status = strtotime($row['last_checkin']) > strtotime($row['last_checkout']) ? 'Inside' : 'Outside';
        } elseif ($row['last_checkin']) {
            $current_status = 'Inside';
        }
        
        fputcsv($output, [
            $row['visitor_id'],
            $row['full_name'],
            $row['phone'],
            $row['email'],
            $row['id_number'],
            $row['company'],
            $row['vehicle_number'],
            ucfirst($row['status']),
            $row['is_pre_registered'] ? 'Yes' : 'No',
            date('Y-m-d H:i:s', strtotime($row['created_at'])),
            $row['total_visits'] ?? 0,
            $row['first_visit'] ? date('Y-m-d H:i:s', strtotime($row['first_visit'])) : '',
            $row['last_checkin'] ? date('Y-m-d H:i:s', strtotime($row['last_checkin'])) : '',
            $row['last_checkout'] ? date('Y-m-d H:i:s', strtotime($row['last_checkout'])) : '',
            $current_status
        ]);
    }
}

fclose($output);
exit;
?>