<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_vehicles') {
    header('Content-Type: application/json');
    
    $search = sanitizeInput($_GET['q'] ?? '');
    $results = [];
    
    if (strlen($search) >= 2) {
        // Search by last 4 digits of vehicle_id, license plate, make, model, or owner name
        $stmt = $db->prepare("
            SELECT vehicle_id, license_plate, make, model, owner_name, owner_company, qr_code 
            FROM vehicles 
            WHERE status = 'active' 
            AND (
                RIGHT(vehicle_id, 4) LIKE ? 
                OR license_plate LIKE ? 
                OR make LIKE ? 
                OR model LIKE ? 
                OR owner_name LIKE ?
                OR CONCAT(make, ' ', model) LIKE ?
            )
            ORDER BY license_plate 
            LIMIT 10
        ");
        
        $search_param = "%$search%";
        $stmt->execute([$search_param, $search_param, $search_param, $search_param, $search_param, $search_param]);
        $results = $stmt->fetchAll();
    }
    
    echo json_encode(['success' => true, 'vehicles' => $results]);
    exit;
}

$settings = getSettings($db);

// Get operator's locations
$stmt = $db->prepare("SELECT l.* FROM locations l JOIN operator_locations ol ON l.id = ol.location_id WHERE ol.operator_id = ? AND l.is_active = 1 ORDER BY ol.is_primary DESC, l.location_name");
$stmt->execute([$session['operator_id']]);
$operator_locations = $stmt->fetchAll();

$default_location_id = $operator_locations[0]['id'] ?? 1;
$selected_location_id = intval($_GET['location_id'] ?? $_POST['location_id'] ?? $default_location_id);

