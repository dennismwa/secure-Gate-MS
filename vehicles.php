<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);
$settings = getSettings($db);

$action = $_GET['action'] ?? 'list';

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
    
    $errors = [];
    if (empty($license_plate)) $errors[] = 'License plate is required';
    if (empty($owner_name)) $errors[] = 'Owner name is required';
    
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
            header('Location: vehicles.php?action=view&id=' . $vehicle_id);
            exit;
        } else {
            $errors[] = 'Failed to register vehicle';
        }
    }
}

// Handle vehicle check-in/out
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $action == 'process_entry') {
    $vehicle_id = sanitizeInput($_POST['vehicle_id']);
    $location_id = intval($_POST['location_id']);
    $gate_id = intval($_POST['gate_id']) ?: null;
    $entry_purpose = sanitizeInput($_POST['entry_purpose']);
    $log_type = sanitizeInput($_POST['log_type']);
    $driver_name = sanitizeInput($_POST['driver_name']);
    $driver_phone = sanitizeInput($_POST['driver_phone']);
    $driver_license = sanitizeInput($_POST['driver_license']);
    $passenger_count = intval($_POST['passenger_count']);
    $cargo_description = sanitizeInput($_POST['cargo_description']);
    $delivery_company = sanitizeInput($_POST['delivery_company']);
    $delivery_reference = sanitizeInput($_POST['delivery_reference']);
    $destination_department = sanitizeInput($_POST['destination_department']);
    $contact_person = sanitizeInput($_POST['contact_person']);
    $expected_duration = intval($_POST['expected_duration']) ?: null;
    $notes = sanitizeInput($_POST['notes']);
    
    // Validate vehicle exists
    $stmt = $db->prepare("SELECT * FROM vehicles WHERE vehicle_id = ? AND status = 'active'");
    $stmt->execute([$vehicle_id]);
    $vehicle = $stmt->fetch();
    
    if ($vehicle && in_array($log_type, ['check_in', 'check_out']) && in_array($entry_purpose, ['delivery', 'pickup', 'service', 'maintenance', 'visitor', 'staff'])) {
        // Record in vehicle_logs table
        $stmt = $db->prepare("INSERT INTO vehicle_logs (vehicle_id, log_type, location_id, gate_id, entry_purpose, driver_name, driver_phone, driver_license, passenger_count, cargo_description, delivery_company, delivery_reference, destination_department, contact_person, expected_duration, operator_id, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$vehicle_id, $log_type, $location_id, $gate_id, $entry_purpose, $driver_name, $driver_phone, $driver_license, $passenger_count, $cargo_description, $delivery_company, $delivery_reference, $destination_department, $contact_person, $expected_duration, $session['operator_id'], $notes]);
        
        // Also record in gate_logs for unified tracking
        $stmt = $db->prepare("INSERT INTO gate_logs (location_id, gate_id, vehicle_id, log_type, entry_type, delivery_type, delivery_company, delivery_reference, operator_id, notes) VALUES (?, ?, ?, ?, 'vehicle', ?, ?, ?, ?, ?)");
        $stmt->execute([$location_id, $gate_id, $vehicle_id, $log_type, $entry_purpose == 'delivery' ? 'delivery' : null, $delivery_company, $delivery_reference, $session['operator_id'], $notes]);
        
        // Create notification
        try {
            $action_text = $log_type === 'check_in' ? 'entered' : 'exited';
            $location_stmt = $db->prepare("SELECT location_name FROM locations WHERE id = ?");
            $location_stmt->execute([$location_id]);
            $location_name = $location_stmt->fetch()['location_name'] ?? 'Unknown Location';
            createNotification($db, $log_type, 'Vehicle ' . ucfirst($action_text), "Vehicle {$vehicle['license_plate']} has $action_text $location_name", null, $session['operator_id']);
        } catch (Exception $e) {
            error_log("Notification creation error: " . $e->getMessage());
        }
        
        logActivity($db, $session['operator_id'], 'vehicle_' . $log_type, "Vehicle $log_type for: {$vehicle['license_plate']}", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => ucfirst(str_replace('_', ' ', $log_type)) . ' successful for ' . $vehicle['license_plate'],
            'vehicle' => $vehicle,
            'action' => $log_type
        ]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid vehicle or action']);
    }
    exit;
}

// Get vehicle data for view/edit
if ($action == 'view' || $action == 'edit') {
    $vehicle_id = $_GET['id'] ?? '';
    $stmt = $db->prepare("SELECT v.*, vt.type_name, vt.default_duration FROM vehicles v LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id WHERE v.vehicle_id = ?");
    $stmt->execute([$vehicle_id]);
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        setMessage('Vehicle not found', 'error');
        header('Location: vehicles.php');
        exit;
    }
    
    // Get vehicle logs
    $stmt = $db->prepare("SELECT vl.*, l.location_name, g.gate_name, go.operator_name FROM vehicle_logs vl 
                         LEFT JOIN locations l ON vl.location_id = l.id 
                         LEFT JOIN gates g ON vl.gate_id = g.id
                         JOIN gate_operators go ON vl.operator_id = go.id 
                         WHERE vl.vehicle_id = ? ORDER BY vl.log_timestamp DESC LIMIT 20");
    $stmt->execute([$vehicle_id]);
    $vehicle_logs = $stmt->fetchAll();
}

// Get vehicles list
if ($action == 'list' || $action == 'scanner') {
    $search = $_GET['search'] ?? '';
    $status_filter = $_GET['status'] ?? '';
    $location_filter = $_GET['location'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = 20;
    $offset = ($page - 1) * $per_page;
    
    $where_conditions = ['1=1'];
    $params = [];
    
    if (!empty($search)) {
        $where_conditions[] = "(v.license_plate LIKE ? OR v.make LIKE ? OR v.model LIKE ? OR v.owner_name LIKE ? OR v.owner_company LIKE ? OR v.vehicle_id LIKE ?)";
        $search_param = "%$search%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param, $search_param]);
    }
    
    if (!empty($status_filter)) {
        $where_conditions[] = "v.status = ?";
        $params[] = $status_filter;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Get total count
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM vehicles v WHERE $where_clause");
    $stmt->execute($params);
    $total_vehicles = $stmt->fetch()['total'];
    $total_pages = ceil($total_vehicles / $per_page);
    
    // Get vehicles with current status
    $stmt = $db->prepare("SELECT v.*, vt.type_name,
                         (SELECT log_type FROM vehicle_logs vl WHERE vl.vehicle_id = v.vehicle_id ORDER BY log_timestamp DESC LIMIT 1) as last_action,
                         (SELECT log_timestamp FROM vehicle_logs vl WHERE vl.vehicle_id = v.vehicle_id ORDER BY log_timestamp DESC LIMIT 1) as last_activity,
                         (SELECT l.location_name FROM vehicle_logs vl JOIN locations l ON vl.location_id = l.id WHERE vl.vehicle_id = v.vehicle_id ORDER BY vl.log_timestamp DESC LIMIT 1) as last_location
                         FROM vehicles v 
                         LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
                         WHERE $where_clause 
                         ORDER BY v.created_at DESC 
                         LIMIT $per_page OFFSET $offset");
    $stmt->execute($params);
    $vehicles = $stmt->fetchAll();
}

// Get locations and vehicle types for forms
$locations_stmt = $db->query("SELECT id, location_name, location_code FROM locations WHERE is_active = 1 ORDER BY location_name");
$locations = $locations_stmt->fetchAll();

$vehicle_types_stmt = $db->query("SELECT id, type_name, default_duration FROM vehicle_types WHERE is_active = 1 ORDER BY type_name");
$vehicle_types = $vehicle_types_stmt->fetchAll();

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
            .mobile-stack { flex-direction: column !important; }
            .mobile-full { width: 100% !important; }
            .mobile-hidden { display: none !important; }
            .mobile-overflow { overflow-x: auto !important; }
        }
        
        .scanner-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.3);
            pointer-events: none;
        }
        
        .scanner-frame {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 250px;
            height: 250px;
            border: 3px solid #3b82f6;
            border-radius: 12px;
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Navigation -->
    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-2 sm:px-4 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <a href="dashboard.php" class="text-gray-600 hover:text-gray-900">
                        <i class="fas fa-arrow-left"></i>
                    </a>
                    <div class="h-8 w-8 sm:h-10 sm:w-10 bg-green-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-truck text-white text-sm sm:text-base"></i>
                    </div>
                    <div>
                        <h1 class="text-lg sm:text-xl font-semibold text-gray-900">Vehicle Management</h1>
                        <p class="text-xs sm:text-sm text-gray-500 hidden sm:block">Register and manage vehicles</p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <a href="vehicles.php?action=scanner" class="text-blue-600 hover:text-blue-800 text-sm">
                        <i class="fas fa-qrcode"></i>
                        <span class="ml-1 hidden sm:inline">Scanner</span>
                    </a>
                    <a href="locations.php" class="text-purple-600 hover:text-purple-800 text-sm">
                        <i class="fas fa-map-marker-alt"></i>
                        <span class="ml-1 hidden sm:inline">Locations</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-2 sm:px-4 lg:px-8 py-4 sm:py-8">
        <?php if ($message): ?>
            <div class="mb-4 sm:mb-6 p-3 sm:p-4 rounded-lg border <?php 
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

        <?php if ($action == 'scanner'): ?>
            <!-- Vehicle Scanner Interface -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Scanner Section -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-6">
                        <i class="fas fa-camera mr-2"></i>Vehicle QR Scanner
                    </h3>
                    
                    <div class="relative mb-6">
                        <video id="video" class="w-full h-64 bg-gray-900 rounded-lg"></video>
                        <canvas id="canvas" class="hidden"></canvas>
                        
                        <div class="scanner-overlay">
                            <div class="scanner-frame"></div>
                        </div>
                        
                        <div id="scanning-indicator" class="absolute top-4 right-4 bg-red-500 text-white px-3 py-1 rounded-full text-sm font-medium hidden">
                            <i class="fas fa-circle animate-pulse mr-1"></i>Scanning...
                        </div>
                    </div>
                    
                    <div class="flex space-x-4 mb-4">
                        <button id="startCamera" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium">
                            <i class="fas fa-play mr-2"></i>Start Camera
                        </button>
                        <button id="stopCamera" class="flex-1 bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg font-medium" disabled>
                            <i class="fas fa-stop mr-2"></i>Stop Camera
                        </button>
                    </div>
                    
                    <!-- Manual Entry -->
                    <div class="border-t pt-6">
                        <h4 class="font-medium text-gray-900 mb-4">Manual Entry</h4>
                        <div class="flex space-x-2">
                            <input type="text" id="manual_vehicle_id" placeholder="Vehicle ID or License Plate" 
                                   class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <button onclick="processManualEntry()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Entry Form -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-6">Vehicle Entry Details</h3>
                    
                    <div id="selected_vehicle" class="mb-6 p-4 bg-gray-50 rounded-lg hidden">
                        <h4 class="font-medium text-gray-900 mb-2">Selected Vehicle:</h4>
                        <div id="vehicle_info"></div>
                    </div>
                    
                    <form id="entryForm" class="space-y-4">
                        <input type="hidden" id="vehicle_id" name="vehicle_id">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="location_id" class="block text-sm font-medium text-gray-700">Location *</label>
                                <select id="location_id" name="location_id" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                    <option value="">Select Location</option>
                                    <?php foreach ($locations as $location): ?>
                                        <option value="<?php echo $location['id']; ?>"><?php echo htmlspecialchars($location['location_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label for="gate_id" class="block text-sm font-medium text-gray-700">Gate</label>
                                <select id="gate_id" name="gate_id" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                    <option value="">Select Gate</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Action *</label>
                                <div class="grid grid-cols-2 gap-2">
                                    <button type="button" id="checkin_btn" class="p-3 border-2 border-green-300 text-green-700 rounded-lg hover:bg-green-50 transition-colors">
                                        <i class="fas fa-sign-in-alt mr-2"></i>Check In
                                    </button>
                                    <button type="button" id="checkout_btn" class="p-3 border-2 border-red-300 text-red-700 rounded-lg hover:bg-red-50 transition-colors">
                                        <i class="fas fa-sign-out-alt mr-2"></i>Check Out
                                    </button>
                                </div>
                                <input type="hidden" id="log_type" name="log_type">
                            </div>
                            
                            <div>
                                <label for="entry_purpose" class="block text-sm font-medium text-gray-700">Purpose *</label>
                                <select id="entry_purpose" name="entry_purpose" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                    <option value="">Select Purpose</option>
                                    <option value="delivery">Delivery</option>
                                    <option value="pickup">Pickup</option>
                                    <option value="service">Service/Maintenance</option>
                                    <option value="visitor">Visitor</option>
                                    <option value="staff">Staff Vehicle</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Driver Information -->
                        <div class="border-t pt-4">
                            <h5 class="font-medium text-gray-900 mb-3">Driver Information</h5>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label for="driver_name" class="block text-sm font-medium text-gray-700">Driver Name</label>
                                    <input type="text" id="driver_name" name="driver_name" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label for="driver_phone" class="block text-sm font-medium text-gray-700">Driver Phone</label>
                                    <input type="tel" id="driver_phone" name="driver_phone" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label for="driver_license" class="block text-sm font-medium text-gray-700">License Number</label>
                                    <input type="text" id="driver_license" name="driver_license" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Delivery/Service Details -->
                        <div id="delivery_details" class="border-t pt-4 hidden">
                            <h5 class="font-medium text-gray-900 mb-3">Delivery/Service Details</h5>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="delivery_company" class="block text-sm font-medium text-gray-700">Company</label>
                                    <input type="text" id="delivery_company" name="delivery_company" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label for="delivery_reference" class="block text-sm font-medium text-gray-700">Reference Number</label>
                                    <input type="text" id="delivery_reference" name="delivery_reference" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label for="destination_department" class="block text-sm font-medium text-gray-700">Department</label>
                                    <input type="text" id="destination_department" name="destination_department" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label for="contact_person" class="block text-sm font-medium text-gray-700">Contact Person</label>
                                    <input type="text" id="contact_person" name="contact_person" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>
                            <div class="mt-4">
                                <label for="cargo_description" class="block text-sm font-medium text-gray-700">Cargo Description</label>
                                <textarea id="cargo_description" name="cargo_description" rows="2" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"></textarea>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="passenger_count" class="block text-sm font-medium text-gray-700">Passenger Count</label>
                                <input type="number" id="passenger_count" name="passenger_count" min="0" value="1" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label for="expected_duration" class="block text-sm font-medium text-gray-700">Expected Duration (minutes)</label>
                                <input type="number" id="expected_duration" name="expected_duration" min="5" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>
                        
                        <div>
                            <label for="notes" class="block text-sm font-medium text-gray-700">Notes</label>
                            <textarea id="notes" name="notes" rows="3" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"></textarea>
                        </div>
                        
                        <button type="submit" id="submit_btn" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-3 rounded-lg font-medium disabled:opacity-50" disabled>
                            <i class="fas fa-check mr-2"></i>Process Entry
                        </button>
                    </form>
                </div>
            </div>

        <?php elseif ($action == 'register'): ?>
            <!-- Vehicle Registration Form -->
            <div class="max-w-3xl mx-auto">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <div class="mb-6">
                        <h3 class="text-lg font-semibold text-gray-900">Register New Vehicle</h3>
                        <p class="text-sm text-gray-600">Add a new vehicle to the system</p>
                    </div>
                    
                    <?php if (isset($errors) && !empty($errors)): ?>
                        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                            <ul class="list-disc list-inside text-red-600 text-sm">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" class="space-y-6">
                        <!-- Vehicle Information -->
                        <div>
                            <h4 class="font-medium text-gray-900 mb-4 border-b pb-2">Vehicle Information</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="license_plate" class="block text-sm font-medium text-gray-700">License Plate *</label>
                                    <input type="text" id="license_plate" name="license_plate" required 
                                           value="<?php echo htmlspecialchars($_POST['license_plate'] ?? ''); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 uppercase"
                                           placeholder="KDQ 123A">
                                </div>
                                
                                <div>
                                    <label for="vehicle_type_id" class="block text-sm font-medium text-gray-700">Vehicle Type</label>
                                    <select id="vehicle_type_id" name="vehicle_type_id" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                        <option value="">Select Type</option>
                                        <?php foreach ($vehicle_types as $type): ?>
                                            <option value="<?php echo $type['id']; ?>" <?php echo ($_POST['vehicle_type_id'] ?? '') == $type['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($type['type_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div>
                                    <label for="make" class="block text-sm font-medium text-gray-700">Make</label>
                                    <input type="text" id="make" name="make" 
                                           value="<?php echo htmlspecialchars($_POST['make'] ?? ''); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                           placeholder="Toyota">
                                </div>
                                
                                <div>
                                    <label for="model" class="block text-sm font-medium text-gray-700">Model</label>
                                    <input type="text" id="model" name="model" 
                                           value="<?php echo htmlspecialchars($_POST['model'] ?? ''); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                           placeholder="Camry">
                                </div>
                                
                                <div>
                                    <label for="year" class="block text-sm font-medium text-gray-700">Year</label>
                                    <input type="number" id="year" name="year" min="1900" max="<?php echo date('Y') + 1; ?>"
                                           value="<?php echo htmlspecialchars($_POST['year'] ?? ''); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                                
                                <div>
                                    <label for="color" class="block text-sm font-medium text-gray-700">Color</label>
                                    <input type="text" id="color" name="color" 
                                           value="<?php echo htmlspecialchars($_POST['color'] ?? ''); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                           placeholder="White">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Owner Information -->
                        <div>
                            <h4 class="font-medium text-gray-900 mb-4 border-b pb-2">Owner Information</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="owner_name" class="block text-sm font-medium text-gray-700">Owner Name *</label>
                                    <input type="text" id="owner_name" name="owner_name" required
                                           value="<?php echo htmlspecialchars($_POST['owner_name'] ?? ''); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                                
                                <div>
                                    <label for="owner_phone" class="block text-sm font-medium text-gray-700">Owner Phone</label>
                                    <input type="tel" id="owner_phone" name="owner_phone"
                                           value="<?php echo htmlspecialchars($_POST['owner_phone'] ?? ''); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                                
                                <div class="md:col-span-2">
                                    <label for="owner_company" class="block text-sm font-medium text-gray-700">Owner Company</label>
                                    <input type="text" id="owner_company" name="owner_company"
                                           value="<?php echo htmlspecialchars($_POST['owner_company'] ?? ''); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Driver Information -->
                        <div>
                            <h4 class="font-medium text-gray-900 mb-4 border-b pb-2">Default Driver Information</h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                <div>
                                    <label for="driver_name" class="block text-sm font-medium text-gray-700">Driver Name</label>
                                    <input type="text" id="driver_name" name="driver_name"
                                           value="<?php echo htmlspecialchars($_POST['driver_name'] ?? ''); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                                
                                <div>
                                    <label for="driver_phone" class="block text-sm font-medium text-gray-700">Driver Phone</label>
                                    <input type="tel" id="driver_phone" name="driver_phone"
                                           value="<?php echo htmlspecialchars($_POST['driver_phone'] ?? ''); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                                
                                <div>
                                    <label for="driver_license" class="block text-sm font-medium text-gray-700">Driver License</label>
                                    <input type="text" id="driver_license" name="driver_license"
                                           value="<?php echo htmlspecialchars($_POST['driver_license'] ?? ''); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Vehicle Classifications -->
                        <div>
                            <h4 class="font-medium text-gray-900 mb-4 border-b pb-2">Vehicle Classification</h4>
                            <div class="space-y-4">
                                <div class="flex items-center">
                                    <input type="checkbox" id="is_company_vehicle" name="is_company_vehicle" 
                                           <?php echo isset($_POST['is_company_vehicle']) ? 'checked' : ''; ?>
                                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                    <label for="is_company_vehicle" class="ml-2 block text-sm text-gray-900">Company Vehicle</label>
                                </div>
                                
                                <div class="flex items-center">
                                    <input type="checkbox" id="is_delivery_vehicle" name="is_delivery_vehicle"
                                           <?php echo isset($_POST['is_delivery_vehicle']) ? 'checked' : ''; ?>
                                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                    <label for="is_delivery_vehicle" class="ml-2 block text-sm text-gray-900">Delivery Vehicle</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex justify-end space-x-4">
                            <a href="vehicles.php" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</a>
                            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium">
                                <i class="fas fa-truck mr-2"></i>Register Vehicle
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        <?php elseif ($action == 'view' && isset($vehicle)): ?>
            <!-- Vehicle Details View -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="lg:col-span-2 space-y-6">
                    <!-- Vehicle Information -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-semibold text-gray-900">Vehicle Information</h3>
                            <div class="flex space-x-2">
                                <a href="vehicles.php?action=edit&id=<?php echo $vehicle['vehicle_id']; ?>" 
                                   class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg text-sm">
                                    <i class="fas fa-edit mr-1"></i>Edit
                                </a>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-600">License Plate</label>
                                <p class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($vehicle['license_plate']); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600">Vehicle Type</label>
                                <p class="text-gray-900"><?php echo htmlspecialchars($vehicle['type_name'] ?? 'Not specified'); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600">Make & Model</label>
                                <p class="text-gray-900"><?php echo htmlspecialchars(trim($vehicle['make'] . ' ' . $vehicle['model'])); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600">Year & Color</label>
                                <p class="text-gray-900"><?php echo htmlspecialchars($vehicle['year'] . ' ' . $vehicle['color']); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600">Owner</label>
                                <p class="text-gray-900"><?php echo htmlspecialchars($vehicle['owner_name']); ?></p>
                                <?php if ($vehicle['owner_company']): ?>
                                    <p class="text-sm text-gray-600"><?php echo htmlspecialchars($vehicle['owner_company']); ?></p>
                                <?php endif; ?>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600">Contact</label>
                                <?php if ($vehicle['owner_phone']): ?>
                                    <p class="text-gray-900"><?php echo htmlspecialchars($vehicle['owner_phone']); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="mt-6 flex space-x-4">
                            <?php if ($vehicle['is_company_vehicle']): ?>
                                <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-sm">Company Vehicle</span>
                            <?php endif; ?>
                            <?php if ($vehicle['is_delivery_vehicle']): ?>
                                <span class="px-3 py-1 bg-orange-100 text-orange-800 rounded-full text-sm">Delivery Vehicle</span>
                            <?php endif; ?>
                            <span class="px-3 py-1 rounded-full text-sm <?php 
                                echo $vehicle['status'] === 'active' ? 'bg-green-100 text-green-800' : 
                                    ($vehicle['status'] === 'blocked' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-800'); ?>">
                                <?php echo ucfirst($vehicle['status']); ?>
                            </span>
                        </div>
                    </div>

                    <!-- Activity History -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-6">Recent Activity</h3>
                        
                        <?php if (empty($vehicle_logs)): ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-history text-4xl mb-4 text-gray-300"></i>
                                <p>No activity recorded yet</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-4">
                                <?php foreach ($vehicle_logs as $log): ?>
                                    <div class="flex items-center p-4 bg-gray-50 rounded-lg">
                                        <div class="flex-shrink-0">
                                            <div class="w-10 h-10 rounded-full flex items-center justify-center <?php 
                                                echo $log['log_type'] === 'check_in' ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600'; ?>">
                                                <i class="fas <?php echo $log['log_type'] === 'check_in' ? 'fa-sign-in-alt' : 'fa-sign-out-alt'; ?>"></i>
                                            </div>
                                        </div>
                                        <div class="ml-4 flex-1">
                                            <div class="flex items-center justify-between">
                                                <div>
                                                    <p class="font-medium text-gray-900">
                                                        <?php echo ucfirst(str_replace('_', ' ', $log['log_type'])); ?> - <?php echo ucfirst($log['entry_purpose']); ?>
                                                    </p>
                                                    <p class="text-sm text-gray-600">
                                                        <?php echo htmlspecialchars($log['location_name']); ?>
                                                        <?php if ($log['gate_name']): ?>
                                                            • <?php echo htmlspecialchars($log['gate_name']); ?>
                                                        <?php endif; ?>
                                                        • By: <?php echo htmlspecialchars($log['operator_name']); ?>
                                                    </p>
                                                    <?php if ($log['driver_name']): ?>
                                                        <p class="text-sm text-gray-600">Driver: <?php echo htmlspecialchars($log['driver_name']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-sm text-gray-500">
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
                <div class="space-y-6">
                    <!-- QR Code -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 text-center">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Vehicle QR Code</h3>
                        <div class="bg-gray-100 p-4 rounded-lg mb-4">
                            <canvas id="qrcode" width="200" height="200"></canvas>
                        </div>
                        <button onclick="downloadQRCode()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-download mr-2"></i>Download QR
                        </button>
                    </div>

                    <!-- Quick Actions -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Quick Actions</h3>
                        <div class="space-y-3">
                            <a href="vehicles.php?action=scanner" class="block w-full bg-green-600 hover:bg-green-700 text-white px-4 py-3 rounded-lg text-center font-medium">
                                <i class="fas fa-qrcode mr-2"></i>Vehicle Scanner
                            </a>
                            <button onclick="quickEntry('check_in')" class="block w-full bg-blue-500 hover:bg-blue-600 text-white px-4 py-3 rounded-lg text-center font-medium">
                                <i class="fas fa-sign-in-alt mr-2"></i>Quick Check-in
                            </button>
                            <button onclick="quickEntry('check_out')" class="block w-full bg-red-500 hover:bg-red-600 text-white px-4 py-3 rounded-lg text-center font-medium">
                                <i class="fas fa-sign-out-alt mr-2"></i>Quick Check-out
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- Vehicles List -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between space-y-4 lg:space-y-0">
                    <form method="GET" class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-4">
                        <input type="hidden" name="action" value="list">
                        <div class="relative">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                   class="pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 w-full sm:w-64" 
                                   placeholder="Search vehicles...">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center">
                                <i class="fas fa-search text-gray-400"></i>
                            </div>
                        </div>
                        <select name="status" class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="blocked" <?php echo $status_filter === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
                            <option value="maintenance" <?php echo $status_filter === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                        </select>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium">
                            <i class="fas fa-search mr-2"></i>Search
                        </button>
                    </form>
                    
                    <div class="flex space-x-2">
                        <a href="vehicles.php?action=scanner" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium">
                            <i class="fas fa-qrcode mr-2"></i>Scanner
                        </a>
                        <a href="vehicles.php?action=register" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium">
                            <i class="fas fa-plus mr-2"></i>Register Vehicle
                        </a>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">
                        Vehicles (<?php echo number_format($total_vehicles); ?> total)
                    </h3>
                </div>
                
                <?php if (empty($vehicles)): ?>
                    <div class="px-6 py-12 text-center text-gray-500">
                        <i class="fas fa-truck text-4xl mb-4 text-gray-300"></i>
                        <p class="text-lg mb-2">No vehicles found</p>
                        <p>Register your first vehicle to get started</p>
                        <a href="vehicles.php?action=register" class="mt-4 inline-flex items-center bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium">
                            <i class="fas fa-plus mr-2"></i>Register Vehicle
                        </a>
                    </div>
                <?php else: ?>
                    <div class="mobile-overflow">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Vehicle</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase mobile-hidden">Owner</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase mobile-hidden">Last Activity</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4">
                                            <div>
                                                <div class="font-bold text-gray-900"><?php echo htmlspecialchars($vehicle['license_plate']); ?></div>
                                                <div class="text-sm text-gray-600">
                                                    <?php echo htmlspecialchars(trim($vehicle['make'] . ' ' . $vehicle['model'])); ?>
                                                </div>
                                                <div class="text-sm text-gray-500">ID: <?php echo htmlspecialchars($vehicle['vehicle_id']); ?></div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 mobile-hidden">
                                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($vehicle['owner_name']); ?></div>
                                            <?php if ($vehicle['owner_company']): ?>
                                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($vehicle['owner_company']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex flex-col space-y-1">
                                                <span class="px-2 py-1 text-xs font-medium rounded-full <?php 
                                                    echo $vehicle['status'] === 'active' ? 'bg-green-100 text-green-800' : 
                                                        ($vehicle['status'] === 'blocked' ? 'bg-red-100 text-red-800' : 
                                                         ($vehicle['status'] === 'maintenance' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800')); ?>">
                                                    <?php echo ucfirst($vehicle['status']); ?>
                                                </span>
                                                <?php if ($vehicle['last_action']): ?>
                                                    <span class="px-2 py-1 text-xs font-medium rounded-full <?php 
                                                        echo $vehicle['last_action'] === 'check_in' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800'; ?>">
                                                        <?php echo $vehicle['last_action'] === 'check_in' ? 'Inside' : 'Outside'; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 mobile-hidden">
                                            <?php if ($vehicle['last_activity']): ?>
                                                <div class="text-sm text-gray-900">
                                                    <?php echo date('M j, g:i A', strtotime($vehicle['last_activity'])); ?>
                                                </div>
                                                <?php if ($vehicle['last_location']): ?>
                                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($vehicle['last_location']); ?></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-gray-400">Never visited</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex space-x-2">
                                                <a href="vehicles.php?action=view&id=<?php echo $vehicle['vehicle_id']; ?>" 
                                                   class="text-blue-600 hover:text-blue-900 text-sm">
                                                    <i class="fas fa-eye mr-1"></i>View
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
                        <div class="px-6 py-4 border-t border-gray-200">
                            <div class="flex items-center justify-between">
                                <div class="text-sm text-gray-700">
                                    Showing <?php echo (($page - 1) * $per_page) + 1; ?> to <?php echo min($page * $per_page, $total_vehicles); ?> of <?php echo $total_vehicles; ?> results
                                </div>
                                <div class="flex space-x-2">
                                    <?php if ($page > 1): ?>
                                        <a href="?action=list&page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" 
                                           class="px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-600 hover:bg-gray-50">Previous</a>
                                    <?php endif; ?>
                                    
                                    <?php if ($page < $total_pages): ?>
                                        <a href="?action=list&page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" 
                                           class="px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-600 hover:bg-gray-50">Next</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Success Modal -->
    <div id="successModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3 text-center">
                <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-green-100">
                    <i class="fas fa-check text-green-600 text-xl"></i>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mt-2" id="success_title">Success!</h3>
                <div class="mt-2 px-7 py-3">
                    <p class="text-sm text-gray-500" id="success_message"></p>
                </div>
                <div class="items-center px-4 py-3">
                    <button onclick="closeModal()" class="px-4 py-2 bg-green-500 text-white text-base font-medium rounded-md w-full shadow-sm hover:bg-green-600">
                        OK
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- QR Scanner Library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jsqr/1.4.0/jsQR.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js"></script>
    
    <script>
        <?php if ($action == 'scanner'): ?>
        // QR Scanner functionality
        // QR Scanner functionality
let video = document.getElementById('video');
let canvas = document.getElementById('canvas');
let context = canvas.getContext('2d');
let scanning = false;
let stream = null;

document.getElementById('startCamera').addEventListener('click', startCamera);
document.getElementById('stopCamera').addEventListener('click', stopCamera);

async function startCamera() {
    try {
        const constraints = {
            video: { 
                facingMode: { ideal: 'environment' },
                width: { ideal: 1280 },
                height: { ideal: 720 }
            }
        };
        
        stream = await navigator.mediaDevices.getUserMedia(constraints);
        video.srcObject = stream;
        video.play();
        
        document.getElementById('startCamera').disabled = true;
        document.getElementById('stopCamera').disabled = false;
        document.getElementById('scanning-indicator').classList.remove('hidden');
        scanning = true;
        
        video.addEventListener('loadedmetadata', () => {
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            scanForQR();
        });
        
    } catch (err) {
        console.error('Camera error:', err);
        alert('Unable to access camera');
    }
}

function stopCamera() {
    scanning = false;
    if (stream) {
        stream.getTracks().forEach(track => track.stop());
    }
    video.srcObject = null;
    
    document.getElementById('startCamera').disabled = false;
    document.getElementById('stopCamera').disabled = true;
    document.getElementById('scanning-indicator').classList.add('hidden');
}

function scanForQR() {
    if (!scanning) return;
    
    if (video.readyState === video.HAVE_ENOUGH_DATA) {
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        context.drawImage(video, 0, 0, canvas.width, canvas.height);
        
        const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
        const code = jsQR(imageData.data, imageData.width, imageData.height);
        
        if (code) {
            processScannedVehicle(code.data);
            return;
        }
    }
    
    if (scanning) {
        requestAnimationFrame(scanForQR);
    }
}

function processScannedVehicle(qrData) {
    // Extract vehicle ID from QR code data
    const vehicleId = extractVehicleIdFromQR(qrData);
    if (vehicleId) {
        loadVehicleInfo(vehicleId);
    } else {
        alert('Invalid QR code format');
    }
}

function extractVehicleIdFromQR(qrData) {
    // QR code format: VEH-XXXXXXLICENSEPLATE or just VEHXXXXXX
    const match = qrData.match(/^(VEH[A-Z0-9]+)/i);
    return match ? match[1] : null;
}

function processManualEntry() {
    const input = document.getElementById('manual_vehicle_id').value.trim().toUpperCase();
    if (input) {
        loadVehicleInfo(input);
    }
}

async function loadVehicleInfo(vehicleId) {
    try {
        const response = await fetch(`api/get_vehicle.php?id=${encodeURIComponent(vehicleId)}`);
        const data = await response.json();
        
        if (data.success) {
            displayVehicleInfo(data.vehicle);
            document.getElementById('vehicle_id').value = data.vehicle.vehicle_id;
            document.getElementById('selected_vehicle').classList.remove('hidden');
            
            // Pre-fill driver information if available
            if (data.vehicle.driver_name) {
                document.getElementById('driver_name').value = data.vehicle.driver_name;
            }
            if (data.vehicle.driver_phone) {
                document.getElementById('driver_phone').value = data.vehicle.driver_phone;
            }
            if (data.vehicle.driver_license) {
                document.getElementById('driver_license').value = data.vehicle.driver_license;
            }
            
            // Enable form
            enableEntryForm();
        } else {
            alert('Vehicle not found: ' + data.message);
        }
    } catch (error) {
        console.error('Error loading vehicle:', error);
        alert('Error loading vehicle information');
    }
}

function displayVehicleInfo(vehicle) {
    const info = document.getElementById('vehicle_info');
    info.innerHTML = `
        <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
                <strong>License:</strong> ${escapeHtml(vehicle.license_plate)}
            </div>
            <div>
                <strong>Vehicle:</strong> ${escapeHtml(vehicle.make + ' ' + vehicle.model)}
            </div>
            <div>
                <strong>Owner:</strong> ${escapeHtml(vehicle.owner_name)}
            </div>
            <div>
                <strong>Type:</strong> ${escapeHtml(vehicle.type_name || 'N/A')}
            </div>
        </div>
    `;
}

function enableEntryForm() {
    document.getElementById('submit_btn').disabled = false;
}

// Entry form handlers
document.getElementById('checkin_btn').addEventListener('click', function() {
    selectAction('check_in');
});

document.getElementById('checkout_btn').addEventListener('click', function() {
    selectAction('check_out');
});

function selectAction(action) {
    document.getElementById('log_type').value = action;
    
    // Update button styles
    const checkinBtn = document.getElementById('checkin_btn');
    const checkoutBtn = document.getElementById('checkout_btn');
    
    checkinBtn.classList.remove('bg-green-500', 'text-white', 'border-green-500');
    checkoutBtn.classList.remove('bg-red-500', 'text-white', 'border-red-500');
    checkinBtn.classList.add('border-green-300', 'text-green-700');
    checkoutBtn.classList.add('border-red-300', 'text-red-700');
    
    if (action === 'check_in') {
        checkinBtn.classList.remove('border-green-300', 'text-green-700');
        checkinBtn.classList.add('bg-green-500', 'text-white', 'border-green-500');
    } else {
        checkoutBtn.classList.remove('border-red-300', 'text-red-700');
        checkoutBtn.classList.add('bg-red-500', 'text-white', 'border-red-500');
    }
    
    updateSubmitButton();
}

// Purpose selection handler
document.getElementById('entry_purpose').addEventListener('change', function() {
    const purpose = this.value;
    const deliveryDetails = document.getElementById('delivery_details');
    
    if (purpose === 'delivery' || purpose === 'pickup' || purpose === 'service') {
        deliveryDetails.classList.remove('hidden');
    } else {
        deliveryDetails.classList.add('hidden');
    }
    
    updateSubmitButton();
});

// Location selection handler
document.getElementById('location_id').addEventListener('change', async function() {
    const locationId = this.value;
    const gateSelect = document.getElementById('gate_id');
    
    // Clear gates
    gateSelect.innerHTML = '<option value="">Select Gate</option>';
    
    if (locationId) {
        try {
            const response = await fetch(`api/get_gates.php?location_id=${locationId}`);
            const data = await response.json();
            
            if (data.success) {
                data.gates.forEach(gate => {
                    const option = document.createElement('option');
                    option.value = gate.id;
                    option.textContent = gate.gate_name;
                    gateSelect.appendChild(option);
                });
            }
        } catch (error) {
            console.error('Error loading gates:', error);
        }
    }
    
    updateSubmitButton();
});

function updateSubmitButton() {
    const vehicleId = document.getElementById('vehicle_id').value;
    const locationId = document.getElementById('location_id').value;
    const logType = document.getElementById('log_type').value;
    const purpose = document.getElementById('entry_purpose').value;
    
    const isValid = vehicleId && locationId && logType && purpose;
    document.getElementById('submit_btn').disabled = !isValid;
}

// Form submission
document.getElementById('entryForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'process_entry');
    
    try {
        const response = await fetch('vehicles.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            showSuccessModal('Entry Processed', data.message);
            resetForm();
        } else {
            alert('Error: ' + data.message);
        }
    } catch (error) {
        console.error('Form submission error:', error);
        alert('Error processing entry');
    }
});

function resetForm() {
    document.getElementById('entryForm').reset();
    document.getElementById('vehicle_id').value = '';
    document.getElementById('log_type').value = '';
    document.getElementById('selected_vehicle').classList.add('hidden');
    document.getElementById('delivery_details').classList.add('hidden');
    document.getElementById('submit_btn').disabled = true;
    
    // Reset action buttons
    const checkinBtn = document.getElementById('checkin_btn');
    const checkoutBtn = document.getElementById('checkout_btn');
    checkinBtn.classList.remove('bg-green-500', 'text-white', 'border-green-500');
    checkoutBtn.classList.remove('bg-red-500', 'text-white', 'border-red-500');
    checkinBtn.classList.add('border-green-300', 'text-green-700');
    checkoutBtn.classList.add('border-red-300', 'text-red-700');
}

// QR Code generation for vehicle view
function generateVehicleQR(vehicleData) {
    const qr = qrcode(0, 'M');
    qr.addData(vehicleData);
    qr.make();
    
    const canvas = document.getElementById('qrcode');
    if (canvas) {
        const ctx = canvas.getContext('2d');
        const modules = qr.modules;
        const moduleCount = qr.getModuleCount();
        const cellSize = Math.floor(canvas.width / moduleCount);
        
        ctx.fillStyle = '#FFFFFF';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        
        ctx.fillStyle = '#000000';
        for (let row = 0; row < moduleCount; row++) {
            for (let col = 0; col < moduleCount; col++) {
                if (modules[row][col]) {
                    ctx.fillRect(col * cellSize, row * cellSize, cellSize, cellSize);
                }
            }
        }
    }
}

function downloadQRCode() {
    const canvas = document.getElementById('qrcode');
    if (canvas) {
        const link = document.createElement('a');
        link.download = 'vehicle-qr-code.png';
        link.href = canvas.toDataURL();
        link.click();
    }
}

// Quick entry functions for vehicle view
async function quickEntry(action) {
    const vehicleId = document.querySelector('[data-vehicle-id]')?.dataset.vehicleId;
    if (!vehicleId) return;
    
    const locationId = prompt('Enter Location ID:');
    if (!locationId) return;
    
    const purpose = prompt('Enter Purpose (delivery/pickup/service/visitor/staff):');
    if (!purpose) return;
    
    const formData = new FormData();
    formData.append('action', 'process_entry');
    formData.append('vehicle_id', vehicleId);
    formData.append('location_id', locationId);
    formData.append('log_type', action);
    formData.append('entry_purpose', purpose);
    formData.append('passenger_count', '1');
    
    try {
        const response = await fetch('vehicles.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            showSuccessModal('Quick Entry', data.message);
            setTimeout(() => location.reload(), 2000);
        } else {
            alert('Error: ' + data.message);
        }
    } catch (error) {
        console.error('Quick entry error:', error);
        alert('Error processing quick entry');
    }
}

// Modal functions
function showSuccessModal(title, message) {
    document.getElementById('success_title').textContent = title;
    document.getElementById('success_message').textContent = message;
    document.getElementById('successModal').classList.remove('hidden');
}

function closeModal() {
    document.getElementById('successModal').classList.add('hidden');
}

// Utility functions
function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, function(m) { return map[m]; });
}

// Auto-uppercase license plate input
document.addEventListener('DOMContentLoaded', function() {
    const licenseInput = document.getElementById('license_plate');
    if (licenseInput) {
        licenseInput.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
    }
    
    // Generate QR code for vehicle view if vehicle data exists
    if (typeof vehicleQRData !== 'undefined') {
        generateVehicleQR(vehicleQRData);
    }
    
    // Auto-scan on page load if camera permission is already granted
    if (document.getElementById('video')) {
        navigator.permissions.query({name: 'camera'}).then(function(result) {
            if (result.state === 'granted') {
                // Optional: Auto-start camera if permission already granted
                // startCamera();
            }
        });
    }
});

// Handle form validation on real-time
document.addEventListener('input', function(e) {
    if (e.target.form && e.target.form.id === 'entryForm') {
        updateSubmitButton();
    }
});

// Handle license plate formatting
function formatLicensePlate(input) {
    let value = input.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    
    // Kenya license plate format: KXX 123X
    if (value.length <= 3) {
        input.value = value;
    } else if (value.length <= 6) {
        input.value = value.slice(0, 3) + ' ' + value.slice(3);
    } else {
        input.value = value.slice(0, 3) + ' ' + value.slice(3, 6) + value.slice(6, 7);
    }
}

// Vehicle search functionality
function searchVehicles() {
    const searchTerm = document.getElementById('vehicle_search')?.value.toLowerCase();
    const rows = document.querySelectorAll('tbody tr');
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
    });
}

// Export vehicle data
function exportVehicleData() {
    const data = [];
    const rows = document.querySelectorAll('tbody tr');
    
    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        const rowData = Array.from(cells).map(cell => cell.textContent.trim());
        data.push(rowData);
    });
    
    // Convert to CSV
    const csv = data.map(row => row.join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    
    const a = document.createElement('a');
    a.href = url;
    a.download = 'vehicles-' + new Date().toISOString().split('T')[0] + '.csv';
    a.click();
    
    window.URL.revokeObjectURL(url);
}

// Print functionality
function printVehicleInfo() {
    window.print();
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey || e.metaKey) {
        switch(e.key) {
            case 'k':
                e.preventDefault();
                document.getElementById('manual_vehicle_id')?.focus();
                break;
            case 'Enter':
                if (document.activeElement === document.getElementById('manual_vehicle_id')) {
                    e.preventDefault();
                    processManualEntry();
                }
                break;
        }
    }
});

// Touch/mobile optimizations
let touchStartX = 0;
let touchStartY = 0;

document.addEventListener('touchstart', function(e) {
    touchStartX = e.touches[0].clientX;
    touchStartY = e.touches[0].clientY;
}, {passive: true});

document.addEventListener('touchend', function(e) {
    const touchEndX = e.changedTouches[0].clientX;
    const touchEndY = e.changedTouches[0].clientY;
    
    const deltaX = touchEndX - touchStartX;
    const deltaY = touchEndY - touchStartY;
    
    // Swipe right on scanner to start camera
    if (Math.abs(deltaX) > Math.abs(deltaY) && deltaX > 100 && 
        document.getElementById('startCamera') && 
        !document.getElementById('startCamera').disabled) {
        startCamera();
    }
}, {passive: true});

// Service worker registration for offline capability
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('/sw.js').then(function(registration) {
            console.log('ServiceWorker registration successful');
        }, function(err) {
            console.log('ServiceWorker registration failed');
        });
    });
}

