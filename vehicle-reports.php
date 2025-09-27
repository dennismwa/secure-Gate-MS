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

$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$report_type = $_GET['report_type'] ?? 'overview';

$stmt = $db->prepare("SELECT 
    COUNT(CASE WHEN log_type = 'check_in' THEN 1 END) as total_checkins,
    COUNT(CASE WHEN log_type = 'check_out' THEN 1 END) as total_checkouts,
    COUNT(DISTINCT vehicle_id) as unique_vehicles,
    COUNT(DISTINCT DATE(log_timestamp)) as active_days,
    AVG(CASE WHEN log_type = 'check_out' AND expected_duration > 0 THEN 
        TIMESTAMPDIFF(MINUTE, 
            (SELECT log_timestamp FROM vehicle_logs vl2 WHERE vl2.vehicle_id = vehicle_logs.vehicle_id AND vl2.log_type = 'check_in' AND vl2.log_timestamp < vehicle_logs.log_timestamp ORDER BY vl2.log_timestamp DESC LIMIT 1),
            log_timestamp
        ) END) as avg_duration_minutes
FROM vehicle_logs 
WHERE location_id = ? AND DATE(log_timestamp) BETWEEN ? AND ?");
$stmt->execute([$selected_location_id, $date_from, $date_to]);
$overview_stats = $stmt->fetch();

$stmt = $db->prepare("SELECT 
    vt.type_name,
    COUNT(DISTINCT v.vehicle_id) as vehicle_count,
    COUNT(vl.id) as total_visits,
    AVG(CASE WHEN vl.expected_duration > 0 THEN vl.expected_duration END) as avg_expected_duration
FROM vehicle_types vt
LEFT JOIN vehicles v ON vt.id = v.vehicle_type_id
LEFT JOIN vehicle_logs vl ON v.vehicle_id = vl.vehicle_id AND vl.location_id = ? AND DATE(vl.log_timestamp) BETWEEN ? AND ?
WHERE vt.is_active = 1
GROUP BY vt.id, vt.type_name
ORDER BY total_visits DESC");
$stmt->execute([$selected_location_id, $date_from, $date_to]);
$vehicle_type_stats = $stmt->fetchAll();

$stmt = $db->prepare("SELECT 
    DATE(log_timestamp) as log_date,
    COUNT(CASE WHEN log_type = 'check_in' THEN 1 END) as checkins,
    COUNT(CASE WHEN log_type = 'check_out' THEN 1 END) as checkouts,
    COUNT(DISTINCT vehicle_id) as unique_vehicles,
    COUNT(CASE WHEN entry_purpose = 'delivery' THEN 1 END) as deliveries
FROM vehicle_logs 
WHERE location_id = ? AND DATE(log_timestamp) BETWEEN ? AND ?
GROUP BY DATE(log_timestamp)
ORDER BY log_date ASC");
$stmt->execute([$selected_location_id, $date_from, $date_to]);
$daily_trends = $stmt->fetchAll();

$stmt = $db->prepare("SELECT 
    v.vehicle_id,
    v.license_plate,
    v.make,
    v.model,
    v.owner_company,
    vt.type_name,
    COUNT(vl.id) as total_visits,
    COUNT(CASE WHEN vl.log_type = 'check_in' THEN 1 END) as checkins,
    COUNT(CASE WHEN vl.log_type = 'check_out' THEN 1 END) as checkouts,
    MAX(vl.log_timestamp) as last_visit,
    AVG(CASE WHEN vl.expected_duration > 0 THEN vl.expected_duration END) as avg_duration
FROM vehicles v
LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
JOIN vehicle_logs vl ON v.vehicle_id = vl.vehicle_id
WHERE vl.location_id = ? AND DATE(vl.log_timestamp) BETWEEN ? AND ?
GROUP BY v.vehicle_id
ORDER BY total_visits DESC
LIMIT 20");
$stmt->execute([$selected_location_id, $date_from, $date_to]);
$top_vehicles = $stmt->fetchAll();

$stmt = $db->prepare("SELECT 
    entry_purpose,
    COUNT(*) as total_count,
    COUNT(CASE WHEN log_type = 'check_in' THEN 1 END) as arrivals,
    COUNT(CASE WHEN log_type = 'check_out' THEN 1 END) as departures,
    AVG(CASE WHEN expected_duration > 0 THEN expected_duration END) as avg_expected_duration,
    COUNT(CASE WHEN delivery_company IS NOT NULL THEN 1 END) as with_company
FROM vehicle_logs 
WHERE location_id = ? AND DATE(log_timestamp) BETWEEN ? AND ? 
AND entry_purpose IN ('delivery', 'pickup', 'service', 'maintenance')
GROUP BY entry_purpose
ORDER BY total_count DESC");
$stmt->execute([$selected_location_id, $date_from, $date_to]);
$delivery_performance = $stmt->fetchAll();

$stmt = $db->prepare("SELECT 
    HOUR(log_timestamp) as hour,
    COUNT(*) as activity_count,
    COUNT(CASE WHEN log_type = 'check_in' THEN 1 END) as checkins,
    COUNT(CASE WHEN log_type = 'check_out' THEN 1 END) as checkouts
FROM vehicle_logs 
WHERE location_id = ? AND DATE(log_timestamp) BETWEEN ? AND ?
GROUP BY HOUR(log_timestamp)
ORDER BY hour ASC");
$stmt->execute([$selected_location_id, $date_from, $date_to]);
$hourly_activity = $stmt->fetchAll();

$stmt = $db->prepare("SELECT 
    v.license_plate,
    v.make,
    v.model,
    vl1.log_timestamp as checkin_time,
    vl2.log_timestamp as checkout_time,
    TIMESTAMPDIFF(MINUTE, vl1.log_timestamp, vl2.log_timestamp) as actual_duration,
    vl1.expected_duration,
    vl1.entry_purpose,
    vl1.delivery_company
FROM vehicle_logs vl1
JOIN vehicles v ON vl1.vehicle_id = v.vehicle_id
LEFT JOIN vehicle_logs vl2 ON vl1.vehicle_id = vl2.vehicle_id 
    AND vl2.log_type = 'check_out' 
    AND vl2.log_timestamp > vl1.log_timestamp
    AND vl2.location_id = vl1.location_id
    AND NOT EXISTS (
        SELECT 1 FROM vehicle_logs vl3 
        WHERE vl3.vehicle_id = vl1.vehicle_id 
        AND vl3.location_id = vl1.location_id
        AND vl3.log_timestamp > vl1.log_timestamp 
        AND vl3.log_timestamp < vl2.log_timestamp
    )
WHERE vl1.location_id = ? AND vl1.log_type = 'check_in' 
AND DATE(vl1.log_timestamp) BETWEEN ? AND ?
AND vl2.log_timestamp IS NOT NULL
ORDER BY vl1.log_timestamp DESC
LIMIT 50");
$stmt->execute([$selected_location_id, $date_from, $date_to]);
$duration_analysis = $stmt->fetchAll();

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management System'); ?> - Vehicle Reports</title>
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
            .mobile-overflow { overflow-x: auto !important; }
        }
        
        .report-card { 
            transition: transform 0.2s ease, box-shadow 0.2s ease; 
        }
        .report-card:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1); 
        }
        
        .chart-container {
            position: relative;
            height: 300px;
        }
        
        @media (max-width: 640px) {
            .chart-container {
                height: 250px;
            }
        }
        
        .export-btn {
            transition: all 0.2s ease;
        }
        
        .export-btn:hover {
            transform: translateY(-1px);
        }
        
        .progress-bar {
            transition: width 0.3s ease;
        }
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
                        <i class="fas fa-chart-bar text-white text-sm sm:text-base"></i>
                    </div>
                    <div>
                        <h1 class="text-lg sm:text-xl font-semibold text-gray-900">Vehicle Reports</h1>
                        <p class="text-xs sm:text-sm text-gray-500 hidden sm:block">
                            <?php echo htmlspecialchars($current_location['location_name'] ?? 'All Locations'); ?>
                        </p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <button onclick="exportReport()" class="export-btn text-green-600 hover:text-green-800 text-sm sm:text-base">
                        <i class="fas fa-download"></i>
                        <span class="ml-1 hidden sm:inline">Export</span>
                    </button>
                    <a href="vehicle-dashboard.php?location_id=<?php echo $selected_location_id; ?>" class="text-purple-600 hover:text-purple-800 text-sm sm:text-base">
                        <i class="fas fa-tachometer-alt"></i>
                        <span class="ml-1 hidden sm:inline">Dashboard</span>
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

        
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-3 sm:p-6 mb-6">
            <div class="flex flex-col space-y-4">
                <form method="GET" class="flex flex-col space-y-3 sm:flex-row sm:space-y-0 sm:space-x-4">
                    <?php if (count($operator_locations) > 1): ?>
                        <div>
                            <label for="location_id" class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                            <select name="location_id" id="location_id" class="px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm">
                                <?php foreach ($operator_locations as $location): ?>
                                    <option value="<?php echo $location['id']; ?>" <?php echo $location['id'] == $selected_location_id ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($location['location_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="location_id" value="<?php echo $selected_location_id; ?>">
                    <?php endif; ?>
                    
                    <div>
                        <label for="date_from" class="block text-sm font-medium text-gray-700 mb-1">From Date</label>
                        <input type="date" name="date_from" id="date_from" value="<?php echo $date_from; ?>" 
                               class="px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm">
                    </div>
                    
                    <div>
                        <label for="date_to" class="block text-sm font-medium text-gray-700 mb-1">To Date</label>
                        <input type="date" name="date_to" id="date_to" value="<?php echo $date_to; ?>" 
                               class="px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm">
                    </div>
                    
                    <div>
                        <label for="report_type" class="block text-sm font-medium text-gray-700 mb-1">Report Type</label>
                        <select name="report_type" id="report_type" class="px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm">
                            <option value="overview" <?php echo $report_type === 'overview' ? 'selected' : ''; ?>>Overview</option>
                            <option value="detailed" <?php echo $report_type === 'detailed' ? 'selected' : ''; ?>>Detailed</option>
                            <option value="performance" <?php echo $report_type === 'performance' ? 'selected' : ''; ?>>Performance</option>
                        </select>
                    </div>
                    
                    <div class="flex items-end">
                        <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg font-medium transition-colors text-sm">
                            <i class="fas fa-filter mr-2"></i>Apply Filters
                        </button>
                    </div>
                </form>
                
                <div class="flex flex-wrap gap-2">
                    <button onclick="setQuickDateRange('today')" class="px-3 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded-full transition-colors">Today</button>
                    <button onclick="setQuickDateRange('yesterday')" class="px-3 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded-full transition-colors">Yesterday</button>
                    <button onclick="setQuickDateRange('week')" class="px-3 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded-full transition-colors">This Week</button>
                    <button onclick="setQuickDateRange('month')" class="px-3 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded-full transition-colors">This Month</button>
                    <button onclick="setQuickDateRange('quarter')" class="px-3 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded-full transition-colors">This Quarter</button>
                </div>
            </div>
        </div>

        
        <div class="grid mobile-grid-2 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-6 mb-6 sm:mb-8">
            <div class="report-card bg-white p-4 sm:p-6 rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-3 bg-blue-100 rounded-lg">
                        <i class="fas fa-car text-blue-600 text-lg"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Unique Vehicles</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($overview_stats['unique_vehicles']); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="report-card bg-white p-4 sm:p-6 rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-3 bg-green-100 rounded-lg">
                        <i class="fas fa-sign-in-alt text-green-600 text-lg"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Total Check-ins</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($overview_stats['total_checkins']); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="report-card bg-white p-4 sm:p-6 rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-3 bg-red-100 rounded-lg">
                        <i class="fas fa-sign-out-alt text-red-600 text-lg"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Total Check-outs</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($overview_stats['total_checkouts']); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="report-card bg-white p-4 sm:p-6 rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-3 bg-purple-100 rounded-lg">
                        <i class="fas fa-clock text-purple-600 text-lg"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Avg Duration</p>
                        <p class="text-2xl font-bold text-gray-900">
                            <?php echo $overview_stats['avg_duration_minutes'] ? round($overview_stats['avg_duration_minutes']) . 'm' : 'N/A'; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Daily Activity Trend</h3>
                <div class="chart-container">
                    <canvas id="dailyTrendChart"></canvas>
                </div>
            </div>

            
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Vehicle Type Distribution</h3>
                <div class="chart-container">
                    <canvas id="vehicleTypeChart"></canvas>
                </div>
            </div>
        </div>

        
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6 mb-8">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Peak Hours Analysis</h3>
            <div class="chart-container">
                <canvas id="peakHoursChart"></canvas>
            </div>
        </div>

        
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Top Vehicles by Activity</h3>
                <div class="overflow-x-auto mobile-overflow">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Vehicle</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase mobile-hidden">Type</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Visits</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase mobile-hidden">Last Visit</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach (array_slice($top_vehicles, 0, 10) as $vehicle): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-3 py-2">
                                        <div>
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($vehicle['license_plate']); ?>
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                <?php echo htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']); ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 mobile-hidden">
                                        <span class="px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-800">
                                            <?php echo htmlspecialchars($vehicle['type_name'] ?? 'Unknown'); ?>
                                        </span>
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="text-sm font-medium text-gray-900"><?php echo $vehicle['total_visits']; ?></div>
                                        <div class="text-xs text-gray-500">
                                            In: <?php echo $vehicle['checkins']; ?> | Out: <?php echo $vehicle['checkouts']; ?>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 mobile-hidden">
                                        <div class="text-sm text-gray-900">
                                            <?php echo date('M j, Y', strtotime($vehicle['last_visit'])); ?>
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            <?php echo date('g:i A', strtotime($vehicle['last_visit'])); ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Delivery Performance</h3>
                <div class="space-y-4">
                    <?php foreach ($delivery_performance as $delivery): ?>
                        <div class="p-4 bg-gray-50 rounded-lg">
                            <div class="flex justify-between items-center mb-2">
                                <h4 class="font-medium text-gray-900"><?php echo ucfirst($delivery['entry_purpose']); ?></h4>
                                <span class="text-lg font-bold text-purple-600"><?php echo $delivery['total_count']; ?></span>
                            </div>
                            <div class="grid grid-cols-2 gap-4 text-sm">
                                <div>
                                    <span class="text-gray-600">Arrivals:</span>
                                    <span class="font-medium text-green-600"><?php echo $delivery['arrivals']; ?></span>
                                </div>
                                <div>
                                    <span class="text-gray-600">Departures:</span>
                                    <span class="font-medium text-red-600"><?php echo $delivery['departures']; ?></span>
                                </div>
                            </div>
                            <?php if ($delivery['avg_expected_duration']): ?>
                                <div class="mt-2 text-sm text-gray-600">
                                    Avg Duration: <?php echo round($delivery['avg_expected_duration']); ?> minutes
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        
        <?php if (!empty($duration_analysis)): ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6 mb-8">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Duration Analysis</h3>
                <div class="overflow-x-auto mobile-overflow">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Vehicle</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase mobile-hidden">Purpose</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Expected</th>
                                <th class="px-3py-2 text-left text-xs font-medium text-gray-500 uppercase">Actual</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Variance</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach (array_slice($duration_analysis, 0, 15) as $analysis): ?>
                                <?php 
                                $variance = null;
                                $variance_class = 'text-gray-900';
                                if ($analysis['expected_duration'] && $analysis['actual_duration']) {
                                    $variance = $analysis['actual_duration'] - $analysis['expected_duration'];
                                    $variance_class = $variance > 0 ? 'text-red-600' : 'text-green-600';
                                }
                                ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-3 py-2">
                                        <div>
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($analysis['license_plate']); ?>
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                <?php echo htmlspecialchars($analysis['make'] . ' ' . $analysis['model']); ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 mobile-hidden">
                                        <span class="px-2 py-1 text-xs font-medium rounded-full bg-purple-100 text-purple-800">
                                            <?php echo ucfirst($analysis['entry_purpose']); ?>
                                        </span>
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="text-sm text-gray-900">
                                            <?php echo $analysis['expected_duration'] ? $analysis['expected_duration'] . 'm' : 'N/A'; ?>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="text-sm text-gray-900">
                                            <?php echo $analysis['actual_duration'] . 'm'; ?>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="text-sm font-medium <?php echo $variance_class; ?>">
                                            <?php 
                                            if ($variance !== null) {
                                                echo ($variance > 0 ? '+' : '') . $variance . 'm';
                                            } else {
                                                echo 'N/A';
                                            }
                                            ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Export Options</h3>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <button onclick="exportToPDF()" class="export-btn bg-red-600 hover:bg-red-700 text-white px-4 py-3 rounded-lg font-medium transition-colors">
                    <i class="fas fa-file-pdf mr-2"></i>Export to PDF
                </button>
                <button onclick="exportToExcel()" class="export-btn bg-green-600 hover:bg-green-700 text-white px-4 py-3 rounded-lg font-medium transition-colors">
                    <i class="fas fa-file-excel mr-2"></i>Export to Excel
                </button>
                <button onclick="exportToCSV()" class="export-btn bg-blue-600 hover:bg-blue-700 text-white px-4 py-3 rounded-lg font-medium transition-colors">
                    <i class="fas fa-file-csv mr-2"></i>Export to CSV
                </button>
            </div>
        </div>
    </div>

    <script>
        const dailyTrendData = <?php echo json_encode($daily_trends); ?>;
        const vehicleTypeData = <?php echo json_encode($vehicle_type_stats); ?>;
        const hourlyData = <?php echo json_encode($hourly_activity); ?>;

        document.addEventListener('DOMContentLoaded', function() {
            initializeDailyTrendChart();
            initializeVehicleTypeChart();
            initializePeakHoursChart();
        });

        function initializeDailyTrendChart() {
            const ctx = document.getElementById('dailyTrendChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: dailyTrendData.map(d => {
                        const date = new Date(d.log_date);
                        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                    }),
                    datasets: [{
                        label: 'Check-ins',
                        data: dailyTrendData.map(d => d.checkins),
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        tension: 0.4,
                        fill: true
                    }, {
                        label: 'Check-outs',
                        data: dailyTrendData.map(d => d.checkouts),
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        tension: 0.4,
                        fill: true
                    }, {
                        label: 'Unique Vehicles',
                        data: dailyTrendData.map(d => d.unique_vehicles),
                        borderColor: '#8b5cf6',
                        backgroundColor: 'rgba(139, 92, 246, 0.1)',
                        tension: 0.4,
                        fill: false
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                padding: 20,
                                font: {
                                    size: window.innerWidth < 640 ? 10 : 12
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                font: {
                                    size: window.innerWidth < 640 ? 10 : 12
                                }
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        },
                        x: {
                            ticks: {
                                font: {
                                    size: window.innerWidth < 640 ? 10 : 12
                                }
                            },
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }

        function initializeVehicleTypeChart() {
            const ctx = document.getElementById('vehicleTypeChart').getContext('2d');
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: vehicleTypeData.map(d => d.type_name),
                    datasets: [{
                        data: vehicleTypeData.map(d => d.total_visits),
                        backgroundColor: [
                            '#3b82f6',
                            '#10b981',
                            '#f59e0b',
                            '#ef4444',
                            '#8b5cf6',
                            '#06b6d4'
                        ],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                font: {
                                    size: window.innerWidth < 640 ? 10 : 12
                                }
                            }
                        }
                    }
                }
            });
        }

        function initializePeakHoursChart() {
            const ctx = document.getElementById('peakHoursChart').getContext('2d');
            
            const hourlyActivityMap = {};
            hourlyData.forEach(h => {
                hourlyActivityMap[h.hour] = h.activity_count;
            });
            
            const hours = [];
            const activities = [];
            for (let i = 0; i < 24; i++) {
                hours.push(i + ':00');
                activities.push(hourlyActivityMap[i] || 0);
            }
            
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: hours,
                    datasets: [{
                        label: 'Total Activity',
                        data: activities,
                        backgroundColor: 'rgba(139, 92, 246, 0.6)',
                        borderColor: '#8b5cf6',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                font: {
                                    size: window.innerWidth < 640 ? 10 : 12
                                }
                            }
                        },
                        x: {
                            ticks: {
                                maxRotation: 45,
                                font: {
                                    size: window.innerWidth < 640 ? 8 : 10
                                }
                            }
                        }
                    }
                }
            });
        }

        function setQuickDateRange(range) {
            const today = new Date();
            let fromDate, toDate;

            switch (range) {
                case 'today':
                    fromDate = toDate = today.toISOString().split('T')[0];
                    break;
                case 'yesterday':
                    const yesterday = new Date(today);
                    yesterday.setDate(yesterday.getDate() - 1);
                    fromDate = toDate = yesterday.toISOString().split('T')[0];
                    break;
                case 'week':
                    const weekStart = new Date(today);
                    weekStart.setDate(today.getDate() - today.getDay());
                    fromDate = weekStart.toISOString().split('T')[0];
                    toDate = today.toISOString().split('T')[0];
                    break;
                case 'month':
                    const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);
                    fromDate = monthStart.toISOString().split('T')[0];
                    toDate = today.toISOString().split('T')[0];
                    break;
                case 'quarter':
                    const quarterStart = new Date(today.getFullYear(), Math.floor(today.getMonth() / 3) * 3, 1);
                    fromDate = quarterStart.toISOString().split('T')[0];
                    toDate = today.toISOString().split('T')[0];
                    break;
            }

            document.getElementById('date_from').value = fromDate;
            document.getElementById('date_to').value = toDate;
        }

        function exportReport() {
            const exportMenu = document.createElement('div');
            exportMenu.className = 'fixed inset-0 bg-gray-600 bg-opacity-50 z-50 flex items-center justify-center';
            exportMenu.innerHTML = `
                <div class="bg-white rounded-xl p-6 max-w-sm w-full mx-4">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Export Report</h3>
                    <div class="space-y-3">
                        <button onclick="exportToPDF(); closeExportMenu();" class="w-full bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-file-pdf mr-2"></i>PDF Report
                        </button>
                        <button onclick="exportToExcel(); closeExportMenu();" class="w-full bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-file-excel mr-2"></i>Excel Spreadsheet
                        </button>
                        <button onclick="exportToCSV(); closeExportMenu();" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-file-csv mr-2"></i>CSV Data
                        </button>
                    </div>
                    <button onclick="closeExportMenu()" class="mt-4 w-full border border-gray-300 rounded-lg px-4 py-2 text-gray-700 hover:bg-gray-50">
                        Cancel
                    </button>
                </div>
            `;
            
            document.body.appendChild(exportMenu);
            
            window.closeExportMenu = function() {
                document.body.removeChild(exportMenu);
            };
        }

        function exportToPDF() {
            const params = new URLSearchParams({
                location_id: <?php echo $selected_location_id; ?>,
                date_from: '<?php echo $date_from; ?>',
                date_to: '<?php echo $date_to; ?>',
                report_type: '<?php echo $report_type; ?>',
                format: 'pdf'
            });
            
            window.open(`export-vehicle-report.php?${params.toString()}`, '_blank');
        }

        function exportToExcel() {
            const params = new URLSearchParams({
                location_id: <?php echo $selected_location_id; ?>,
                date_from: '<?php echo $date_from; ?>',
                date_to: '<?php echo $date_to; ?>',
                report_type: '<?php echo $report_type; ?>',
                format: 'excel'
            });
            
            window.open(`export-vehicle-report.php?${params.toString()}`, '_blank');
        }

        function exportToCSV() {
            const params = new URLSearchParams({
                location_id: <?php echo $selected_location_id; ?>,
                date_from: '<?php echo $date_from; ?>',
                date_to: '<?php echo $date_to; ?>',
                report_type: '<?php echo $report_type; ?>',
                format: 'csv'
            });
            
            window.open(`export-vehicle-report.php?${params.toString()}`, '_blank');
        }

        setInterval(() => {
            if (!document.hidden) {
                window.location.reload();
            }
        }, 300000);

        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
        });

        window.addEventListener('resize', function() {
        });
    </script>
</body>
</html>