<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);
$settings = getSettings($db);

// Only admin can manage locations
if ($session['role'] !== 'admin') {
    setMessage('Access denied. Admin privileges required.', 'error');
    header('Location: dashboard.php');
    exit;
}

$action = $_GET['action'] ?? 'list';

// Handle location creation/update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($action == 'create' || $action == 'update') {
        $location_name = sanitizeInput($_POST['location_name']);
        $location_code = sanitizeInput($_POST['location_code']);
        $address = sanitizeInput($_POST['address']);
        $contact_person = sanitizeInput($_POST['contact_person']);
        $contact_phone = sanitizeInput($_POST['contact_phone']);
        $contact_email = sanitizeInput($_POST['contact_email']);
        $timezone = sanitizeInput($_POST['timezone']);
        $operating_hours_from = sanitizeInput($_POST['operating_hours_from']);
        $operating_hours_to = sanitizeInput($_POST['operating_hours_to']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        // Validation
        $errors = [];
        if (empty($location_name)) $errors[] = 'Location name is required';
        if (empty($location_code)) $errors[] = 'Location code is required';
        if (!empty($contact_email) && !validateEmail($contact_email)) $errors[] = 'Invalid email format';
        if (!empty($contact_phone) && !validatePhone($contact_phone)) $errors[] = 'Invalid phone format';
        
        // Check for duplicate codes/names
        if ($action == 'create') {
            $stmt = $db->prepare("SELECT id FROM locations WHERE location_code = ? OR location_name = ?");
            $stmt->execute([$location_code, $location_name]);
            if ($stmt->fetch()) {
                $errors[] = 'Location code or name already exists';
            }
        } else {
            $location_id = intval($_POST['location_id']);
            $stmt = $db->prepare("SELECT id FROM locations WHERE (location_code = ? OR location_name = ?) AND id != ?");
            $stmt->execute([$location_code, $location_name, $location_id]);
            if ($stmt->fetch()) {
                $errors[] = 'Location code or name already exists';
            }
        }
        
        if (empty($errors)) {
            try {
                $db->beginTransaction();
                
                if ($action == 'create') {
                    $stmt = $db->prepare("INSERT INTO locations (location_name, location_code, address, contact_person, contact_phone, contact_email, timezone, operating_hours_from, operating_hours_to, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $success = $stmt->execute([$location_name, $location_code, $address, $contact_person, $contact_phone, $contact_email, $timezone, $operating_hours_from, $operating_hours_to, $is_active]);
                    
                    if ($success) {
                        $new_location_id = $db->lastInsertId();
                        
                        // Create default gates for new location
                        $default_gates = [
                            ['name' => 'Main Gate', 'code' => $location_code . '_G1', 'type' => 'both', 'vehicles' => 1, 'pedestrians' => 1],
                            ['name' => 'Pedestrian Gate', 'code' => $location_code . '_G2', 'type' => 'both', 'vehicles' => 0, 'pedestrians' => 1],
                            ['name' => 'Vehicle Gate', 'code' => $location_code . '_G3', 'type' => 'both', 'vehicles' => 1, 'pedestrians' => 0]
                        ];
                        
                        $gate_stmt = $db->prepare("INSERT INTO gates (location_id, gate_name, gate_code, gate_type, supports_vehicles, supports_pedestrians) VALUES (?, ?, ?, ?, ?, ?)");
                        foreach ($default_gates as $gate) {
                            $gate_stmt->execute([$new_location_id, $gate['name'], $gate['code'], $gate['type'], $gate['vehicles'], $gate['pedestrians']]);
                        }
                        
                        logActivity($db, $session['operator_id'], 'location_create', "Created new location: $location_name", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
                        $message = 'Location created successfully with default gates';
                    }
                } else {
                    $stmt = $db->prepare("UPDATE locations SET location_name = ?, location_code = ?, address = ?, contact_person = ?, contact_phone = ?, contact_email = ?, timezone = ?, operating_hours_from = ?, operating_hours_to = ?, is_active = ? WHERE id = ?");
                    $success = $stmt->execute([$location_name, $location_code, $address, $contact_person, $contact_phone, $contact_email, $timezone, $operating_hours_from, $operating_hours_to, $is_active, $location_id]);
                    
                    if ($success) {
                        logActivity($db, $session['operator_id'], 'location_update', "Updated location: $location_name", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
                        $message = 'Location updated successfully';
                    }
                }
                
                $db->commit();
                
                if ($success) {
                    setMessage($message, 'success');
                    header('Location: manage-locations.php');
                    exit;
                } else {
                    throw new Exception('Database operation failed');
                }
                
            } catch (Exception $e) {
                $db->rollBack();
                $errors[] = 'Failed to save location: ' . $e->getMessage();
            }
        }
    }
    
    // Handle operator assignment
    if ($action == 'assign_operators') {
        $location_id = intval($_POST['location_id']);
        $selected_operators = $_POST['operators'] ?? [];
        $primary_operator = intval($_POST['primary_operator'] ?? 0);
        
        try {
            $db->beginTransaction();
            
            // Remove existing assignments
            $stmt = $db->prepare("DELETE FROM operator_locations WHERE location_id = ?");
            $stmt->execute([$location_id]);
            
            // Add new assignments
            if (!empty($selected_operators)) {
                $stmt = $db->prepare("INSERT INTO operator_locations (operator_id, location_id, is_primary, assigned_by) VALUES (?, ?, ?, ?)");
                foreach ($selected_operators as $operator_id) {
                    $is_primary = ($operator_id == $primary_operator) ? 1 : 0;
                    $stmt->execute([intval($operator_id), $location_id, $is_primary, $session['operator_id']]);
                }
            }
            
            $db->commit();
            
            logActivity($db, $session['operator_id'], 'operator_assignment', "Updated operator assignments for location ID: $location_id", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
            setMessage('Operator assignments updated successfully', 'success');
            
        } catch (Exception $e) {
            $db->rollBack();
            setMessage('Failed to update operator assignments: ' . $e->getMessage(), 'error');
        }
        
        header('Location: manage-locations.php?action=view&id=' . $location_id);
        exit;
    }
}

// Get location data for view/edit
if (($action == 'view' || $action == 'edit') && isset($_GET['id'])) {
    $location_id = intval($_GET['id']);
    $stmt = $db->prepare("SELECT * FROM locations WHERE id = ?");
    $stmt->execute([$location_id]);
    $location = $stmt->fetch();
    
    if (!$location) {
        setMessage('Location not found', 'error');
        header('Location: manage-locations.php');
        exit;
    }
    
    // Get location gates
    $stmt = $db->prepare("SELECT * FROM gates WHERE location_id = ? ORDER BY gate_name");
    $stmt->execute([$location_id]);
    $location_gates = $stmt->fetchAll();
    
    // Get assigned operators
    $stmt = $db->prepare("SELECT ol.*, go.operator_name, go.operator_code FROM operator_locations ol JOIN gate_operators go ON ol.operator_id = go.id WHERE ol.location_id = ? ORDER BY ol.is_primary DESC, go.operator_name");
    $stmt->execute([$location_id]);
    $assigned_operators = $stmt->fetchAll();
    
    // Get location statistics
    $stmt = $db->prepare("SELECT 
        COUNT(CASE WHEN log_type = 'check_in' AND DATE(log_timestamp) = CURDATE() THEN 1 END) as today_checkins,
        COUNT(CASE WHEN log_type = 'check_out' AND DATE(log_timestamp) = CURDATE() THEN 1 END) as today_checkouts,
        COUNT(DISTINCT CASE WHEN DATE(log_timestamp) = CURDATE() THEN visitor_id END) as today_unique_visitors,
        COUNT(DISTINCT CASE WHEN DATE(log_timestamp) = CURDATE() THEN vehicle_id END) as today_unique_vehicles
        FROM gate_logs WHERE location_id = ?");
    $stmt->execute([$location_id]);
    $location_stats = $stmt->fetch();
}

// Get locations list
if ($action == 'list') {
    $search = $_GET['search'] ?? '';
    $status_filter = $_GET['status'] ?? '';
    
    $where_conditions = ['1=1'];
    $params = [];
    
    if (!empty($search)) {
        $where_conditions[] = "(location_name LIKE ? OR location_code LIKE ? OR address LIKE ? OR contact_person LIKE ?)";
        $search_param = "%$search%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    }
    
    if ($status_filter !== '') {
        $where_conditions[] = "is_active = ?";
        $params[] = intval($status_filter);
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $stmt = $db->prepare("SELECT l.*, 
                         (SELECT COUNT(*) FROM gates WHERE location_id = l.id) as gate_count,
                         (SELECT COUNT(*) FROM operator_locations WHERE location_id = l.id) as operator_count,
                         (SELECT COUNT(*) FROM gate_logs WHERE location_id = l.id AND DATE(log_timestamp) = CURDATE()) as today_activity
                         FROM locations l 
                         WHERE $where_clause 
                         ORDER BY l.is_active DESC, l.location_name ASC");
    $stmt->execute($params);
    $locations = $stmt->fetchAll();
}

// Get all operators for assignment
$stmt = $db->query("SELECT id, operator_name, operator_code FROM gate_operators WHERE is_active = 1 ORDER BY operator_name");
$all_operators = $stmt->fetchAll();

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management System'); ?> - Location Management</title>
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
        .location-card { transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .location-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1); }
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
                    <div class="h-8 w-8 sm:h-10 sm:w-10 bg-blue-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-map-marker-alt text-white text-sm sm:text-base"></i>
                    </div>
                    <div>
                        <h1 class="text-lg sm:text-xl font-semibold text-gray-900">Location Management</h1>
                        <p class="text-xs sm:text-sm text-gray-500 hidden sm:block">Manage gate locations and settings</p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <a href="manage-vehicles.php" class="text-green-600 hover:text-green-800 text-sm sm:text-base">
                        <i class="fas fa-car"></i>
                        <span class="ml-1 hidden sm:inline">Vehicles</span>
                    </a>
                    <a href="settings.php" class="text-gray-600 hover:text-gray-800 text-sm sm:text-base">
                        <i class="fas fa-cog"></i>
                        <span class="ml-1 hidden sm:inline">Settings</span>
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
                                   placeholder="Search locations...">
                        </div>
                        <select name="status" class="px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 w-full sm:w-auto text-sm sm:text-base">
                            <option value="">All Status</option>
                            <option value="1" <?php echo $status_filter === '1' ? 'selected' : ''; ?>>Active</option>
                            <option value="0" <?php echo $status_filter === '0' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium transition-colors w-full sm:w-auto text-sm sm:text-base">
                            <i class="fas fa-search mr-2"></i>Search
                        </button>
                    </form>
                    
                    <a href="manage-locations.php?action=create" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition-colors inline-flex items-center justify-center text-sm sm:text-base">
                        <i class="fas fa-plus mr-2"></i>Add New Location
                    </a>
                </div>
            </div>

            <!-- Locations Grid -->
            <?php if (empty($locations)): ?>
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 sm:p-12 text-center text-gray-500">
                    <i class="fas fa-map-marker-alt text-3xl sm:text-4xl mb-4 text-gray-300"></i>
                    <p class="text-base sm:text-lg mb-2">No locations found</p>
                    <p class="text-sm sm:text-base">Start by adding your first location</p>
                    <a href="manage-locations.php?action=create" class="mt-4 inline-flex items-center bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition-colors text-sm sm:text-base">
                        <i class="fas fa-plus mr-2"></i>Add Location
                    </a>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6">
                    <?php foreach ($locations as $loc): ?>
                        <div class="location-card bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                            <div class="flex items-start justify-between mb-4">
                                <div class="flex-1">
                                    <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-1">
                                        <?php echo htmlspecialchars($loc['location_name']); ?>
                                    </h3>
                                    <p class="text-xs sm:text-sm text-gray-600">
                                        Code: <?php echo htmlspecialchars($loc['location_code']); ?>
                                    </p>
                                </div>
                                <span class="px-2 py-1 text-xs font-medium rounded-full <?php 
                                    echo $loc['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                    <?php echo $loc['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </div>
                            
                            <?php if ($loc['address']): ?>
                                <div class="mb-3">
                                    <p class="text-xs sm:text-sm text-gray-600">
                                        <i class="fas fa-map-marker-alt mr-2"></i>
                                        <?php echo htmlspecialchars($loc['address']); ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                            
                            <div class="grid grid-cols-3 gap-2 sm:gap-4 mb-4 text-center">
                                <div class="bg-blue-50 rounded-lg p-2 sm:p-3">
                                    <div class="text-sm sm:text-lg font-bold text-blue-600"><?php echo $loc['gate_count']; ?></div>
                                    <div class="text-xs text-blue-600">Gates</div>
                                </div>
                                <div class="bg-green-50 rounded-lg p-2 sm:p-3">
                                    <div class="text-sm sm:text-lg font-bold text-green-600"><?php echo $loc['operator_count']; ?></div>
                                    <div class="text-xs text-green-600">Operators</div>
                                </div>
                                <div class="bg-purple-50 rounded-lg p-2 sm:p-3">
                                    <div class="text-sm sm:text-lg font-bold text-purple-600"><?php echo $loc['today_activity']; ?></div>
                                    <div class="text-xs text-purple-600">Today</div>
                                </div>
                            </div>
                            
                            <div class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-2">
                                <a href="manage-locations.php?action=view&id=<?php echo $loc['id']; ?>" 
                                   class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg text-xs sm:text-sm font-medium transition-colors text-center">
                                    <i class="fas fa-eye mr-1"></i>View
                                </a>
                                <a href="manage-locations.php?action=edit&id=<?php echo $loc['id']; ?>" 
                                   class="flex-1 bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded-lg text-xs sm:text-sm font-medium transition-colors text-center">
                                    <i class="fas fa-edit mr-1"></i>Edit
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php elseif ($action == 'create' || $action == 'edit'): ?>
            <!-- Location Form -->
            <div class="max-w-4xl mx-auto">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                    <div class="mb-4 sm:mb-6">
                        <h3 class="text-lg font-semibold text-gray-900">
                            <?php echo $action == 'create' ? 'Create New Location' : 'Edit Location'; ?>
                        </h3>
                        <p class="text-sm text-gray-600">Configure location details and operating parameters</p>
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
                        <?php if ($action == 'edit'): ?>
                            <input type="hidden" name="location_id" value="<?php echo $location['id']; ?>">
                        <?php endif; ?>
                        
                        <!-- Basic Information -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                            <div>
                                <label for="location_name" class="block text-sm font-medium text-gray-700">Location Name *</label>
                                <input type="text" id="location_name" name="location_name" required 
                                       value="<?php echo htmlspecialchars($action == 'edit' ? $location['location_name'] : ($_POST['location_name'] ?? '')); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" 
                                       placeholder="Main Campus Gate">
                            </div>
                            
                            <div>
                                <label for="location_code" class="block text-sm font-medium text-gray-700">Location Code *</label>
                                <input type="text" id="location_code" name="location_code" required 
                                       value="<?php echo htmlspecialchars($action == 'edit' ? $location['location_code'] : ($_POST['location_code'] ?? '')); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base uppercase" 
                                       placeholder="MAIN" maxlength="20" style="text-transform: uppercase;">
                            </div>
                            
                            <div class="md:col-span-2">
                                <label for="address" class="block text-sm font-medium text-gray-700">Address</label>
                                <textarea id="address" name="address" rows="3"
                                          class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" 
                                          placeholder="Full address of the location"><?php echo htmlspecialchars($action == 'edit' ? $location['address'] : ($_POST['address'] ?? '')); ?></textarea>
                            </div>
                        </div>
                        
                        <!-- Contact Information -->
                        <div class="border-t border-gray-200 pt-6">
                            <h4 class="text-base font-medium text-gray-900 mb-4">Contact Information</h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 sm:gap-6">
                                <div>
                                    <label for="contact_person" class="block text-sm font-medium text-gray-700">Contact Person</label>
                                    <input type="text" id="contact_person" name="contact_person" 
                                           value="<?php echo htmlspecialchars($action == 'edit' ? $location['contact_person'] : ($_POST['contact_person'] ?? '')); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" 
                                           placeholder="contact@company.com">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Operating Parameters -->
                        <div class="border-t border-gray-200 pt-6">
                            <h4 class="text-base font-medium text-gray-900 mb-4">Operating Parameters</h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 sm:gap-6">
                                <div>
                                    <label for="timezone" class="block text-sm font-medium text-gray-700">Timezone</label>
                                    <select id="timezone" name="timezone" 
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base">
                                        <?php 
                                        $current_timezone = $action == 'edit' ? $location['timezone'] : ($_POST['timezone'] ?? 'Africa/Nairobi');
                                        $timezones = [
                                            'Africa/Nairobi' => 'Africa/Nairobi (EAT)',
                                            'America/New_York' => 'America/New_York (EST/EDT)',
                                            'Europe/London' => 'Europe/London (GMT/BST)',
                                            'Asia/Tokyo' => 'Asia/Tokyo (JST)',
                                            'Australia/Sydney' => 'Australia/Sydney (AEST/AEDT)'
                                        ];
                                        foreach ($timezones as $tz => $label): ?>
                                            <option value="<?php echo $tz; ?>" <?php echo $current_timezone === $tz ? 'selected' : ''; ?>>
                                                <?php echo $label; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div>
                                    <label for="operating_hours_from" class="block text-sm font-medium text-gray-700">Opening Time</label>
                                    <input type="time" id="operating_hours_from" name="operating_hours_from" 
                                           value="<?php echo $action == 'edit' ? $location['operating_hours_from'] : ($_POST['operating_hours_from'] ?? '06:00'); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base">
                                </div>
                                
                                <div>
                                    <label for="operating_hours_to" class="block text-sm font-medium text-gray-700">Closing Time</label>
                                    <input type="time" id="operating_hours_to" name="operating_hours_to" 
                                           value="<?php echo $action == 'edit' ? $location['operating_hours_to'] : ($_POST['operating_hours_to'] ?? '22:00'); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Status -->
                        <div class="border-t border-gray-200 pt-6">
                            <div class="flex items-center">
                                <input type="checkbox" id="is_active" name="is_active" 
                                       <?php echo ($action == 'edit' ? $location['is_active'] : 1) ? 'checked' : ''; ?>
                                       class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                <label for="is_active" class="ml-2 block text-sm text-gray-900">
                                    Location is active and operational
                                </label>
                            </div>
                        </div>
                        
                        <div class="flex flex-col sm:flex-row justify-end space-y-2 sm:space-y-0 sm:space-x-4">
                            <a href="manage-locations.php" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors text-center text-sm sm:text-base">
                                Cancel
                            </a>
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium transition-colors text-sm sm:text-base">
                                <i class="fas fa-save mr-2"></i><?php echo $action == 'create' ? 'Create Location' : 'Update Location'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        <?php elseif ($action == 'view' && isset($location)): ?>
            <!-- Location Details View -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-8">
                <div class="lg:col-span-2 space-y-4 sm:space-y-6">
                    <!-- Location Information -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-4 sm:mb-6 space-y-2 sm:space-y-0">
                            <h3 class="text-lg font-semibold text-gray-900">Location Details</h3>
                            <div class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-2 w-full sm:w-auto">
                                <a href="manage-locations.php?action=edit&id=<?php echo $location['id']; ?>" 
                                   class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg text-sm font-medium transition-colors text-center">
                                    <i class="fas fa-edit mr-1"></i>Edit
                                </a>
                                <a href="vehicle-dashboard.php?location_id=<?php echo $location['id']; ?>" 
                                   class="bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded-lg text-sm font-medium transition-colors text-center">
                                    <i class="fas fa-car mr-1"></i>Vehicle Dashboard
                                </a>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-600">Location Name</label>
                                <p class="text-gray-900 text-sm sm:text-base"><?php echo htmlspecialchars($location['location_name']); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600">Location Code</label>
                                <p class="text-gray-900 text-sm sm:text-base"><?php echo htmlspecialchars($location['location_code']); ?></p>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-gray-600">Address</label>
                                <p class="text-gray-900 text-sm sm:text-base"><?php echo htmlspecialchars($location['address'] ?: 'Not specified'); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600">Contact Person</label>
                                <p class="text-gray-900 text-sm sm:text-base"><?php echo htmlspecialchars($location['contact_person'] ?: 'Not specified'); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600">Contact Phone</label>
                                <p class="text-gray-900 text-sm sm:text-base"><?php echo htmlspecialchars($location['contact_phone'] ?: 'Not specified'); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600">Operating Hours</label>
                                <p class="text-gray-900 text-sm sm:text-base">
                                    <?php echo date('g:i A', strtotime($location['operating_hours_from'])); ?> - 
                                    <?php echo date('g:i A', strtotime($location['operating_hours_to'])); ?>
                                </p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600">Status</label>
                                <span class="px-2 py-1 text-xs font-medium rounded-full <?php 
                                    echo $location['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                    <?php echo $location['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Gates Management -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Location Gates</h3>
                        
                        <?php if (empty($location_gates)): ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-door-open text-3xl mb-4 text-gray-300"></i>
                                <p class="text-sm sm:text-base">No gates configured</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($location_gates as $gate): ?>
                                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                        <div>
                                            <h4 class="font-medium text-gray-900"><?php echo htmlspecialchars($gate['gate_name']); ?></h4>
                                            <p class="text-sm text-gray-600">
                                                Code: <?php echo htmlspecialchars($gate['gate_code']); ?> • 
                                                Type: <?php echo ucfirst($gate['gate_type']); ?>
                                                <?php if ($gate['supports_vehicles']): ?>
                                                    • <i class="fas fa-car text-blue-500"></i> Vehicles
                                                <?php endif; ?>
                                                <?php if ($gate['supports_pedestrians']): ?>
                                                    • <i class="fas fa-walking text-green-500"></i> Pedestrians
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <span class="px-2 py-1 text-xs font-medium rounded-full <?php 
                                            echo $gate['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                            <?php echo $gate['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Operator Assignment -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Assigned Operators</h3>
                        
                        <form method="POST" class="space-y-4">
                            <input type="hidden" name="action" value="assign_operators">
                            <input type="hidden" name="location_id" value="<?php echo $location['id']; ?>">
                            
                            <div class="space-y-3">
                                <?php foreach ($all_operators as $operator): ?>
                                    <?php 
                                    $is_assigned = false;
                                    $is_primary = false;
                                    foreach ($assigned_operators as $assigned) {
                                        if ($assigned['operator_id'] == $operator['id']) {
                                            $is_assigned = true;
                                            $is_primary = $assigned['is_primary'];
                                            break;
                                        }
                                    }
                                    ?>
                                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                        <div class="flex items-center">
                                            <input type="checkbox" name="operators[]" value="<?php echo $operator['id']; ?>" 
                                                   id="op_<?php echo $operator['id']; ?>" 
                                                   <?php echo $is_assigned ? 'checked' : ''; ?>
                                                   class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                            <label for="op_<?php echo $operator['id']; ?>" class="ml-3 text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($operator['operator_name']); ?>
                                                <span class="text-gray-500">(<?php echo htmlspecialchars($operator['operator_code']); ?>)</span>
                                            </label>
                                        </div>
                                        <div class="flex items-center">
                                            <input type="radio" name="primary_operator" value="<?php echo $operator['id']; ?>" 
                                                   id="primary_<?php echo $operator['id']; ?>" 
                                                   <?php echo $is_primary ? 'checked' : ''; ?>
                                                   class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300">
                                            <label for="primary_<?php echo $operator['id']; ?>" class="ml-2 text-sm text-gray-600">
                                                Primary
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition-colors text-sm sm:text-base">
                                <i class="fas fa-save mr-2"></i>Update Assignments
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Statistics & Quick Actions -->
                <div class="space-y-4 sm:space-y-6">
                    <!-- Today's Statistics -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Today's Activity</h3>
                        
                        <div class="space-y-3">
                            <div class="flex justify-between items-center p-3 bg-blue-50 rounded-lg">
                                <span class="text-sm font-medium text-blue-800">Check-ins</span>
                                <span class="text-lg font-bold text-blue-600"><?php echo $location_stats['today_checkins']; ?></span>
                            </div>
                            <div class="flex justify-between items-center p-3 bg-green-50 rounded-lg">
                                <span class="text-sm font-medium text-green-800">Check-outs</span>
                                <span class="text-lg font-bold text-green-600"><?php echo $location_stats['today_checkouts']; ?></span>
                            </div>
                            <div class="flex justify-between items-center p-3 bg-purple-50 rounded-lg">
                                <span class="text-sm font-medium text-purple-800">Unique Visitors</span>
                                <span class="text-lg font-bold text-purple-600"><?php echo $location_stats['today_unique_visitors']; ?></span>
                            </div>
                            <div class="flex justify-between items-center p-3 bg-orange-50 rounded-lg">
                                <span class="text-sm font-medium text-orange-800">Unique Vehicles</span>
                                <span class="text-lg font-bold text-orange-600"><?php echo $location_stats['today_unique_vehicles']; ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Quick Actions</h3>
                        
                        <div class="space-y-3">
                            <a href="vehicle-scanner.php?location_id=<?php echo $location['id']; ?>" 
                               class="block w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-3 rounded-lg text-center font-medium transition-colors">
                                <i class="fas fa-qrcode mr-2"></i>Vehicle Scanner
                            </a>
                            <a href="scanner.php?location_id=<?php echo $location['id']; ?>" 
                               class="block w-full bg-green-600 hover:bg-green-700 text-white px-4 py-3 rounded-lg text-center font-medium transition-colors">
                                <i class="fas fa-user-check mr-2"></i>Visitor Scanner
                            </a>
                            <a href="delivery-tracking.php?location_id=<?php echo $location['id']; ?>" 
                               class="block w-full bg-orange-600 hover:bg-orange-700 text-white px-4 py-3 rounded-lg text-center font-medium transition-colors">
                                <i class="fas fa-truck mr-2"></i>Delivery Tracking
                            </a>
                            <a href="vehicle-reports.php?location_id=<?php echo $location['id']; ?>" 
                               class="block w-full bg-purple-600 hover:bg-purple-700 text-white px-4 py-3 rounded-lg text-center font-medium transition-colors">
                                <i class="fas fa-chart-bar mr-2"></i>Location Reports
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Form enhancements
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-uppercase location code
            const locationCodeInput = document.getElementById('location_code');
            if (locationCodeInput) {
                locationCodeInput.addEventListener('input', function() {
                    this.value = this.value.toUpperCase().replace(/[^A-Z0-9_]/g, '');
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
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        const originalText = submitBtn.innerHTML;
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
                        submitBtn.disabled = true;
                        
                        setTimeout(() => {
                            submitBtn.innerHTML = originalText;
                            submitBtn.disabled = false;
                        }, 10000);
                    }
                });
            });

            // Operator assignment checkbox handling
            const operatorCheckboxes = document.querySelectorAll('input[name="operators[]"]');
            const primaryRadios = document.querySelectorAll('input[name="primary_operator"]');
            
            operatorCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const operatorId = this.value;
                    const primaryRadio = document.getElementById('primary_' + operatorId);
                    
                    if (!this.checked && primaryRadio.checked) {
                        primaryRadio.checked = false;
                    }
                });
            });

            primaryRadios.forEach(radio => {
                radio.addEventListener('change', function() {
                    const operatorId = this.value;
                    const operatorCheckbox = document.getElementById('op_' + operatorId);
                    
                    if (this.checked && !operatorCheckbox.checked) {
                        operatorCheckbox.checked = true;
                    }
                });
            });
        });

        // Real-time statistics update
        <?php if ($action == 'view' && isset($location)): ?>
        function updateLocationStats() {
            fetch('api-realtime.php?endpoint=location_stats&location_id=<?php echo $location['id']; ?>')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const stats = data.data;
                        
                        // Update stat cards with animation
                        const elements = {
                            checkins: document.querySelector('.bg-blue-50 .text-lg'),
                            checkouts: document.querySelector('.bg-green-50 .text-lg'),
                            visitors: document.querySelector('.bg-purple-50 .text-lg'),
                            vehicles: document.querySelector('.bg-orange-50 .text-lg')
                        };

                        Object.keys(elements).forEach(key => {
                            if (elements[key] && stats[key] !== undefined) {
                                const current = parseInt(elements[key].textContent) || 0;
                                const target = stats[key];
                                
                                if (current !== target) {
                                    animateNumber(elements[key], current, target);
                                }
                            }
                        });
                    }
                })
                .catch(error => console.error('Error updating location stats:', error));
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

        // Update stats every 30 seconds
        setInterval(updateLocationStats, 30000);
        <?php endif; ?>

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
                        window.location.href = 'manage-locations.php?action=create';
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
            document.querySelectorAll('.location-card').forEach(card => {
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