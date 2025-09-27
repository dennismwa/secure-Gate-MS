<?php
require_once 'config/database.php';

date_default_timezone_set('Africa/Nairobi');

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);
$settings = getSettings($db);

// Handle AJAX requests for quick vehicle checkout
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'quick_vehicle_checkout') {
    header('Content-Type: application/json');
    
    $vehicle_id = sanitizeInput($_POST['vehicle_id'] ?? '');
    $location_id = intval($_POST['location_id'] ?? 1);
    $notes = sanitizeInput($_POST['notes'] ?? 'Quick checkout from dashboard');
    
    if (empty($vehicle_id)) {
        echo json_encode(['success' => false, 'message' => 'Vehicle ID is required']);
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
        
        // Check if vehicle is actually inside (last log was check_in)
        $stmt = $db->prepare("SELECT log_type FROM vehicle_logs WHERE vehicle_id = ? ORDER BY log_timestamp DESC LIMIT 1");
        $stmt->execute([$vehicle_id]);
        $last_log = $stmt->fetch();
        
        if (!$last_log || $last_log['log_type'] !== 'check_in') {
            throw new Exception('Vehicle is not currently checked in');
        }
        
        // Record checkout in vehicle_logs
        $stmt = $db->prepare("INSERT INTO vehicle_logs (vehicle_id, log_type, location_id, entry_purpose, operator_id, notes) VALUES (?, 'check_out', ?, 'quick_checkout', ?, ?)");
        $stmt->execute([$vehicle_id, $location_id, $session['operator_id'], $notes]);
        
        // Also record in gate_logs for compatibility
        $stmt = $db->prepare("INSERT INTO gate_logs (visitor_id, vehicle_id, log_type, entry_type, location_id, operator_id, vehicle_number, notes) VALUES (?, ?, 'check_out', 'vehicle', ?, ?, ?, ?)");
        $stmt->execute([null, $vehicle_id, $location_id, $session['operator_id'], $vehicle['license_plate'], $notes]);
        
        // Create notification
        $stmt = $db->prepare("SELECT location_name FROM locations WHERE id = ?");
        $stmt->execute([$location_id]);
        $location = $stmt->fetch();
        $location_name = $location['location_name'] ?? 'Unknown Location';
        
        createNotification($db, 'check_out', 'Vehicle Check Out', "Vehicle {$vehicle['license_plate']} has checked out from $location_name", null, $session['operator_id']);
        
        // Log activity
        logActivity($db, $session['operator_id'], 'vehicle_quick_checkout', "Quick checkout for vehicle: {$vehicle['license_plate']}", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "Vehicle {$vehicle['license_plate']} successfully checked out",
            'vehicle' => $vehicle
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Get dashboard statistics
$stmt = $db->prepare("SELECT 
    COUNT(CASE WHEN log_type = 'check_in' AND DATE(CONVERT_TZ(log_timestamp, '+00:00', '+03:00')) = CURDATE() THEN 1 END) as today_check_ins,
    COUNT(CASE WHEN log_type = 'check_out' AND DATE(CONVERT_TZ(log_timestamp, '+00:00', '+03:00')) = CURDATE() THEN 1 END) as today_check_outs,
    COUNT(DISTINCT CASE WHEN DATE(CONVERT_TZ(log_timestamp, '+00:00', '+03:00')) = CURDATE() THEN visitor_id END) as today_unique_visitors,
    (SELECT COUNT(*) FROM visitors WHERE status = 'active') as total_active_visitors,
    (SELECT COUNT(*) FROM pre_registrations WHERE status = 'pending' AND visit_date >= CURDATE()) as pending_pre_reg
FROM gate_logs");
$stmt->execute();
$stats = $stmt->fetch();

// Get recent activities
$stmt = $db->prepare("SELECT gl.*, v.full_name, v.phone, v.vehicle_number, go.operator_name,
                     CONVERT_TZ(gl.log_timestamp, '+00:00', '+03:00') as kenya_time
                     FROM gate_logs gl 
                     JOIN visitors v ON gl.visitor_id = v.visitor_id 
                     JOIN gate_operators go ON gl.operator_id = go.id 
                     ORDER BY gl.log_timestamp DESC 
                     LIMIT 10");
$stmt->execute();
$recent_activities = $stmt->fetchAll();

// Get vehicles currently inside with proper data
$stmt = $db->prepare("SELECT v.*, vt.type_name, 
    (SELECT vl.log_timestamp FROM vehicle_logs vl WHERE vl.vehicle_id = v.vehicle_id AND vl.log_type = 'check_in' ORDER BY vl.log_timestamp DESC LIMIT 1) as checkin_time,
    (SELECT vl.entry_purpose FROM vehicle_logs vl WHERE vl.vehicle_id = v.vehicle_id ORDER BY vl.log_timestamp DESC LIMIT 1) as entry_purpose,
    (SELECT vl.expected_duration FROM vehicle_logs vl WHERE vl.vehicle_id = v.vehicle_id ORDER BY vl.log_timestamp DESC LIMIT 1) as expected_duration,
    (SELECT vl.delivery_company FROM vehicle_logs vl WHERE vl.vehicle_id = v.vehicle_id ORDER BY vl.log_timestamp DESC LIMIT 1) as delivery_company,
    (SELECT go.operator_name FROM vehicle_logs vl JOIN gate_operators go ON vl.operator_id = go.id WHERE vl.vehicle_id = v.vehicle_id ORDER BY vl.log_timestamp DESC LIMIT 1) as operator_name
    FROM vehicles v 
    LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
    WHERE v.vehicle_id IN (
        SELECT DISTINCT vehicle_id FROM vehicle_logs vl1
        WHERE vl1.log_timestamp = (
            SELECT MAX(vl2.log_timestamp) 
            FROM vehicle_logs vl2 
            WHERE vl2.vehicle_id = vl1.vehicle_id
        )
        AND vl1.log_type = 'check_in'
    )
    AND v.status = 'active'
    ORDER BY checkin_time DESC");
$stmt->execute();
$inside_vehicles = $stmt->fetchAll();

// Count inside vehicles
$inside_count = count($inside_vehicles);

// Get weekly statistics
$stmt = $db->prepare("SELECT 
    DATE(CONVERT_TZ(log_timestamp, '+00:00', '+03:00')) as log_date,
    COUNT(CASE WHEN log_type = 'check_in' THEN 1 END) as check_ins,
    COUNT(CASE WHEN log_type = 'check_out' THEN 1 END) as check_outs
FROM gate_logs 
WHERE CONVERT_TZ(log_timestamp, '+00:00', '+03:00') >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY DATE(CONVERT_TZ(log_timestamp, '+00:00', '+03:00'))
ORDER BY log_date ASC");
$stmt->execute();
$weekly_stats = $stmt->fetchAll();

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management System'); ?> - Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '<?php echo $settings['primary_color'] ?? '#2563eb'; ?>',
                        secondary: '<?php echo $settings['secondary_color'] ?? '#1f2937'; ?>',
                        accent: '<?php echo $settings['accent_color'] ?? '#10b981'; ?>'
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Mobile-first responsive design */
        @media (max-width: 640px) {
            .mobile-hidden { display: none !important; }
            .mobile-grid-1 { grid-template-columns: repeat(1, minmax(0, 1fr)) !important; }
            .mobile-grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
            .mobile-text-xs { font-size: 0.75rem !important; }
            .mobile-text-sm { font-size: 0.875rem !important; }
            .mobile-p-2 { padding: 0.5rem !important; }
            .mobile-p-3 { padding: 0.75rem !important; }
            .mobile-mb-2 { margin-bottom: 0.5rem !important; }
            .mobile-space-y-2 > * + * { margin-top: 0.5rem !important; }
            
            .stat-card {
                padding: 0.75rem !important;
            }
            
            .stat-card .text-2xl {
                font-size: 1.25rem !important;
                line-height: 1.75rem !important;
            }
            
            .stat-card .text-sm {
                font-size: 0.75rem !important;
                line-height: 1rem !important;
            }
            
            .stat-card .p-3 {
                padding: 0.5rem !important;
            }
            
            .stat-card i {
                font-size: 0.875rem !important;
            }
        }

        .mobile-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            border-top: 1px solid #e5e7eb;
            z-index: 50;
            padding: 0.5rem;
        }

        .mobile-nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 0.5rem;
            text-decoration: none;
            color: #6b7280;
            transition: color 0.2s;
        }

        .mobile-nav-item:hover,
        .mobile-nav-item.active {
            color: #2563eb;
        }

        .mobile-nav-item i {
            font-size: 1.25rem;
            margin-bottom: 0.25rem;
        }

        .mobile-nav-item span {
            font-size: 0.75rem;
            font-weight: 500;
        }

        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .scale-in {
            animation: scaleIn 0.3s ease-out;
        }

        @keyframes scaleIn {
            from { transform: scale(0.95); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        .stat-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .action-button {
            transition: all 0.2s ease;
        }

        .action-button:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .pulse-dot {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .custom-scroll::-webkit-scrollbar {
            width: 4px;
        }

        .custom-scroll::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        .custom-scroll::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 2px;
        }
        
        .loading-spinner {
            border: 2px solid #f3f4f6;
            border-top: 2px solid #3b82f6;
            border-radius: 50%;
            width: 16px;
            height: 16px;
            animation: spin 1s linear infinite;
            display: inline-block;
            margin-right: 0.5rem;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="bg-gray-50 pb-20 sm:pb-0">
    
    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-2 sm:px-4 lg:px-8">
            <div class="flex justify-between h-14 sm:h-16">
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <div class="flex-shrink-0">
                        <div class="h-8 w-8 sm:h-10 sm:w-10 bg-blue-600 rounded-lg flex items-center justify-center">
                            <i class="fas fa-shield-alt text-white text-sm sm:text-base"></i>
                        </div>
                    </div>
                    <div>
                        <h1 class="text-base sm:text-xl font-semibold text-gray-900 truncate">
                            <?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management'); ?>
                        </h1>
                        <p class="text-xs sm:text-sm text-gray-500 hidden sm:block">
                            Welcome, <?php echo htmlspecialchars($session['operator_name']); ?>
                        </p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <div class="flex items-center text-xs sm:text-sm text-gray-500">
                        <span class="pulse-dot w-2 h-2 bg-green-500 rounded-full mr-2"></span>
                        <span class="mobile-hidden" id="current-time"><?php echo date('D, M j, Y - g:i A'); ?></span>
                        <span class="sm:hidden" id="current-time-mobile"><?php echo date('H:i'); ?></span>
                    </div>
                    <button onclick="toggleNotifications()" class="relative text-gray-500 hover:text-gray-700 sm:hidden">
                        <i class="fas fa-bell"></i>
                        <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full h-4 w-4 flex items-center justify-center">
                            <?php
                            $stmt = $db->prepare("SELECT COUNT(*) as unread FROM notifications WHERE is_read = 0");
                            $stmt->execute();
                            $unread = $stmt->fetch()['unread'];
                            echo $unread > 9 ? '9+' : $unread;
                            ?>
                        </span>
                    </button>
                    <a href="logout.php" class="text-gray-500 hover:text-gray-700 mobile-hidden">
                        <i class="fas fa-sign-out-alt"></i>
                        <span class="ml-1">Logout</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-2 sm:px-4 lg:px-8 py-4 sm:py-8">
        <?php if ($message): ?>
            <div class="mb-4 sm:mb-6 p-3 sm:p-4 rounded-lg border scale-in <?php 
                echo $message['type'] === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 
                    ($message['type'] === 'warning' ? 'bg-yellow-50 border-yellow-200 text-yellow-700' : 
                     'bg-green-50 border-green-200 text-green-700'); ?>">
                <div class="flex items-center">
                    <i class="fas <?php 
                        echo $message['type'] === 'error' ? 'fa-exclamation-circle' : 
                            ($message['type'] === 'warning' ? 'fa-exclamation-triangle' : 'fa-check-circle'); 
                        ?> mr-2"></i>
                    <span class="text-sm sm:text-base"><?php echo htmlspecialchars($message['message']); ?></span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Quick Action Buttons -->
        <div class="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-6 mb-6 sm:mb-8">
            <a href="scanner.php" class="action-button bg-blue-600 hover:bg-blue-700 text-white p-4 sm:p-6 rounded-xl shadow-sm transition-colors fade-in">
                <div class="flex flex-col items-center text-center">
                    <i class="fas fa-qrcode text-xl sm:text-2xl mb-2"></i>
                    <h3 class="text-sm sm:text-lg font-semibold">QR Scanner</h3>
                    <p class="text-xs sm:text-sm opacity-90 mobile-hidden">Scan visitor codes</p>
                </div>
            </a>
            <a href="vehicle-scanner.php" class="action-button bg-purple-600 hover:bg-purple-700 text-white p-4 sm:p-6 rounded-xl shadow-sm transition-colors fade-in">
                <div class="flex flex-col items-center text-center">
                    <i class="fas fa-truck text-xl sm:text-2xl mb-2"></i>
                    <h3 class="text-sm sm:text-lg font-semibold">Vehicle Scanner</h3>
                    <p class="text-xs sm:text-sm opacity-90 mobile-hidden">Scan vehicle QR codes</p>
                </div>
            </a>
            
            <a href="visitors.php" class="action-button bg-green-600 hover:bg-green-700 text-white p-4 sm:p-6 rounded-xl shadow-sm transition-colors fade-in">
                <div class="flex flex-col items-center text-center">
                    <i class="fas fa-users text-xl sm:text-2xl mb-2"></i>
                    <h3 class="text-sm sm:text-lg font-semibold">Visitors</h3>
                    <p class="text-xs sm:text-sm opacity-90 mobile-hidden">Manage visitors</p>
                </div>
            </a>
            
            <a href="manage-vehicles.php?action=register" class="action-button bg-orange-600 hover:bg-orange-700 text-white p-4 sm:p-6 rounded-xl shadow-sm transition-colors fade-in">
                <div class="flex flex-col items-center text-center">
                    <i class="fas fa-plus text-xl sm:text-2xl mb-2"></i>
                    <h3 class="text-sm sm:text-lg font-semibold">Add Vehicle</h3>
                    <p class="text-xs sm:text-sm opacity-90 mobile-hidden">Register new vehicle</p>
                </div>
            </a>
        </div>

        <!-- Stats Overview -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 sm:gap-4 mb-6 sm:mb-8">
            <div class="stat-card bg-white p-3 sm:p-6 rounded-lg sm:rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-2 sm:p-3 bg-blue-100 rounded-lg">
                        <i class="fas fa-users text-blue-600 text-sm"></i>
                    </div>
                    <div class="ml-2 sm:ml-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-600">Inside</p>
                        <p class="text-lg sm:text-2xl font-bold text-gray-900"><?php echo $inside_count; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="stat-card bg-white p-3 sm:p-6 rounded-lg sm:rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-2 sm:p-3 bg-green-100 rounded-lg">
                        <i class="fas fa-sign-in-alt text-green-600 text-sm"></i>
                    </div>
                    <div class="ml-2 sm:ml-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-600">Today In</p>
                        <p class="text-lg sm:text-2xl font-bold text-gray-900"><?php echo $stats['today_check_ins']; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="stat-card bg-white p-3 sm:p-6 rounded-lg sm:rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-2 sm:p-3 bg-red-100 rounded-lg">
                        <i class="fas fa-sign-out-alt text-red-600 text-sm"></i>
                    </div>
                    <div class="ml-2 sm:ml-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-600">Today Out</p>
                        <p class="text-lg sm:text-2xl font-bold text-gray-900"><?php echo $stats['today_check_outs']; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="stat-card bg-white p-3 sm:p-6 rounded-lg sm:rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-2 sm:p-3 bg-purple-100 rounded-lg">
                        <i class="fas fa-user-friends text-purple-600 text-sm"></i>
                    </div>
                    <div class="ml-2 sm:ml-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-600">Unique</p>
                        <p class="text-lg sm:text-2xl font-bold text-gray-900"><?php echo $stats['today_unique_visitors']; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="stat-card bg-white p-3 sm:p-6 rounded-lg sm:rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-2 sm:p-3 bg-yellow-100 rounded-lg">
                        <i class="fas fa-clock text-yellow-600 text-sm"></i>
                    </div>
                    <div class="ml-2 sm:ml-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-600">Pending</p>
                        <p class="text-lg sm:text-2xl font-bold text-gray-900"><?php echo $stats['pending_pre_reg']; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="stat-card bg-white p-3 sm:p-6 rounded-lg sm:rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-2 sm:p-3 bg-indigo-100 rounded-lg">
                        <i class="fas fa-users-cog text-indigo-600 text-sm"></i>
                    </div>
                    <div class="ml-2 sm:ml-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-600">Active</p>
                        <p class="text-lg sm:text-2xl font-bold text-gray-900"><?php echo $stats['total_active_visitors']; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Vehicles Currently Inside -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-car mr-2 text-green-600"></i>Vehicles Currently Inside (<?php echo count($inside_vehicles); ?>)
                    </h3>
                    <button onclick="refreshInsideVehicles()" class="text-green-600 hover:text-green-800">
                        <i class="fas fa-sync-alt" id="refresh-icon"></i>
                    </button>
                </div>
                
                <div class="space-y-4" id="inside-vehicles">
                    <?php if (empty($inside_vehicles)): ?>
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-car text-4xl mb-4 text-gray-300"></i>
                            <p class="text-lg mb-2">No vehicles currently inside</p>
                            <p class="text-base">Vehicles will appear here when they check in</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($inside_vehicles as $vehicle): ?>
                            <?php 
                            $minutes_inside = $vehicle['checkin_time'] ? round((time() - strtotime($vehicle['checkin_time'])) / 60) : 0;
                            $is_overdue = $vehicle['expected_duration'] && $minutes_inside > $vehicle['expected_duration'];
                            ?>
                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border <?php echo $is_overdue ? 'border-red-300 bg-red-50' : 'border-gray-200'; ?>">
                                <div class="flex items-center space-x-4">
                                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-truck text-green-600"></i>
                                    </div>
                                    <div>
                                        <h4 class="font-medium text-gray-900"><?php echo htmlspecialchars($vehicle['license_plate']); ?></h4>
                                        <p class="text-sm text-gray-600">
                                            <?php echo htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']); ?>
                                            <?php if ($vehicle['type_name']): ?>
                                                • <?php echo htmlspecialchars($vehicle['type_name']); ?>
                                            <?php endif; ?>
                                        </p>
                                        <p class="text-xs text-gray-500">
                                            Owner: <?php echo htmlspecialchars($vehicle['owner_name']); ?>
                                            <?php if ($vehicle['owner_company']): ?>
                                                • Company: <?php echo htmlspecialchars($vehicle['owner_company']); ?>
                                            <?php endif; ?>
                                        </p>
                                        <?php if ($vehicle['operator_name']): ?>
                                            <p class="text-xs text-gray-500">Authorized by: <?php echo htmlspecialchars($vehicle['operator_name']); ?> • <?php echo $minutes_inside; ?> min ago</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex flex-col items-end space-y-2">
                                    <span class="px-3 py-1 text-xs font-medium rounded-full <?php echo $is_overdue ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800'; ?>">
                                        <?php echo $is_overdue ? 'Overdue' : 'Inside'; ?>
                                    </span>
                                    <button onclick="quickCheckout('<?php echo htmlspecialchars($vehicle['vehicle_id']); ?>', '<?php echo htmlspecialchars($vehicle['license_plate']); ?>')" 
                                            class="checkout-btn text-red-600 hover:text-red-800 text-sm font-medium px-3 py-1 border border-red-300 rounded-lg hover:bg-red-50 transition-colors">
                                        <i class="fas fa-sign-out-alt mr-1"></i>Check Out
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Stats & Actions -->
            <div class="space-y-6">
                <!-- Location Quick Stats -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Quick Stats</h3>
                    <div class="space-y-4">
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-600">Total Vehicles</span>
                            <div class="flex items-center space-x-2">
                                <span class="w-3 h-3 bg-blue-500 rounded-full"></span>
                                <span class="text-sm font-medium">
                                    <?php 
                                    $stmt = $db->prepare("SELECT COUNT(*) FROM vehicles WHERE status = 'active'");
                                    $stmt->execute();
                                    echo $stmt->fetch()['COUNT(*)'];
                                    ?>
                                </span>
                            </div>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-600">Inside Now</span>
                            <div class="flex items-center space-x-2">
                                <span class="w-3 h-3 bg-green-500 rounded-full"></span>
                                <span class="text-sm font-medium"><?php echo count($inside_vehicles); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Management Actions -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Quick Management</h3>
                    <div class="space-y-3">
                        <a href="manage-vehicles.php" class="block w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-3 rounded-lg text-center font-medium transition-colors">
                            <i class="fas fa-car mr-2"></i>Manage Vehicles
                        </a>
                        <a href="manage-locations.php" class="block w-full bg-green-600 hover:bg-green-700 text-white px-4 py-3 rounded-lg text-center font-medium transition-colors">
                            <i class="fas fa-map-marker-alt mr-2"></i>Manage Locations
                        </a>
                        <a href="vehicle-dashboard.php" class="block w-full bg-purple-600 hover:bg-purple-700 text-white px-4 py-3 rounded-lg text-center font-medium transition-colors">
                            <i class="fas fa-tachometer-alt mr-2"></i>Vehicle Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Activity -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900">Recent Activity</h3>
                    <div class="flex items-center space-x-4">
                        <div class="flex items-center space-x-2">
                            <span class="w-3 h-3 bg-green-500 rounded-full"></span>
                            <span class="text-sm text-gray-600">Vehicles</span>
                        </div>
                        <div class="flex items-center space-x-2">
                            <span class="w-3 h-3 bg-blue-500 rounded-full"></span>
                            <span class="text-sm text-gray-600">Visitors</span>
                        </div>
                        <a href="reports.php" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                            View All <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="divide-y divide-gray-200 max-h-96 overflow-y-auto custom-scroll" id="recent-activity">
                <?php if (!empty($recent_activities)): ?>
                    <?php foreach ($recent_activities as $activity): ?>
                        <div class="px-4 sm:px-6 py-3 sm:py-4 hover:bg-gray-50 transition-colors">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-3 sm:space-x-4 flex-1 min-w-0">
                                    <div class="flex-shrink-0">
                                        <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-full flex items-center justify-center <?php 
                                            echo $activity['log_type'] === 'check_in' ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600'; ?>">
                                            <i class="fas <?php echo $activity['log_type'] === 'check_in' ? 'fa-sign-in-alt' : 'fa-sign-out-alt'; ?> text-xs sm:text-sm"></i>
                                        </div>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center space-x-2">
                                            <p class="text-xs sm:text-sm font-medium text-gray-900 truncate">
                                                <?php echo htmlspecialchars($activity['full_name']); ?>
                                            </p>
                                            <span class="px-2 py-1 text-xs font-medium rounded-full flex-shrink-0 <?php 
                                                echo $activity['log_type'] === 'check_in' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $activity['log_type'])); ?>
                                            </span>
                                        </div>
                                        <div class="mt-1 text-xs text-gray-500">
                                            <span><?php echo htmlspecialchars($activity['phone']); ?></span>
                                            <?php if ($activity['vehicle_number']): ?>
                                                <span class="ml-2 mobile-hidden">• Vehicle: <?php echo htmlspecialchars($activity['vehicle_number']); ?></span>
                                            <?php endif; ?>
                                            <span class="ml-2">• By: <?php echo htmlspecialchars($activity['operator_name']); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-xs text-gray-500 flex-shrink-0 ml-2">
                                    <div class="sm:hidden"><?php echo date('H:i', strtotime($activity['kenya_time'])); ?></div>
                                    <div class="mobile-hidden"><?php echo date('M j, g:i A', strtotime($activity['kenya_time'])); ?></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="px-4 sm:px-6 py-8 text-center text-gray-500">
                        <i class="fas fa-history text-3xl sm:text-4xl mb-4 text-gray-300"></i>
                        <p class="text-sm sm:text-base">No recent activity</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Quick Access -->
        <div class="mt-8 sm:mt-12">
            <h3 class="text-lg font-semibold text-gray-900 mb-4 px-2 sm:px-0">Quick Access</h3>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 sm:gap-4">
                <a href="reports.php" class="bg-white p-3 sm:p-4 rounded-lg shadow-sm border border-gray-200 text-center hover:shadow-md transition-shadow">
                    <i class="fas fa-chart-bar text-orange-500 text-xl sm:text-2xl mb-2"></i>
                    <p class="text-xs sm:text-sm font-medium text-gray-700">Reports</p>
                </a>
                
                <a href="settings.php" class="bg-white p-3 sm:p-4 rounded-lg shadow-sm border border-gray-200 text-center hover:shadow-md transition-shadow">
                    <i class="fas fa-cog text-gray-500 text-xl sm:text-2xl mb-2"></i>
                    <p class="text-xs sm:text-sm font-medium text-gray-700">Settings</p>
                </a>
                
                <a href="notifications.php" class="bg-white p-3 sm:p-4 rounded-lg shadow-sm border border-gray-200 text-center hover:shadow-md transition-shadow relative">
                    <i class="fas fa-bell text-yellow-500 text-xl sm:text-2xl mb-2"></i>
                    <p class="text-xs sm:text-sm font-medium text-gray-700">Notifications</p>
                    <?php if ($unread > 0): ?>
                        <span class="absolute top-1 right-1 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center">
                            <?php echo $unread > 9 ? '9+' : $unread; ?>
                        </span>
                    <?php endif; ?>
                </a>
                
                <a href="backup-system.php" class="bg-white p-3 sm:p-4 rounded-lg shadow-sm border border-gray-200 text-center hover:shadow-md transition-shadow">
                    <i class="fas fa-database text-green-500 text-xl sm:text-2xl mb-2"></i>
                    <p class="text-xs sm:text-sm font-medium text-gray-700">Backup</p>
                </a>
                
                <a href="system-health.php" class="bg-white p-3 sm:p-4 rounded-lg shadow-sm border border-gray-200 text-center hover:shadow-md transition-shadow">
                    <i class="fas fa-heartbeat text-red-500 text-xl sm:text-2xl mb-2"></i>
                    <p class="text-xs sm:text-sm font-medium text-gray-700">Health</p>
                </a>
                
                <a href="operators.php" class="bg-white p-3 sm:p-4 rounded-lg shadow-sm border border-gray-200 text-center hover:shadow-md transition-shadow">
                    <i class="fas fa-users-cog text-blue-500 text-xl sm:text-2xl mb-2"></i>
                    <p class="text-xs sm:text-sm font-medium text-gray-700">Operators</p>
                </a>
            </div>
        </div>
    </div>

    <!-- Mobile Navigation -->
    <div class="mobile-nav sm:hidden">
        <div class="grid grid-cols-5 gap-1">
            <a href="dashboard.php" class="mobile-nav-item active">
                <i class="fas fa-home"></i>
                <span>Home</span>
            </a>
            <a href="scanner.php" class="mobile-nav-item">
                <i class="fas fa-qrcode"></i>
                <span>Scan</span>
            </a>
            <a href="visitors.php" class="mobile-nav-item">
                <i class="fas fa-users"></i>
                <span>Visitors</span>
            </a>
            <a href="reports.php" class="mobile-nav-item">
                <i class="fas fa-chart-bar"></i>
                <span>Reports</span>
            </a>
            <a href="settings.php" class="mobile-nav-item">
                <i class="fas fa-cog"></i>
                <span>Settings</span>
            </a>
        </div>
    </div>

    <!-- Notification Panel -->
    <div id="notificationPanel" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50 sm:hidden">
        <div class="fixed bottom-0 left-0 right-0 bg-white rounded-t-xl p-4 max-h-96 overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold">Notifications</h3>
                <button onclick="toggleNotifications()" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="notificationContent">
                <!-- Dynamic content loaded here -->
            </div>
        </div>
    </div>

    <!-- Success/Error Toast Container -->
    <div id="toast-container" class="fixed top-4 right-4 z-50 space-y-2"></div>

    <script>
        // Global variables for timers
        let dashboardUpdateInterval = null;
        let timeUpdateInterval = null;
        let isRefreshing = false;

        document.addEventListener('DOMContentLoaded', function() {
            initializeDashboard();
        });

        function initializeDashboard() {
            startRealTimeUpdates();
            initializeActionButtons();
            updateActiveNavItem();
            disableSwipeRefresh();
        }

        function startRealTimeUpdates() {
            if (dashboardUpdateInterval) clearInterval(dashboardUpdateInterval);
            if (timeUpdateInterval) clearInterval(timeUpdateInterval);
            
            dashboardUpdateInterval = setInterval(updateDashboardStats, 30000);
            timeUpdateInterval = setInterval(updateCurrentTime, 60000);
            
            updateCurrentTime();
        }

        function updateDashboardStats() {
            if (isRefreshing) return;
            
            isRefreshing = true;
            
            // Simple stats update without complex API calls - just refresh the page section
            // This is a fallback approach since we don't have api-realtime.php
            setTimeout(() => {
                isRefreshing = false;
            }, 5000);
        }

        function updateCurrentTime() {
            const now = new Date();
            
            const desktopTimeElement = document.getElementById('current-time');
            if (desktopTimeElement) {
                const timeString = now.toLocaleString('en-KE', {
                    weekday: 'short',
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric',
                    hour: 'numeric',
                    minute: '2-digit',
                    hour12: true,
                    timeZone: 'Africa/Nairobi'
                });
                desktopTimeElement.textContent = timeString;
            }
            
            const mobileTimeElement = document.getElementById('current-time-mobile');
            if (mobileTimeElement) {
                const mobileTimeString = now.toLocaleTimeString('en-KE', {
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: false,
                    timeZone: 'Africa/Nairobi'
                });
                mobileTimeElement.textContent = mobileTimeString;
            }
        }

        function initializeActionButtons() {
            document.querySelectorAll('.action-button').forEach(button => {
                button.addEventListener('click', function(e) {
                    if (!this.classList.contains('loading')) {
                        this.classList.add('loading');
                        const icon = this.querySelector('i');
                        if (icon) {
                            const originalClass = icon.className;
                            icon.className = 'fas fa-spinner fa-spin text-xl sm:text-2xl mb-2';
                            
                            setTimeout(() => {
                                this.classList.remove('loading');
                                icon.className = originalClass;
                            }, 1500);
                        }
                    }
                });
            });
        }

        // Vehicle checkout function - this is the main fix
        function quickCheckout(vehicleId, licensePlate) {
            if (!vehicleId || !licensePlate) {
                showToast('Invalid vehicle data', 'error');
                return;
            }
            
            if (confirm(`Check out vehicle ${licensePlate}?`)) {
                // Find the checkout button and show loading state
                const button = event.target.closest('.checkout-btn');
                const originalContent = button.innerHTML;
                button.innerHTML = '<div class="loading-spinner"></div>Processing...';
                button.disabled = true;
                
                const formData = new FormData();
                formData.append('action', 'quick_vehicle_checkout');
                formData.append('vehicle_id', vehicleId);
                formData.append('location_id', '1'); // Default location, you might want to make this dynamic
                formData.append('notes', 'Quick checkout from dashboard');
                
                fetch(window.location.pathname, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message, 'success');
                        
                        // Remove the vehicle from the display
                        const vehicleElement = button.closest('.flex.items-center.justify-between');
                        if (vehicleElement) {
                            vehicleElement.style.transition = 'opacity 0.5s ease-out';
                            vehicleElement.style.opacity = '0';
                            setTimeout(() => {
                                vehicleElement.remove();
                                
                                // Update the count in the header
                                const header = document.querySelector('h3');
                                if (header && header.textContent.includes('Vehicles Currently Inside')) {
                                    const currentCount = parseInt(header.textContent.match(/\((\d+)\)/)[1]);
                                    const newCount = Math.max(0, currentCount - 1);
                                    header.innerHTML = header.innerHTML.replace(/\(\d+\)/, `(${newCount})`);
                                    
                                    // Update the stat card
                                    const insideStatCard = document.querySelector('.stat-card .text-lg.sm\\:text-2xl');
                                    if (insideStatCard) {
                                        insideStatCard.textContent = newCount;
                                    }
                                }
                                
                                // If no vehicles left, show empty message
                                const container = document.getElementById('inside-vehicles');
                                if (container && container.children.length === 0) {
                                    container.innerHTML = `
                                        <div class="text-center py-8 text-gray-500">
                                            <i class="fas fa-car text-4xl mb-4 text-gray-300"></i>
                                            <p class="text-lg mb-2">No vehicles currently inside</p>
                                            <p class="text-base">Vehicles will appear here when they check in</p>
                                        </div>
                                    `;
                                }
                            }, 500);
                        }
                    } else {
                        showToast('Error: ' + data.message, 'error');
                        button.innerHTML = originalContent;
                        button.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Checkout error:', error);
                    showToast('An error occurred during checkout', 'error');
                    button.innerHTML = originalContent;
                    button.disabled = false;
                });
            }
        }

        function refreshInsideVehicles() {
            const refreshIcon = document.getElementById('refresh-icon');
            refreshIcon.classList.add('fa-spin');
            
            // Simple page refresh for the vehicles section
            setTimeout(() => {
                window.location.reload();
            }, 500);
        }

        function toggleNotifications() {
            const panel = document.getElementById('notificationPanel');
            const content = document.getElementById('notificationContent');
            
            if (panel.classList.contains('hidden')) {
                loadNotifications();
                panel.classList.remove('hidden');
            } else {
                panel.classList.add('hidden');
            }
        }

        function loadNotifications() {
            const content = document.getElementById('notificationContent');
            content.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
            
            // Simulate loading notifications
            setTimeout(() => {
                content.innerHTML = `
                    <div class="text-center py-8 text-gray-500">
                        <i class="fas fa-bell-slash text-3xl mb-2"></i>
                        <p>No new notifications</p>
                    </div>
                `;
            }, 1000);
        }

        function updateActiveNavItem() {
            const currentPath = window.location.pathname;
            const navItems = document.querySelectorAll('.mobile-nav-item');
            
            navItems.forEach(item => {
                item.classList.remove('active');
                const href = item.getAttribute('href');
                if (href && (currentPath.endsWith(href) || (href === 'dashboard.php' && currentPath.endsWith('/')))) {
                    item.classList.add('active');
                }
            });
        }

        function disableSwipeRefresh() {
            let startY = 0;
            let isScrolling = false;

            document.addEventListener('touchstart', function(e) {
                startY = e.touches[0].clientY;
                isScrolling = window.scrollY > 0;
            }, { passive: true });

            document.addEventListener('touchmove', function(e) {
                const currentY = e.touches[0].clientY;
                const diff = currentY - startY;
                
                if (window.scrollY === 0 && diff > 0 && !isScrolling) {
                    e.preventDefault();
                }
            }, { passive: false });
        }

        // Toast notification system
        function showToast(message, type = 'info') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            
            const bgColor = type === 'success' ? 'bg-green-500' : 
                           type === 'error' ? 'bg-red-500' : 
                           type === 'warning' ? 'bg-yellow-500' : 'bg-blue-500';
            
            toast.className = `${bgColor} text-white px-4 py-3 rounded-lg shadow-lg transform transition-all duration-300 translate-x-full opacity-0 max-w-sm`;
            toast.innerHTML = `
                <div class="flex items-center">
                    <i class="fas ${type === 'success' ? 'fa-check' : type === 'error' ? 'fa-times' : type === 'warning' ? 'fa-exclamation-triangle' : 'fa-info'} mr-2"></i>
                    <span class="text-sm font-medium">${message}</span>
                    <button onclick="this.parentElement.parentElement.remove()" class="ml-4 text-white hover:text-gray-200">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            
            container.appendChild(toast);
            
            // Animate in
            setTimeout(() => {
                toast.classList.remove('translate-x-full', 'opacity-0');
            }, 100);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                if (toast.parentElement) {
                    toast.classList.add('translate-x-full', 'opacity-0');
                    setTimeout(() => {
                        if (toast.parentElement) {
                            toast.remove();
                        }
                    }, 300);
                }
            }, 5000);
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey || e.metaKey) {
                switch(e.key) {
                    case '1':
                        e.preventDefault();
                        window.location.href = 'scanner.php';
                        break;
                    case '2':
                        e.preventDefault();
                        window.location.href = 'vehicle-scanner.php';
                        break;
                    case '3':
                        e.preventDefault();
                        window.location.href = 'visitors.php';
                        break;
                    case 'r':
                        e.preventDefault();
                        if (!isRefreshing) {
                            refreshInsideVehicles();
                        }
                        break;
                }
            }
        });

        // Clean up intervals when page is hidden
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                if (dashboardUpdateInterval) clearInterval(dashboardUpdateInterval);
                if (timeUpdateInterval) clearInterval(timeUpdateInterval);
            } else {
                startRealTimeUpdates();
            }
        });

        // Clean up on page unload
        window.addEventListener('beforeunload', function() {
            if (dashboardUpdateInterval) clearInterval(dashboardUpdateInterval);
            if (timeUpdateInterval) clearInterval(timeUpdateInterval);
        });

        // Error handling for fetch requests
        window.addEventListener('unhandledrejection', function(event) {
            console.error('Unhandled promise rejection:', event.reason);
        });

        // Handle network errors gracefully
        window.addEventListener('online', function() {
            showToast('Connection restored', 'success');
        });

        window.addEventListener('offline', function() {
            showToast('Working offline', 'warning');
        });
    </script>
</body>
</html>