// Handle vehicle QR code scanning
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['vehicle_qr'])) {
    $vehicle_qr = sanitizeInput($_POST['vehicle_qr']);
    $location_id = intval($_POST['location_id']);
    $entry_purpose = sanitizeInput($_POST['entry_purpose'] ?? 'visitor');
    $driver_name = sanitizeInput($_POST['driver_name'] ?? '');
    $driver_phone = sanitizeInput($_POST['driver_phone'] ?? '');
    $driver_license = sanitizeInput($_POST['driver_license'] ?? '');
    $passenger_count = intval($_POST['passenger_count'] ?? 0);
    $cargo_description = sanitizeInput($_POST['cargo_description'] ?? '');
    $delivery_company = sanitizeInput($_POST['delivery_company'] ?? '');
    $delivery_reference = sanitizeInput($_POST['delivery_reference'] ?? '');
    $destination_department = sanitizeInput($_POST['destination_department'] ?? '');
    $contact_person = sanitizeInput($_POST['contact_person'] ?? '');
    $expected_duration = intval($_POST['expected_duration'] ?? 0);
    $notes = sanitizeInput($_POST['notes'] ?? '');
    
    // Find vehicle by QR code
    $stmt = $db->prepare("SELECT * FROM vehicles WHERE qr_code = ? AND status = 'active'");
    $stmt->execute([$vehicle_qr]);
    $vehicle = $stmt->fetch();
    
    if ($vehicle) {
        // Get last activity from vehicle_logs (not gate_logs)
        $stmt = $db->prepare("SELECT log_type FROM vehicle_logs WHERE vehicle_id = ? ORDER BY log_timestamp DESC LIMIT 1");
        $stmt->execute([$vehicle['vehicle_id']]);
        $last_log = $stmt->fetch();
        
        $next_action = (!$last_log || $last_log['log_type'] == 'check_out') ? 'check_in' : 'check_out';
        
        try {
            $db->beginTransaction();
            
            // Update driver info if provided (for check-in)
            if ($next_action === 'check_in' && ($driver_name || $driver_phone || $driver_license)) {
                $stmt = $db->prepare("UPDATE vehicles SET driver_name = COALESCE(NULLIF(?, ''), driver_name), driver_phone = COALESCE(NULLIF(?, ''), driver_phone), driver_license = COALESCE(NULLIF(?, ''), driver_license) WHERE vehicle_id = ?");
                $stmt->execute([$driver_name, $driver_phone, $driver_license, $vehicle['vehicle_id']]);
            }
            
            // Record the vehicle activity in vehicle_logs table
            $stmt = $db->prepare("INSERT INTO vehicle_logs (vehicle_id, log_type, location_id, entry_purpose, driver_name, driver_phone, driver_license, passenger_count, cargo_description, delivery_company, delivery_reference, destination_department, contact_person, expected_duration, operator_id, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $success = $stmt->execute([$vehicle['vehicle_id'], $next_action, $location_id, $entry_purpose, $driver_name ?: $vehicle['driver_name'], $driver_phone ?: $vehicle['driver_phone'], $driver_license ?: $vehicle['driver_license'], $passenger_count, $cargo_description, $delivery_company, $delivery_reference, $destination_department, $contact_person, $expected_duration > 0 ? $expected_duration : null, $session['operator_id'], $notes]);
            
            if ($success) {
                // Create notification
                $location_name = '';
                foreach ($operator_locations as $loc) {
                    if ($loc['id'] == $location_id) {
                        $location_name = $loc['location_name'];
                        break;
                    }
                }
                
                $action_text = $next_action === 'check_in' ? 'checked in' : 'checked out';
                createNotification($db, $next_action, ucfirst(str_replace('_', ' ', $next_action)), "Vehicle {$vehicle['license_plate']} has $action_text at $location_name", null, $session['operator_id']);
                
                logActivity($db, $session['operator_id'], 'vehicle_scan', "Vehicle QR scan $next_action for: {$vehicle['license_plate']}", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
                
                $db->commit();
                
                $scan_result = [
                    'success' => true,
                    'vehicle' => $vehicle,
                    'action' => $next_action,
                    'location' => $location_name,
                    'message' => ucfirst(str_replace('_', ' ', $next_action)) . ' successful'
                ];
            } else {
                throw new Exception('Failed to record vehicle activity');
            }
            
        } catch (Exception $e) {
            $db->rollBack();
            $scan_result = [
                'success' => false,
                'message' => 'Failed to process vehicle scan: ' . $e->getMessage()
            ];
        }
        
    } else {
        $scan_result = [
            'success' => false,
            'message' => 'Vehicle not found or inactive. Please register the vehicle first.'
        ];
    }
}

// Get departments for dropdown
$stmt = $db->query("SELECT department_name FROM departments WHERE is_active = 1 ORDER BY department_name");
$departments = $stmt->fetchAll();

// Get vehicle types for entry purpose
$stmt = $db->query("SELECT * FROM vehicle_types WHERE is_active = 1 ORDER BY type_name");
$vehicle_types = $stmt->fetchAll();

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management System'); ?> - Vehicle Scanner</title>
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
            .camera-container { height: 250px !important; }
            .scanner-frame { width: 200px !important; height: 200px !important; }
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
            border: 3px solid #10b981;
            border-radius: 12px;
            background: transparent;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .animate-pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        /* Additional styles for search results */
#search_results {
    max-width: calc(100% - 2rem);
}

.search-result-item {
    transition: background-color 0.15s ease;
}

.search-result-item:focus {
    outline: none;
    background-color: #f3f4f6;
}

@media (max-width: 640px) {
    #search_results {
        position: fixed;
        left: 1rem;
        right: 1rem;
        max-width: calc(100% - 2rem);
    }
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
                    <div class="h-8 w-8 sm:h-10 sm:w-10 bg-green-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-truck text-white text-sm sm:text-base"></i>
                    </div>
                    <div>
                        <h1 class="text-lg sm:text-xl font-semibold text-gray-900">Vehicle Scanner</h1>
                        <p class="text-xs sm:text-sm text-gray-500 hidden sm:block">Scan vehicle QR codes for check-in/out</p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <a href="manage-vehicles.php" class="text-blue-600 hover:text-blue-800 text-sm sm:text-base">
                        <i class="fas fa-car"></i>
                        <span class="ml-1 hidden sm:inline">Vehicles</span>
                    </a>
                    <a href="scanner.php" class="text-purple-600 hover:text-purple-800 text-sm sm:text-base">
                        <i class="fas fa-user"></i>
                        <span class="ml-1 hidden sm:inline">Visitors</span>
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
                    <i class="fas fa-map-marker-alt text-blue-600"></i>
                    <label for="location_selector" class="text-sm font-medium text-gray-700">Scanning Location:</label>
                    <select id="location_selector" onchange="changeLocation()" 
                            class="px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                        <?php foreach ($operator_locations as $location): ?>
                            <option value="<?php echo $location['id']; ?>" <?php echo $location['id'] == $selected_location_id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($location['location_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($scan_result)): ?>
            <!-- Scan Result -->
            <div class="mb-4 sm:mb-6 p-4 sm:p-6 rounded-xl border-2 <?php echo $scan_result['success'] ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200'; ?>">
                <div class="flex flex-col sm:flex-row items-start sm:items-center space-y-3 sm:space-y-0 sm:space-x-4">
                    <div class="flex-shrink-0">
                        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-full flex items-center justify-center <?php echo $scan_result['success'] ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600'; ?>">
                            <i class="fas <?php echo $scan_result['success'] ? 'fa-check' : 'fa-times'; ?> text-lg sm:text-xl"></i>
                        </div>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-base sm:text-lg font-semibold <?php echo $scan_result['success'] ? 'text-green-900' : 'text-red-900'; ?>">
                            <?php echo htmlspecialchars($scan_result['message']); ?>
                        </h3>
                        <?php if ($scan_result['success'] && isset($scan_result['vehicle'])): ?>
                            <div class="mt-2 text-sm sm:text-base <?php echo $scan_result['success'] ? 'text-green-700' : 'text-red-700'; ?>">
                                <p class="font-medium">
                                    <?php echo htmlspecialchars($scan_result['vehicle']['license_plate']); ?> - 
                                    <?php echo htmlspecialchars($scan_result['vehicle']['make'] . ' ' . $scan_result['vehicle']['model']); ?>
                                </p>
                                <p>Owner: <?php echo htmlspecialchars($scan_result['vehicle']['owner_name']); ?>
                                    <?php if ($scan_result['vehicle']['owner_company']): ?>
                                        (<?php echo htmlspecialchars($scan_result['vehicle']['owner_company']); ?>)
                                    <?php endif; ?>
                                </p>
                            </div>
                            
                            <div class="mt-3 p-3 bg-white rounded-lg border">
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 sm:gap-4 text-xs sm:text-sm">
                                    <div>
                                        <span class="font-medium text-gray-600">Action:</span>
                                        <div class="mt-1">
                                            <span class="px-2 py-1 text-xs rounded-full <?php echo $scan_result['action'] === 'check_in' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $scan_result['action'])); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div>
                                        <span class="font-medium text-gray-600">Time:</span>
                                        <div class="mt-1"><?php echo date('g:i A'); ?></div>
                                    </div>
                                    <div>
                                        <span class="font-medium text-gray-600">Location:</span>
                                        <div class="mt-1"><?php echo htmlspecialchars($scan_result['location'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div>
                                        <span class="font-medium text-gray-600">Vehicle ID:</span>
                                        <div class="mt-1"><?php echo htmlspecialchars($scan_result['vehicle']['vehicle_id']); ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Scanner Interface -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-8">
            <!-- Camera Scanner -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-4 sm:mb-6">
                    <i class="fas fa-camera mr-2"></i>Vehicle QR Scanner
                </h3>
                
                <div class="relative camera-container">
                    <video id="video" class="w-full h-48 sm:h-64 bg-gray-900 rounded-lg"></video>
                    <canvas id="canvas" class="hidden"></canvas>
                    
                    <div class="scanner-overlay">
                        <div class="scanner-frame"></div>
                    </div>
                    
                    <div id="scanning-indicator" class="absolute top-2 sm:top-4 right-2 sm:right-4 bg-green-500 text-white px-2 sm:px-3 py-1 rounded-full text-xs sm:text-sm font-medium hidden">
                        <i class="fas fa-circle animate-pulse mr-1"></i>Scanning...
                    </div>
                </div>
                
                <div class="mt-4 flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-4">
                    <button id="startCamera" class="flex-1 bg-green-600 hover:bg-green-700 text-white px-3 sm:px-4 py-2 rounded-lg font-medium transition-colors text-sm sm:text-base">
                        <i class="fas fa-play mr-2"></i>Start Scanner
                    </button>
                    <button id="stopCamera" class="flex-1 bg-gray-600 hover:bg-gray-700 text-white px-3 sm:px-4 py-2 rounded-lg font-medium transition-colors text-sm sm:text-base" disabled>
                        <i class="fas fa-stop mr-2"></i>Stop Scanner
                    </button>
                </div>
                
                <div class="mt-4 text-xs sm:text-sm text-gray-600">
                    <div class="flex items-center mb-2">
                        <i class="fas fa-info-circle mr-2 text-green-500"></i>
                        <span>Position vehicle QR code within the green frame</span>
                    </div>
                    <div class="flex items-center">
                        <i class="fas fa-lightbulb mr-2 text-yellow-500"></i>
                        <span>Ensure good lighting for best scanning results</span>
                    </div>
                </div>
            </div>

            <!-- Vehicle Information Form -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-4 sm:mb-6">
                    <i class="fas fa-clipboard-list mr-2"></i>Vehicle Information
                </h3>
                
                <form id="vehicleScanForm" method="POST" class="space-y-4">
                    <input type="hidden" id="vehicle_qr" name="vehicle_qr">
                    <input type="hidden" name="location_id" value="<?php echo $selected_location_id; ?>">
                    
                    <div class="space-y-3">
    <label for="vehicle_search" class="block text-sm font-medium text-gray-700">
        Search Vehicle
    </label>
    
    <div class="relative">
        <input type="text" 
               id="vehicle_search" 
               autocomplete="off"
               placeholder="Enter: Last 4 digits, License plate, Make/Model, or Owner name" 
               class="w-full px-3 py-2 pr-10 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 text-sm sm:text-base">
        <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
            <i class="fas fa-search text-gray-400"></i>
        </div>
    </div>
    
    <!-- Search Results Dropdown -->
    <div id="search_results" class="hidden absolute z-10 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-64 overflow-y-auto">
        <!-- Results will be populated here -->
    </div>
    
    <!-- Selected Vehicle Display -->
    <div id="selected_vehicle_info" class="hidden p-3 bg-green-50 border border-green-200 rounded-lg">
        <div class="flex items-center justify-between">
            <div class="flex-1">
                <div class="flex items-center space-x-2">
                    <i class="fas fa-check-circle text-green-600"></i>
                    <span class="font-medium text-green-900" id="selected_vehicle_text"></span>
                </div>
                <p class="text-xs text-green-700 mt-1" id="selected_vehicle_details"></p>
            </div>
            <button type="button" onclick="clearVehicleSelection()" class="text-red-600 hover:text-red-800">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>
    
    <div class="text-xs text-gray-500 space-y-1">
        <p><i class="fas fa-info-circle mr-1"></i>Quick tips:</p>
        <ul class="list-disc list-inside ml-4 space-y-0.5">
            <li>Type last 4 digits of vehicle ID (e.g., "1234")</li>
            <li>Enter license plate (e.g., "KDA 001A")</li>
            <li>Search by make/model (e.g., "Toyota Camry")</li>
            <li>Search by owner name</li>
        </ul>
    </div>
</div>
                    
                    <div>
                        <label for="entry_purpose" class="block text-sm font-medium text-gray-700">Purpose of Visit *</label>
                        <select id="entry_purpose" name="entry_purpose" required onchange="togglePurposeFields()"
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 text-sm sm:text-base">
                            <option value="visitor">Visitor</option>
                            <option value="delivery">Delivery</option>
                            <option value="pickup">Pickup</option>
                            <option value="service">Service</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="staff">Staff</option>
                        </select>
                    </div>

                    <!-- Delivery/Service Specific Fields -->
                    <div id="delivery_fields" class="hidden space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="delivery_company" class="block text-sm font-medium text-gray-700">Company</label>
                                <input type="text" id="delivery_company" name="delivery_company" 
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 text-sm sm:text-base" 
                                       placeholder="Delivery company name">
                            </div>
                            <div>
                                <label for="delivery_reference" class="block text-sm font-medium text-gray-700">Reference Number</label>
                                <input type="text" id="delivery_reference" name="delivery_reference" 
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 text-sm sm:text-base" 
                                       placeholder="Tracking/Reference #">
                            </div>
                        </div>
                        
                        <div>
                            <label for="cargo_description" class="block text-sm font-medium text-gray-700">Cargo Description</label>
                            <textarea id="cargo_description" name="cargo_description" rows="2"
                                      class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 text-sm sm:text-base" 
                                      placeholder="Describe items being delivered/picked up"></textarea>
                        </div>
                    </div>
                    
                    <!-- Driver Information -->
                    <div class="border-t border-gray-200 pt-4">
                        <h4 class="text-sm font-medium text-gray-900 mb-3">Driver Information (Optional)</h4>
                        <div class="space-y-3">
                            <div>
                                <label for="driver_name" class="block text-sm font-medium text-gray-700">Driver Name</label>
                                <input type="text" id="driver_name" name="driver_name" 
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 text-sm sm:text-base" 
                                       placeholder="Driver's full name">
                            </div>
                            
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label for="driver_phone" class="block text-sm font-medium text-gray-700">Driver Phone</label>
                                    <input type="tel" id="driver_phone" name="driver_phone" 
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 text-sm sm:text-base" 
                                           placeholder="+254700000000">
                                </div>
                                <div>
                                    <label for="driver_license" class="block text-sm font-medium text-gray-700">License Number</label>
                                    <input type="text" id="driver_license" name="driver_license" 
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 text-sm sm:text-base" 
                                           placeholder="License number">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Additional Information -->
                    <div class="border-t border-gray-200 pt-4 space-y-3">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label for="passenger_count" class="block text-sm font-medium text-gray-700">Passenger Count</label>
                                <input type="number" id="passenger_count" name="passenger_count" min="0" max="50" value="1"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 text-sm sm:text-base">
                            </div>
                            
                            <div>
                                <label for="expected_duration" class="block text-sm font-medium text-gray-700">Expected Duration (minutes)</label>
                                <input type="number" id="expected_duration" name="expected_duration" min="5" max="1440"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 text-sm sm:text-base" 
                                       placeholder="Optional">
                            </div>
                        </div>
                        
                        <div>
                            <label for="destination_department" class="block text-sm font-medium text-gray-700">Destination Department</label>
                            <select id="destination_department" name="destination_department" 
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 text-sm sm:text-base">
                                <option value="">Select department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept['department_name']); ?>">
                                        <?php echo htmlspecialchars($dept['department_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="contact_person" class="block text-sm font-medium text-gray-700">Contact Person</label>
                            <input type="text" id="contact_person" name="contact_person" 
                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 text-sm sm:text-base" 
                                   placeholder="Person to contact">
                        </div>
                        
                        <div>
                            <label for="notes" class="block text-sm font-medium text-gray-700">Notes</label>
                            <textarea id="notes" name="notes" rows="2"
                                      class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 text-sm sm:text-base" 
                                      placeholder="Additional notes..."></textarea>
                        </div>
                    </div>
                    
                    <button type="button" id="processManualQR" class="w-full bg-green-600 hover:bg-green-700 text-white px-4 py-3 rounded-lg font-medium transition-colors text-sm sm:text-base">
                        <i class="fas fa-check mr-2"></i>Process Vehicle Entry
                    </button>
                </form>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="mt-6 sm:mt-8 grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6">
            <a href="manage-vehicles.php?action=register" class="bg-blue-600 hover:bg-blue-700 text-white p-4 rounded-xl text-center transition-colors">
                <i class="fas fa-plus text-xl sm:text-2xl mb-2"></i>
                <h4 class="font-semibold text-sm sm:text-base">Register Vehicle</h4>
                <p class="text-xs sm:text-sm opacity-90">Add new vehicle</p>
            </a>
            
            <a href="delivery-tracking.php?location_id=<?php echo $selected_location_id; ?>" class="bg-orange-600 hover:bg-orange-700 text-white p-4 rounded-xl text-center transition-colors">
                <i class="fas fa-truck text-xl sm:text-2xl mb-2"></i>
                <h4 class="font-semibold text-sm sm:text-base">Delivery Tracking</h4>
                <p class="text-xs sm:text-sm opacity-90">Track deliveries</p>
            </a>
            
            <a href="vehicle-dashboard.php?location_id=<?php echo $selected_location_id; ?>" class="bg-purple-600 hover:bg-purple-700 text-white p-4 rounded-xl text-center transition-colors">
                <i class="fas fa-tachometer-alt text-xl sm:text-2xl mb-2"></i>
                <h4 class="font-semibold text-sm sm:text-base">Vehicle Dashboard</h4>
                <p class="text-xs sm:text-sm opacity-90">Current status</p>
            </a>
        </div>
    </div>

    <!-- QR Scanner Library -->
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
    
    <script>
        let video = document.getElementById('video');
        let canvas = document.getElementById('canvas');
        let context = canvas.getContext('2d');
        let scanning = false;
        let stream = null;

        const startCameraBtn = document.getElementById('startCamera');
        const stopCameraBtn = document.getElementById('stopCamera');
        const scanningIndicator = document.getElementById('scanning-indicator');

        startCameraBtn.addEventListener('click', startCamera);
        stopCameraBtn.addEventListener('click', stopCamera);

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
                
                startCameraBtn.disabled = true;
                stopCameraBtn.disabled = false;
                scanningIndicator.classList.remove('hidden');
                scanning = true;
                
                video.addEventListener('loadedmetadata', () => {
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                    scanForQR();
                });
                
                showNotification('Vehicle scanner started', 'success');
                
            } catch (err) {
                console.error('Error accessing camera:', err);
                showNotification('Unable to access camera. Please check permissions.', 'error');
            }
        }

        function stopCamera() {
            scanning = false;
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
            }
            video.srcObject = null;
            
            startCameraBtn.disabled = false;
            stopCameraBtn.disabled = true;
            scanningIndicator.classList.add('hidden');
            
            showNotification('Scanner stopped', 'info');
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
                    processVehicleQR(code.data);
                    return;
                }
            }
            
            requestAnimationFrame(scanForQR);
        }

        function processVehicleQR(qrData) {
            stopCamera();
            document.getElementById('vehicle_qr').value = qrData;
            document.getElementById('manual_qr').value = qrData;
            
            showNotification('Vehicle QR detected! Processing...', 'success');
            
            // Auto-submit the form
            setTimeout(() => {
                document.getElementById('vehicleScanForm').submit();
            }, 500);
        }

        // Manual QR processing
        document.getElementById('processManualQR').addEventListener('click', function() {
            const manualQR = document.getElementById('manual_qr').value.trim();
            if (manualQR) {
                document.getElementById('vehicle_qr').value = manualQR;
                
                const button = this;
                const originalText = button.innerHTML;
                button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
                button.disabled = true;
                
                setTimeout(() => {
                    document.getElementById('vehicleScanForm').submit();
                }, 500);
            } else {
                showNotification('Please enter a vehicle QR code or scan using the camera', 'warning');
            }
        });

        // Toggle purpose-specific fields
        function togglePurposeFields() {
            const purpose = document.getElementById('entry_purpose').value;
            const deliveryFields = document.getElementById('delivery_fields');
            
            if (purpose === 'delivery' || purpose === 'pickup' || purpose === 'service') {
                deliveryFields.classList.remove('hidden');
            } else {
                deliveryFields.classList.add('hidden');
            }
        }

        // Location change handler
        function changeLocation() {
            const locationId = document.getElementById('location_selector').value;
            window.location.href = `vehicle-scanner.php?location_id=${locationId}`;
        }

        // Phone number formatting
        document.addEventListener('DOMContentLoaded', function() {
            const phoneInputs = document.querySelectorAll('input[type="tel"]');
            phoneInputs.forEach(input => {
                input.addEventListener('input', function() {
                    this.value = this.value.replace(/[^\d+\s-()]/g, '');
                });
            });

            // Auto-focus on manual input when clicked
            document.getElementById('manual_qr').addEventListener('focus', function() {
                if (scanning) {
                    stopCamera();
                }
            });

            // Initialize purpose fields
            togglePurposeFields();
        });

        // Show notification function
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
            
            // Animate in
            setTimeout(() => {
                notification.classList.remove('translate-x-full', 'opacity-0');
            }, 100);
            
            // Remove after 3 seconds
            setTimeout(() => {
                notification.classList.add('translate-x-full', 'opacity-0');
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'F1') {
                e.preventDefault();
                startCamera();
            } else if (e.key === 'Escape') {
                stopCamera();
            } else if (e.key === 'Enter' && e.target.id === 'manual_qr') {
                e.preventDefault();
                document.getElementById('processManualQR').click();
            }
        });

        // Handle device orientation change
        window.addEventListener('orientationchange', function() {
            if (scanning) {
                setTimeout(() => {
                    stopCamera();
                    setTimeout(startCamera, 500);
                }, 500);
            }
        });

        // Handle page visibility change
        document.addEventListener('visibilitychange', function() {
            if (document.hidden && scanning) {
                stopCamera();
            }
        });

        // Mobile touch gestures for camera controls
        let touchStartY = 0;
        if (video) {
            video.addEventListener('touchstart', function(e) {
                touchStartY = e.touches[0].clientY;
            });

            video.addEventListener('touchend', function(e) {
                const touchEndY = e.changedTouches[0].clientY;
                const diff = touchStartY - touchEndY;
                
                if (Math.abs(diff) > 50) {
                    if (diff > 0 && !scanning) {
                        startCamera();
                    } else if (diff < 0 && scanning) {
                        stopCamera();
                    }
                }
            });
        }

        // Form submission validation
        document.getElementById('vehicleScanForm').addEventListener('submit', function(e) {
            if (!document.getElementById('vehicle_qr').value) {
                e.preventDefault();
                showNotification('Please scan a vehicle QR code or enter one manually', 'warning');
                return;
            }

            const purpose = document.getElementById('entry_purpose').value;
            if ((purpose === 'delivery' || purpose === 'pickup') && !document.getElementById('delivery_company').value) {
                e.preventDefault();
                showNotification('Please enter the delivery company name', 'warning');
                return;
            }
        });

        // Auto-start camera on page load for mobile devices
        if (window.innerWidth <= 768) {
            window.addEventListener('load', function() {
                setTimeout(() => {
                    if (confirm('Start vehicle scanner automatically?')) {
                        startCamera();
                    }
                }, 1000);
            });
        }

        // Enhanced form validation with real-time feedback
        const formFields = document.querySelectorAll('#vehicleScanForm input, #vehicleScanForm select, #vehicleScanForm textarea');
        formFields.forEach(field => {
            field.addEventListener('blur', function() {
                validateField(this);
            });
        });

        function validateField(field) {
            let isValid = true;
            let message = '';

            // Reset previous validation state
            field.classList.remove('border-red-500', 'border-green-500');
            
            if (field.hasAttribute('required') && !field.value.trim()) {
                isValid = false;
                message = 'This field is required';
            } else if (field.type === 'tel' && field.value && !validatePhone(field.value)) {
                isValid = false;
                message = 'Please enter a valid phone number';
            } else if (field.type === 'number' && field.value) {
                const min = parseInt(field.getAttribute('min') || 0);
                const max = parseInt(field.getAttribute('max') || Number.MAX_VALUE);
                const value = parseInt(field.value);
                
                if (value < min || value > max) {
                    isValid = false;
                    message = `Value must be between ${min} and ${max}`;
                }
            }

            // Apply validation styling
            if (field.value.trim()) {
                field.classList.add(isValid ? 'border-green-500' : 'border-red-500');
            }

            // Show/hide validation message
            const existingError = field.parentNode.querySelector('.validation-error');
            if (existingError) {
                existingError.remove();
            }

            if (!isValid && message) {
                const errorElement = document.createElement('div');
                errorElement.className = 'validation-error text-red-600 text-xs mt-1';
                errorElement.textContent = message;
                field.parentNode.appendChild(errorElement);
            }

            return isValid;
        }

        function validatePhone(phone) {
            return /^[+]?[\d\s\-()]{10,15}$/.test(phone);
        }

        // Quick entry presets for common scenarios
        function applyQuickPreset(preset) {
            const presets = {
                delivery: {
                    entry_purpose: 'delivery',
                    passenger_count: 1,
                    expected_duration: 30
                },
                service: {
                    entry_purpose: 'service',
                    passenger_count: 1,
                    expected_duration: 120
                },
                visitor: {
                    entry_purpose: 'visitor',
                    passenger_count: 2,
                    expected_duration: 60
                }
            };

            if (presets[preset]) {
                Object.keys(presets[preset]).forEach(key => {
                    const field = document.getElementById(key);
                    if (field) {
                        field.value = presets[preset][key];
                        if (key === 'entry_purpose') {
                            togglePurposeFields();
                        }
                    }
                });
                showNotification(`Applied ${preset} preset`, 'success');
            }
        }

        // Add quick preset buttons
        const quickPresets = document.createElement('div');
        quickPresets.className = 'mt-4 flex space-x-2 text-xs';
        quickPresets.innerHTML = `
            <button type="button" onclick="applyQuickPreset('delivery')" class="px-2 py-1 bg-orange-100 text-orange-800 rounded">Delivery</button>
            <button type="button" onclick="applyQuickPreset('service')" class="px-2 py-1 bg-blue-100 text-blue-800 rounded">Service</button>
            <button type="button" onclick="applyQuickPreset('visitor')" class="px-2 py-1 bg-green-100 text-green-800 rounded">Visitor</button>
        `;
        
        // Insert presets after entry purpose field
        const entryPurposeField = document.getElementById('entry_purpose').parentNode;
        entryPurposeField.appendChild(quickPresets);
        
        
        
        
        let searchTimeout;