// Network status monitoring
window.addEventListener('online', function() {
    document.body.classList.remove('offline');
    showNotification('Connection restored', 'success');
});

window.addEventListener('offline', function() {
    document.body.classList.add('offline');
    showNotification('Working offline', 'warning');
});

function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `fixed top-4 right-4 p-4 rounded-lg z-50 ${
        type === 'success' ? 'bg-green-500' : 
        type === 'warning' ? 'bg-yellow-500' : 
        type === 'error' ? 'bg-red-500' : 'bg-blue-500'
    } text-white`;
    notification.textContent = message;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.remove();
    }, 3000);
}

// Initialize QR code generation if on vehicle view page
if (typeof vehicleId !== 'undefined' && typeof licensePlate !== 'undefined') {
    const qrData = vehicleId + licensePlate;
    generateVehicleQR(qrData);
}

// Auto-focus on manual entry input when scanner page loads
if (document.getElementById('manual_vehicle_id')) {
    document.getElementById('manual_vehicle_id').focus();
}

// Handle escape key to close modals
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
    }
});

// Initialize tooltips and help text
document.addEventListener('DOMContentLoaded', function() {
    // Add tooltips to form fields
    const tooltips = {
        'vehicle_id': 'Scan QR code or enter vehicle ID manually',
        'location_id': 'Select the location where the vehicle is entering/exiting',
        'entry_purpose': 'Choose the purpose of the visit',
        'expected_duration': 'Estimated time the vehicle will stay (in minutes)'
    };
    
    Object.keys(tooltips).forEach(id => {
        const element = document.getElementById(id);
        if (element) {
            element.title = tooltips[id];
        }
    });
});

// Handle camera permission requests gracefully
async function requestCameraPermission() {
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ video: true });
        stream.getTracks().forEach(track => track.stop());
        return true;
    } catch (err) {
        console.warn('Camera permission denied:', err);
        return false;
    }
}

// Periodic check for stuck camera streams
setInterval(() => {
    if (stream && scanning) {
        const tracks = stream.getTracks();
        const activeTracks = tracks.filter(track => track.readyState === 'live');
        if (activeTracks.length === 0) {
            console.warn('Camera stream lost, attempting restart');
            stopCamera();
        }
    }
}, 30000);

// Handle visibility changes to pause/resume scanning
document.addEventListener('visibilitychange', function() {
    if (document.hidden && scanning) {
        stopCamera();
    }
});

// Clean up resources when page unloads
window.addEventListener('beforeunload', function() {
    if (stream) {
        stream.getTracks().forEach(track => track.stop());
    }
});

// End of script
</script>
</body>
</html>