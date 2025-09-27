<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);
$settings = getSettings($db);

// Debug: Check if session is working
if (!$session) {
    error_log("Session check failed in operators.php");
    setMessage('Session expired. Please login again.', 'error');
    header('Location: login.php');
    exit;
}

// Only admin can access operator management
if ($session['role'] !== 'admin') {
    error_log("Access denied in operators.php - Role: " . ($session['role'] ?? 'none'));
    setMessage('Access denied. Admin privileges required.', 'error');
    header('Location: dashboard.php');
    exit;
}

$action = $_GET['action'] ?? 'list';

// Debug: Log the action
error_log("Operators.php - Action: " . $action . " - User: " . ($session['operator_name'] ?? 'unknown'));

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $post_action = $_POST['form_action'] ?? $action;
    
    if ($post_action == 'create') {
        // Handle operator creation
        $operator_name = sanitizeInput($_POST['operator_name']);
        $operator_code = strtoupper(sanitizeInput($_POST['operator_code']));
        $email = sanitizeInput($_POST['email']);
        $phone = sanitizeInput($_POST['phone']);
        $department = sanitizeInput($_POST['department']);
        $shift_start = sanitizeInput($_POST['shift_start']);
        $shift_end = sanitizeInput($_POST['shift_end']);
        $password = $_POST['password'];
        $role = sanitizeInput($_POST['role']);
        $notes = sanitizeInput($_POST['notes']);
        $permissions = $_POST['permissions'] ?? [];
        
        $errors = [];
        if (empty($operator_name)) $errors[] = 'Operator name is required';
        if (empty($operator_code)) $errors[] = 'Operator code is required';
        if (empty($password)) $errors[] = 'Password is required';
        if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters';
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format';
        if (!empty($phone) && !validatePhone($phone)) $errors[] = 'Invalid phone format';
        
        // Check if operator code already exists
        $stmt = $db->prepare("SELECT id FROM gate_operators WHERE operator_code = ?");
        $stmt->execute([$operator_code]);
        if ($stmt->fetch()) {
            $errors[] = 'Operator code already exists';
        }
        
        // Check if email already exists (if provided)
        if (!empty($email)) {
            $stmt = $db->prepare("SELECT id FROM gate_operators WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = 'Email address already exists';
            }
        }
        
        if (empty($errors)) {
            try {
                $db->beginTransaction();
                
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $db->prepare("INSERT INTO gate_operators (operator_name, operator_code, email, phone, department, shift_start, shift_end, password_hash, role, notes, is_active, password_changed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())");
                $stmt->execute([$operator_name, $operator_code, $email, $phone, $department, $shift_start, $shift_end, $password_hash, $role, $notes]);
                
                $new_operator_id = $db->lastInsertId();
                
                // Add permissions
                if (!empty($permissions)) {
                    $stmt = $db->prepare("INSERT INTO operator_permissions (operator_id, permission_name, granted_by) VALUES (?, ?, ?)");
                    foreach ($permissions as $permission) {
                        $stmt->execute([$new_operator_id, $permission, $session['operator_id']]);
                    }
                }
                
                $db->commit();
                
                logActivity($db, $session['operator_id'], 'operator_create', "Created operator: $operator_name ($operator_code)", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
                setMessage("Operator $operator_name created successfully", 'success');
                header('Location: operators.php');
                exit;
                
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Operator creation error: " . $e->getMessage());
                $errors[] = 'Failed to create operator: ' . $e->getMessage();
            }
        }
        
    } elseif ($post_action == 'update') {
        // Handle operator update
        $operator_id = intval($_POST['operator_id']);
        $operator_name = sanitizeInput($_POST['operator_name']);
        $operator_code = strtoupper(sanitizeInput($_POST['operator_code']));
        $email = sanitizeInput($_POST['email']);
        $phone = sanitizeInput($_POST['phone']);
        $department = sanitizeInput($_POST['department']);
        $shift_start = sanitizeInput($_POST['shift_start']);
        $shift_end = sanitizeInput($_POST['shift_end']);
        $role = sanitizeInput($_POST['role']);
        $notes = sanitizeInput($_POST['notes']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $permissions = $_POST['permissions'] ?? [];
        
        $password = $_POST['password'];
        $update_password = !empty($password);
        
        $errors = [];
        if (empty($operator_name)) $errors[] = 'Operator name is required';
        if (empty($operator_code)) $errors[] = 'Operator code is required';
        if ($update_password && strlen($password) < 6) $errors[] = 'Password must be at least 6 characters';
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format';
        if (!empty($phone) && !validatePhone($phone)) $errors[] = 'Invalid phone format';
        
        // Check if operator code exists for other operators
        $stmt = $db->prepare("SELECT id FROM gate_operators WHERE operator_code = ? AND id != ?");
        $stmt->execute([$operator_code, $operator_id]);
        if ($stmt->fetch()) {
            $errors[] = 'Operator code already exists';
        }
        
        // Check if email exists for other operators (if provided)
        if (!empty($email)) {
            $stmt = $db->prepare("SELECT id FROM gate_operators WHERE email = ? AND id != ?");
            $stmt->execute([$email, $operator_id]);
            if ($stmt->fetch()) {
                $errors[] = 'Email address already exists';
            }
        }
        
        if (empty($errors)) {
            try {
                $db->beginTransaction();
                
                if ($update_password) {
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("UPDATE gate_operators SET operator_name = ?, operator_code = ?, email = ?, phone = ?, department = ?, shift_start = ?, shift_end = ?, password_hash = ?, role = ?, notes = ?, is_active = ?, password_changed_at = NOW() WHERE id = ?");
                    $result = $stmt->execute([$operator_name, $operator_code, $email, $phone, $department, $shift_start, $shift_end, $password_hash, $role, $notes, $is_active, $operator_id]);
                } else {
                    $stmt = $db->prepare("UPDATE gate_operators SET operator_name = ?, operator_code = ?, email = ?, phone = ?, department = ?, shift_start = ?, shift_end = ?, role = ?, notes = ?, is_active = ? WHERE id = ?");
                    $result = $stmt->execute([$operator_name, $operator_code, $email, $phone, $department, $shift_start, $shift_end, $role, $notes, $is_active, $operator_id]);
                }
                
                // Update permissions
                $stmt = $db->prepare("DELETE FROM operator_permissions WHERE operator_id = ?");
                $stmt->execute([$operator_id]);
                
                if (!empty($permissions)) {
                    $stmt = $db->prepare("INSERT INTO operator_permissions (operator_id, permission_name, granted_by) VALUES (?, ?, ?)");
                    foreach ($permissions as $permission) {
                        $stmt->execute([$operator_id, $permission, $session['operator_id']]);
                    }
                }
                
                $db->commit();
                
                if ($result) {
                    logActivity($db, $session['operator_id'], 'operator_update', "Updated operator: $operator_name ($operator_code)", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
                    setMessage("Operator $operator_name updated successfully", 'success');
                } else {
                    setMessage('Failed to update operator', 'error');
                }
                
                header('Location: operators.php');
                exit;
                
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Operator update error: " . $e->getMessage());
                $errors[] = 'Failed to update operator: ' . $e->getMessage();
            }
        }
        
    } elseif ($post_action == 'delete') {
        // Handle operator deletion
        $operator_id = intval($_POST['operator_id']);
        
        // Don't allow self-deletion
        if ($operator_id == $session['operator_id']) {
            setMessage('You cannot delete your own account', 'error');
        } else {
            // Check if this is the last admin
            $stmt = $db->prepare("SELECT COUNT(*) as admin_count FROM gate_operators WHERE role = 'admin' AND is_active = 1");
            $stmt->execute();
            $admin_count = $stmt->fetch()['admin_count'];
            
            $stmt = $db->prepare("SELECT role, operator_name FROM gate_operators WHERE id = ?");
            $stmt->execute([$operator_id]);
            $operator_to_delete = $stmt->fetch();
            
            if ($operator_to_delete['role'] == 'admin' && $admin_count <= 1) {
                setMessage('Cannot delete the last admin operator', 'error');
            } else {
                try {
                    $db->beginTransaction();
                    
                    // Soft delete by setting is_active to 0
                    $stmt = $db->prepare("UPDATE gate_operators SET is_active = 0 WHERE id = ?");
                    $stmt->execute([$operator_id]);
                    
                    // Remove permissions
                    $stmt = $db->prepare("DELETE FROM operator_permissions WHERE operator_id = ?");
                    $stmt->execute([$operator_id]);
                    
                    $db->commit();
                    
                    logActivity($db, $session['operator_id'], 'operator_delete', "Deactivated operator: " . $operator_to_delete['operator_name'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
                    setMessage('Operator deactivated successfully', 'success');
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    error_log("Operator deletion error: " . $e->getMessage());
                    setMessage('Failed to deactivate operator', 'error');
                }
            }
        }
        
        header('Location: operators.php');
        exit;
    }
}

// Get operator for edit
if ($action == 'edit') {
    $operator_id = intval($_GET['id'] ?? 0);
    $stmt = $db->prepare("SELECT * FROM gate_operators WHERE id = ?");
    $stmt->execute([$operator_id]);
    $edit_operator = $stmt->fetch();
    
    if (!$edit_operator) {
        setMessage('Operator not found', 'error');
        header('Location: operators.php');
        exit;
    }
    
    // Get operator permissions
    $stmt = $db->prepare("SELECT permission_name FROM operator_permissions WHERE operator_id = ?");
    $stmt->execute([$operator_id]);
    $operator_permissions = [];
    while ($row = $stmt->fetch()) {
        $operator_permissions[] = $row['permission_name'];
    }
}

// Get all operators with their activity stats
$stmt = $db->prepare("SELECT 
    go.*,
    COUNT(gl.id) as total_activities,
    MAX(gl.log_timestamp) as last_activity_time,
    COUNT(DISTINCT DATE(gl.log_timestamp)) as active_days
FROM gate_operators go
LEFT JOIN gate_logs gl ON go.id = gl.operator_id
WHERE go.is_active = 1
GROUP BY go.id
ORDER BY go.role DESC, go.operator_name ASC");
$stmt->execute();
$operators = $stmt->fetchAll();

// Define available permissions
$available_permissions = [
    'manage_operators' => 'Manage Operators',
    'manage_settings' => 'Manage Settings',
    'manage_locations' => 'Manage Locations',
    'manage_departments' => 'Manage Departments',
    'manage_vehicles' => 'Manage Vehicles',
    'manage_visitors' => 'Manage Visitors',
    'view_reports' => 'View Reports',
    'export_data' => 'Export Data',
    'manage_pre_registrations' => 'Manage Pre-registrations',
    'scan_qr' => 'Scan QR Codes',
    'print_cards' => 'Print Visitor Cards',
    'backup_system' => 'System Backup'
];

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management System'); ?> - Operators</title>
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
        
        .modal-enter { animation: modalEnter 0.3s ease-out; }
        @keyframes modalEnter {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
        }
        
        .permission-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.5rem;
        }
    </style>
</head>
<body class="bg-gray-50 pb-16 sm:pb-0">
    <!-- Navigation -->
    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-2 sm:px-4 lg:px-8">
            <div class="flex justify-between h-14 sm:h-16">
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <a href="settings.php" class="text-gray-600 hover:text-gray-900">
                        <i class="fas fa-arrow-left"></i>
                    </a>
                    <div class="h-8 w-8 sm:h-10 sm:w-10 bg-indigo-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-users-cog text-white text-sm sm:text-base"></i>
                    </div>
                    <div>
                        <h1 class="text-base sm:text-xl font-semibold text-gray-900">System Operators</h1>
                        <p class="text-xs sm:text-sm text-gray-500 hidden sm:block">Manage system operators and permissions</p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <a href="settings.php" class="text-blue-600 hover:text-blue-800 text-xs sm:text-sm">
                        <i class="fas fa-cog"></i>
                        <span class="ml-1 hidden sm:inline">Settings</span>
                    </a>
                    <a href="dashboard.php" class="text-blue-600 hover:text-blue-800 text-xs sm:text-sm">
                        <i class="fas fa-home"></i>
                        <span class="ml-1 hidden sm:inline">Dashboard</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-6xl mx-auto px-2 sm:px-4 lg:px-8 py-4 sm:py-8">
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
                    <span class="text-sm sm:text-base"><?php echo htmlspecialchars($message['message']); ?></span>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($action == 'create' || $action == 'edit'): ?>
            <!-- Create/Edit Form -->
            <div class="max-w-4xl mx-auto">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                    <div class="mb-4 sm:mb-6">
                        <h3 class="text-base sm:text-lg font-semibold text-gray-900">
                            <?php echo $action == 'create' ? 'Create New Operator' : 'Edit Operator'; ?>
                        </h3>
                        <p class="text-sm text-gray-600">
                            <?php echo $action == 'create' ? 'Add a new system operator with specific permissions' : 'Update operator information and permissions'; ?>
                        </p>
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
                    
                    <form method="POST" action="operators.php" class="space-y-6">
                        <input type="hidden" name="form_action" value="<?php echo $action; ?>">
                        <?php if ($action == 'edit'): ?>
                            <input type="hidden" name="operator_id" value="<?php echo $edit_operator['id']; ?>">
                        <?php endif; ?>
                        
                        <!-- Basic Information -->
                        <div>
                            <h4 class="text-base font-medium text-gray-900 mb-4 border-b pb-2">Basic Information</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                                <div>
                                    <label for="operator_name" class="block text-sm font-medium text-gray-700">Full Name *</label>
                                    <input type="text" id="operator_name" name="operator_name" required 
                                           value="<?php echo htmlspecialchars($action == 'edit' ? $edit_operator['operator_name'] : ($_POST['operator_name'] ?? '')); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm sm:text-base" 
                                           placeholder="Enter full name">
                                </div>
                                
                                <div>
                                    <label for="operator_code" class="block text-sm font-medium text-gray-700">Operator Code *</label>
                                    <input type="text" id="operator_code" name="operator_code" required 
                                           value="<?php echo htmlspecialchars($action == 'edit' ? $edit_operator['operator_code'] : ($_POST['operator_code'] ?? '')); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm sm:text-base uppercase" 
                                           placeholder="e.g., OP001"
                                           style="text-transform: uppercase;">
                                </div>
                                
                                <div>
                                    <label for="email" class="block text-sm font-medium text-gray-700">Email Address</label>
                                    <input type="email" id="email" name="email" 
                                           value="<?php echo htmlspecialchars($action == 'edit' ? $edit_operator['email'] : ($_POST['email'] ?? '')); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm sm:text-base" 
                                           placeholder="operator@company.com">
                                </div>
                                
                                <div>
                                    <label for="phone" class="block text-sm font-medium text-gray-700">Phone Number</label>
                                    <input type="tel" id="phone" name="phone" 
                                           value="<?php echo htmlspecialchars($action == 'edit' ? $edit_operator['phone'] : ($_POST['phone'] ?? '')); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm sm:text-base" 
                                           placeholder="+254700000000">
                                </div>
                                
                                <div>
                                    <label for="department" class="block text-sm font-medium text-gray-700">Department</label>
                                    <input type="text" id="department" name="department" 
                                           value="<?php echo htmlspecialchars($action == 'edit' ? $edit_operator['department'] : ($_POST['department'] ?? '')); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm sm:text-base" 
                                           placeholder="Security, Administration, etc.">
                                </div>
                                
                                <div>
                                    <label for="role" class="block text-sm font-medium text-gray-700">Role *</label>
                                    <select id="role" name="role" required 
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm sm:text-base">
                                        <option value="operator" <?php echo ($action == 'edit' ? $edit_operator['role'] : ($_POST['role'] ?? '')) === 'operator' ? 'selected' : ''; ?>>Operator</option>
                                        <option value="admin" <?php echo ($action == 'edit' ? $edit_operator['role'] : ($_POST['role'] ?? '')) === 'admin' ? 'selected' : ''; ?>>Administrator</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Work Schedule -->
                        <div>
                            <h4 class="text-base font-medium text-gray-900 mb-4 border-b pb-2">Work Schedule</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                                <div>
                                    <label for="shift_start" class="block text-sm font-medium text-gray-700">Shift Start Time</label>
                                    <input type="time" id="shift_start" name="shift_start" 
                                           value="<?php echo htmlspecialchars($action == 'edit' ? $edit_operator['shift_start'] : ($_POST['shift_start'] ?? '')); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm sm:text-base">
                                </div>
                                
                                <div>
                                    <label for="shift_end" class="block text-sm font-medium text-gray-700">Shift End Time</label>
                                    <input type="time" id="shift_end" name="shift_end" 
                                           value="<?php echo htmlspecialchars($action == 'edit' ? $edit_operator['shift_end'] : ($_POST['shift_end'] ?? '')); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm sm:text-base">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Security -->
                        <div>
                            <h4 class="text-base font-medium text-gray-900 mb-4 border-b pb-2">Security Settings</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                                <div>
                                    <label for="password" class="block text-sm font-medium text-gray-700">
                                        Password <?php echo $action == 'create' ? '*' : '(leave blank to keep current)'; ?>
                                    </label>
                                    <input type="password" id="password" name="password" 
                                           <?php echo $action == 'create' ? 'required' : ''; ?>
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm sm:text-base" 
                                           placeholder="Enter password (min 6 characters)">
                                </div>
                                
                                <?php if ($action == 'edit'): ?>
                                    <div class="flex items-center justify-center">
                                        <div class="flex items-center">
                                            <input type="checkbox" id="is_active" name="is_active" 
                                                   <?php echo $edit_operator['is_active'] ? 'checked' : ''; ?>
                                                   class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded">
                                            <label for="is_active" class="ml-2 block text-sm text-gray-900">
                                                Active (operator can login)
                                            </label>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Permissions -->
                        <div>
                            <h4 class="text-base font-medium text-gray-900 mb-4 border-b pb-2">System Permissions</h4>
                            <div class="permission-grid">
                                <?php foreach ($available_permissions as $permission => $label): ?>
                                    <div class="flex items-center">
                                        <input type="checkbox" id="perm_<?php echo $permission; ?>" name="permissions[]" value="<?php echo $permission; ?>"
                                               <?php echo ($action == 'edit' && in_array($permission, $operator_permissions ?? [])) ? 'checked' : ''; ?>
                                               class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded">
                                        <label for="perm_<?php echo $permission; ?>" class="ml-2 block text-sm text-gray-900">
                                            <?php echo htmlspecialchars($label); ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <p class="mt-2 text-xs text-gray-500">Select the permissions this operator should have. Administrators automatically have all permissions.</p>
                        </div>
                        
                        <!-- Notes -->
                        <div>
                            <label for="notes" class="block text-sm font-medium text-gray-700">Notes</label>
                            <textarea id="notes" name="notes" rows="3"
                                      class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm sm:text-base" 
                                      placeholder="Additional notes about this operator..."><?php echo htmlspecialchars($action == 'edit' ? $edit_operator['notes'] : ($_POST['notes'] ?? '')); ?></textarea>
                        </div>
                        
                        <div class="flex flex-col sm:flex-row justify-end space-y-2 sm:space-y-0 sm:space-x-4">
                            <a href="operators.php" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors text-center text-sm sm:text-base">
                                Cancel
                            </a>
                            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg font-medium transition-colors text-sm sm:text-base">
                                <i class="fas <?php echo $action == 'create' ? 'fa-plus' : 'fa-save'; ?> mr-2"></i>
                                <?php echo $action == 'create' ? 'Create Operator' : 'Update Operator'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        <?php else: ?>
            <!-- Operators List -->
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-4 sm:mb-6 space-y-3 sm:space-y-0">
                <div>
                    <h2 class="text-xl sm:text-2xl font-bold text-gray-900">System Operators</h2>
                    <p class="text-sm sm:text-base text-gray-600">Manage gate system operators and their permissions</p>
                </div>
                <button onclick="window.location.href='operators.php?action=create'" class="w-full sm:w-auto bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-3 rounded-lg font-medium transition-colors inline-flex items-center justify-center text-sm sm:text-base">
                    <i class="fas fa-plus mr-2"></i>Add Operator
                </button>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-3 sm:px-6 py-3 sm:py-4 border-b border-gray-200">
                    <h3 class="text-base sm:text-lg font-semibold text-gray-900">
                        Active Operators (<?php echo count($operators); ?> total)
                    </h3>
                </div>
                
                <div class="mobile-overflow">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-2 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Operator</th>
                                <th class="px-2 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                                <th class="px-2 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider mobile-hidden">Contact</th>
                                <th class="px-2 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider mobile-hidden">Activity</th>
                                <th class="px-2 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider mobile-hidden">Last Login</th>
                                <th class="px-2 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($operators as $operator): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-2 sm:px-6 py-2 sm:py-4">
                                        <div>
                                            <div class="text-xs sm:text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($operator['operator_name']); ?>
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                Code: <?php echo htmlspecialchars($operator['operator_code']); ?>
                                            </div>
                                            <?php if ($operator['department']): ?>
                                                <div class="text-xs text-gray-500">
                                                    <?php echo htmlspecialchars($operator['department']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-2 sm:px-6 py-2 sm:py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-medium rounded-full <?php 
                                            echo $operator['role'] === 'admin' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800'; ?>">
                                            <?php echo ucfirst($operator['role']); ?>
                                        </span>
                                        <?php if ($operator['shift_start'] && $operator['shift_end']): ?>
                                            <div class="text-xs text-gray-500 mt-1">
                                                <?php echo date('H:i', strtotime($operator['shift_start'])); ?> - <?php echo date('H:i', strtotime($operator['shift_end'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-2 sm:px-6 py-2 sm:py-4 mobile-hidden">
                                        <?php if ($operator['email']): ?>
                                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($operator['email']); ?></div>
                                        <?php endif; ?>
                                        <?php if ($operator['phone']): ?>
                                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($operator['phone']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-2 sm:px-6 py-2 sm:py-4 whitespace-nowrap text-xs sm:text-sm text-gray-900 mobile-hidden">
                                        <?php echo number_format($operator['total_activities']); ?> activities
                                        <?php if ($operator['active_days']): ?>
                                            <div class="text-xs text-gray-500">
                                                <?php echo $operator['active_days']; ?> active days
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-2 sm:px-6 py-2 sm:py-4 whitespace-nowrap text-xs sm:text-sm text-gray-500 mobile-hidden">
                                        <?php if ($operator['last_login']): ?>
                                            <?php echo date('M j, Y g:i A', strtotime($operator['last_login'])); ?>
                                        <?php else: ?>
                                            Never
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-2 sm:px-6 py-2 sm:py-4 whitespace-nowrap text-xs sm:text-sm font-medium">
                                        <div class="flex flex-col sm:flex-row space-y-1 sm:space-y-0 sm:space-x-3">
                                            <a href="operators.php?action=edit&id=<?php echo $operator['id']; ?>" 
                                               class="text-indigo-600 hover:text-indigo-900 text-center sm:text-left">
                                                <i class="fas fa-edit mr-1"></i>Edit
                                            </a>
                                            <?php if ($operator['id'] != $session['operator_id']): ?>
                                                <button onclick="resetPassword(<?php echo $operator['id']; ?>, '<?php echo htmlspecialchars($operator['operator_name']); ?>')" 
                                                        class="text-orange-600 hover:text-orange-900 text-center sm:text-left">
                                                    <i class="fas fa-key mr-1"></i>Reset
                                                </button>
                                                <button onclick="deleteOperator(<?php echo $operator['id']; ?>, '<?php echo htmlspecialchars($operator['operator_name']); ?>')" 
                                                        class="text-red-600 hover:text-red-900 text-center sm:text-left">
                                                    <i class="fas fa-trash mr-1"></i>Delete
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Mobile Bottom Navigation -->
    <div class="fixed bottom-0 left-0 right-0 bg-white border-t border-gray-200 sm:hidden">
        <div class="grid grid-cols-4 gap-1">
            <a href="dashboard.php" class="flex flex-col items-center py-2 text-gray-600">
                <i class="fas fa-home text-lg"></i>
                <span class="text-xs mt-1">Home</span>
            </a>
            <a href="settings.php" class="flex flex-col items-center py-2 text-gray-600">
                <i class="fas fa-cog text-lg"></i>
                <span class="text-xs mt-1">Settings</span>
            </a>
            <a href="operators.php" class="flex flex-col items-center py-2 text-indigo-600">
                <i class="fas fa-users-cog text-lg"></i>
                <span class="text-xs mt-1">Operators</span>
            </a>
            <a href="reports.php" class="flex flex-col items-center py-2 text-gray-600">
                <i class="fas fa-chart-bar text-lg"></i>
                <span class="text-xs mt-1">Reports</span>
            </a>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div id="resetPasswordModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50">
        <div class="relative top-10 sm:top-20 mx-auto p-4 sm:p-5 border w-11/12 sm:w-96 shadow-lg rounded-md bg-white modal-enter">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base sm:text-lg font-medium text-gray-900">Reset Password</h3>
                    <button onclick="closeResetModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <form id="resetPasswordForm" method="POST" action="reset-operator-password.php">
                    <input type="hidden" id="reset_operator_id" name="operator_id">
                    
                    <div class="mb-4">
                        <p class="text-sm text-gray-600 mb-3">
                            Reset password for operator: <span id="reset_operator_name" class="font-medium"></span>
                        </p>
                        
                        <label for="new_password" class="block text-sm font-medium text-gray-700">New Password</label>
                        <input type="password" id="new_password" name="new_password" required 
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm sm:text-base" 
                               placeholder="Enter new password (min 6 characters)">
                    </div>
                    
                    <div class="mb-4">
                        <label for="confirm_password" class="block text-sm font-medium text-gray-700">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required 
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm sm:text-base" 
                               placeholder="Confirm new password">
                    </div>
                    
                    <div class="flex flex-col sm:flex-row justify-end space-y-2 sm:space-y-0 sm:space-x-3">
                        <button type="button" onclick="closeResetModal()" 
                                class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors text-center text-sm sm:text-base">
                            Cancel
                        </button>
                        <button type="submit" 
                                class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg font-medium transition-colors text-sm sm:text-base">
                            <i class="fas fa-key mr-2"></i>Reset Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50">
        <div class="relative top-10 sm:top-20 mx-auto p-4 sm:p-5 border w-11/12 sm:w-96 shadow-lg rounded-md bg-white modal-enter">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base sm:text-lg font-medium text-gray-900">Confirm Deletion</h3>
                    <button onclick="closeDeleteModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <form id="deleteForm" method="POST" action="operators.php">
                    <input type="hidden" name="form_action" value="delete">
                    <input type="hidden" id="delete_operator_id" name="operator_id">
                    
                    <div class="mb-4">
                        <p class="text-sm text-gray-600 mb-3">
                            Are you sure you want to deactivate operator: <span id="delete_operator_name" class="font-medium"></span>?
                        </p>
                        <p class="text-xs text-red-600">This action will prevent the operator from logging in and remove all their permissions.</p>
                    </div>
                    
                    <div class="flex flex-col sm:flex-row justify-end space-y-2 sm:space-y-0 sm:space-x-3">
                        <button type="button" onclick="closeDeleteModal()" 
                                class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors text-center text-sm sm:text-base">
                            Cancel
                        </button>
                        <button type="submit" 
                                class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg font-medium transition-colors text-sm sm:text-base">
                            <i class="fas fa-trash mr-2"></i>Deactivate Operator
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function resetPassword(operatorId, operatorName) {
            document.getElementById('reset_operator_id').value = operatorId;
            document.getElementById('reset_operator_name').textContent = operatorName;
            document.getElementById('new_password').value = '';
            document.getElementById('confirm_password').value = '';
            document.getElementById('resetPasswordModal').classList.remove('hidden');
            
            setTimeout(() => {
                document.getElementById('new_password').focus();
            }, 300);
        }

        function closeResetModal() {
            document.getElementById('resetPasswordModal').classList.add('hidden');
        }

        function deleteOperator(operatorId, operatorName) {
            document.getElementById('delete_operator_id').value = operatorId;
            document.getElementById('delete_operator_name').textContent = operatorName;
            document.getElementById('deleteModal').classList.remove('hidden');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.add('hidden');
        }

        // Close modals when clicking outside
        document.getElementById('resetPasswordModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeResetModal();
            }
        });

        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDeleteModal();
            }
        });

        // Password confirmation validation
        document.getElementById('resetPasswordForm').addEventListener('submit', function(e) {
            const password = document.getElementById('new_password').value;
            const confirm = document.getElementById('confirm_password').value;
            
            if (password.length < 6) {
                e.preventDefault();
                showNotification('Password must be at least 6 characters long', 'error');
                return;
            }
            
            if (password !== confirm) {
                e.preventDefault();
                showNotification('Passwords do not match', 'error');
                return;
            }
            
            const button = this.querySelector('button[type="submit"]');
            button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Resetting...';
            button.disabled = true;
        });

        // Auto-generate operator code
        document.addEventListener('DOMContentLoaded', function() {
            const nameField = document.getElementById('operator_name');
            const codeField = document.getElementById('operator_code');
            
            if (nameField && codeField && !codeField.value) {
                nameField.addEventListener('input', function() {
                    if (!codeField.value || codeField.dataset.autoGenerated === 'true') {
                        const name = this.value.toUpperCase().replace(/[^A-Z]/g, '');
                        const code = 'OP' + name.substring(0, 2) + Math.floor(Math.random() * 1000).toString().padStart(3, '0');
                        codeField.value = code;
                        codeField.dataset.autoGenerated = 'true';
                    }
                });
                
                codeField.addEventListener('input', function() {
                    if (this.value) {
                        this.dataset.autoGenerated = 'false';
                    }
                });
            }

            // Auto-uppercase operator code
            if (codeField) {
                codeField.addEventListener('input', function() {
                    this.value = this.value.toUpperCase();
                });
            }
        });

        // Role-based permission management
        document.addEventListener('DOMContentLoaded', function() {
            const roleSelect = document.getElementById('role');
            const permissionCheckboxes = document.querySelectorAll('input[name="permissions[]"]');
            
            if (roleSelect && permissionCheckboxes.length > 0) {
                roleSelect.addEventListener('change', function() {
                    if (this.value === 'admin') {
                        // Check all permissions for admin
                        permissionCheckboxes.forEach(checkbox => {
                            checkbox.checked = true;
                            checkbox.disabled = true;
                        });
                    } else {
                        // Enable all permissions for operator
                        permissionCheckboxes.forEach(checkbox => {
                            checkbox.disabled = false;
                        });
                    }
                });
                
                // Trigger on page load
                roleSelect.dispatchEvent(new Event('change'));
            }
        });

        // Form submission handling
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form:not(#resetPasswordForm):not(#deleteForm)');
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

        // Show notification function
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 z-50 p-3 sm:p-4 rounded-lg border max-w-xs sm:max-w-sm transform transition-all duration-300 translate-x-full opacity-0 ${
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
                    } mr-2 flex-shrink-0"></i>
                    <span class="text-sm font-medium">${message}</span>
                    <button onclick="this.parentElement.parentElement.remove()" class="ml-2 text-gray-400 hover:text-gray-600 flex-shrink-0">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            // Animate in
            setTimeout(() => {
                notification.classList.remove('translate-x-full', 'opacity-0');
            }, 100);
            
            // Remove after 5 seconds
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.classList.add('translate-x-full', 'opacity-0');
                    setTimeout(() => {
                        if (notification.parentNode) {
                            notification.parentNode.removeChild(notification);
                        }
                    }, 300);
                }
            }, 5000);
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeResetModal();
                closeDeleteModal();
            } else if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                window.location.href = 'operators.php?action=create';
            }
        });

        // Mobile keyboard handling
        if (window.innerWidth <= 768) {
            const inputs = document.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    setTimeout(() => {
                        this.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }, 300);
                });
            });
        }

        // Real-time validation
        document.addEventListener('DOMContentLoaded', function() {
            const passwordField = document.getElementById('password');
            const confirmField = document.getElementById('confirm_password');
            const emailField = document.getElementById('email');
            const phoneField = document.getElementById('phone');
            
            if (passwordField) {
                passwordField.addEventListener('input', function() {
                    const value = this.value;
                    if (value.length > 0 && value.length < 6) {
                        this.setCustomValidity('Password must be at least 6 characters');
                    } else {
                        this.setCustomValidity('');
                    }
                });
            }
            
            if (confirmField) {
                confirmField.addEventListener('input', function() {
                    const password = document.getElementById('new_password').value;
                    if (this.value !== password) {
                        this.setCustomValidity('Passwords do not match');
                    } else {
                        this.setCustomValidity('');
                    }
                });
            }

            if (emailField) {
                emailField.addEventListener('blur', function() {
                    if (this.value && !this.value.includes('@')) {
                        showNotification('Please enter a valid email address', 'warning');
                    }
                });
            }

            if (phoneField) {
                phoneField.addEventListener('input', function() {
                    this.value = this.value.replace(/[^\d+\s\-()]/g, '');
                });
            }
        });

        // Bulk actions (for future enhancement)
        function selectAllOperators() {
            const checkboxes = document.querySelectorAll('input[name="operator_ids[]"]');
            checkboxes.forEach(checkbox => checkbox.checked = true);
        }

        function deselectAllOperators() {
            const checkboxes = document.querySelectorAll('input[name="operator_ids[]"]');
            checkboxes.forEach(checkbox => checkbox.checked = false);
        }

        // Export operators data
        function exportOperators() {
            window.location.href = 'export.php?type=operators';
        }

        // Print operators list
        function printOperators() {
            window.print();
        }

        // Quick search functionality
        function quickSearch() {
            const searchTerm = document.getElementById('quick_search')?.value.toLowerCase();
            const rows = document.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        }

        // Permission tooltips
        document.addEventListener('DOMContentLoaded', function() {
            const permissionTooltips = {
                'manage_operators': 'Create, edit, and delete other operators',
                'manage_settings': 'Modify system settings and configuration',
                'manage_locations': 'Add and manage facility locations',
                'manage_departments': 'Create and manage organizational departments',
                'manage_vehicles': 'Register and manage vehicles',
                'manage_visitors': 'Register and manage visitors',
                'view_reports': 'Access system reports and analytics',
                'export_data': 'Export data to various formats',
                'manage_pre_registrations': 'Handle visitor pre-registrations',
                'scan_qr': 'Use QR code scanning features',
                'print_cards': 'Print visitor identification cards',
                'backup_system': 'Perform system backups and maintenance'
            };

            Object.keys(permissionTooltips).forEach(permission => {
                const element = document.getElementById('perm_' + permission);
                if (element) {
                    element.title = permissionTooltips[permission];
                }
            });
        });

        // Save draft functionality
        function saveDraft() {
            const form = document.querySelector('form');
            if (form) {
                const formData = new FormData(form);
                const data = {};
                for (let [key, value] of formData.entries()) {
                    data[key] = value;
                }
                localStorage.setItem('operator_form_draft', JSON.stringify(data));
                showNotification('Draft saved', 'success');
            }
        }

        function loadDraft() {
            const savedDraft = localStorage.getItem('operator_form_draft');
            if (savedDraft) {
                try {
                    const data = JSON.parse(savedDraft);
                    Object.keys(data).forEach(key => {
                        const field = document.getElementById(key) || document.querySelector(`[name="${key}"]`);
                        if (field) {
                            if (field.type === 'checkbox') {
                                field.checked = data[key] === field.value;
                            } else {
                                field.value = data[key];
                            }
                        }
                    });
                    showNotification('Draft loaded', 'info');
                } catch (e) {
                    localStorage.removeItem('operator_form_draft');
                }
            }
        }

        // Auto-save every 30 seconds
        setInterval(() => {
            if (document.querySelector('form') && document.querySelector('input[name="operator_name"]')?.value) {
                saveDraft();
            }
        }, 30000);

        // Clear draft on successful submission
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function() {
                    localStorage.removeItem('operator_form_draft');
                });
            });
        });
    </script>
</body>
</html>