let currentSearchResults = [];

const vehicleSearchInput = document.getElementById('vehicle_search');
const searchResultsDiv = document.getElementById('search_results');
const selectedVehicleInfo = document.getElementById('selected_vehicle_info');
const vehicleQRInput = document.getElementById('vehicle_qr');

vehicleSearchInput.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const query = this.value.trim();
    
    if (query.length < 2) {
        searchResultsDiv.classList.add('hidden');
        searchResultsDiv.innerHTML = '';
        return;
    }
    
    searchTimeout = setTimeout(() => {
        searchVehicles(query);
    }, 300);
});

async function searchVehicles(query) {
    try {
        const response = await fetch(`vehicle-scanner.php?ajax=search_vehicles&q=${encodeURIComponent(query)}`);
        const data = await response.json();
        
        if (data.success && data.vehicles.length > 0) {
            currentSearchResults = data.vehicles;
            displaySearchResults(data.vehicles);
        } else {
            searchResultsDiv.innerHTML = `
                <div class="p-4 text-center text-gray-500 text-sm">
                    <i class="fas fa-search mb-2"></i>
                    <p>No vehicles found</p>
                    <a href="manage-vehicles.php?action=register" class="text-blue-600 hover:text-blue-800 text-xs mt-1 block">
                        Register new vehicle
                    </a>
                </div>
            `;
            searchResultsDiv.classList.remove('hidden');
        }
    } catch (error) {
        console.error('Search error:', error);
        showNotification('Error searching vehicles', 'error');
    }
}

