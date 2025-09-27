<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);
$settings = getSettings($db);

// Get operator's locations
$stmt = $db->prepare("SELECT l.* FROM locations l JOIN operator_locations ol ON l.id = ol.location_id WHERE ol.operator_id = ? AND l.is_active = 1 ORDER BY ol.is_primary DESC, l.location_name");
$stmt->execute([$session['operator_id']]);
$operator_locations = $stmt->fetchAll();

$default_location_id = $operator_locations[0]['id'] ?? 1;
$selected_location_id = intval($_GET['location_id'] ?? $default_location_id);

$action = $_GET['action'] ?? 'dashboard';

// Handle delivery creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $action == 'create') {
    $delivery_type = sanitizeInput($_POST['delivery_type']);
    $delivery_company = sanitizeInput($_POST['delivery_company']);
    $reference_number = sanitizeInput($_POST['reference_number']);
    $sender_name = sanitizeInput($_POST['sender_name']);
    $sender_phone = sanitizeInput($_POST['sender_phone']);
    $receiver_name = sanitizeInput($_POST['receiver_name']);
    $receiver_phone = sanitizeInput($_POST['receiver_phone']);
    $receiver_department = sanitizeInput($_POST['receiver_department']);
    $package_description = sanitizeInput($_POST['package_description']);
    $package_count = intval($_POST['package_count']);
    $special_instructions = sanitizeInput($_POST['special_instructions']);
    $scheduled_time = sanitizeInput($_POST['scheduled_time']);
    $vehicle_id = sanitizeInput($_POST['vehicle_id']) ?: null;
    
    // Validation
    $errors = [];
    if (empty($delivery_company)) $errors[] = 'Delivery company is required';
    if (empty($receiver_name)) $errors[] = 'Receiver name is required';
    if (!validatePhone($receiver_phone)) $errors[] = 'Valid receiver phone is required';
    if (!empty($sender_phone) && !validatePhone($sender_phone)) $errors[] = 'Invalid sender phone format';
    
    if (empty($errors)) {
        $delivery_id = generateUniqueId('DEL');
        
        $stmt = $db->prepare("INSERT INTO deliveries (delivery_id, vehicle_id, location_id, delivery_type, delivery_company, reference_number, sender_name, sender_phone, receiver_name, receiver_phone, receiver_department, package_description, package_count, special_instructions, scheduled_time, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        if ($stmt->execute([$delivery_id, $vehicle_id, $selected_location_id, $delivery_type, $delivery_company, $reference_number, $sender_name, $sender_phone, $receiver_name, $receiver_phone, $receiver_department, $package_description, $package_count, $special_instructions, $scheduled_time, $session['operator_id']])) {
            logActivity($db, $session['operator_id'], 'delivery_create', "Created delivery: $delivery_id for $delivery_company", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
            setMessage("Delivery scheduled successfully! Delivery ID: $delivery_id", 'success');
            header('Location: delivery-tracking.php?location_id=' . $selected_location_id);
            exit;
        } else {
            $errors[] = 'Failed to create delivery';
        }
    }
}

// Handle delivery status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $action == 'update_status') {
    $delivery_id = sanitizeInput($_POST['delivery_id']);
    $new_status = sanitizeInput($_POST['new_status']);
    $notes = sanitizeInput($_POST['notes']);
    
    $update_fields = ['status = ?', 'updated_by = ?'];
    $params = [$new_status, $session['operator_id']];
    
    // Set timestamps based on status
    if ($new_status === 'arrived') {
        $update_fields[] = 'arrived_time = NOW()';
    } elseif ($new_status === 'completed') {
        $update_fields[] = 'completed_time = NOW()';
    }
    
    $stmt = $db->prepare("UPDATE deliveries SET " . implode(', ', $update_fields) . " WHERE delivery_id = ?");
    $params[] = $delivery_id;
    
    if ($stmt->execute($params)) {
        // Log the status change
        logActivity($db, $session['operator_id'], 'delivery_status_update', "Updated delivery $delivery_id status to $new_status", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        
        // Create notification
        createNotification($db, 'alert', 'Delivery Status Updated', "Delivery $delivery_id status changed to $new_status", null, $session['operator_id']);
        
        setMessage('Delivery status updated successfully', 'success');
    } else {
        setMessage('Failed to update delivery status', 'error');
    }
    
    header('Location: delivery-tracking.php?location_id=' . $selected_location_id);
    exit;
}

