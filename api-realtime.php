<?php
require_once 'config/database.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);

$endpoint = $_GET['endpoint'] ?? '';

try {
    switch ($endpoint) {
        case 'dashboard_stats':
            $stmt = $db->prepare("SELECT 
                COUNT(CASE WHEN log_type = 'check_in' AND DATE(log_timestamp) = CURDATE() THEN 1 END) as today_check_ins,
                COUNT(CASE WHEN log_type = 'check_out' AND DATE(log_timestamp) = CURDATE() THEN 1 END) as today_check_outs,
                COUNT(DISTINCT CASE WHEN DATE(log_timestamp) = CURDATE() THEN visitor_id END) as today_unique_visitors
            FROM gate_logs");
            $stmt->execute();
            $stats = $stmt->fetch();

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

            echo json_encode([
                'success' => true,
                'data' => [
                    'today_check_ins' => (int)$stats['today_check_ins'],
                    'today_check_outs' => (int)$stats['today_check_outs'],
                    'today_unique_visitors' => (int)$stats['today_unique_visitors'],
                    'currently_inside' => (int)$inside_count,
                    'pending_prereg' => (int)$pending_prereg,
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ]);
            break;

        case 'recent_activity':
            $limit = min(10, max(1, intval($_GET['limit'] ?? 5)));
            
            $stmt = $db->prepare("SELECT 
                gl.log_type, gl.log_timestamp, 
                v.full_name, v.phone, v.company,
                go.operator_name
            FROM gate_logs gl
            JOIN visitors v ON gl.visitor_id = v.visitor_id
            JOIN gate_operators go ON gl.operator_id = go.id
            ORDER BY gl.log_timestamp DESC
            LIMIT ?");
            $stmt->execute([$limit]);
            $activities = $stmt->fetchAll();

            $formatted_activities = [];
            foreach ($activities as $activity) {
                $formatted_activities[] = [
                    'type' => $activity['log_type'],
                    'visitor_name' => $activity['full_name'],
                    'company' => $activity['company'],
                    'operator' => $activity['operator_name'],
                    'time' => date('g:i A', strtotime($activity['log_timestamp'])),
                    'time_ago' => time_elapsed_string($activity['log_timestamp']),
                    'timestamp' => $activity['log_timestamp']
                ];
            }

            echo json_encode([
                'success' => true,
                'data' => $formatted_activities
            ]);
            break;

        case 'notifications':
            $stmt = $db->prepare("
                SELECT n.*, v.full_name as visitor_name, go.operator_name
                FROM notifications n
                LEFT JOIN visitors v ON n.visitor_id = v.visitor_id
                LEFT JOIN gate_operators go ON n.operator_id = go.id
                WHERE n.is_read = 0
                ORDER BY n.created_at DESC
                LIMIT 10
            ");
            $stmt->execute();
            $notifications = $stmt->fetchAll();

            $formatted_notifications = [];
            foreach ($notifications as $notification) {
                $formatted_notifications[] = [
                    'id' => $notification['id'],
                    'type' => $notification['type'],
                    'title' => $notification['title'],
                    'message' => $notification['message'],
                    'visitor_name' => $notification['visitor_name'],
                    'operator_name' => $notification['operator_name'],
                    'created_at' => $notification['created_at'],
                    'time_ago' => time_elapsed_string($notification['created_at'])
                ];
            }

            $stmt = $db->prepare("SELECT COUNT(*) as unread_count FROM notifications WHERE is_read = 0");
            $stmt->execute();
            $unread_count = $stmt->fetch()['unread_count'];

            echo json_encode([
                'success' => true,
                'data' => [
                    'notifications' => $formatted_notifications,
                    'unread_count' => (int)$unread_count
                ]
            ]);
            break;

        case 'system_status':
            $status = [
                'database' => 'online',
                'timestamp' => date('Y-m-d H:i:s'),
                'operator' => $session['operator_name'],
                'session_expires' => date('H:i:s', strtotime($session['expires_at']))
            ];

            $start_time = microtime(true);
            $stmt = $db->query("SELECT 1");
            $db_response_time = round((microtime(true) - $start_time) * 1000, 2);
            $status['db_response_ms'] = $db_response_time;

            $free_space = disk_free_space('.');
            $total_space = disk_total_space('.');
            $status['disk_free_percent'] = round(($free_space / $total_space) * 100, 1);

            echo json_encode([
                'success' => true,
                'data' => $status
            ]);
            break;

        case 'visitor_status':
            $visitor_id = sanitizeInput($_GET['visitor_id'] ?? '');
            
            if (empty($visitor_id)) {
                throw new Exception('Visitor ID is required');
            }

            $stmt = $db->prepare("
                SELECT v.*, 
                       (SELECT log_type FROM gate_logs gl WHERE gl.visitor_id = v.visitor_id ORDER BY log_timestamp DESC LIMIT 1) as last_action,
                       (SELECT log_timestamp FROM gate_logs gl WHERE gl.visitor_id = v.visitor_id ORDER BY log_timestamp DESC LIMIT 1) as last_activity
                FROM visitors v 
                WHERE v.visitor_id = ? AND v.status = 'active'
            ");
            $stmt->execute([$visitor_id]);
            $visitor = $stmt->fetch();

            if (!$visitor) {
                throw new Exception('Visitor not found');
            }

            $current_status = 'Outside';
            if ($visitor['last_action'] === 'check_in') {
                $current_status = 'Inside';
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'visitor_id' => $visitor['visitor_id'],
                    'full_name' => $visitor['full_name'],
                    'phone' => $visitor['phone'],
                    'company' => $visitor['company'],
                    'current_status' => $current_status,
                    'last_activity' => $visitor['last_activity'] ? date('M j, g:i A', strtotime($visitor['last_activity'])) : 'Never visited'
                ]
            ]);
            break;

        default:
            throw new Exception('Invalid endpoint');
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
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