function displaySearchResults(vehicles) {
    const resultsHTML = vehicles.map((vehicle, index) => `
        <div class="search-result-item p-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0" 
             onclick="selectVehicle(${index})"
             data-index="${index}">
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <div class="flex items-center space-x-2">
                        <span class="font-semibold text-gray-900">${escapeHtml(vehicle.license_plate)}</span>
                        <span class="px-2 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full">
                            ${escapeHtml(vehicle.vehicle_id.slice(-4))}
                        </span>
                    </div>
                    <p class="text-sm text-gray-600 mt-0.5">
                        ${escapeHtml(vehicle.make)} ${escapeHtml(vehicle.model)}
                    </p>
                    <p class="text-xs text-gray-500">
                        Owner: ${escapeHtml(vehicle.owner_name)}
                        ${vehicle.owner_company ? `(${escapeHtml(vehicle.owner_company)})` : ''}
                    </p>
                </div>
                <div class="text-green-600">
                    <i class="fas fa-chevron-right"></i>
                </div>
            </div>
        </div>
    `).join('');
    
    searchResultsDiv.innerHTML = resultsHTML;
    searchResultsDiv.classList.remove('hidden');
}

function selectVehicle(index) {
    const vehicle = currentSearchResults[index];
    
    // Set the QR code value
    vehicleQRInput.value = vehicle.qr_code;
    
    // Update the display
    document.getElementById('selected_vehicle_text').textContent = 
        `${vehicle.license_plate} - ${vehicle.make} ${vehicle.model}`;
    document.getElementById('selected_vehicle_details').textContent = 
        `Owner: ${vehicle.owner_name}${vehicle.owner_company ? ' (' + vehicle.owner_company + ')' : ''} • ID: ${vehicle.vehicle_id}`;
    
    // Show selected vehicle info
    selectedVehicleInfo.classList.remove('hidden');
    
    // Hide search results
    searchResultsDiv.classList.add('hidden');
    
    // Clear search input
    vehicleSearchInput.value = '';
    
    // Enable the process button
    document.getElementById('processManualQR').disabled = false;
    
    showNotification(`Selected: ${vehicle.license_plate}`, 'success');
}