// Get current location info
$stmt = $db->prepare("SELECT * FROM locations WHERE id = ?");
$stmt->execute([$selected_location_id]);
$current_location = $stmt->fetch();

// Get delivery statistics
$stmt = $db->prepare("SELECT 
    COUNT(CASE WHEN status = 'scheduled' THEN 1 END) as scheduled,
    COUNT(CASE WHEN status = 'arrived' THEN 1 END) as arrived,
    COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress,
    COUNT(CASE WHEN status = 'completed' AND DATE(completed_time) = CURDATE() THEN 1 END) as completed_today,
    COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled,
    COUNT(CASE WHEN scheduled_time < NOW() AND status = 'scheduled' THEN 1 END) as overdue
FROM deliveries WHERE location_id = ? AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
$stmt->execute([$selected_location_id]);
$delivery_stats = $stmt->fetch();

// Get active deliveries
$stmt = $db->prepare("SELECT d.*, v.license_plate, v.make, v.model 
                     FROM deliveries d 
                     LEFT JOIN vehicles v ON d.vehicle_id = v.vehicle_id 
                     WHERE d.location_id = ? AND d.status IN ('scheduled', 'arrived', 'in_progress')
                     ORDER BY 
                        CASE d.status 
                            WHEN 'arrived' THEN 1 
                            WHEN 'in_progress' THEN 2 
                            WHEN 'scheduled' THEN 3 
                        END,
                        d.scheduled_time ASC");
$stmt->execute([$selected_location_id]);
$active_deliveries = $stmt->fetchAll();

// Get recent deliveries
$stmt = $db->prepare("SELECT d.*, v.license_plate, v.make, v.model, go.operator_name as updated_by_name
                     FROM deliveries d 
                     LEFT JOIN vehicles v ON d.vehicle_id = v.vehicle_id 
                     LEFT JOIN gate_operators go ON d.updated_by = go.id
                     WHERE d.location_id = ? 
                     ORDER BY d.updated_at DESC 
                     LIMIT 20");
$stmt->execute([$selected_location_id]);
$recent_deliveries = $stmt->fetchAll();

// Get vehicles for assignment
$stmt = $db->query("SELECT vehicle_id, license_plate, make, model FROM vehicles WHERE status = 'active' AND is_delivery_vehicle = 1 ORDER BY license_plate");
$delivery_vehicles = $stmt->fetchAll();

// Get departments
$stmt = $db->query("SELECT department_name FROM departments WHERE is_active = 1 ORDER BY department_name");
$departments = $stmt->fetchAll();

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management System'); ?> - Delivery Tracking</title>
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
    <style>
        @media (max-width: 768px) {
            .mobile-stack { flex-direction: column !important; gap: 0.5rem !important; }
            .mobile-full { width: 100% !important; }
            .mobile-text-sm { font-size: 0.875rem !important; }
            .mobile-hidden { display: none !important; }
            .mobile-grid-1 { grid-template-columns: repeat(1, minmax(0, 1fr)) !important; }
            .mobile-grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
        }
        
        .delivery-card { 
            transition: transform 0.2s ease, box-shadow 0.2s ease; 
            border-left: 4px solid transparent;
        }
        .delivery-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1); }
        .delivery-scheduled { border-left-color: #3b82f6; }
        .delivery-arrived { border-left-color: #f59e0b; }
        .delivery-in-progress { border-left-color: #10b981; }
        .delivery-completed { border-left-color: #6b7280; }
        .delivery-overdue { border-left-color: #ef4444; animation: pulse 2s infinite; }
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 500;
            border-radius: 9999px;
        }
        
        .pulse-dot { animation: pulse 2s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
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
                    <div class="h-8 w-8 sm:h-10 sm:w-10 bg-orange-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-truck text-white text-sm sm:text-base"></i>
                    </div>
                    <div>
                        <h1 class="text-lg sm:text-xl font-semibold text-gray-900">Delivery Tracking</h1>
                        <p class="text-xs sm:text-sm text-gray-500 hidden sm:block">
                            <?php echo htmlspecialchars($current_location['location_name'] ?? 'All Locations'); ?>
                        </p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <div class="flex items-center text-xs sm:text-sm text-gray-500">
                        <span class="pulse-dot w-2 h-2 bg-orange-500 rounded-full mr-2"></span>
                        <span class="mobile-hidden"><?php echo date('D, M j, Y - g:i A'); ?></span>
                        <span class="sm:hidden"><?php echo date('H:i'); ?></span>
                    </div>
                    <a href="vehicle-scanner.php?location_id=<?php echo $selected_location_id; ?>" class="text-green-600 hover:text-green-800 text-sm sm:text-base">
                        <i class="fas fa-qrcode"></i>
                        <span class="ml-1 hidden sm:inline">Scanner</span>
                    </a>
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

        <!-- Location Selector -->
        <?php if (count($operator_locations) > 1): ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-3 sm:p-4 mb-4 sm:mb-6">
                <div class="flex items-center space-x-3">
                    <i class="fas fa-map-marker-alt text-orange-600"></i>
                    <label for="location_selector" class="text-sm font-medium text-gray-700">Location:</label>
                    <select id="location_selector" onchange="changeLocation()" 
                            class="px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500 text-sm">
                        <?php foreach ($operator_locations as $location): ?>
                            <option value="<?php echo $location['id']; ?>" <?php echo $location['id'] == $selected_location_id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($location['location_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="grid mobile-grid-2 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-6 mb-6 sm:mb-8">
            <button onclick="openScheduleModal()" class="bg-orange-600 hover:bg-orange-700 text-white p-4 rounded-xl shadow-sm transition-colors">
                <div class="flex flex-col items-center text-center">
                    <i class="fas fa-calendar-plus text-xl sm:text-2xl mb-2"></i>
                    <h3 class="text-sm sm:text-lg font-semibold">Schedule Delivery</h3>
                    <p class="text-xs sm:text-sm opacity-90 mobile-hidden">New delivery</p>
                </div>
            </button>
            
            <a href="vehicle-scanner.php?location_id=<?php echo $selected_location_id; ?>" class="bg-green-600 hover:bg-green-700 text-white p-4 rounded-xl shadow-sm transition-colors">
                <div class="flex flex-col items-center text-center">
                    <i class="fas fa-qrcode text-xl sm:text-2xl mb-2"></i>
                    <h3 class="text-sm sm:text-lg font-semibold">Vehicle Scanner</h3>
                    <p class="text-xs sm:text-sm opacity-90 mobile-hidden">Scan vehicles</p>
                </div>
            </a>
            
            <button onclick="bulkUpdateStatus()" class="bg-blue-600 hover:bg-blue-700 text-white p-4 rounded-xl shadow-sm transition-colors">
                <div class="flex flex-col items-center text-center">
                    <i class="fas fa-tasks text-xl sm:text-2xl mb-2"></i>
                    <h3 class="text-sm sm:text-lg font-semibold">Bulk Update</h3>
                    <p class="text-xs sm:text-sm opacity-90 mobile-hidden">Mass operations</p>
                </div>
            </button>
            
            <a href="delivery-reports.php?location_id=<?php echo $selected_location_id; ?>" class="bg-purple-600 hover:bg-purple-700 text-white p-4 rounded-xl shadow-sm transition-colors">
                <div class="flex flex-col items-center text-center">
                    <i class="fas fa-chart-line text-xl sm:text-2xl mb-2"></i>
                    <h3 class="text-sm sm:text-lg font-semibold">Reports</h3>
                    <p class="text-xs sm:text-sm opacity-90 mobile-hidden">Analytics</p>
                </div>
            </a>
        </div>

        <!-- Statistics Cards -->
        <div class="grid mobile-grid-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 sm:gap-6 mb-6 sm:mb-8">
            <div class="bg-white p-3 sm:p-6 rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-2 bg-blue-100 rounded-lg">
                        <i class="fas fa-clock text-blue-600 text-sm sm:text-base"></i>
                    </div>
                    <div class="ml-3 sm:ml-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-600">Scheduled</p>
                        <p class="text-lg sm:text-2xl font-bold text-gray-900"><?php echo $delivery_stats['scheduled']; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-3 sm:p-6 rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-2 bg-yellow-100 rounded-lg">
                        <i class="fas fa-truck text-yellow-600 text-sm sm:text-base"></i>
                    </div>
                    <div class="ml-3 sm:ml-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-600">Arrived</p>
                        <p class="text-lg sm:text-2xl font-bold text-gray-900"><?php echo $delivery_stats['arrived']; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-3 sm:p-6 rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-2 bg-green-100 rounded-lg">
                        <i class="fas fa-sync text-green-600 text-sm sm:text-base"></i>
                    </div>
                    <div class="ml-3 sm:ml-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-600">In Progress</p>
                        <p class="text-lg sm:text-2xl font-bold text-gray-900"><?php echo $delivery_stats['in_progress']; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-3 sm:p-6 rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-2 bg-gray-100 rounded-lg">
                        <i class="fas fa-check text-gray-600 text-sm sm:text-base"></i>
                    </div>
                    <div class="ml-3 sm:ml-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-600">Completed</p>
                        <p class="text-lg sm:text-2xl font-bold text-gray-900"><?php echo $delivery_stats['completed_today']; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-3 sm:p-6 rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-2 bg-red-100 rounded-lg">
                        <i class="fas fa-exclamation-triangle text-red-600 text-sm sm:text-base"></i>
                    </div>
                    <div class="ml-3 sm:ml-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-600">Overdue</p>
                        <p class="text-lg sm:text-2xl font-bold text-gray-900"><?php echo $delivery_stats['overdue']; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-3 sm:p-6 rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-2 bg-gray-100 rounded-lg">
                        <i class="fas fa-times text-gray-600 text-sm sm:text-base"></i>
                    </div>
                    <div class="ml-3 sm:ml-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-600">Cancelled</p>
                        <p class="text-lg sm:text-2xl font-bold text-gray-900"><?php echo $delivery_stats['cancelled']; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Active Deliveries -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-8">
            <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                <div class="flex items-center justify-between mb-4 sm:mb-6">
                    <h3 class="text-base sm:text-lg font-semibold text-gray-900">
                        <i class="fas fa-truck mr-2 text-orange-600"></i>Active Deliveries (<?php echo count($active_deliveries); ?>)
                    </h3>
                    <button onclick="refreshDeliveries()" class="text-orange-600 hover:text-orange-800">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
                
                <?php if (empty($active_deliveries)): ?>
                    <div class="text-center py-8 sm:py-12 text-gray-500">
                        <i class="fas fa-truck text-3xl sm:text-4xl mb-4 text-gray-300"></i>
                        <p class="text-base sm:text-lg mb-2">No active deliveries</p>
                        <p class="text-sm sm:text-base">Schedule a new delivery to get started</p>
                        <button onclick="openScheduleModal()" class="mt-4 inline-flex items-center bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg font-medium transition-colors text-sm sm:text-base">
                            <i class="fas fa-plus mr-2"></i>Schedule Delivery
                        </button>
                    </div>
                <?php else: ?>
                    <div class="space-y-3 sm:space-y-4 max-h-96 overflow-y-auto">
                        <?php foreach ($active_deliveries as $delivery): ?>
                            <?php 
                            $is_overdue = $delivery['scheduled_time'] < date('Y-m-d H:i:s') && $delivery['status'] === 'scheduled';
                            $status_class = 'delivery-' . $delivery['status'] . ($is_overdue ? ' delivery-overdue' : '');
                            ?>
                            <div class="delivery-card <?php echo $status_class; ?> p-3 sm:p-4 bg-gray-50 rounded-lg border border-gray-200">
                                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between space-y-2 sm:space-y-0">
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-2 sm:space-x-3">
                                            <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center">
                                                <i class="fas fa-truck text-orange-600"></i>
                                            </div>
                                            <div>
                                                <h4 class="font-medium text-gray-900 text-sm sm:text-base">
                                                    <?php echo htmlspecialchars($delivery['delivery_company']); ?>
                                                </h4>
                                                <p class="text-xs sm:text-sm text-gray-600">
                                                    <?php echo htmlspecialchars($delivery['delivery_id']); ?>
                                                    <?php if ($delivery['reference_number']): ?>
                                                        • Ref: <?php echo htmlspecialchars($delivery['reference_number']); ?>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-2 sm:mt-3 flex flex-wrap gap-2">
                                            <span class="status-badge <?php 
                                                echo $delivery['status'] === 'scheduled' ? 'bg-blue-100 text-blue-800' :
                                                    ($delivery['status'] === 'arrived' ? 'bg-yellow-100 text-yellow-800' :
                                                     ($delivery['status'] === 'in_progress' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800')); ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $delivery['status'])); ?>
                                            </span>
                                            
                                            <span class="status-badge bg-purple-100 text-purple-800">
                                                <?php echo ucfirst($delivery['delivery_type']); ?>
                                            </span>
                                            
                                            <?php if ($is_overdue): ?>
                                                <span class="status-badge bg-red-100 text-red-800">
                                                    <i class="fas fa-exclamation-triangle mr-1"></i>Overdue
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="mt-2 text-xs sm:text-sm text-gray-500">
                                            <div class="flex flex-wrap gap-4">
                                                <span>To: <?php echo htmlspecialchars($delivery['receiver_name']); ?></span>
                                                <?php if ($delivery['receiver_department']): ?>
                                                    <span>Dept: <?php echo htmlspecialchars($delivery['receiver_department']); ?></span>
                                                <?php endif; ?>
                                                <span>Scheduled: <?php echo date('M j, g:i A', strtotime($delivery['scheduled_time'])); ?></span>
                                                <?php if ($delivery['license_plate']): ?>
                                                    <span>Vehicle: <?php echo htmlspecialchars($delivery['license_plate']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($delivery['package_description']): ?>
                                                <div class="mt-1">Items: <?php echo htmlspecialchars($delivery['package_description']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="flex space-x-2 sm:space-x-3">
                                        <button onclick="openStatusModal('<?php echo $delivery['delivery_id']; ?>', '<?php echo $delivery['status']; ?>')" 
                                                class="text-blue-600 hover:text-blue-800 text-xs sm:text-sm">
                                            <i class="fas fa-edit mr-1"></i>Update
                                        </button>
                                        <button onclick="viewDeliveryDetails('<?php echo $delivery['delivery_id']; ?>')" 
                                                class="text-green-600 hover:text-green-800 text-xs sm:text-sm">
                                            <i class="fas fa-eye mr-1"></i>Details
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Recent Activity & Quick Stats -->
            <div class="space-y-4 sm:space-y-6">
                <!-- Today's Summary -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                    <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-4">Today's Summary</h3>
                    
                    <div class="space-y-3">
                        <div class="flex justify-between items-center p-3 bg-orange-50 rounded-lg">
                            <span class="text-sm font-medium text-orange-800">Total Deliveries</span>
                            <span class="text-lg font-bold text-orange-600">
                                <?php echo $delivery_stats['scheduled'] + $delivery_stats['arrived'] + $delivery_stats['in_progress'] + $delivery_stats['completed_today']; ?>
                            </span>
                        </div>
                        
                        <div class="flex justify-between items-center p-3 bg-green-50 rounded-lg">
                            <span class="text-sm font-medium text-green-800">Completed</span>
                            <span class="text-lg font-bold text-green-600">
                                <?php echo $delivery_stats['completed_today']; ?>
                            </span>
                        </div>
                        
                        <div class="flex justify-between items-center p-3 bg-yellow-50 rounded-lg">
                            <span class="text-sm font-medium text-yellow-800">Pending</span>
                            <span class="text-lg font-bold text-yellow-600">
                                <?php echo $delivery_stats['scheduled'] + $delivery_stats['arrived'] + $delivery_stats['in_progress']; ?>
                            </span>
                        </div>
                        
                        <?php if ($delivery_stats['overdue'] > 0): ?>
                            <div class="flex justify-between items-center p-3 bg-red-50 rounded-lg">
                                <span class="text-sm font-medium text-red-800">Overdue</span>
                                <span class="text-lg font-bold text-red-600">
                                    <?php echo $delivery_stats['overdue']; ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                    <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-4">Quick Actions</h3>
                    
                    <div class="space-y-3">
                        <button onclick="openScheduleModal()" class="w-full bg-orange-600 hover:bg-orange-700 text-white px-4 py-3 rounded-lg font-medium transition-colors">
                            <i class="fas fa-plus mr-2"></i>Schedule Delivery
                        </button>
                        <button onclick="markAllArrived()" class="w-full bg-yellow-600 hover:bg-yellow-700 text-white px-4 py-3 rounded-lg font-medium transition-colors">
                            <i class="fas fa-truck mr-2"></i>Mark All Arrived
                        </button>
                        <button onclick="completeReadyDeliveries()" class="w-full bg-green-600 hover:bg-green-700 text-white px-4 py-3 rounded-lg font-medium transition-colors">
                            <i class="fas fa-check mr-2"></i>Complete Ready
                        </button>
                        <a href="delivery-reports.php?location_id=<?php echo $selected_location_id; ?>" class="block w-full bg-purple-600 hover:bg-purple-700 text-white px-4 py-3 rounded-lg font-medium transition-colors text-center">
                            <i class="fas fa-chart-bar mr-2"></i>View Reports
                        </a>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                    <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-4">Recent Activity</h3>
                    
                    <div class="space-y-3 max-h-64 overflow-y-auto">
                        <?php if (empty($recent_deliveries)): ?>
                            <p class="text-sm text-gray-500 text-center py-4">No recent deliveries</p>
                        <?php else: ?>
                            <?php foreach (array_slice($recent_deliveries, 0, 10) as $delivery): ?>
                                <div class="flex items-center space-x-3 p-2 hover:bg-gray-50 rounded">
                                    <div class="w-2 h-2 rounded-full <?php 
                                        echo $delivery['status'] === 'completed' ? 'bg-green-500' :
                                            ($delivery['status'] === 'in_progress' ? 'bg-yellow-500' :
                                             ($delivery['status'] === 'arrived' ? 'bg-blue-500' : 'bg-gray-500')); ?>"></div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-xs font-medium text-gray-900 truncate">
                                            <?php echo htmlspecialchars($delivery['delivery_company']); ?>
                                        </p>
                                        <p class="text-xs text-gray-500">
                                            <?php echo ucfirst(str_replace('_', ' ', $delivery['status'])); ?>
                                            • <?php echo timeAgo($delivery['updated_at']); ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Schedule Delivery Modal -->
    <div id="scheduleModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-xl max-w-2xl w-full max-h-90vh overflow-y-auto">
                <div class="p-4 sm:p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold text-gray-900">Schedule New Delivery</h3>
                        <button onclick="closeScheduleModal()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    
                    <form method="POST" action="?action=create&location_id=<?php echo $selected_location_id; ?>" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="delivery_type" class="block text-sm font-medium text-gray-700">Delivery Type *</label>
                                <select id="delivery_type" name="delivery_type" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                                    <option value="delivery">Delivery</option>
                                    <option value="pickup">Pickup</option>
                                    <option value="service">Service</option>
                                    <option value="maintenance">Maintenance</option>
                                </select>
                            </div>
                            
                            <div>
                                <label for="delivery_company" class="block text-sm font-medium text-gray-700">Company *</label>
                                <input type="text" id="delivery_company" name="delivery_company" required 
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500" 
                                       placeholder="Delivery company name">
                            </div>
                            
                            <div>
                                <label for="reference_number" class="block text-sm font-medium text-gray-700">Reference Number</label>
                                <input type="text" id="reference_number" name="reference_number" 
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500" 
                                       placeholder="Tracking/Reference number">
                            </div>
                            
                            <div>
                                <label for="scheduled_time" class="block text-sm font-medium text-gray-700">Scheduled Time *</label>
                                <input type="datetime-local" id="scheduled_time" name="scheduled_time" required 
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                            </div>
                        </div>
                        
                        <!-- Sender Information -->
                        <div class="border-t border-gray-200 pt-4">
                            <h4 class="text-base font-medium text-gray-900 mb-3">Sender Information</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="sender_name" class="block text-sm font-medium text-gray-700">Sender Name</label>
                                    <input type="text" id="sender_name" name="sender_name" 
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500" 
                                           placeholder="Sender's name">
                                </div>
                                
                                <div>
                                    <label for="sender_phone" class="block text-sm font-medium text-gray-700">Sender Phone</label>
                                    <input type="tel" id="sender_phone" name="sender_phone" 
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500" 
                                           placeholder="+254700000000">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Receiver Information -->
                        <div class="border-t border-gray-200 pt-4">
                            <h4 class="text-base font-medium text-gray-900 mb-3">Receiver Information</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="receiver_name" class="block text-sm font-medium text-gray-700">Receiver Name *</label>
                                    <input type="text" id="receiver_name" name="receiver_name" required 
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500" 
                                           placeholder="Receiver's name">
                                </div>
                                
                                <div>
                                    <label for="receiver_phone" class="block text-sm font-medium text-gray-700">Receiver Phone *</label>
                                    <input type="tel" id="receiver_phone" name="receiver_phone" required 
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500" 
                                           placeholder="+254700000000">
                                </div>
                                
                                <div>
                                    <label for="receiver_department" class="block text-sm font-medium text-gray-700">Department</label>
                                    <select id="receiver_department" name="receiver_department" 
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                                        <option value="">Select department</option>
                                        <?php foreach ($departments as $dept): ?>
                                            <option value="<?php echo htmlspecialchars($dept['department_name']); ?>">
                                                <?php echo htmlspecialchars($dept['department_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div>
                                    <label for="vehicle_id" class="block text-sm font-medium text-gray-700">Assign Vehicle</label>
                                    <select id="vehicle_id" name="vehicle_id" 
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                                        <option value="">No vehicle assigned</option>
                                        <?php foreach ($delivery_vehicles as $vehicle): ?>
                                            <option value="<?php echo $vehicle['vehicle_id']; ?>">
                                                <?php echo htmlspecialchars($vehicle['license_plate'] . ' - ' . $vehicle['make'] . ' ' . $vehicle['model']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Package Details -->
                        <div class="border-t border-gray-200 pt-4">
                            <h4 class="text-base font-medium text-gray-900 mb-3">Package Details</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="package_count" class="block text-sm font-medium text-gray-700">Package Count</label>
                                    <input type="number" id="package_count" name="package_count" min="1" value="1" 
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                                </div>
                                
                                <div>
                                    <label for="package_description" class="block text-sm font-medium text-gray-700">Description</label>
                                    <input type="text" id="package_description" name="package_description" 
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500" 
                                           placeholder="Package description">
                                </div>
                                
                                <div class="md:col-span-2">
                                    <label for="special_instructions" class="block text-sm font-medium text-gray-700">Special Instructions</label>
                                    <textarea id="special_instructions" name="special_instructions" rows="3" 
                                              class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500" 
                                              placeholder="Any special handling instructions..."></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex justify-end space-x-4 pt-4">
                            <button type="button" onclick="closeScheduleModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors">
                                Cancel
                            </button>
                            <button type="submit" class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg font-medium transition-colors">
                                <i class="fas fa-calendar-plus mr-2"></i>Schedule Delivery
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Status Update Modal -->
    <div id="statusModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-xl max-w-md w-full">
                <div class="p-4 sm:p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold text-gray-900">Update Delivery Status</h3>
                        <button onclick="closeStatusModal()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    
                    <form method="POST" action="?action=update_status&location_id=<?php echo $selected_location_id; ?>" class="space-y-4">
                        <input type="hidden" id="status_delivery_id" name="delivery_id">
                        
                        <div>
                            <label for="new_status" class="block text-sm font-medium text-gray-700">New Status</label>
                            <select id="new_status" name="new_status" required 
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                                <option value="scheduled">Scheduled</option>
                                <option value="arrived">Arrived</option>
                                <option value="in_progress">In Progress</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        
                        <div>
                            <label for="status_notes" class="block text-sm font-medium text-gray-700">Notes</label>
                            <textarea id="status_notes" name="notes" rows="3" 
                                      class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500" 
                                      placeholder="Additional notes about status change..."></textarea>
                        </div>
                        
                        <div class="flex justify-end space-x-4">
                            <button type="button" onclick="closeStatusModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors">
                                Cancel
                            </button>
                            <button type="submit" class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg font-medium transition-colors">
                                <i class="fas fa-save mr-2"></i>Update Status
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Location change handler
        function changeLocation() {
            const locationId = document.getElementById('location_selector').value;
            window.location.href = `delivery-tracking.php?location_id=${locationId}`;
        }

        // Modal functions
        function openScheduleModal() {
            document.getElementById('scheduleModal').classList.remove('hidden');
            // Set default scheduled time to current time + 1 hour
            const now = new Date();
            now.setHours(now.getHours() + 1);
            document.getElementById('scheduled_time').value = now.toISOString().slice(0, 16);
        }

        function closeScheduleModal() {
            document.getElementById('scheduleModal').classList.add('hidden');
        }

        function openStatusModal(deliveryId, currentStatus) {
            document.getElementById('status_delivery_id').value = deliveryId;
            document.getElementById('new_status').value = currentStatus;
            document.getElementById('statusModal').classList.remove('hidden');
        }

        function closeStatusModal() {
            document.getElementById('statusModal').classList.add('hidden');
        }

        // Bulk operations
        function markAllArrived() {
            if (confirm('Mark all scheduled deliveries as arrived?')) {
                // Implementation for bulk update
                alert('Feature coming soon!');
            }
        }

        function completeReadyDeliveries() {
            if (confirm('Mark all in-progress deliveries as completed?')) {
                // Implementation for bulk complete
                alert('Feature coming soon!');
            }
        }

        function bulkUpdateStatus() {
            alert('Bulk update feature coming soon!');
        }

        function viewDeliveryDetails(deliveryId) {
            // Implementation for viewing delivery details
            alert('Delivery details: ' + deliveryId);
        }

        function refreshDeliveries() {
            window.location.reload();
        }

        // Real-time updates
        function startRealTimeUpdates() {
            setInterval(updateDeliveryData, 30000); // Update every 30 seconds
        }

        function updateDeliveryData() {
            fetch(`api-realtime.php?endpoint=delivery_tracking&location_id=<?php echo $selected_location_id; ?>`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateStatistics(data.data.stats);
                    }
                })
                .catch(error => console.error('Error updating delivery data:', error));
        }

        function updateStatistics(stats) {
            // Update stat cards with new data
            const statElements = [
                { selector: '.bg-blue-100 + div .text-2xl', value: stats.scheduled },
                { selector: '.bg-yellow-100 + div .text-2xl', value: stats.arrived },
                { selector: '.bg-green-100 + div .text-2xl', value: stats.in_progress },
                { selector: '.bg-gray-100 + div .text-2xl', value: stats.completed_today }
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

        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            startRealTimeUpdates();

            // Phone number formatting
            const phoneInputs = document.querySelectorAll('input[type="tel"]');
            phoneInputs.forEach(input => {
                input.addEventListener('input', function() {
                    this.value = this.value.replace(/[^\d+\s-()]/g, '');
                });
            });

            // Close modals on background click
            document.getElementById('scheduleModal').addEventListener('click', function(e) {
                if (e.target === this) {
                    closeScheduleModal();
                }
            });

            document.getElementById('statusModal').addEventListener('click', function(e) {
                if (e.target === this) {
                    closeStatusModal();
                }
            });

            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeScheduleModal();
                    closeStatusModal();
                } else if (e.ctrlKey && e.key === 'n') {
                    e.preventDefault();
                    openScheduleModal();
                }
            });
        });
    </script>
</body>
</html>