<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);
$settings = getSettings($db);

$stmt = $db->prepare("SELECT l.* FROM locations l JOIN operator_locations ol ON l.id = ol.location_id WHERE ol.operator_id = ? AND l.is_active = 1 ORDER BY ol.is_primary DESC, l.location_name");
$stmt->execute([$session['operator_id']]);
$operator_locations = $stmt->fetchAll();

$default_location_id = $operator_locations[0]['id'] ?? 1;
$selected_location_id = intval($_GET['location_id'] ?? $default_location_id);

$stmt = $db->prepare("SELECT * FROM locations WHERE id = ?");
$stmt->execute([$selected_location_id]);
$current_location = $stmt->fetch();

if (!$current_location) {
    setMessage('Location not found', 'error');
    header('Location: dashboard.php');
    exit;
}

$stmt = $db->prepare("SELECT 
    COUNT(CASE WHEN log_type = 'check_in' AND DATE(CONVERT_TZ(log_timestamp, '+00:00', '+03:00')) = CURDATE() THEN 1 END) as today_check_ins,
    COUNT(CASE WHEN log_type = 'check_out' AND DATE(CONVERT_TZ(log_timestamp, '+00:00', '+03:00')) = CURDATE() THEN 1 END) as today_check_outs,
    COUNT(DISTINCT CASE WHEN DATE(CONVERT_TZ(log_timestamp, '+00:00', '+03:00')) = CURDATE() THEN vehicle_id END) as today_unique_vehicles,
    COUNT(CASE WHEN log_type = 'check_in' AND entry_purpose = 'delivery' AND DATE(CONVERT_TZ(log_timestamp, '+00:00', '+03:00')) = CURDATE() THEN 1 END) as today_deliveries
FROM vehicle_logs WHERE location_id = ?");
$stmt->execute([$selected_location_id]);
$vehicle_stats = $stmt->fetch();

$stmt = $db->prepare("SELECT v.*, vt.type_name, latest_log.log_timestamp as checkin_time, latest_log.entry_purpose, latest_log.expected_duration, latest_log.delivery_company, latest_log.operator_name
    FROM vehicles v 
    LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
    JOIN (
        SELECT vl.vehicle_id, vl.log_timestamp, vl.entry_purpose, vl.expected_duration, vl.delivery_company, go.operator_name,
               ROW_NUMBER() OVER (PARTITION BY vl.vehicle_id ORDER BY vl.log_timestamp DESC) as rn
        FROM vehicle_logs vl
        JOIN gate_operators go ON vl.operator_id = go.id
        WHERE vl.location_id = ?
    ) latest_log ON v.vehicle_id = latest_log.vehicle_id AND latest_log.rn = 1
    WHERE latest_log.log_timestamp = (
        SELECT MAX(log_timestamp) 
        FROM vehicle_logs vl2 
        WHERE vl2.vehicle_id = v.vehicle_id AND vl2.location_id = ?
    )
    AND latest_log.log_timestamp IN (
        SELECT log_timestamp 
        FROM vehicle_logs vl3 
        WHERE vl3.vehicle_id = v.vehicle_id AND vl3.location_id = ? AND vl3.log_type = 'check_in'
        AND NOT EXISTS (
            SELECT 1 FROM vehicle_logs vl4 
            WHERE vl4.vehicle_id = v.vehicle_id AND vl4.location_id = ? 
            AND vl4.log_type = 'check_out' AND vl4.log_timestamp > vl3.log_timestamp
        )
    )
    ORDER BY latest_log.log_timestamp DESC");
$stmt->execute([$selected_location_id, $selected_location_id, $selected_location_id, $selected_location_id]);
$inside_vehicles = $stmt->fetchAll();

$stmt = $db->prepare("SELECT vl.*, v.license_plate, v.make, v.model, v.owner_name, v.owner_company, 
                     go.operator_name, vt.type_name,
                     CONVERT_TZ(vl.log_timestamp, '+00:00', '+03:00') as kenya_time
                     FROM vehicle_logs vl 
                     JOIN vehicles v ON vl.vehicle_id = v.vehicle_id 
                     JOIN gate_operators go ON vl.operator_id = go.id 
                     LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
                     WHERE vl.location_id = ?
                     ORDER BY vl.log_timestamp DESC 
                     LIMIT 20");
$stmt->execute([$selected_location_id]);
$recent_vehicle_activity = $stmt->fetchAll();

$stmt = $db->prepare("SELECT v.*, vt.type_name, vl.log_timestamp as checkin_time, vl.expected_duration, vl.entry_purpose,
                     TIMESTAMPDIFF(MINUTE, vl.log_timestamp, NOW()) as minutes_inside
                     FROM vehicles v
                     LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
                     JOIN vehicle_logs vl ON v.vehicle_id = vl.vehicle_id
                     WHERE vl.location_id = ? AND vl.log_type = 'check_in' 
                     AND vl.expected_duration > 0
                     AND TIMESTAMPDIFF(MINUTE, vl.log_timestamp, NOW()) > vl.expected_duration
                     AND NOT EXISTS (
                         SELECT 1 FROM vehicle_logs vl2 
                         WHERE vl2.vehicle_id = v.vehicle_id AND vl2.location_id = ? 
                         AND vl2.log_type = 'check_out' AND vl2.log_timestamp > vl.log_timestamp
                     )
                     ORDER BY minutes_inside DESC");
$stmt->execute([$selected_location_id, $selected_location_id]);
$overdue_vehicles = $stmt->fetchAll();

$stmt = $db->prepare("SELECT 
    entry_purpose,
    COUNT(*) as count,
    COUNT(CASE WHEN log_type = 'check_in' THEN 1 END) as arrivals,
    COUNT(CASE WHEN log_type = 'check_out' THEN 1 END) as departures
FROM vehicle_logs 
WHERE location_id = ? AND DATE(CONVERT_TZ(log_timestamp, '+00:00', '+03:00')) = CURDATE()
AND entry_purpose IN ('delivery', 'pickup', 'service', 'maintenance')
GROUP BY entry_purpose 
ORDER BY count DESC");
$stmt->execute([$selected_location_id]);
$delivery_stats = $stmt->fetchAll();

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management System'); ?> - Vehicle Dashboard</title>
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
        @media (max-width: 768px) {
            .mobile-stack { flex-direction: column !important; gap: 0.5rem !important; }
            .mobile-full { width: 100% !important; }
            .mobile-text-sm { font-size: 0.875rem !important; }
            .mobile-hidden { display: none !important; }
            .mobile-grid-1 { grid-template-columns: repeat(1, minmax(0, 1fr)) !important; }
            .mobile-grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
        }
        
        .vehicle-card { transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .vehicle-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1); }
        .pulse-dot { animation: pulse 2s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        .overdue { animation: pulse 2s infinite; border-color: #ef4444 !important; }
    </style>
</head>
<body class="bg-gray-50">
    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-2 sm:px-4 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <a href="dashboard.php" class="text-gray-600 hover:text-gray-900">
                        <i class="fas fa-arrow-left"></i>
                    </a>
                    <div class="h-8 w-8 sm:h-10 sm:w-10 bg-purple-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-tachometer-alt text-white text-sm sm:text-base"></i>
                    </div>
                    <div>
                        <h1 class="text-lg sm:text-xl font-semibold text-gray-900">Vehicle Dashboard</h1>
                        <p class="text-xs sm:text-sm text-gray-500 hidden sm:block">
                            <?php echo htmlspecialchars($current_location['location_name']); ?>
                        </p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <div class="flex items-center text-xs sm:text-sm text-gray-500">
                        <span class="pulse-dot w-2 h-2 bg-green-500 rounded-full mr-2"></span>
                        <span class="mobile-hidden"><?php echo date('D, M j, Y - g:i A'); ?></span>
                        <span class="sm:hidden"><?php echo date('H:i'); ?></span>
                    </div>
                    <a href="vehicle-scanner.php?location_id=<?php echo $selected_location_id; ?>" class="text-green-600 hover:text-green-800 text-sm sm:text-base">
                        <i class="fas fa-qrcode"></i>
                        <span class="ml-1 hidden sm:inline">Scanner</span>
                    </a>
                    <a href="manage-vehicles.php" class="text-blue-600 hover:text-blue-800 text-sm sm:text-base">
                        <i class="fas fa-car"></i>
                        <span class="ml-1 hidden sm:inline">Vehicles</span>
                    </a>
                    <a href="dashboard.php" class="text-blue-600 hover:text-blue-800 text-sm sm:text-base">
                        <i class="fas fa-home"></i>
                        <span class="ml-1 hidden sm:inline">Dashboard</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-2 sm:px-4 lg:px-8 py-4 sm:py-8">
        <?php if ($message): ?>
            <div class="mb-4 sm:mb-6 p-3 sm:p-4 rounded-lg border text-sm sm:text-base <?php 
                echo $message['type'] === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 
                    ($message['type'] === 'warning' ? 'bg-yellow-50 border-yellow-200 text-yellow-700' : 
                     'bg-green-50 border-green-200 text-green-700'); ?>">
                <div class="flex items-center">
                    <i class="fas <?php 
                        echo $message['type'] === 'error' ? 'fa-exclamation-circle' : 
                            ($message['type'] === 'warning' ? 'fa-exclamation-triangle' : 'fa-check-circle'); 
                        ?> mr-2"></i>
                    <?php echo htmlspecialchars($message['message']); ?>
                </div>
            </div>
        <?php endif; ?>

        
        <?php if (count($operator_locations) > 1): ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-3 sm:p-4 mb-4 sm:mb-6">
                <div class="flex items-center space-x-3">
                    <i class="fas fa-map-marker-alt text-purple-600"></i>
                    <label for="location_selector" class="text-sm font-medium text-gray-700">Location:</label>
                    <select id="location_selector" onchange="changeLocation()" 
                            class="px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm">
                        <?php foreach ($operator_locations as $location): ?>
                            <option value="<?php echo $location['id']; ?>" <?php echo $location['id'] == $selected_location_id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($location['location_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        <?php endif; ?>

        
        <div class="grid mobile-grid-2 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-6 mb-6 sm:mb-8">
            <a href="vehicle-scanner.php?location_id=<?php echo $selected_location_id; ?>" class="bg-green-600 hover:bg-green-700 text-white p-4 rounded-xl shadow-sm transition-colors">
                <div class="flex flex-col items-center text-center">
                    <i class="fas fa-qrcode text-xl sm:text-2xl mb-2"></i>
                    <h3 class="text-sm sm:text-lg font-semibold">Vehicle Scanner</h3>
                    <p class="text-xs sm:text-sm opacity-90 mobile-hidden">Scan vehicle QR</p>
                </div>
            </a>
            
            <a href="manage-vehicles.php?action=register" class="bg-blue-600 hover:bg-blue-700 text-white p-4 rounded-xl shadow-sm transition-colors">
                <div class="flex flex-col items-center text-center">
                    <i class="fas fa-plus text-xl sm:text-2xl mb-2"></i>
                    <h3 class="text-sm sm:text-lg font-semibold">Add Vehicle</h3>
                    <p class="text-xs sm:text-sm opacity-90 mobile-hidden">Register new vehicle</p>
                </div>
            </a>
            
            <a href="delivery-tracking.php?location_id=<?php echo $selected_location_id; ?>" class="bg-orange-600 hover:bg-orange-700 text-white p-4 rounded-xl shadow-sm transition-colors">
                <div class="flex flex-col items-center text-center">
                    <i class="fas fa-truck text-xl sm:text-2xl mb-2"></i>
                    <h3 class="text-sm sm:text-lg font-semibold">Deliveries</h3>
                    <p class="text-xs sm:text-sm opacity-90 mobile-hidden">Track deliveries</p>
                </div>
            </a>
            
            <a href="vehicle-reports.php?location_id=<?php echo $selected_location_id; ?>" class="bg-purple-600 hover:bg-purple-700 text-white p-4 rounded-xl shadow-sm transition-colors">
                <div class="flex flex-col items-center text-center">
                    <i class="fas fa-chart-bar text-xl sm:text-2xl mb-2"></i>
                    <h3 class="text-sm sm:text-lg font-semibold">Reports</h3>
                    <p class="text-xs sm:text-sm opacity-90 mobile-hidden">Vehicle analytics</p>
                </div>
            </a>
        </div>

        
        <div class="grid mobile-grid-2 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-6 mb-6 sm:mb-8">
            <div class="bg-white p-3 sm:p-6 rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-2 bg-green-100 rounded-lg">
                        <i class="fas fa-sign-in-alt text-green-600 text-sm sm:text-base"></i>
                    </div>
                    <div class="ml-3 sm:ml-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-600">Vehicle Check-ins</p>
                        <p class="text-lg sm:text-2xl font-bold text-gray-900"><?php echo $vehicle_stats['today_check_ins']; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-3 sm:p-6 rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-2 bg-red-100 rounded-lg">
                        <i class="fas fa-sign-out-alt text-red-600 text-sm sm:text-base"></i>
                    </div>
                    <div class="ml-3 sm:ml-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-600">Vehicle Check-outs</p>
                        <p class="text-lg sm:text-2xl font-bold text-gray-900"><?php echo $vehicle_stats['today_check_outs']; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-3 sm:p-6 rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-2 bg-purple-100 rounded-lg">
                        <i class="fas fa-car text-purple-600 text-sm sm:text-base"></i>
                    </div>
                    <div class="ml-3 sm:ml-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-600">Inside Now</p>
                        <p class="text-lg sm:text-2xl font-bold text-gray-900"><?php echo count($inside_vehicles); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-3 sm:p-6 rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-2 bg-orange-100 rounded-lg">
                        <i class="fas fa-truck text-orange-600 text-sm sm:text-base"></i>
                    </div>
                    <div class="ml-3 sm:ml-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-600">Deliveries</p>
                        <p class="text-lg sm:text-2xl font-bold text-gray-900"><?php echo $vehicle_stats['today_deliveries']; ?></p>
                    </div>
                </div>
            </div>
        </div>

        
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-8">
            
            <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                <div class="flex items-center justify-between mb-4 sm:mb-6">
                    <h3 class="text-base sm:text-lg font-semibold text-gray-900">
                        <i class="fas fa-car mr-2 text-purple-600"></i>Currently Inside (<?php echo count($inside_vehicles); ?>)
                    </h3>
                    <button onclick="refreshInsideVehicles()" class="text-purple-600 hover:text-purple-800">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
                
                <?php if (empty($inside_vehicles)): ?>
                    <div class="text-center py-8 sm:py-12 text-gray-500">
                        <i class="fas fa-car text-3xl sm:text-4xl mb-4 text-gray-300"></i>
                        <p class="text-base sm:text-lg mb-2">No vehicles currently inside</p>
                        <p class="text-sm sm:text-base">Vehicles will appear here when they check in</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-3 sm:space-y-4 max-h-96 overflow-y-auto">
                        <?php foreach ($inside_vehicles as $vehicle): ?>
                            <?php 
                            $minutes_inside = round((time() - strtotime($vehicle['checkin_time'])) / 60);
                            $is_overdue = $vehicle['expected_duration'] && $minutes_inside > $vehicle['expected_duration'];
                            ?>
                            <div class="vehicle-card p-3 sm:p-4 bg-gray-50 rounded-lg border <?php echo $is_overdue ? 'overdue border-red-300' : 'border-gray-200'; ?>">
                                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between space-y-2 sm:space-y-0">
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-2 sm:space-x-3">
                                            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
                                                <i class="fas fa-car text-purple-600"></i>
                                            </div>
                                            <div>
                                                <h4 class="font-medium text-gray-900 text-sm sm:text-base">
                                                    <?php echo htmlspecialchars($vehicle['license_plate']); ?>
                                                </h4>
                                                <p class="text-xs sm:text-sm text-gray-600">
                                                    <?php echo htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']); ?>
                                                    <?php if ($vehicle['owner_company']): ?>
                                                        • <?php echo htmlspecialchars($vehicle['owner_company']); ?>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-2 sm:mt-3 flex flex-wrap gap-2">
                                            <span class="px-2 py-1 text-xs font-medium rounded-full <?php 
                                                echo $vehicle['entry_purpose'] === 'delivery' ? 'bg-orange-100 text-orange-800' :
                                                    ($vehicle['entry_purpose'] === 'service' ? 'bg-blue-100 text-blue-800' :
                                                     ($vehicle['entry_purpose'] === 'visitor' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800')); ?>">
                                                <?php echo ucfirst($vehicle['entry_purpose']); ?>
                                            </span>
                                            
                                            <?php if ($vehicle['type_name']): ?>
                                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-800">
                                                    <?php echo htmlspecialchars($vehicle['type_name']); ?>
                                                </span>
                                            <?php endif; ?>
                                            
                                            <?php if ($is_overdue): ?>
                                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-red-100 text-red-800">
                                                    <i class="fas fa-exclamation-triangle mr-1"></i>Overdue
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="mt-2 text-xs sm:text-sm text-gray-500">
                                            <div class="flex flex-wrap gap-4">
                                                <span>Inside: <?php echo $minutes_inside; ?> min</span>
                                                <?php if ($vehicle['expected_duration']): ?>
                                                    <span>Expected: <?php echo $vehicle['expected_duration']; ?> min</span>
                                                <?php endif; ?>
                                                <?php if ($vehicle['delivery_company']): ?>
                                                    <span>Company: <?php echo htmlspecialchars($vehicle['delivery_company']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="mt-1">Checked in by: <?php echo htmlspecialchars($vehicle['operator_name']); ?></div>
                                        </div>
                                    </div>
                                    
                                    <div class="flex space-x-2 sm:space-x-3">
                                        <a href="manage-vehicles.php?action=view&id=<?php echo $vehicle['vehicle_id']; ?>" 
                                           class="text-blue-600 hover:text-blue-800 text-xs sm:text-sm">
                                            <i class="fas fa-eye mr-1"></i>View
                                        </a>
                                        <button onclick="quickCheckout('<?php echo $vehicle['vehicle_id']; ?>', '<?php echo htmlspecialchars($vehicle['license_plate']); ?>')" 
                                                class="text-red-600 hover:text-red-800 text-xs sm:text-sm">
                                            <i class="fas fa-sign-out-alt mr-1"></i>Check Out
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            
            <div class="space-y-4 sm:space-y-6">
                
                <?php if (!empty($overdue_vehicles)): ?>
                    <div class="bg-red-50 border border-red-200 rounded-xl p-4 sm:p-6">
                        <div class="flex items-center mb-3">
                            <i class="fas fa-exclamation-triangle text-red-600 mr-2"></i>
                            <h3 class="text-base font-semibold text-red-900">Overdue Vehicles</h3>
                        </div>
                        
                        <div class="space-y-2">
                            <?php foreach (array_slice($overdue_vehicles, 0, 3) as $overdue): ?>
                                <div class="flex justify-between items-center text-sm">
                                    <span class="font-medium text-red-800">
                                        <?php echo htmlspecialchars($overdue['license_plate']); ?>
                                    </span>
                                    <span class="text-red-600">
                                        +<?php echo $overdue['minutes_inside'] - $overdue['expected_duration']; ?> min
                                    </span>
                                </div>
                            <?php endforeach; ?>
                            
                            <?php if (count($overdue_vehicles) > 3): ?>
                                <p class="text-xs text-red-600 mt-2">
                                    And <?php echo count($overdue_vehicles) - 3; ?> more overdue vehicles
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                
                <?php if (!empty($delivery_stats)): ?>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                        <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-4">Today's Deliveries</h3>
                        
                        <div class="space-y-3">
                            <?php foreach ($delivery_stats as $stat): ?>
                                <div class="flex justify-between items-center p-3 bg-orange-50 rounded-lg">
                                    <div>
                                        <span class="text-sm font-medium text-orange-800">
                                            <?php echo ucfirst($stat['entry_purpose']); ?>
                                        </span>
                                        <p class="text-xs text-orange-600">
                                            In: <?php echo $stat['arrivals']; ?> • Out: <?php echo $stat['departures']; ?>
                                        </p>
                                    </div>
                                    <span class="text-lg font-bold text-orange-600">
                                        <?php echo $stat['count']; ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                    <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-4">Quick Stats</h3>
                    
                    <div class="space-y-3">
                        <div class="flex justify-between items-center p-3 bg-blue-50 rounded-lg">
                            <span class="text-sm font-medium text-blue-800">Total Activity</span>
                            <span class="text-lg font-bold text-blue-600">
                                <?php echo $vehicle_stats['today_check_ins'] + $vehicle_stats['today_check_outs']; ?>
                            </span>
                        </div>
                        
                        <div class="flex justify-between items-center p-3 bg-green-50 rounded-lg">
                            <span class="text-sm font-medium text-green-800">Unique Vehicles</span>
                            <span class="text-lg font-bold text-green-600">
                                <?php echo $vehicle_stats['today_unique_vehicles']; ?>
                            </span>
                        </div>
                        
                        <div class="flex justify-between items-center p-3 bg-purple-50 rounded-lg">
                            <span class="text-sm font-medium text-purple-800">Average Stay</span>
                            <span class="text-lg font-bold text-purple-600">
                                <?php 
                                $avg_duration = 0;
                                if (!empty($inside_vehicles)) {
                                    $total_minutes = 0;
                                    $count = 0;
                                    foreach ($inside_vehicles as $vehicle) {
                                        $minutes_inside = round((time() - strtotime($vehicle['checkin_time'])) / 60);
                                        $total_minutes += $minutes_inside;
                                        $count++;
                                    }
                                    $avg_duration = $count > 0 ? round($total_minutes / $count) : 0;
                                }
                                echo $avg_duration; ?>m
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        
        <div class="mt-6 sm:mt-8 bg-white rounded-xl shadow-sm border border-gray-200">
            <div class="px-4 sm:px-6 py-3 sm:py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h3 class="text-base sm:text-lg font-semibold text-gray-900">Recent Vehicle Activity</h3>
                    <button onclick="refreshRecentActivity()" class="text-purple-600 hover:text-purple-800">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
            </div>
            
            <div class="divide-y divide-gray-200 max-h-96 overflow-y-auto">
                <?php if (empty($recent_vehicle_activity)): ?>
                    <div class="px-4 sm:px-6 py-8 text-center text-gray-500">
                        <i class="fas fa-history text-3xl sm:text-4xl mb-4 text-gray-300"></i>
                        <p class="text-sm sm:text-base">No recent vehicle activity</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($recent_vehicle_activity as $activity): ?>
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
                                                <?php echo htmlspecialchars($activity['license_plate']); ?>
                                            </p>
                                            <span class="px-2 py-1 text-xs font-medium rounded-full flex-shrink-0 <?php 
                                                echo $activity['log_type'] === 'check_in' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $activity['log_type'])); ?>
                                            </span>
                                            <span class="px-2 py-1 text-xs font-medium rounded-full flex-shrink-0 <?php 
                                                echo $activity['entry_purpose'] === 'delivery' ? 'bg-orange-100 text-orange-800' :
                                                    ($activity['entry_purpose'] === 'service' ? 'bg-blue-100 text-blue-800' :
                                                     'bg-gray-100 text-gray-800'); ?>">
                                                <?php echo ucfirst($activity['entry_purpose']); ?>
                                            </span>
                                        </div>
                                        <div class="mt-1 text-xs text-gray-500">
                                            <span><?php echo htmlspecialchars($activity['make'] . ' ' . $activity['model']); ?></span>
                                            <?php if ($activity['owner_company']): ?>
                                                <span class="ml-2">• <?php echo htmlspecialchars($activity['owner_company']); ?></span>
                                            <?php endif; ?>
                                            <span class="ml-2 mobile-hidden">• By: <?php echo htmlspecialchars($activity['operator_name']); ?></span>
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
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function changeLocation() {
            const locationId = document.getElementById('location_selector').value;
            window.location.href = `vehicle-dashboard.php?location_id=${locationId}`;
        }

        function quickCheckout(vehicleId, licensePlate) {
            if (confirm(`Check out vehicle ${licensePlate}?`)) {
                const formData = new FormData();
                formData.append('vehicle_id', vehicleId);
                formData.append('action', 'quick_checkout');
                formData.append('location_id', <?php echo $selected_location_id; ?>);
                
                fetch('process-vehicle-checkout.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(`Vehicle ${licensePlate} checked out successfully`, 'success');
                        setTimeout(() => window.location.reload(), 1000);
                    } else {
                        showNotification(`Error: ${data.message}`, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('An error occurred during checkout', 'error');
                });
            }
        }

        function refreshInsideVehicles() {
            window.location.reload();
        }

        function refreshRecentActivity() {
            window.location.reload();
        }

        function startRealTimeUpdates() {
            setInterval(updateDashboardData, 30000); // Update every 30 seconds
        }

        function updateDashboardData() {
            fetch(`api-realtime.php?endpoint=vehicle_dashboard&location_id=<?php echo $selected_location_id; ?>`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateStatistics(data.data.stats);
                        updateOverdueAlert(data.data.overdue);
                    }
                })
                .catch(error => console.error('Error updating dashboard:', error));
        }

        function updateStatistics(stats) {
            const statElements = [
                { selector: '.bg-green-100 + div .text-2xl', value: stats.today_check_ins },
                { selector: '.bg-red-100 + div .text-2xl', value: stats.today_check_outs },
                { selector: '.bg-purple-100 + div .text-2xl', value: stats.currently_inside },
                { selector: '.bg-orange-100 + div .text-2xl', value: stats.today_deliveries }
            ];

            statElements.forEach(stat => {
                const element = document.querySelector(stat.selector);
                if (element && element.textContent !== stat.value.toString()) {
                    animateNumber(element, parseInt(element.textContent) || 0, stat.value);
                }
            });
        }

        function animateNumber(element, start, end) {
            const duration = 1000;
            const startTime = performance.now();
            
            function update(currentTime) {
                const elapsed = currentTime - startTime;
                const progress = Math.min(elapsed / duration, 1);
                
                const current = Math.round(start + (end - start) * progress);
                element.textContent = current;
                
                if (progress < 1) {
                    requestAnimationFrame(update);
                }
            }
            
            requestAnimationFrame(update);
        }

        function updateOverdueAlert(overdueVehicles) {
            if (overdueVehicles.length > 0) {
                const overdueAlert = document.querySelector('.bg-red-50');
                if (overdueAlert) {
                    overdueAlert.classList.add('animate-pulse');
                    setTimeout(() => {
                        overdueAlert.classList.remove('animate-pulse');
                    }, 2000);
                }
            }
        }

        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 z-50 p-4 rounded-lg border max-w-sm transform transition-all duration-300 translate-x-full opacity-0 ${
                type === 'success' ? 'bg-green-50 border-green-200 text-green-800' :
                type === 'error' ? 'bg-red-50 border-red-200 text-red-800' :
                type === 'warning' ? 'bg-yellow-50 border-yellow-200 text-yellow-800' :
                'bg-blue-50 border-blue-200 text-blue-800'
            }`;
            
            notification.innerHTML = `
                <div class="flex items-center">
                    <i class="fas ${
                        type === 'success' ? 'fa-check-circle' :
                        type === 'error' ? 'fa-exclamation-circle' :
                        type === 'warning' ? 'fa-exclamation-triangle' :
                        'fa-info-circle'
                    } mr-2"></i>
                    <span class="text-sm font-medium">${message}</span>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.classList.remove('translate-x-full', 'opacity-0');
            }, 100);
            
            setTimeout(() => {
                notification.classList.add('translate-x-full', 'opacity-0');
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }

        document.addEventListener('DOMContentLoaded', function() {
            startRealTimeUpdates();
            
            document.querySelectorAll('.vehicle-card').forEach(card => {
                card.addEventListener('click', function(e) {
                    if (!e.target.closest('button') && !e.target.closest('a')) {
                        const licensePlate = this.querySelector('h4').textContent;
                    }
                });
            });
        });

        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey || e.metaKey) {
                switch(e.key) {
                    case 's':
                        e.preventDefault();
                        window.location.href = 'vehicle-scanner.php?location_id=<?php echo $selected_location_id; ?>';
                        break;
                    case 'r':
                        e.preventDefault();
                        window.location.reload();
                        break;
                }
            }
        });

        if (window.innerWidth <= 768) {
            let startY = 0;
            
            document.addEventListener('touchstart', function(e) {
                startY = e.touches[0].clientY;
            });
            
            document.addEventListener('touchend', function(e) {
                const endY = e.changedTouches[0].clientY;
                const diff = startY - endY;
                
                if (Math.abs(diff) > 100 && window.scrollY < 50) {
                    if (diff > 0) {
                        window.location.reload();
                    }
                }
            });
        }
    </script>
</body>

  
</body>
</html>