function clearVehicleSelection() {
    vehicleQRInput.value = '';
    selectedVehicleInfo.classList.add('hidden');
    vehicleSearchInput.value = '';
    vehicleSearchInput.focus();
    document.getElementById('processManualQR').disabled = false;
}

// Close search results when clicking outside
document.addEventListener('click', function(event) {
    if (!vehicleSearchInput.contains(event.target) && !searchResultsDiv.contains(event.target)) {
        searchResultsDiv.classList.add('hidden');
    }
});

// Keyboard navigation for search results
vehicleSearchInput.addEventListener('keydown', function(e) {
    const items = document.querySelectorAll('.search-result-item');
    
    if (e.key === 'ArrowDown' && items.length > 0) {
        e.preventDefault();
        items[0].focus();
        items[0].classList.add('bg-gray-100');
    } else if (e.key === 'Escape') {
        searchResultsDiv.classList.add('hidden');
    }
});

document.addEventListener('keydown', function(e) {
    const activeItem = document.activeElement;
    if (activeItem && activeItem.classList.contains('search-result-item')) {
        const items = Array.from(document.querySelectorAll('.search-result-item'));
        const currentIndex = items.indexOf(activeItem);
        
        if (e.key === 'ArrowDown' && currentIndex < items.length - 1) {
            e.preventDefault();
            items[currentIndex].classList.remove('bg-gray-100');
            items[currentIndex + 1].focus();
            items[currentIndex + 1].classList.add('bg-gray-100');
        } else if (e.key === 'ArrowUp' && currentIndex > 0) {
            e.preventDefault();
            items[currentIndex].classList.remove('bg-gray-100');
            items[currentIndex - 1].focus();
            items[currentIndex - 1].classList.add('bg-gray-100');
        } else if (e.key === 'Enter') {
            e.preventDefault();
            activeItem.click();
        } else if (e.key === 'Escape') {
            searchResultsDiv.classList.add('hidden');
            vehicleSearchInput.focus();
        }
    }
});

