<?php
require_once 'config/database.php';

header('Content-Type: application/json');

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);

try {
    $stmt = $db->prepare("SELECT 
        COUNT(CASE WHEN log_type = 'check_in' AND DATE(log_timestamp) = CURDATE() THEN 1 END) as today_check_ins,
        COUNT(CASE WHEN log_type = 'check_out' AND DATE(log_timestamp) = CURDATE() THEN 1 END) as today_check_outs,
        COUNT(DISTINCT CASE WHEN DATE(log_timestamp) = CURDATE() THEN visitor_id END) as today_unique_visitors
    FROM gate_logs");
    $stmt->execute();
    $today_stats = $stmt->fetch();

    $stmt = $db->prepare("SELECT COUNT(*) as inside_count FROM (
        SELECT visitor_id, 
               MAX(CASE WHEN log_type = 'check_in' THEN log_timestamp END) as last_checkin,
               MAX(CASE WHEN log_type = 'check_out' THEN log_timestamp END) as last_checkout
        FROM gate_logs 
        GROUP BY visitor_id
        HAVING last_checkin IS NOT NULL AND (last_checkout IS NULL OR last_checkin > last_checkout)
    ) as inside_visitors");
    $stmt->execute();
    $inside_count = $stmt->fetch()['inside_count'];

    $stmt = $db->prepare("SELECT COUNT(*) as pending_count FROM pre_registrations WHERE status = 'pending' AND visit_date >= CURDATE()");
    $stmt->execute();
    $pending_prereg = $stmt->fetch()['pending_count'];

    $stmt = $db->prepare("SELECT 
        gl.log_type, gl.log_timestamp, 
        v.full_name, v.phone, v.company,
        go.operator_name
    FROM gate_logs gl
    JOIN visitors v ON gl.visitor_id = v.visitor_id
    JOIN gate_operators go ON gl.operator_id = go.id
    ORDER BY gl.log_timestamp DESC
    LIMIT 5");
    $stmt->execute();
    $recent_activities = $stmt->fetchAll();

    $formatted_activities = [];
    foreach ($recent_activities as $activity) {
        $formatted_activities[] = [
            'type' => $activity['log_type'],
            'visitor_name' => $activity['full_name'],
            'company' => $activity['company'],
            'operator' => $activity['operator_name'],
            'time' => date('g:i A', strtotime($activity['log_timestamp'])),
            'time_ago' => time_elapsed_string($activity['log_timestamp'])
        ];
    }

    echo json_encode([
        'success' => true,
        'stats' => [
            'today_check_ins' => (int)$today_stats['today_check_ins'],
            'today_check_outs' => (int)$today_stats['today_check_outs'],
            'today_unique_visitors' => (int)$today_stats['today_unique_visitors'],
            'currently_inside' => (int)$inside_count,
            'pending_prereg' => (int)$pending_prereg
        ],
        'recent_activities' => $formatted_activities,
        'timestamp' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    error_log("Stats API error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to fetch statistics']);
}

function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}
?>