<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);
$settings = getSettings($db);

$action = $_GET['action'] ?? 'list';
$message = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $action == 'create') {
    $full_name = sanitizeInput($_POST['full_name']);
    $phone = sanitizeInput($_POST['phone']);
    $email = sanitizeInput($_POST['email']);
    $company = sanitizeInput($_POST['company']);
    $vehicle_number = sanitizeInput($_POST['vehicle_number']);
    $visit_date = sanitizeInput($_POST['visit_date']);
    $visit_time_from = sanitizeInput($_POST['visit_time_from']);
    $visit_time_to = sanitizeInput($_POST['visit_time_to']);
    $purpose_of_visit = sanitizeInput($_POST['purpose_of_visit']);
    $host_name = sanitizeInput($_POST['host_name']);
    $host_department = sanitizeInput($_POST['host_department']);

    if (empty($full_name)) $errors[] = "Full name is required";
    if (empty($phone)) $errors[] = "Phone number is required";
    if (empty($visit_date)) $errors[] = "Visit date is required";
    if (!empty($visit_date) && $visit_date < date('Y-m-d')) $errors[] = "Visit date cannot be in the past";
    if (!empty($visit_time_from) && !empty($visit_time_to) && $visit_time_from >= $visit_time_to) {
        $errors[] = "End time must be after start time";
    }

    if (empty($errors)) {
        try {
            $qr_data = uniqid('prereg_', true);
            
            $stmt = $db->prepare("
                INSERT INTO pre_registrations 
                (full_name, phone, email, company, vehicle_number, visit_date, visit_time_from, visit_time_to, 
                 purpose_of_visit, host_name, host_department, qr_code, status, created_by_operator, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
            ");
            
            $stmt->execute([
                $full_name, $phone, $email, $company, $vehicle_number, $visit_date, 
                $visit_time_from, $visit_time_to, $purpose_of_visit, $host_name, 
                $host_department, $qr_data, $session['operator_id']
            ]);

            logActivity($db, $session['operator_id'], 'pre_registration', "Created pre-registration for: $full_name", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
            setMessage('Pre-registration created successfully and is pending approval.', 'success');
            
            header('Location: pre-register.php');
            exit;
            
        } catch (Exception $e) {
            $errors[] = "Error creating pre-registration: " . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_status') {
    $reg_id = (int)$_POST['reg_id'];
    $status = sanitizeInput($_POST['status']);
    
    if (in_array($status, ['approved', 'rejected'])) {
        try {
            $stmt = $db->prepare("
                UPDATE pre_registrations 
                SET status = ?, approved_by_operator = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$status, $session['operator_id'], $reg_id]);
            
            $stmt = $db->prepare("SELECT * FROM pre_registrations WHERE id = ?");
            $stmt->execute([$reg_id]);
            $pre_reg = $stmt->fetch();
            
            if ($pre_reg) {
                logActivity($db, $session['operator_id'], 'pre_registration_' . $status, "Pre-registration {$status}: {$pre_reg['full_name']}", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
            }
            
            setMessage("Pre-registration has been " . $status . " successfully.", 'success');
            
        } catch (Exception $e) {
            setMessage("Error updating pre-registration: " . $e->getMessage(), 'error');
        }
    }
}

$search = sanitizeInput($_GET['search'] ?? '');
$status_filter = sanitizeInput($_GET['status'] ?? '');
$date_filter = sanitizeInput($_GET['date'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$where_conditions = ["1=1"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(pr.full_name LIKE ? OR pr.phone LIKE ? OR pr.company LIKE ? OR pr.host_name LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
}

if (!empty($status_filter)) {
    $where_conditions[] = "pr.status = ?";
    $params[] = $status_filter;
}

if (!empty($date_filter)) {
    $where_conditions[] = "pr.visit_date = ?";
    $params[] = $date_filter;
}

$where_clause = implode(" AND ", $where_conditions);

$count_stmt = $db->prepare("
    SELECT COUNT(*) as total 
    FROM pre_registrations pr 
    WHERE $where_clause
");
$count_stmt->execute($params);
$total_registrations = $count_stmt->fetch()['total'];
$total_pages = ceil($total_registrations / $per_page);

$stmt = $db->prepare("
    SELECT pr.*, 
           creator.operator_name as created_by_name,
           approver.operator_name as approved_by_name
    FROM pre_registrations pr
    LEFT JOIN gate_operators creator ON pr.created_by_operator = creator.id
    LEFT JOIN gate_operators approver ON pr.approved_by_operator = approver.id
    WHERE $where_clause
    ORDER BY pr.created_at DESC
    LIMIT $per_page OFFSET $offset
");
$stmt->execute($params);
$pre_registrations = $stmt->fetchAll();

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management System'); ?> - Pre-Registration</title>
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
            .mobile-text-sm { font-size: 0.875rem !important; }
            .mobile-p-2 { padding: 0.5rem !important; }
        }
    </style>
</head>
<body class="bg-gray-50">
    
    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <a href="dashboard.php" class="text-gray-600 hover:text-gray-900 mr-4">
                        <i class="fas fa-arrow-left"></i>
                    </a>
                    <div class="h-10 w-10 bg-purple-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-calendar-plus text-white"></i>
                    </div>
                    <div class="ml-3">
                        <h1 class="text-xl font-semibold text-gray-900">Pre-Registration</h1>
                        <p class="text-sm text-gray-500 mobile-hidden">Schedule future visits</p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-4">
                    <a href="scanner.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-qrcode"></i>
                        <span class="ml-1 mobile-hidden">Scanner</span>
                    </a>
                    <a href="dashboard.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-home"></i>
                        <span class="ml-1 mobile-hidden">Dashboard</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-lg border <?php 
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

        <?php if ($action == 'create'): ?>
            
            <div class="max-w-3xl mx-auto">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <div class="mb-6">
                        <h3 class="text-lg font-semibold text-gray-900">Create Pre-Registration</h3>
                        <p class="text-sm text-gray-600">Schedule a future visit for a visitor</p>
                    </div>
                    
                    <?php if (isset($errors) && !empty($errors)): ?>
                        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                            <div class="flex items-center mb-2">
                                <i class="fas fa-exclamation-circle text-red-600 mr-2"></i>
                                <span class="font-medium text-red-700">Please correct the following errors:</span>
                            </div>
                            <ul class="list-disc list-inside text-red-600 text-sm">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            
                            <div class="md:col-span-2">
                                <h4 class="text-md font-medium text-gray-900 mb-3 border-b pb-2">Visitor Information</h4>
                            </div>
                            
                            <div>
                                <label for="full_name" class="block text-sm font-medium text-gray-700">Full Name *</label>
                                <input type="text" id="full_name" name="full_name" required 
                                       value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500" 
                                       placeholder="Enter full name">
                            </div>
                            
                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-700">Phone Number *</label>
                                <input type="tel" id="phone" name="phone" required 
                                       value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500" 
                                       placeholder="+1234567890">
                            </div>
                            
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                                <input type="email" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500" 
                                       placeholder="email@example.com">
                            </div>
                            
                            <div>
                                <label for="company" class="block text-sm font-medium text-gray-700">Company</label>
                                <input type="text" id="company" name="company" 
                                       value="<?php echo htmlspecialchars($_POST['company'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500" 
                                       placeholder="Company or organization">
                            </div>
                            
                            <div>
                                <label for="vehicle_number" class="block text-sm font-medium text-gray-700">Vehicle Number</label>
                                <input type="text" id="vehicle_number" name="vehicle_number" 
                                       value="<?php echo htmlspecialchars($_POST['vehicle_number'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500" 
                                       placeholder="Vehicle registration number">
                            </div>
                            
                            
                            <div class="md:col-span-2 mt-6">
                                <h4 class="text-md font-medium text-gray-900 mb-3 border-b pb-2">Visit Information</h4>
                            </div>
                            
                            <div>
                                <label for="visit_date" class="block text-sm font-medium text-gray-700">Visit Date *</label>
                                <input type="date" id="visit_date" name="visit_date" required 
                                       value="<?php echo htmlspecialchars($_POST['visit_date'] ?? ''); ?>"
                                       min="<?php echo date('Y-m-d'); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label for="visit_time_from" class="block text-sm font-medium text-gray-700">From Time</label>
                                    <input type="time" id="visit_time_from" name="visit_time_from" 
                                           value="<?php echo htmlspecialchars($_POST['visit_time_from'] ?? ''); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                                </div>
                                <div>
                                    <label for="visit_time_to" class="block text-sm font-medium text-gray-700">To Time</label>
                                    <input type="time" id="visit_time_to" name="visit_time_to" 
                                           value="<?php echo htmlspecialchars($_POST['visit_time_to'] ?? ''); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                                </div>
                            </div>
                            
                            <div>
                                <label for="purpose_of_visit" class="block text-sm font-medium text-gray-700">Purpose of Visit</label>
                                <input type="text" id="purpose_of_visit" name="purpose_of_visit" 
                                       value="<?php echo htmlspecialchars($_POST['purpose_of_visit'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500" 
                                       placeholder="Meeting, delivery, etc.">
                            </div>
                            
                            <div>
                                <label for="host_name" class="block text-sm font-medium text-gray-700">Host Name</label>
                                <input type="text" id="host_name" name="host_name" 
                                       value="<?php echo htmlspecialchars($_POST['host_name'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500" 
                                       placeholder="Person being visited">
                            </div>
                            
                            <div>
                                <label for="host_department" class="block text-sm font-medium text-gray-700">Department</label>
                                <select id="host_department" name="host_department" 
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                                    <option value="">Select department</option>
                                    <?php
                                    $stmt = $db->query("SELECT department_name FROM departments WHERE is_active = 1 ORDER BY department_name");
                                    while ($dept = $stmt->fetch()) {
                                        $selected = ($_POST['host_department'] ?? '') === $dept['department_name'] ? 'selected' : '';
                                        echo "<option value='" . htmlspecialchars($dept['department_name']) . "' $selected>" . htmlspecialchars($dept['department_name']) . "</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="flex mobile-stack justify-end space-x-4">
                            <a href="pre-register.php" class="mobile-full px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors text-center">
                                Cancel
                            </a>
                            <button type="submit" class="mobile-full bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg font-medium transition-colors">
                                <i class="fas fa-calendar-plus mr-2"></i>Create Pre-Registration
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        <?php else: ?>
            
            
            
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between space-y-4 lg:space-y-0">
                    <form method="GET" class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-4 mobile-full">
                        <div class="relative mobile-full">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-search text-gray-400"></i>
                            </div>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                   class="pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500 w-full sm:w-64" 
                                   placeholder="Search registrations...">
                        </div>
                        <select name="status" class="px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500 mobile-full">
                            <option value="">All Status</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            <option value="used" <?php echo $status_filter === 'used' ? 'selected' : ''; ?>>Used</option>
                        </select>
                        <input type="date" name="date" value="<?php echo htmlspecialchars($date_filter); ?>" 
                               class="px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500 mobile-full">
                        <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg font-medium transition-colors mobile-full">
                            <i class="fas fa-search mr-2"></i>Search
                        </button>
                    </form>
                    
                    <a href="pre-register.php?action=create" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition-colors inline-flex items-center mobile-full justify-center">
                        <i class="fas fa-plus mr-2"></i>New Pre-Registration
                    </a>
                </div>
            </div>

            
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">
                        Pre-Registrations (<?php echo number_format($total_registrations); ?> total)
                    </h3>
                </div>
                
                <?php if (empty($pre_registrations)): ?>
                    <div class="px-6 py-12 text-center text-gray-500">
                        <i class="fas fa-calendar-plus text-4xl mb-4 text-gray-300"></i>
                        <p class="text-lg mb-2">No pre-registrations found</p>
                        <p>Create a new pre-registration to get started</p>
                        <a href="pre-register.php?action=create" class="mt-4 inline-flex items-center bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg font-medium transition-colors">
                            <i class="fas fa-plus mr-2"></i>Create Pre-Registration
                        </a>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Visitor</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider mobile-hidden">Visit Details</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider mobile-hidden">Host Info</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider mobile-hidden">Created By</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($pre_registrations as $registration): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4">
                                            <div>
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($registration['full_name']); ?>
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($registration['phone']); ?>
                                                </div>
                                                <?php if ($registration['company']): ?>
                                                    <div class="text-sm text-gray-500">
                                                        <?php echo htmlspecialchars($registration['company']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($registration['vehicle_number']): ?>
                                                    <div class="text-sm text-gray-500">
                                                        <i class="fas fa-car mr-1"></i><?php echo htmlspecialchars($registration['vehicle_number']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 mobile-hidden">
                                            <div class="text-sm text-gray-900">
                                                <div class="font-medium">
                                                    <?php echo date('M j, Y', strtotime($registration['visit_date'])); ?>
                                                </div>
                                                <?php if ($registration['visit_time_from'] && $registration['visit_time_to']): ?>
                                                    <div class="text-gray-500">
                                                        <?php echo date('g:i A', strtotime($registration['visit_time_from'])); ?> - 
                                                        <?php echo date('g:i A', strtotime($registration['visit_time_to'])); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($registration['purpose_of_visit']): ?>
                                                    <div class="text-gray-500 mt-1">
                                                        Purpose: <?php echo htmlspecialchars($registration['purpose_of_visit']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 mobile-hidden">
                                            <?php if ($registration['host_name']): ?>
                                                <div class="text-sm text-gray-900">
                                                    <?php echo htmlspecialchars($registration['host_name']); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($registration['host_department']): ?>
                                                <div class="text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($registration['host_department']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span class="px-2 py-1 text-xs font-medium rounded-full <?php 
                                                echo $registration['status'] === 'approved' ? 'bg-green-100 text-green-800' : 
                                                    ($registration['status'] === 'rejected' ? 'bg-red-100 text-red-800' : 
                                                    ($registration['status'] === 'used' ? 'bg-blue-100 text-blue-800' : 'bg-yellow-100 text-yellow-800')); ?>">
                                                <?php echo ucfirst($registration['status']); ?>
                                            </span>
                                            <?php if ($registration['approved_by_name']): ?>
                                                <div class="text-xs text-gray-500 mt-1 mobile-hidden">
                                                    By: <?php echo htmlspecialchars($registration['approved_by_name']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 mobile-hidden">
                                            <div class="text-sm text-gray-900">
                                                <?php echo htmlspecialchars($registration['created_by_name'] ?? 'System'); ?>
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                <?php echo date('M j, g:i A', strtotime($registration['created_at'])); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php if ($registration['status'] === 'pending'): ?>
                                                <div class="flex mobile-stack space-x-2">
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="action" value="update_status">
                                                        <input type="hidden" name="reg_id" value="<?php echo $registration['id']; ?>">
                                                        <input type="hidden" name="status" value="approved">
                                                        <button type="submit" onclick="return confirm('Approve this pre-registration?')" 
                                                                class="text-green-600 hover:text-green-900 text-sm font-medium mobile-full mobile-p-2">
                                                            <i class="fas fa-check mr-1"></i>Approve
                                                        </button>
                                                    </form>
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="action" value="update_status">
                                                        <input type="hidden" name="reg_id" value="<?php echo $registration['id']; ?>">
                                                        <input type="hidden" name="status" value="rejected">
                                                        <button type="submit" onclick="return confirm('Reject this pre-registration?')" 
                                                                class="text-red-600 hover:text-red-900 text-sm font-medium mobile-full mobile-p-2">
                                                            <i class="fas fa-times mr-1"></i>Reject
                                                        </button>
                                                    </form>
                                                </div>
                                            <?php elseif ($registration['status'] === 'approved' && $registration['qr_code']): ?>
                                                <button onclick="showQRCode('<?php echo htmlspecialchars($registration['qr_code']); ?>', '<?php echo htmlspecialchars($registration['full_name']); ?>')" 
                                                        class="text-blue-600 hover:text-blue-900 text-sm font-medium">
                                                    <i class="fas fa-qrcode mr-1"></i>View QR
                                                </button>
                                            <?php else: ?>
                                                <span class="text-gray-400 text-sm">No actions</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    
                    <?php if ($total_pages > 1): ?>
                        <div class="px-6 py-4 border-t border-gray-200">
                            <div class="flex flex-col sm:flex-row items-center justify-between space-y-3 sm:space-y-0">
                                <div class="text-sm text-gray-700">
                                    Showing <?php echo (($page - 1) * $per_page) + 1; ?> to <?php echo min($page * $per_page, $total_registrations); ?> of <?php echo $total_registrations; ?> results
                                </div>
                                <div class="flex space-x-2">
                                    <?php if ($page > 1): ?>
                                        <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&date=<?php echo urlencode($date_filter); ?>" 
                                           class="px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-600 hover:bg-gray-50">Previous</a>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&date=<?php echo urlencode($date_filter); ?>" 
                                           class="px-3 py-2 border rounded-lg text-sm <?php echo $i === $page ? 'bg-purple-600 text-white border-purple-600' : 'border-gray-300 text-gray-600 hover:bg-gray-50'; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $total_pages): ?>
                                        <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&date=<?php echo urlencode($date_filter); ?>" 
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

    
    <div id="qrModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3 text-center">
                <h3 class="text-lg font-medium text-gray-900 mb-4" id="qrModalTitle">Pre-Registration QR Code</h3>
                <div class="flex justify-center mb-4">
                    <div id="qrCodeContainer" class="border rounded-lg p-4"></div>
                </div>
                <div class="text-sm text-gray-600 mb-4">
                    <p>Show this QR code at the gate for quick check-in</p>
                </div>
                <div class="flex justify-center space-x-4">
                    <button onclick="downloadQRCode()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                        <i class="fas fa-download mr-2"></i>Download
                    </button>
                    <button onclick="closeQRModal()" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    
    <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
    
    <script>
        let currentQRCanvas = null;
        let currentVisitorName = '';

        function showQRCode(qrData, visitorName) {
            currentVisitorName = visitorName;
            document.getElementById('qrModalTitle').textContent = `QR Code for ${visitorName}`;
            
            const container = document.getElementById('qrCodeContainer');
            container.innerHTML = '';
            
            QRCode.toCanvas(container, qrData, {
                width: 200,
                height: 200,
                colorDark: '#000000',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            }, function (error, canvas) {
                if (error) {
                    console.error(error);
                    container.innerHTML = '<div class="text-red-600">QR Code Error</div>';
                } else {
                    currentQRCanvas = canvas;
                }
            });
            
            document.getElementById('qrModal').classList.remove('hidden');
        }

        function closeQRModal() {
            document.getElementById('qrModal').classList.add('hidden');
            currentQRCanvas = null;
        }

        function downloadQRCode() {
            if (currentQRCanvas) {
                const link = document.createElement('a');
                link.download = `pre-registration-qr-${currentVisitorName.replace(/\s+/g, '-').toLowerCase()}.png`;
                link.href = currentQRCanvas.toDataURL();
                link.click();
            }
        }

        document.getElementById('qrModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeQRModal();
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            const visitDateInput = document.getElementById('visit_date');
            if (visitDateInput) {
                visitDateInput.min = new Date().toISOString().split('T')[0];
            }

            const timeFromInput = document.getElementById('visit_time_from');
            const timeToInput = document.getElementById('visit_time_to');
            
            if (timeFromInput && timeToInput) {
                timeFromInput.addEventListener('change', function() {
                    if (timeToInput.value && this.value >= timeToInput.value) {
                        timeToInput.value = '';
                        alert('End time must be after start time');
                    }
                });
                
                timeToInput.addEventListener('change', function() {
                    if (timeFromInput.value && this.value <= timeFromInput.value) {
                        this.value = '';
                        alert('End time must be after start time');
                    }
                });
            }

            const phoneInputs = document.querySelectorAll('input[type="tel"]');
            phoneInputs.forEach(input => {
                input.addEventListener('input', function() {
                    this.value = this.value.replace(/[^\d+\s-()]/g, '');
                });
            });

            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const button = this.querySelector('button[type="submit"]');
                    if (button && !button.hasAttribute('onclick')) {
                        const originalText = button.innerHTML;
                        button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
                        button.disabled = true;
                        
                        setTimeout(() => {
                            button.innerHTML = originalText;
                            button.disabled = false;
                        }, 5000);
                    }
                });
            });
        });

        <?php if ($action !== 'create'): ?>
        setTimeout(function() {
            if (!document.getElementById('qrModal').classList.contains('hidden')) return;
            window.location.reload();
        }, 60000); // Refresh every minute
        <?php endif; ?>
    </script>
</body>
</html>