<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);
$settings = getSettings($db);

$action = $_GET['action'] ?? 'list';

// Handle AJAX quick actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['quick_action'])) {
    header('Content-Type: application/json');
    
    $vehicle_id = sanitizeInput($_POST['vehicle_id'] ?? '');
    $action_type = sanitizeInput($_POST['action_type'] ?? '');
    $location_id = intval($_POST['location_id'] ?? 1);
    
    if (empty($vehicle_id) || empty($action_type)) {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        exit;
    }
    
    // Verify vehicle exists
    $stmt = $db->prepare("SELECT vehicle_id, license_plate, status FROM vehicles WHERE vehicle_id = ?");
    $stmt->execute([$vehicle_id]);
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        echo json_encode(['success' => false, 'message' => 'Vehicle not found']);
        exit;
    }
    
    if ($vehicle['status'] !== 'active') {
        echo json_encode(['success' => false, 'message' => 'Vehicle is not active and cannot be processed']);
        exit;
    }
    
    // Check current status
    $stmt = $db->prepare("SELECT log_type FROM vehicle_logs WHERE vehicle_id = ? ORDER BY log_timestamp DESC LIMIT 1");
    $stmt->execute([$vehicle_id]);
    $last_log = $stmt->fetch();
    $current_status = $last_log ? $last_log['log_type'] : '';
    
    // Validate action based on current status
    if ($action_type === 'check_in' && $current_status === 'check_in') {
        echo json_encode(['success' => false, 'message' => 'Vehicle is already checked in']);
        exit;
    }
    
    if ($action_type === 'check_out' && $current_status === 'check_out') {
        echo json_encode(['success' => false, 'message' => 'Vehicle is already checked out']);
        exit;
    }
    
    try {
        // Insert vehicle log
        $stmt = $db->prepare("INSERT INTO vehicle_logs (vehicle_id, log_type, operator_id, location_id, log_timestamp, entry_purpose) VALUES (?, ?, ?, ?, NOW(), ?)");
        $purpose = $action_type === 'check_in' ? 'Quick check-in' : 'Quick check-out';
        
        if ($stmt->execute([$vehicle_id, $action_type, $session['operator_id'], $location_id, $purpose])) {
            // Log activity
            $action_description = ucfirst(str_replace('_', ' ', $action_type)) . " for vehicle: " . $vehicle['license_plate'];
            logActivity($db, $session['operator_id'], 'vehicle_' . $action_type, $action_description, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
            
            $status_text = $action_type === 'check_in' ? 'checked in' : 'checked out';
            echo json_encode(['success' => true, 'message' => "Vehicle {$vehicle['license_plate']} has been $status_text successfully"]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to record vehicle log']);
        }
    } catch (Exception $e) {
        error_log("Quick vehicle action error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An error occurred while processing the request']);
    }
    
    exit;
}

// Handle vehicle registration
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $action == 'register') {
    $license_plate = strtoupper(sanitizeInput($_POST['license_plate']));
    $vehicle_type_id = intval($_POST['vehicle_type_id']);
    $make = sanitizeInput($_POST['make']);
    $model = sanitizeInput($_POST['model']);
    $year = intval($_POST['year']);
    $color = sanitizeInput($_POST['color']);
    $owner_name = sanitizeInput($_POST['owner_name']);
    $owner_phone = sanitizeInput($_POST['owner_phone']);
    $owner_company = sanitizeInput($_POST['owner_company']);
    $driver_name = sanitizeInput($_POST['driver_name']);
    $driver_phone = sanitizeInput($_POST['driver_phone']);
    $driver_license = sanitizeInput($_POST['driver_license']);
    $is_company_vehicle = isset($_POST['is_company_vehicle']) ? 1 : 0;
    $is_delivery_vehicle = isset($_POST['is_delivery_vehicle']) ? 1 : 0;
    
    // Validation
    $errors = [];
    if (empty($license_plate)) $errors[] = 'License plate is required';
    if (empty($make)) $errors[] = 'Vehicle make is required';
    if (empty($model)) $errors[] = 'Vehicle model is required';
    if (empty($owner_name)) $errors[] = 'Owner name is required';
    if (!empty($owner_phone) && !validatePhone($owner_phone)) $errors[] = 'Invalid owner phone format';
    if (!empty($driver_phone) && !validatePhone($driver_phone)) $errors[] = 'Invalid driver phone format';
    
    // Check if license plate already exists
    $stmt = $db->prepare("SELECT vehicle_id FROM vehicles WHERE license_plate = ?");
    $stmt->execute([$license_plate]);
    if ($stmt->fetch()) {
        $errors[] = 'A vehicle with this license plate already exists';
    }
    
    if (empty($errors)) {
        $vehicle_id = generateUniqueId('VEH');
        $qr_code = generateQRCode($vehicle_id . $license_plate);
        
        $stmt = $db->prepare("INSERT INTO vehicles (vehicle_id, license_plate, vehicle_type_id, make, model, year, color, owner_name, owner_phone, owner_company, driver_name, driver_phone, driver_license, qr_code, is_company_vehicle, is_delivery_vehicle) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        if ($stmt->execute([$vehicle_id, $license_plate, $vehicle_type_id, $make, $model, $year, $color, $owner_name, $owner_phone, $owner_company, $driver_name, $driver_phone, $driver_license, $qr_code, $is_company_vehicle, $is_delivery_vehicle])) {
            logActivity($db, $session['operator_id'], 'vehicle_registration', "Registered new vehicle: $license_plate", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
            setMessage("Vehicle registered successfully! Vehicle ID: $vehicle_id", 'success');
            header('Location: manage-vehicles.php?action=view&id=' . $vehicle_id);
            exit;
        } else {
            $errors[] = 'Failed to register vehicle';
        }
    }
}

// Handle vehicle update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $action == 'update') {
    $vehicle_id = sanitizeInput($_POST['vehicle_id']);
    $license_plate = strtoupper(sanitizeInput($_POST['license_plate']));
    $vehicle_type_id = intval($_POST['vehicle_type_id']);
    $make = sanitizeInput($_POST['make']);
    $model = sanitizeInput($_POST['model']);
    $year = intval($_POST['year']);
    $color = sanitizeInput($_POST['color']);
    $owner_name = sanitizeInput($_POST['owner_name']);
    $owner_phone = sanitizeInput($_POST['owner_phone']);
    $owner_company = sanitizeInput($_POST['owner_company']);
    $driver_name = sanitizeInput($_POST['driver_name']);
    $driver_phone = sanitizeInput($_POST['driver_phone']);
    $driver_license = sanitizeInput($_POST['driver_license']);
    $is_company_vehicle = isset($_POST['is_company_vehicle']) ? 1 : 0;
    $is_delivery_vehicle = isset($_POST['is_delivery_vehicle']) ? 1 : 0;
    $status = sanitizeInput($_POST['status']);
    
    $stmt = $db->prepare("UPDATE vehicles SET license_plate = ?, vehicle_type_id = ?, make = ?, model = ?, year = ?, color = ?, owner_name = ?, owner_phone = ?, owner_company = ?, driver_name = ?, driver_phone = ?, driver_license = ?, is_company_vehicle = ?, is_delivery_vehicle = ?, status = ? WHERE vehicle_id = ?");
    
    if ($stmt->execute([$license_plate, $vehicle_type_id, $make, $model, $year, $color, $owner_name, $owner_phone, $owner_company, $driver_name, $driver_phone, $driver_license, $is_company_vehicle, $is_delivery_vehicle, $status, $vehicle_id])) {
        logActivity($db, $session['operator_id'], 'vehicle_update', "Updated vehicle: $license_plate", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        setMessage('Vehicle updated successfully', 'success');
    } else {
        setMessage('Failed to update vehicle', 'error');
    }
    
    header('Location: manage-vehicles.php?action=view&id=' . $vehicle_id);
    exit;
}

// Get vehicle data for view/edit
if ($action == 'view' || $action == 'edit') {
    $vehicle_id = $_GET['id'] ?? '';
    $stmt = $db->prepare("SELECT v.*, vt.type_name FROM vehicles v LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id WHERE v.vehicle_id = ?");
    $stmt->execute([$vehicle_id]);
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        setMessage('Vehicle not found', 'error');
        header('Location: manage-vehicles.php');
        exit;
    }
    
    // Get vehicle logs
    $stmt = $db->prepare("SELECT vl.*, go.operator_name, l.location_name FROM vehicle_logs vl JOIN gate_operators go ON vl.operator_id = go.id LEFT JOIN locations l ON vl.location_id = l.id WHERE vl.vehicle_id = ? ORDER BY vl.log_timestamp DESC LIMIT 20");
    $stmt->execute([$vehicle_id]);
    $vehicle_logs = $stmt->fetchAll();
    
    // Get current status
    $stmt = $db->prepare("SELECT log_type FROM vehicle_logs WHERE vehicle_id = ? ORDER BY log_timestamp DESC LIMIT 1");
    $stmt->execute([$vehicle_id]);
    $last_log = $stmt->fetch();
    $current_status = $last_log ? ($last_log['log_type'] === 'check_in' ? 'Inside' : 'Outside') : 'Never Visited';
}

// Get vehicles list
if ($action == 'list') {
    $search = $_GET['search'] ?? '';
    $status_filter = $_GET['status'] ?? '';
    $type_filter = $_GET['type'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = 20;
    $offset = ($page - 1) * $per_page;
    
    $where_conditions = ['1=1'];
    $params = [];
    
    if (!empty($search)) {
        $where_conditions[] = "(license_plate LIKE ? OR make LIKE ? OR model LIKE ? OR owner_name LIKE ? OR owner_company LIKE ? OR vehicle_id LIKE ?)";
        $search_param = "%$search%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param, $search_param]);
    }
    
    if (!empty($status_filter)) {
        $where_conditions[] = "v.status = ?";
        $params[] = $status_filter;
    }
    
    if (!empty($type_filter)) {
        $where_conditions[] = "v.vehicle_type_id = ?";
        $params[] = $type_filter;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Get total count
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM vehicles v WHERE $where_clause");
    $stmt->execute($params);
    $total_vehicles = $stmt->fetch()['total'];
    $total_pages = ceil($total_vehicles / $per_page);
    
    // Get vehicles
    $stmt = $db->prepare("SELECT v.*, vt.type_name,
                         (SELECT log_type FROM vehicle_logs vl WHERE vl.vehicle_id = v.vehicle_id ORDER BY log_timestamp DESC LIMIT 1) as last_action,
                         (SELECT log_timestamp FROM vehicle_logs vl WHERE vl.vehicle_id = v.vehicle_id ORDER BY log_timestamp DESC LIMIT 1) as last_activity
                         FROM vehicles v 
                         LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
                         WHERE $where_clause 
                         ORDER BY v.created_at DESC 
                         LIMIT $per_page OFFSET $offset");
    $stmt->execute($params);
    $vehicles = $stmt->fetchAll();
}

// Get vehicle types for dropdown
$stmt = $db->query("SELECT * FROM vehicle_types WHERE is_active = 1 ORDER BY type_name");
$vehicle_types = $stmt->fetchAll();

// Get locations for quick actions
$stmt = $db->query("SELECT * FROM locations WHERE is_active = 1 ORDER BY location_name");
$locations = $stmt->fetchAll();

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management System'); ?> - Vehicle Management</title>
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
            .mobile-overflow { overflow-x: auto !important; }
        }
        .vehicle-card { transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .vehicle-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1); }
        .qr-container { display: flex; justify-content: center; align-items: center; min-height: 200px; }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Fixed Navigation Header -->
    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-2 sm:px-4 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <a href="dashboard.php" class="text-gray-600 hover:text-gray-900">
                        <i class="fas fa-arrow-left"></i>
                    </a>
                    <div class="h-8 w-8 sm:h-10 sm:w-10 bg-blue-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-car text-white text-sm sm:text-base"></i>
                    </div>
                    <div>
                        <h1 class="text-lg sm:text-xl font-semibold text-gray-900">Vehicle Management</h1>
                        <p class="text-xs sm:text-sm text-gray-500 hidden sm:block">Register and manage vehicles</p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <a href="vehicle-scanner.php" class="text-green-600 hover:text-green-800 text-sm sm:text-base">
                        <i class="fas fa-qrcode"></i>
                        <span class="ml-1 hidden sm:inline">Scanner</span>
                    </a>
                    <a href="vehicle-dashboard.php" class="text-purple-600 hover:text-purple-800 text-sm sm:text-base">
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

        <?php if ($action == 'list'): ?>
            <!-- Search and Filter Bar -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-3 sm:p-6 mb-4 sm:mb-6">
                <div class="flex flex-col space-y-4">
                    <form method="GET" class="flex flex-col space-y-3 sm:flex-row sm:space-y-0 sm:space-x-4">
                        <input type="hidden" name="action" value="list">
                        <div class="relative flex-1">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-search text-gray-400 text-sm"></i>
                            </div>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                   class="pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 w-full text-sm sm:text-base" 
                                   placeholder="Search vehicles...">
                        </div>
                        <select name="status" class="px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 w-full sm:w-auto text-sm sm:text-base">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="blocked" <?php echo $status_filter === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
                            <option value="maintenance" <?php echo $status_filter === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                        </select>
                        <select name="type" class="px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 w-full sm:w-auto text-sm sm:text-base">
                            <option value="">All Types</option>
                            <?php foreach ($vehicle_types as $type): ?>
                                <option value="<?php echo $type['id']; ?>" <?php echo $type_filter == $type['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type['type_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium transition-colors w-full sm:w-auto text-sm sm:text-base">
                            <i class="fas fa-search mr-2"></i>Search
                        </button>
                    </form>
                    
                    <div class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-4">
                        <a href="manage-vehicles.php?action=register" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition-colors inline-flex items-center justify-center text-sm sm:text-base">
                            <i class="fas fa-plus mr-2"></i>Register Vehicle
                        </a>
                        <a href="vehicle-bulk-import.php" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg font-medium transition-colors inline-flex items-center justify-center text-sm sm:text-base">
                            <i class="fas fa-upload mr-2"></i>Bulk Import
                        </a>
                    </div>
                </div>
            </div>

            <!-- Vehicles Table -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-3 sm:px-6 py-3 sm:py-4 border-b border-gray-200">
                    <h3 class="text-base sm:text-lg font-semibold text-gray-900">
                        Vehicles List (<?php echo number_format($total_vehicles); ?> total)
                    </h3>
                </div>
                
                <?php if (empty($vehicles)): ?>
                    <div class="px-3 sm:px-6 py-8 sm:py-12 text-center text-gray-500">
                        <i class="fas fa-car text-3xl sm:text-4xl mb-4 text-gray-300"></i>
                        <p class="text-base sm:text-lg mb-2">No vehicles found</p>
                        <p class="text-sm sm:text-base">Start by registering your first vehicle</p>
                        <a href="manage-vehicles.php?action=register" class="mt-4 inline-flex items-center bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition-colors text-sm sm:text-base">
                            <i class="fas fa-plus mr-2"></i>Register Vehicle
                        </a>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto mobile-overflow">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-2 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vehicle</th>
                                    <th class="px-2 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider mobile-hidden">Owner</th>
                                    <th class="px-2 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider mobile-hidden">Type</th>
                                    <th class="px-2 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-2 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-2 sm:px-6 py-2 sm:py-4">
                                            <div>
                                                <div class="text-xs sm:text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($vehicle['license_plate']); ?>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    <?php echo htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']); ?>
                                                    <?php if ($vehicle['year']): ?>
                                                        (<?php echo $vehicle['year']; ?>)
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-xs text-gray-500 sm:hidden">
                                                    ID: <?php echo htmlspecialchars($vehicle['vehicle_id']); ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-2 sm:px-6 py-2 sm:py-4 mobile-hidden">
                                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($vehicle['owner_name']); ?></div>
                                            <?php if ($vehicle['owner_company']): ?>
                                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($vehicle['owner_company']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-2 sm:px-6 py-2 sm:py-4 mobile-hidden">
                                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-800">
                                                <?php echo htmlspecialchars($vehicle['type_name'] ?? 'Unknown'); ?>
                                            </span>
                                            <?php if ($vehicle['is_delivery_vehicle']): ?>
                                                <div class="mt-1">
                                                    <span class="px-2 py-1 text-xs font-medium rounded-full bg-orange-100 text-orange-800">
                                                        Delivery
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-2 sm:px-6 py-2 sm:py-4">
                                            <span class="px-2 py-1 text-xs font-medium rounded-full <?php 
                                                echo $vehicle['status'] === 'active' ? 'bg-green-100 text-green-800' : 
                                                    ($vehicle['status'] === 'blocked' ? 'bg-red-100 text-red-800' : 
                                                     ($vehicle['status'] === 'maintenance' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800')); ?>">
                                                <?php echo ucfirst($vehicle['status']); ?>
                                            </span>
                                            <?php if ($vehicle['last_action']): ?>
                                                <div class="mt-1">
                                                    <span class="px-2 py-1 text-xs font-medium rounded-full <?php 
                                                        echo $vehicle['last_action'] === 'check_in' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800'; ?>">
                                                        <?php echo $vehicle['last_action'] === 'check_in' ? 'Inside' : 'Outside'; ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-2 sm:px-6 py-2 sm:py-4">
                                            <div class="flex flex-col sm:flex-row space-y-1 sm:space-y-0 sm:space-x-2">
                                                <a href="manage-vehicles.php?action=view&id=<?php echo $vehicle['vehicle_id']; ?>" 
                                                   class="text-blue-600 hover:text-blue-900 text-xs sm:text-sm">
                                                    <i class="fas fa-eye mr-1"></i>View
                                                </a>
                                                <a href="manage-vehicles.php?action=edit&id=<?php echo $vehicle['vehicle_id']; ?>" 
                                                   class="text-green-600 hover:text-green-900 text-xs sm:text-sm">
                                                    <i class="fas fa-edit mr-1"></i>Edit
                                                </a>
                                                <a href="vehicle-scanner.php?prefill=<?php echo $vehicle['vehicle_id']; ?>" 
                                                   class="text-purple-600 hover:text-purple-900 text-xs sm:text-sm">
                                                    <i class="fas fa-qrcode mr-1"></i>Scan
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="px-3 sm:px-6 py-3 sm:py-4 border-t border-gray-200">
                            <div class="flex flex-col sm:flex-row items-center justify-between space-y-3 sm:space-y-0">
                                <div class="text-xs sm:text-sm text-gray-700">
                                    Showing <?php echo (($page - 1) * $per_page) + 1; ?> to <?php echo min($page * $per_page, $total_vehicles); ?> of <?php echo $total_vehicles; ?> results
                                </div>
                                <div class="flex space-x-1 sm:space-x-2">
                                    <?php if ($page > 1): ?>
                                        <a href="?action=list&page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&type=<?php echo urlencode($type_filter); ?>" 
                                           class="px-2 sm:px-3 py-1 sm:py-2 border border-gray-300 rounded-lg text-xs sm:text-sm text-gray-600 hover:bg-gray-50">Previous</a>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                        <a href="?action=list&page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&type=<?php echo urlencode($type_filter); ?>" 
                                           class="px-2 sm:px-3 py-1 sm:py-2 border rounded-lg text-xs sm:text-sm <?php echo $i === $page ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 text-gray-600 hover:bg-gray-50'; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $total_pages): ?>
                                        <a href="?action=list&page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&type=<?php echo urlencode($type_filter); ?>" 
                                           class="px-2 sm:px-3 py-1 sm:py-2 border border-gray-300 rounded-lg text-xs sm:text-sm text-gray-600 hover:bg-gray-50">Next</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        <?php elseif ($action == 'register'): ?>
            <!-- Registration Form -->
            <div class="max-w-4xl mx-auto">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                    <div class="mb-4 sm:mb-6">
                        <h3 class="text-lg font-semibold text-gray-900">Register New Vehicle</h3>
                        <p class="text-sm text-gray-600">Fill in the vehicle's information to register it in the system</p>
                    </div>
                    
                    <?php if (isset($errors) && !empty($errors)): ?>
                        <div class="mb-4 sm:mb-6 p-3 sm:p-4 bg-red-50 border border-red-200 rounded-lg">
                            <div class="flex items-center mb-2">
                                <i class="fas fa-exclamation-circle text-red-600 mr-2"></i>
                                <span class="font-medium text-red-700 text-sm sm:text-base">Please correct the following errors:</span>
                            </div>
                            <ul class="list-disc list-inside text-red-600 text-sm">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" class="space-y-6">
                        <!-- Vehicle Information -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                            <div>
                                <label for="license_plate" class="block text-sm font-medium text-gray-700">License Plate *</label>
                                <input type="text" id="license_plate" name="license_plate" required 
                                       value="<?php echo htmlspecialchars($_POST['license_plate'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base uppercase" 
                                       placeholder="ABC 123D" style="text-transform: uppercase;">
                            </div>
                            
                            <div>
                                <label for="vehicle_type_id" class="block text-sm font-medium text-gray-700">Vehicle Type *</label>
                                <select id="vehicle_type_id" name="vehicle_type_id" required 
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base">
                                    <option value="">Select vehicle type</option>
                                    <?php foreach ($vehicle_types as $type): ?>
                                        <option value="<?php echo $type['id']; ?>" <?php echo ($_POST['vehicle_type_id'] ?? '') == $type['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($type['type_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label for="make" class="block text-sm font-medium text-gray-700">Make *</label>
                                <input type="text" id="make" name="make" required 
                                       value="<?php echo htmlspecialchars($_POST['make'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" 
                                       placeholder="Toyota">
                            </div>
                            
                            <div>
                                <label for="model" class="block text-sm font-medium text-gray-700">Model *</label>
                                <input type="text" id="model" name="model" required 
                                       value="<?php echo htmlspecialchars($_POST['model'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" 
                                       placeholder="Camry">
                            </div>
                            
                            <div>
                                <label for="year" class="block text-sm font-medium text-gray-700">Year</label>
                                <input type="number" id="year" name="year" min="1900" max="<?php echo date('Y') + 1; ?>"
                                       value="<?php echo htmlspecialchars($_POST['year'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" 
                                       placeholder="<?php echo date('Y'); ?>">
                            </div>
                            
                            <div>
                                <label for="color" class="block text-sm font-medium text-gray-700">Color</label>
                                <input type="text" id="color" name="color" 
                                       value="<?php echo htmlspecialchars($_POST['color'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" 
                                       placeholder="White">
                            </div>
                        </div>
                        
                        <!-- Owner Information -->
                        <div class="border-t border-gray-200 pt-6">
                            <h4 class="text-base font-medium text-gray-900 mb-4">Owner Information</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                                <div>
                                    <label for="owner_name" class="block text-sm font-medium text-gray-700">Owner Name *</label>
                                    <input type="text" id="owner_name" name="owner_name" required 
                                           value="<?php echo htmlspecialchars($_POST['owner_name'] ?? ''); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" 
                                           placeholder="Full name">
                                </div>
                                
                                <div>
                                    <label for="owner_phone" class="block text-sm font-medium text-gray-700">Owner Phone</label>
                                    <input type="tel" id="owner_phone" name="owner_phone" 
                                           value="<?php echo htmlspecialchars($_POST['owner_phone'] ?? ''); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" 
                                           placeholder="+254700000000">
                                </div>
                                
                                <div class="md:col-span-2">
                                    <label for="owner_company" class="block text-sm font-medium text-gray-700">Company</label>
                                    <input type="text" id="owner_company" name="owner_company" 
                                           value="<?php echo htmlspecialchars($_POST['owner_company'] ?? ''); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" 
                                           placeholder="Company or organization">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Driver Information -->
                        <div class="border-t border-gray-200 pt-6">
                            <h4 class="text-base font-medium text-gray-900 mb-4">Driver Information (Optional)</h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 sm:gap-6">
                                <div>
                                    <label for="driver_name" class="block text-sm font-medium text-gray-700">Driver Name</label>
                                    <input type="text" id="driver_name" name="driver_name" 
                                           value="<?php echo htmlspecialchars($_POST['driver_name'] ?? ''); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" 
                                           placeholder="Driver's full name">
                                </div>
                                
                                <div>
                                    <label for="driver_phone" class="block text-sm font-medium text-gray-700">Driver Phone</label>
                                    <input type="tel" id="driver_phone" name="driver_phone" 
                                           value="<?php echo htmlspecialchars($_POST['driver_phone'] ?? ''); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" 
                                           placeholder="+254700000000">
                                </div>
                                
                                <div>
                                    <label for="driver_license" class="block text-sm font-medium text-gray-700">License Number</label>
                                    <input type="text" id="driver_license" name="driver_license" 
                                           value="<?php echo htmlspecialchars($_POST['driver_license'] ?? ''); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" 
                                           placeholder="License number">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Vehicle Classification -->
                        <div class="border-t border-gray-200 pt-6">
                            <h4 class="text-base font-medium text-gray-900 mb-4">Vehicle Classification</h4>
                            <div class="space-y-3">
                                <div class="flex items-center">
                                    <input type="checkbox" id="is_company_vehicle" name="is_company_vehicle" 
                                           <?php echo isset($_POST['is_company_vehicle']) ? 'checked' : ''; ?>
                                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                    <label for="is_company_vehicle" class="ml-2 block text-sm text-gray-900">
                                        Company Vehicle
                                    </label>
                                </div>
                                
                                <div class="flex items-center">
                                    <input type="checkbox" id="is_delivery_vehicle" name="is_delivery_vehicle" 
                                           <?php echo isset($_POST['is_delivery_vehicle']) ? 'checked' : ''; ?>
                                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                    <label for="is_delivery_vehicle" class="ml-2 block text-sm text-gray-900">
                                        Delivery Vehicle
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex flex-col sm:flex-row justify-end space-y-2 sm:space-y-0 sm:space-x-4">
                            <a href="manage-vehicles.php" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors text-center text-sm sm:text-base">
                                Cancel
                            </a>
                            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition-colors text-sm sm:text-base">
                                <i class="fas fa-plus mr-2"></i>Register Vehicle
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        <?php elseif ($action == 'view' && isset($vehicle)): ?>
            <!-- Vehicle Details View -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-8">
                <div class="lg:col-span-2 space-y-4 sm:space-y-6">
                    <!-- Vehicle Information -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-4 sm:mb-6 space-y-2 sm:space-y-0">
                            <h3 class="text-lg font-semibold text-gray-900">Vehicle Information</h3>
                            <div class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-2 w-full sm:w-auto">
                                <a href="manage-vehicles.php?action=edit&id=<?php echo $vehicle['vehicle_id']; ?>" 
                                   class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg text-sm font-medium transition-colors text-center">
                                    <i class="fas fa-edit mr-1"></i>Edit
                                </a>
                                <a href="vehicle-scanner.php?prefill=<?php echo $vehicle['vehicle_id']; ?>" 
                                   class="bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded-lg text-sm font-medium transition-colors text-center">
                                    <i class="fas fa-qrcode mr-1"></i>Scan
                                </a>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-600">License Plate</label>
                                <p class="text-gray-900 text-sm sm:text-base font-mono"><?php echo htmlspecialchars($vehicle['license_plate']); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600">Vehicle Type</label>
                                <p class="text-gray-900 text-sm sm:text-base"><?php echo htmlspecialchars($vehicle['type_name'] ?? 'Not specified'); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600">Make & Model</label>
                                <p class="text-gray-900 text-sm sm:text-base"><?php echo htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600">Year</label>
                                <p class="text-gray-900 text-sm sm:text-base"><?php echo htmlspecialchars($vehicle['year'] ?: 'Not specified'); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600">Color</label>
                                <p class="text-gray-900 text-sm sm:text-base"><?php echo htmlspecialchars($vehicle['color'] ?: 'Not specified'); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600">Owner</label>
                                <p class="text-gray-900 text-sm sm:text-base"><?php echo htmlspecialchars($vehicle['owner_name']); ?></p>
                                <?php if ($vehicle['owner_company']): ?>
                                    <p class="text-gray-600 text-xs sm:text-sm"><?php echo htmlspecialchars($vehicle['owner_company']); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if ($vehicle['driver_name'] || $vehicle['driver_phone']): ?>
                            <div class="mt-6 pt-6 border-t border-gray-200">
                                <h4 class="text-base font-medium text-gray-900 mb-3">Driver Information</h4>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <?php if ($vehicle['driver_name']): ?>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-600">Driver Name</label>
                                            <p class="text-gray-900 text-sm sm:text-base"><?php echo htmlspecialchars($vehicle['driver_name']); ?></p>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($vehicle['driver_phone']): ?>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-600">Driver Phone</label>
                                            <p class="text-gray-900 text-sm sm:text-base"><?php echo htmlspecialchars($vehicle['driver_phone']); ?></p>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($vehicle['driver_license']): ?>
                                        <div class="sm:col-span-2">
                                            <label class="block text-sm font-medium text-gray-600">Driver License</label>
                                            <p class="text-gray-900 text-sm sm:text-base"><?php echo htmlspecialchars($vehicle['driver_license']); ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Activity History -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4 sm:mb-6">Recent Activity</h3>
                        
                        <?php if (empty($vehicle_logs)): ?>
                            <div class="text-center py-6 sm:py-8 text-gray-500">
                                <i class="fas fa-history text-3xl sm:text-4xl mb-4 text-gray-300"></i>
                                <p class="text-sm sm:text-base">No activity recorded yet</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-3 sm:space-y-4">
                                <?php foreach ($vehicle_logs as $log): ?>
                                    <div class="flex items-center p-3 sm:p-4 bg-gray-50 rounded-lg">
                                        <div class="flex-shrink-0">
                                            <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-full flex items-center justify-center <?php 
                                                echo $log['log_type'] === 'check_in' ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600'; ?>">
                                                <i class="fas <?php echo $log['log_type'] === 'check_in' ? 'fa-sign-in-alt' : 'fa-sign-out-alt'; ?> text-sm"></i>
                                            </div>
                                        </div>
                                        <div class="ml-3 sm:ml-4 flex-1">
                                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                                                <div class="flex-1">
                                                    <p class="text-sm font-medium text-gray-900">
                                                        <?php echo ucfirst(str_replace('_', ' ', $log['log_type'])); ?>
                                                        <?php if ($log['location_name']): ?>
                                                            at <?php echo htmlspecialchars($log['location_name']); ?>
                                                        <?php endif; ?>
                                                    </p>
                                                    <p class="text-xs sm:text-sm text-gray-500">
                                                        By: <?php echo htmlspecialchars($log['operator_name']); ?>
                                                        <?php if ($log['entry_purpose']): ?>
                                                            • Purpose: <?php echo htmlspecialchars($log['entry_purpose']); ?>
                                                        <?php endif; ?>
                                                        <?php if ($log['delivery_company']): ?>
                                                            • Company: <?php echo htmlspecialchars($log['delivery_company']); ?>
                                                        <?php endif; ?>
                                                    </p>
                                                </div>
                                                <div class="text-xs sm:text-sm text-gray-500 mt-1 sm:mt-0">
                                                    <?php echo date('M j, g:i A', strtotime($log['log_timestamp'])); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- QR Code & Quick Actions -->
                <div class="space-y-4 sm:space-y-6">
                    <!-- Vehicle Status -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6 text-center">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Current Status</h3>
                        <div class="mb-4">
                            <span class="px-4 py-2 text-lg font-medium rounded-full <?php 
                                echo $current_status === 'Inside' ? 'bg-green-100 text-green-800' : 
                                    ($current_status === 'Outside' ? 'bg-gray-100 text-gray-800' : 'bg-blue-100 text-blue-800'); ?>">
                                <?php echo $current_status; ?>
                            </span>
                        </div>
                        <div class="grid grid-cols-2 gap-3 text-sm">
                            <div class="bg-blue-50 p-3 rounded-lg">
                                <div class="font-medium text-blue-800">Vehicle ID</div>
                                <div class="text-blue-600 font-mono"><?php echo htmlspecialchars($vehicle['vehicle_id']); ?></div>
                            </div>
                            <div class="bg-purple-50 p-3 rounded-lg">
                                <div class="font-medium text-purple-800">Status</div>
                                <div class="text-purple-600"><?php echo ucfirst($vehicle['status']); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- QR Code -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6 text-center">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">QR Code</h3>
                        <div class="bg-gray-100 p-4 rounded-lg mb-4 qr-container">
                            <canvas id="qrcode" width="200" height="200"></canvas>
                        </div>
                        <p class="text-sm text-gray-600 mb-4">Scan this QR code for quick vehicle operations</p>
                        <button onclick="downloadQRCode()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors w-full sm:w-auto">
                            <i class="fas fa-download mr-2"></i>Download QR
                        </button>
                    </div>

                    <!-- Quick Actions -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Quick Actions</h3>
                        <div class="space-y-3">
                            <a href="vehicle-scanner.php?prefill=<?php echo $vehicle['vehicle_id']; ?>" 
                               class="block w-full bg-green-600 hover:bg-green-700 text-white px-4 py-3 rounded-lg text-center font-medium transition-colors">
                                <i class="fas fa-qrcode mr-2"></i>Open Scanner
                            </a>
                            <button onclick="quickVehicleAction('check_in')" id="checkin-btn" 
                                    class="block w-full bg-blue-500 hover:bg-blue-600 text-white px-4 py-3 rounded-lg text-center font-medium transition-colors">
                                <i class="fas fa-sign-in-alt mr-2"></i>Quick Check-in
                            </button>
                            <button onclick="quickVehicleAction('check_out')" id="checkout-btn" 
                                    class="block w-full bg-red-500 hover:bg-red-600 text-white px-4 py-3 rounded-lg text-center font-medium transition-colors">
                                <i class="fas fa-sign-out-alt mr-2"></i>Quick Check-out
                            </button>
                            <a href="delivery-tracking.php?vehicle_id=<?php echo $vehicle['vehicle_id']; ?>" 
                               class="block w-full bg-orange-500 hover:bg-orange-600 text-white px-4 py-3 rounded-lg text-center font-medium transition-colors">
                                <i class="fas fa-truck mr-2"></i>Track Deliveries
                            </a>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($action == 'edit' && isset($vehicle)): ?>
            <!-- Edit Form -->
            <div class="max-w-4xl mx-auto">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                    <div class="mb-4 sm:mb-6">
                        <h3 class="text-lg font-semibold text-gray-900">Edit Vehicle</h3>
                        <p class="text-sm text-gray-600">Update vehicle information</p>
                    </div>
                    
                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="vehicle_id" value="<?php echo htmlspecialchars($vehicle['vehicle_id']); ?>">
                        
                        <!-- Vehicle Information -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                            <div>
                                <label for="license_plate" class="block text-sm font-medium text-gray-700">License Plate *</label>
                                <input type="text" id="license_plate" name="license_plate" required 
                                       value="<?php echo htmlspecialchars($vehicle['license_plate']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base uppercase">
                            </div>
                            
                            <div>
                                <label for="vehicle_type_id" class="block text-sm font-medium text-gray-700">Vehicle Type *</label>
                                <select id="vehicle_type_id" name="vehicle_type_id" required 
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base">
                                    <option value="">Select vehicle type</option>
                                    <?php foreach ($vehicle_types as $type): ?>
                                        <option value="<?php echo $type['id']; ?>" <?php echo $vehicle['vehicle_type_id'] == $type['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($type['type_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label for="make" class="block text-sm font-medium text-gray-700">Make *</label>
                                <input type="text" id="make" name="make" required 
                                       value="<?php echo htmlspecialchars($vehicle['make']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base">
                            </div>
                            
                            <div>
                                <label for="model" class="block text-sm font-medium text-gray-700">Model *</label>
                                <input type="text" id="model" name="model" required 
                                       value="<?php echo htmlspecialchars($vehicle['model']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base">
                            </div>
                            
                            <div>
                                <label for="year" class="block text-sm font-medium text-gray-700">Year</label>
                                <input type="number" id="year" name="year" min="1900" max="<?php echo date('Y') + 1; ?>"
                                       value="<?php echo htmlspecialchars($vehicle['year']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base">
                            </div>
                            
                            <div>
                                <label for="color" class="block text-sm font-medium text-gray-700">Color</label>
                                <input type="text" id="color" name="color" 
                                       value="<?php echo htmlspecialchars($vehicle['color']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base">
                            </div>
                        </div>
                        
                        <!-- Owner Information -->
                        <div class="border-t border-gray-200 pt-6">
                            <h4 class="text-base font-medium text-gray-900 mb-4">Owner Information</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                                <div>
                                    <label for="owner_name" class="block text-sm font-medium text-gray-700">Owner Name *</label>
                                    <input type="text" id="owner_name" name="owner_name" required 
                                           value="<?php echo htmlspecialchars($vehicle['owner_name']); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base">
                                </div>
                                
                                <div>
                                    <label for="owner_phone" class="block text-sm font-medium text-gray-700">Owner Phone</label>
                                    <input type="tel" id="owner_phone" name="owner_phone" 
                                           value="<?php echo htmlspecialchars($vehicle['owner_phone']); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base">
                                </div>
                                
                                <div class="md:col-span-2">
                                    <label for="owner_company" class="block text-sm font-medium text-gray-700">Company</label>
                                    <input type="text" id="owner_company" name="owner_company" 
                                           value="<?php echo htmlspecialchars($vehicle['owner_company']); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Driver Information -->
                        <div class="border-t border-gray-200 pt-6">
                            <h4 class="text-base font-medium text-gray-900 mb-4">Driver Information</h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 sm:gap-6">
                                <div>
                                    <label for="driver_name" class="block text-sm font-medium text-gray-700">Driver Name</label>
                                    <input type="text" id="driver_name" name="driver_name" 
                                           value="<?php echo htmlspecialchars($vehicle['driver_name']); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base">
                                </div>
                                
                                <div>
                                    <label for="driver_phone" class="block text-sm font-medium text-gray-700">Driver Phone</label>
                                    <input type="tel" id="driver_phone" name="driver_phone" 
                                           value="<?php echo htmlspecialchars($vehicle['driver_phone']); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base">
                                </div>
                                
                                <div>
                                    <label for="driver_license" class="block text-sm font-medium text-gray-700">License Number</label>
                                    <input type="text" id="driver_license" name="driver_license" 
                                           value="<?php echo htmlspecialchars($vehicle['driver_license']); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Vehicle Classification & Status -->
                        <div class="border-t border-gray-200 pt-6">
                            <h4 class="text-base font-medium text-gray-900 mb-4">Classification & Status</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                                <div class="space-y-3">
                                    <div class="flex items-center">
                                        <input type="checkbox" id="is_company_vehicle" name="is_company_vehicle" 
                                               <?php echo $vehicle['is_company_vehicle'] ? 'checked' : ''; ?>
                                               class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                        <label for="is_company_vehicle" class="ml-2 block text-sm text-gray-900">
                                            Company Vehicle
                                        </label>
                                    </div>
                                    
                                    <div class="flex items-center">
                                        <input type="checkbox" id="is_delivery_vehicle" name="is_delivery_vehicle" 
                                               <?php echo $vehicle['is_delivery_vehicle'] ? 'checked' : ''; ?>
                                               class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                        <label for="is_delivery_vehicle" class="ml-2 block text-sm text-gray-900">
                                            Delivery Vehicle
                                        </label>
                                    </div>
                                </div>
                                
                                <div>
                                    <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                                    <select id="status" name="status" 
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base">
                                        <option value="active" <?php echo $vehicle['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo $vehicle['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                        <option value="blocked" <?php echo $vehicle['status'] === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
                                        <option value="maintenance" <?php echo $vehicle['status'] === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex flex-col sm:flex-row justify-end space-y-2 sm:space-y-0 sm:space-x-4">
                            <a href="manage-vehicles.php?action=view&id=<?php echo $vehicle['vehicle_id']; ?>" 
                               class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors text-center text-sm sm:text-base">
                                Cancel
                            </a>
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium transition-colors text-sm sm:text-base">
                                <i class="fas fa-save mr-2"></i>Update Vehicle
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- QR Code Library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js"></script>
    
    <script>
        <?php if ($action == 'view' && isset($vehicle)): ?>
        // Generate QR Code
        document.addEventListener('DOMContentLoaded', function() {
            const qrData = '<?php echo htmlspecialchars($vehicle['qr_code']); ?>';
            
            try {
                const canvas = document.getElementById('qrcode');
                if (!canvas) return;
                
                const ctx = canvas.getContext('2d');
                const size = 200;
                canvas.width = size;
                canvas.height = size;
                
                const qr = qrcode(0, 'M');
                qr.addData(qrData);
                qr.make();
                
                const cells = qr.getModuleCount();
                const cellSize = size / cells;
                
                ctx.fillStyle = '#FFFFFF';
                ctx.fillRect(0, 0, size, size);
                
                ctx.fillStyle = '#000000';
                for (let row = 0; row < cells; row++) {
                    for (let col = 0; col < cells; col++) {
                        if (qr.isDark(row, col)) {
                            ctx.fillRect(col * cellSize, row * cellSize, cellSize, cellSize);
                        }
                    }
                }
            } catch (error) {
                console.error('QR Code generation error:', error);
            }
        });

        function downloadQRCode() {
            const canvas = document.getElementById('qrcode');
            if (canvas) {
                const link = document.createElement('a');
                link.download = 'vehicle-qr-<?php echo $vehicle['vehicle_id']; ?>.png';
                link.href = canvas.toDataURL();
                link.click();
            }
        }

        function quickVehicleAction(actionType) {
            const button = document.getElementById(actionType === 'check_in' ? 'checkin-btn' : 'checkout-btn');
            const originalText = button.innerHTML;
            
            button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
            button.disabled = true;
            
            const formData = new FormData();
            formData.append('quick_action', '1');
            formData.append('vehicle_id', '<?php echo $vehicle['vehicle_id']; ?>');
            formData.append('action_type', actionType);
            formData.append('location_id', '1'); // Default location
            
            fetch('manage-vehicles.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    window.location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            })
            .finally(() => {
                button.innerHTML = originalText;
                button.disabled = false;
            });
        }
        <?php endif; ?>

        // Form validation and enhancements
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-uppercase license plate
            const licensePlateInput = document.getElementById('license_plate');
            if (licensePlateInput) {
                licensePlateInput.addEventListener('input', function() {
                    this.value = this.value.toUpperCase();
                });
            }

            // Phone number formatting
            const phoneInputs = document.querySelectorAll('input[type="tel"]');
            phoneInputs.forEach(input => {
                input.addEventListener('input', function() {
                    this.value = this.value.replace(/[^\d+\s-()]/g, '');
                });
            });

            // Form submission loading states
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const button = this.querySelector('button[type="submit"]');
                    if (button) {
                        const originalText = button.innerHTML;
                        button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
                        button.disabled = true;
                        
                        setTimeout(() => {
                            button.innerHTML = originalText;
                            button.disabled = false;
                        }, 10000);
                    }
                });
            });
        });

        // Search functionality enhancement
        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput) {
            let searchTimeout;
            
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    if (this.value.length >= 3 || this.value.length === 0) {
                        this.closest('form').submit();
                    }
                }, 500);
            });
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey || e.metaKey) {
                switch(e.key) {
                    case 'n':
                        e.preventDefault();
                        window.location.href = 'manage-vehicles.php?action=register';
                        break;
                    case 'f':
                        e.preventDefault();
                        if (searchInput) searchInput.focus();
                        break;
                }
            }
        });

        // Mobile enhancements
        if (window.innerWidth <= 768) {
            // Add touch feedback
            document.querySelectorAll('.vehicle-card').forEach(card => {
                card.addEventListener('touchstart', function() {
                    this.style.transform = 'scale(0.98)';
                });
                
                card.addEventListener('touchend', function() {
                    this.style.transform = '';
                });
            });

            // Auto-scroll to active form sections
            const inputs = document.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    setTimeout(() => {
                        this.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }, 300);
                });
            });
        }
    </script>
</body>
</html>