// Update processManualQR button to use the selected vehicle
document.getElementById('processManualQR').addEventListener('click', function() {
    const qrValue = vehicleQRInput.value.trim();
    
    if (!qrValue) {
        showNotification('Please search and select a vehicle first', 'warning');
        vehicleSearchInput.focus();
        return;
    }
    
    // Validate purpose
    const purpose = document.getElementById('entry_purpose').value;
    if ((purpose === 'delivery' || purpose === 'pickup') && !document.getElementById('delivery_company').value.trim()) {
        showNotification('Please enter the delivery company name', 'warning');
        document.getElementById('delivery_company').focus();
        return;
    }
    
    const button = this;
    const originalText = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
    button.disabled = true;
    
    setTimeout(() => {
        document.getElementById('vehicleScanForm').submit();
    }, 500);
});

// Helper function
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}

// Update the QR scanner to also populate the vehicle selection
function processVehicleQR(qrData) {
    stopCamera();
    vehicleQRInput.value = qrData;
    
    // Try to fetch vehicle details for display
    fetch(`vehicle-scanner.php?ajax=search_vehicles&q=${qrData.slice(-4)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.vehicles.length > 0) {
                const vehicle = data.vehicles[0];
                document.getElementById('selected_vehicle_text').textContent = 
                    `${vehicle.license_plate} - ${vehicle.make} ${vehicle.model}`;
                document.getElementById('selected_vehicle_details').textContent = 
                    `Owner: ${vehicle.owner_name}${vehicle.owner_company ? ' (' + vehicle.owner_company + ')' : ''} • ID: ${vehicle.vehicle_id}`;
                selectedVehicleInfo.classList.remove('hidden');
            }
        });
    
    showNotification('Vehicle QR detected! Processing...', 'success');
    
    setTimeout(() => {
        document.getElementById('vehicleScanForm').submit();
    }, 500);
}

// Focus search input on page load
window.addEventListener('load', function() {
    if (!vehicleQRInput.value) {
        vehicleSearchInput.focus();
    }
});
    </script>
</body>
</html>
