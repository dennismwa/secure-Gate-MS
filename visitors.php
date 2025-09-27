<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);
$settings = getSettings($db);

$action = $_GET['action'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $action == 'register') {
    $full_name = sanitizeInput($_POST['full_name']);
    $phone = sanitizeInput($_POST['phone']);
    $email = sanitizeInput($_POST['email']);
    $id_number = sanitizeInput($_POST['id_number']);
    $company = sanitizeInput($_POST['company']);
    $vehicle_number = sanitizeInput($_POST['vehicle_number']);
    
    $errors = [];
    if (empty($full_name)) $errors[] = 'Full name is required';
    if (empty($phone)) $errors[] = 'Phone number is required';
    if (!validatePhone($phone)) $errors[] = 'Invalid phone number format';
    if (!empty($email) && !validateEmail($email)) $errors[] = 'Invalid email format';
    
    $stmt = $db->prepare("SELECT visitor_id FROM visitors WHERE phone = ?");
    $stmt->execute([$phone]);
    if ($stmt->fetch()) {
        $errors[] = 'A visitor with this phone number already exists';
    }
    
    if (empty($errors)) {
        $visitor_id = generateUniqueId('VIS');
        $qr_code = generateQRCode($visitor_id . $phone);
        
        $stmt = $db->prepare("INSERT INTO visitors (visitor_id, full_name, phone, email, id_number, company, vehicle_number, qr_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        
        if ($stmt->execute([$visitor_id, $full_name, $phone, $email, $id_number, $company, $vehicle_number, $qr_code])) {
            try {
                createNotification($db, 'check_in', 'New Visitor Registered', "New visitor $full_name has been registered in the system");
            } catch (Exception $e) {
                error_log("Notification creation error: " . $e->getMessage());
            }
            
            logActivity($db, $session['operator_id'], 'visitor_registration', "Registered new visitor: $full_name", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
            setMessage("Visitor registered successfully! Visitor ID: $visitor_id", 'success');
            header('Location: visitors.php?action=view&id=' . $visitor_id);
            exit;
        } else {
            $errors[] = 'Failed to register visitor';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $action == 'update') {
    $visitor_id = sanitizeInput($_POST['visitor_id']);
    $full_name = sanitizeInput($_POST['full_name']);
    $phone = sanitizeInput($_POST['phone']);
    $email = sanitizeInput($_POST['email']);
    $id_number = sanitizeInput($_POST['id_number']);
    $company = sanitizeInput($_POST['company']);
    $vehicle_number = sanitizeInput($_POST['vehicle_number']);
    $status = sanitizeInput($_POST['status']);
    
    $stmt = $db->prepare("UPDATE visitors SET full_name = ?, phone = ?, email = ?, id_number = ?, company = ?, vehicle_number = ?, status = ? WHERE visitor_id = ?");
    
    if ($stmt->execute([$full_name, $phone, $email, $id_number, $company, $vehicle_number, $status, $visitor_id])) {
        logActivity($db, $session['operator_id'], 'visitor_update', "Updated visitor: $full_name", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        setMessage('Visitor updated successfully', 'success');
    } else {
        setMessage('Failed to update visitor', 'error');
    }
    
    header('Location: visitors.php?action=view&id=' . $visitor_id);
    exit;
}

if ($action == 'view' || $action == 'edit') {
    $visitor_id = $_GET['id'] ?? '';
    $stmt = $db->prepare("SELECT * FROM visitors WHERE visitor_id = ?");
    $stmt->execute([$visitor_id]);
    $visitor = $stmt->fetch();
    
    if (!$visitor) {
        setMessage('Visitor not found', 'error');
        header('Location: visitors.php');
        exit;
    }
    
    $stmt = $db->prepare("SELECT gl.*, go.operator_name FROM gate_logs gl JOIN gate_operators go ON gl.operator_id = go.id WHERE gl.visitor_id = ? ORDER BY gl.log_timestamp DESC LIMIT 20");
    $stmt->execute([$visitor_id]);
    $visitor_logs = $stmt->fetchAll();
}

if ($action == 'list') {
    $search = $_GET['search'] ?? '';
    $status_filter = $_GET['status'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = 20;
    $offset = ($page - 1) * $per_page;
    
    $where_conditions = ['1=1'];
    $params = [];
    
    if (!empty($search)) {
        $where_conditions[] = "(full_name LIKE ? OR phone LIKE ? OR company LIKE ? OR vehicle_number LIKE ? OR visitor_id LIKE ?)";
        $search_param = "%$search%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param]);
    }
    
    if (!empty($status_filter)) {
        $where_conditions[] = "status = ?";
        $params[] = $status_filter;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM visitors WHERE $where_clause");
    $stmt->execute($params);
    $total_visitors = $stmt->fetch()['total'];
    $total_pages = ceil($total_visitors / $per_page);
    
    $stmt = $db->prepare("SELECT v.*, 
                         (SELECT log_type FROM gate_logs gl WHERE gl.visitor_id = v.visitor_id ORDER BY log_timestamp DESC LIMIT 1) as last_action,
                         (SELECT log_timestamp FROM gate_logs gl WHERE gl.visitor_id = v.visitor_id ORDER BY log_timestamp DESC LIMIT 1) as last_activity
                         FROM visitors v 
                         WHERE $where_clause 
                         ORDER BY v.created_at DESC 
                         LIMIT $per_page OFFSET $offset");
    $stmt->execute($params);
    $visitors = $stmt->fetchAll();
}

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management System'); ?> - Visitor Management</title>
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
        /* Mobile responsive adjustments */
        @media (max-width: 768px) {
            .mobile-stack {
                flex-direction: column !important;
                gap: 0.5rem !important;
            }
            
            .mobile-full {
                width: 100% !important;
            }
            
            .mobile-text-sm {
                font-size: 0.875rem !important;
            }
            
            .mobile-p-2 {
                padding: 0.5rem !important;
            }
            
            .mobile-hidden {
                display: none !important;
            }
            
            .mobile-overflow {
                overflow-x: auto !important;
            }
        }
        
        /* QR Code container */
        .qr-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 200px;
        }
        
        /* Responsive table */
        @media (max-width: 640px) {
            .responsive-table {
                font-size: 0.75rem;
            }
            
            .responsive-table th,
            .responsive-table td {
                padding: 0.5rem 0.25rem;
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
                        <i class="fas fa-users text-white text-sm sm:text-base"></i>
                    </div>
                    <div>
                        <h1 class="text-lg sm:text-xl font-semibold text-gray-900">Visitor Management</h1>
                        <p class="text-xs sm:text-sm text-gray-500 hidden sm:block">Register and manage visitors</p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <a href="scanner.php" class="text-blue-600 hover:text-blue-800 text-sm sm:text-base">
                        <i class="fas fa-qrcode"></i>
                        <span class="ml-1 hidden sm:inline">Scanner</span>
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
                                   placeholder="Search visitors...">
                        </div>
                        <select name="status" class="px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 w-full sm:w-auto text-sm sm:text-base">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="blocked" <?php echo $status_filter === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
                        </select>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium transition-colors w-full sm:w-auto text-sm sm:text-base">
                            <i class="fas fa-search mr-2"></i>Search
                        </button>
                    </form>
                    
                    <a href="visitors.php?action=register" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition-colors inline-flex items-center justify-center text-sm sm:text-base">
                        <i class="fas fa-user-plus mr-2"></i>Register New Visitor
                    </a>
                </div>
            </div>

            
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-3 sm:px-6 py-3 sm:py-4 border-b border-gray-200">
                    <h3 class="text-base sm:text-lg font-semibold text-gray-900">
                        Visitors List (<?php echo number_format($total_visitors); ?> total)
                    </h3>
                </div>
                
                <?php if (empty($visitors)): ?>
                    <div class="px-3 sm:px-6 py-8 sm:py-12 text-center text-gray-500">
                        <i class="fas fa-users text-3xl sm:text-4xl mb-4 text-gray-300"></i>
                        <p class="text-base sm:text-lg mb-2">No visitors found</p>
                        <p class="text-sm sm:text-base">Start by registering your first visitor</p>
                        <a href="visitors.php?action=register" class="mt-4 inline-flex items-center bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition-colors text-sm sm:text-base">
                            <i class="fas fa-user-plus mr-2"></i>Register Visitor
                        </a>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 responsive-table">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-2 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Visitor</th>
                                    <th class="px-2 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider mobile-hidden">Contact</th>
                                    <th class="px-2 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider mobile-hidden">Company</th>
                                    <th class="px-2 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-2 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($visitors as $visitor): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-2 sm:px-6 py-2 sm:py-4">
                                            <div>
                                                <div class="text-xs sm:text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($visitor['full_name']); ?>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    ID: <?php echo htmlspecialchars($visitor['visitor_id']); ?>
                                                </div>
                                                <div class="text-xs text-gray-500 sm:hidden">
                                                    <?php echo htmlspecialchars($visitor['phone']); ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-2 sm:px-6 py-2 sm:py-4 mobile-hidden">
                                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($visitor['phone']); ?></div>
                                            <?php if ($visitor['email']): ?>
                                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($visitor['email']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-2 sm:px-6 py-2 sm:py-4 mobile-hidden">
                                            <?php if ($visitor['company']): ?>
                                                <div class="text-sm text-gray-900"><?php echo htmlspecialchars($visitor['company']); ?></div>
                                            <?php endif; ?>
                                            <?php if ($visitor['vehicle_number']): ?>
                                                <div class="text-sm text-gray-500">
                                                    <i class="fas fa-car mr-1"></i><?php echo htmlspecialchars($visitor['vehicle_number']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-2 sm:px-6 py-2 sm:py-4">
                                            <span class="px-2 py-1 text-xs font-medium rounded-full <?php 
                                                echo $visitor['status'] === 'active' ? 'bg-green-100 text-green-800' : 
                                                    ($visitor['status'] === 'blocked' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-800'); ?>">
                                                <?php echo ucfirst($visitor['status']); ?>
                                            </span>
                                            <?php if ($visitor['last_action']): ?>
                                                <div class="mt-1">
                                                    <span class="px-2 py-1 text-xs font-medium rounded-full <?php 
                                                        echo $visitor['last_action'] === 'check_in' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800'; ?>">
                                                        <?php echo $visitor['last_action'] === 'check_in' ? 'Inside' : 'Outside'; ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-2 sm:px-6 py-2 sm:py-4">
                                            <div class="flex flex-col sm:flex-row space-y-1 sm:space-y-0 sm:space-x-2">
                                                <a href="visitors.php?action=view&id=<?php echo $visitor['visitor_id']; ?>" 
                                                   class="text-blue-600 hover:text-blue-900 text-xs sm:text-sm">
                                                    <i class="fas fa-eye mr-1"></i>View
                                                </a>
                                                <a href="visitors.php?action=edit&id=<?php echo $visitor['visitor_id']; ?>" 
                                                   class="text-green-600 hover:text-green-900 text-xs sm:text-sm">
                                                    <i class="fas fa-edit mr-1"></i>Edit
                                                </a>
                                                <a href="print-card.php?id=<?php echo $visitor['visitor_id']; ?>" 
                                                   class="text-purple-600 hover:text-purple-900 text-xs sm:text-sm" target="_blank">
                                                    <i class="fas fa-print mr-1"></i>Print
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    
                    <?php if ($total_pages > 1): ?>
                        <div class="px-3 sm:px-6 py-3 sm:py-4 border-t border-gray-200">
                            <div class="flex flex-col sm:flex-row items-center justify-between space-y-3 sm:space-y-0">
                                <div class="text-xs sm:text-sm text-gray-700">
                                    Showing <?php echo (($page - 1) * $per_page) + 1; ?> to <?php echo min($page * $per_page, $total_visitors); ?> of <?php echo $total_visitors; ?> results
                                </div>
                                <div class="flex space-x-1 sm:space-x-2">
                                    <?php if ($page > 1): ?>
                                        <a href="?action=list&page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" 
                                           class="px-2 sm:px-3 py-1 sm:py-2 border border-gray-300 rounded-lg text-xs sm:text-sm text-gray-600 hover:bg-gray-50">Previous</a>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                        <a href="?action=list&page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" 
                                           class="px-2 sm:px-3 py-1 sm:py-2 border rounded-lg text-xs sm:text-sm <?php echo $i === $page ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 text-gray-600 hover:bg-gray-50'; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $total_pages): ?>
                                        <a href="?action=list&page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" 
                                           class="px-2 sm:px-3 py-1 sm:py-2 border border-gray-300 rounded-lg text-xs sm:text-sm text-gray-600 hover:bg-gray-50">Next</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        <?php elseif ($action == 'register'): ?>
            
            <div class="max-w-2xl mx-auto">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                    <div class="mb-4 sm:mb-6">
                        <h3 class="text-lg font-semibold text-gray-900">Register New Visitor</h3>
                        <p class="text-sm text-gray-600">Fill in the visitor's information to register them in the system</p>
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
                    
                    <form method="POST" class="space-y-4 sm:space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                            <div>
                                <label for="full_name" class="block text-sm font-medium text-gray-700">Full Name *</label>
                                <input type="text" id="full_name" name="full_name" required 
                                       value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" 
                                       placeholder="Enter full name">
                            </div>
                            
                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-700">Phone Number *</label>
                                <input type="tel" id="phone" name="phone" required 
                                       value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" 
                                       placeholder="+1234567890">
                            </div>
                            
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                                <input type="email" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" 
                                       placeholder="email@example.com">
                            </div>
                            
                            <div>
                                <label for="id_number" class="block text-sm font-medium text-gray-700">ID Number</label>
                                <input type="text" id="id_number" name="id_number" 
                                       value="<?php echo htmlspecialchars($_POST['id_number'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" 
                                       placeholder="Government ID or passport number">
                            </div>
                            
                            <div>
                                <label for="company" class="block text-sm font-medium text-gray-700">Company</label>
                                <input type="text" id="company" name="company" 
                                       value="<?php echo htmlspecialchars($_POST['company'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" 
                                       placeholder="Company or organization">
                            </div>
                            
                            <div>
                                <label for="vehicle_number" class="block text-sm font-medium text-gray-700">Vehicle Number</label>
                                <input type="text" id="vehicle_number" name="vehicle_number" 
                                       value="<?php echo htmlspecialchars($_POST['vehicle_number'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" 
                                       placeholder="Vehicle registration number">
                            </div>
                        </div>
                        
                        <div class="flex flex-col sm:flex-row justify-end space-y-2 sm:space-y-0 sm:space-x-4">
                            <a href="visitors.php" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors text-center text-sm sm:text-base">
                                Cancel
                            </a>
                            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition-colors text-sm sm:text-base">
                                <i class="fas fa-user-plus mr-2"></i>Register Visitor
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        <?php elseif ($action == 'view' && isset($visitor)): ?>
            
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-8">
                <div class="lg:col-span-2 space-y-4 sm:space-y-6">
                    
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-4 sm:mb-6 space-y-2 sm:space-y-0">
                            <h3 class="text-lg font-semibold text-gray-900">Visitor Information</h3>
                            <div class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-2 w-full sm:w-auto">
                                <a href="visitors.php?action=edit&id=<?php echo $visitor['visitor_id']; ?>" 
                                   class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg text-sm font-medium transition-colors text-center">
                                    <i class="fas fa-edit mr-1"></i>Edit
                                </a>
                                <a href="print-card.php?id=<?php echo $visitor['visitor_id']; ?>" 
                                   class="bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded-lg text-sm font-medium transition-colors text-center" target="_blank">
                                    <i class="fas fa-print mr-1"></i>Print Card
                                </a>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-600">Full Name</label>
                                <p class="text-gray-900 text-sm sm:text-base"><?php echo htmlspecialchars($visitor['full_name']); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600">Phone</label>
                                <p class="text-gray-900 text-sm sm:text-base"><?php echo htmlspecialchars($visitor['phone']); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600">Email</label>
                                <p class="text-gray-900 text-sm sm:text-base"><?php echo htmlspecialchars($visitor['email'] ?: 'Not provided'); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600">ID Number</label>
                                <p class="text-gray-900 text-sm sm:text-base"><?php echo htmlspecialchars($visitor['id_number'] ?: 'Not provided'); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600">Company</label>
                                <p class="text-gray-900 text-sm sm:text-base"><?php echo htmlspecialchars($visitor['company'] ?: 'Not provided'); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600">Vehicle Number</label>
                                <p class="text-gray-900 text-sm sm:text-base"><?php echo htmlspecialchars($visitor['vehicle_number'] ?: 'Not provided'); ?></p>
                            </div>
                        </div>
                    </div>

                    
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Visitor Photo</h3>
                        
                        <div class="flex flex-col sm:flex-row items-start space-y-4 sm:space-y-0 sm:space-x-6">
                            
                            <div class="w-32 h-40 border-2 border-gray-300 rounded-lg overflow-hidden bg-gray-50 flex items-center justify-center mx-auto sm:mx-0">
                                <?php if ($visitor['photo_path'] && file_exists($visitor['photo_path'])): ?>
                                    <img id="current-photo" src="<?php echo htmlspecialchars($visitor['photo_path']); ?>" 
                                         alt="Visitor Photo" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <div id="photo-placeholder" class="text-center text-gray-400">
                                        <i class="fas fa-user text-3xl mb-2"></i>
                                        <p class="text-xs">No Photo</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            
                            <div class="flex-1 w-full">
                                <form id="photo-upload-form" enctype="multipart/form-data" class="space-y-4">
                                    <input type="hidden" name="visitor_id" value="<?php echo $visitor['visitor_id']; ?>">
                                    
                                    <div>
                                        <label for="photo" class="block text-sm font-medium text-gray-700 mb-2">
                                            Upload New Photo
                                        </label>
                                        <input type="file" id="photo" name="photo" accept="image/*" 
                                               class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                                        <p class="text-xs text-gray-500 mt-1">JPG, PNG, or GIF. Max size: 5MB</p>
                                    </div>
                                    
                                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition-colors w-full sm:w-auto">
                                        <i class="fas fa-upload mr-2"></i>Upload Photo
                                    </button>
                                </form>
                                
                                <div id="upload-status" class="mt-4 hidden"></div>
                            </div>
                        </div>
                    </div>

                    
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4 sm:mb-6">Recent Activity</h3>
                        
                        <?php if (empty($visitor_logs)): ?>
                            <div class="text-center py-6 sm:py-8 text-gray-500">
                                <i class="fas fa-history text-3xl sm:text-4xl mb-4 text-gray-300"></i>
                                <p class="text-sm sm:text-base">No activity recorded yet</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-3 sm:space-y-4">
                                <?php foreach ($visitor_logs as $log): ?>
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
                                                    </p>
                                                    <p class="text-xs sm:text-sm text-gray-500">
                                                        By: <?php echo htmlspecialchars($log['operator_name']); ?>
                                                        <?php if ($log['purpose_of_visit']): ?>
                                                            • Purpose: <?php echo htmlspecialchars($log['purpose_of_visit']); ?>
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

                
                <div class="space-y-4 sm:space-y-6">
                    
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6 text-center">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">QR Code</h3>
                        <div class="bg-gray-100 p-4 rounded-lg mb-4 qr-container">
                            <canvas id="qrcode" width="200" height="200"></canvas>
                        </div>
                        <p class="text-sm text-gray-600 mb-4">Scan this QR code for quick check-in/check-out</p>
                        <button onclick="downloadQRCode()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors w-full sm:w-auto">
                            <i class="fas fa-download mr-2"></i>Download QR
                        </button>
                    </div>

                    
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Quick Actions</h3>
                        <div class="space-y-3">
                            <a href="scanner.php" class="block w-full bg-green-600 hover:bg-green-700 text-white px-4 py-3 rounded-lg text-center font-medium transition-colors">
                                <i class="fas fa-qrcode mr-2"></i>Open Scanner
                            </a>
                            <a href="print-card.php?id=<?php echo $visitor['visitor_id']; ?>" target="_blank" 
                               class="block w-full bg-purple-600 hover:bg-purple-700 text-white px-4 py-3 rounded-lg text-center font-medium transition-colors">
                                <i class="fas fa-print mr-2"></i>Print ID Card
                            </a>
                            <button onclick="quickAction('check_in')" id="checkin-btn" 
                                    class="block w-full bg-green-500 hover:bg-green-600 text-white px-4 py-3 rounded-lg text-center font-medium transition-colors">
                                <i class="fas fa-sign-in-alt mr-2"></i>Quick Check-in
                            </button>
                            <button onclick="quickAction('check_out')" id="checkout-btn" 
                                    class="block w-full bg-red-500 hover:bg-red-600 text-white px-4 py-3 rounded-lg text-center font-medium transition-colors">
                                <i class="fas fa-sign-out-alt mr-2"></i>Quick Check-out
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($action == 'edit' && isset($visitor)): ?>
            
            <div class="max-w-2xl mx-auto">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                    <div class="mb-4 sm:mb-6">
                        <h3 class="text-lg font-semibold text-gray-900">Edit Visitor</h3>
                        <p class="text-sm text-gray-600">Update visitor information</p>
                    </div>
                    
                    <form method="POST" class="space-y-4 sm:space-y-6">
                        <input type="hidden" name="visitor_id" value="<?php echo htmlspecialchars($visitor['visitor_id']); ?>">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                            <div>
                                <label for="full_name" class="block text-sm font-medium text-gray-700">Full Name *</label>
                                <input type="text" id="full_name" name="full_name" required 
                                       value="<?php echo htmlspecialchars($visitor['full_name']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base">
                            </div>
                            
                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-700">Phone Number *</label>
                                <input type="tel" id="phone" name="phone" required 
                                       value="<?php echo htmlspecialchars($visitor['phone']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base">
                            </div>
                            
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                                <input type="email" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($visitor['email']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base">
                            </div>
                            
                            <div>
                                <label for="id_number" class="block text-sm font-medium text-gray-700">ID Number</label>
                                <input type="text" id="id_number" name="id_number" 
                                       value="<?php echo htmlspecialchars($visitor['id_number']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base">
                            </div>
                            
                            <div>
                                <label for="company" class="block text-sm font-medium text-gray-700">Company</label>
                                <input type="text" id="company" name="company" 
                                       value="<?php echo htmlspecialchars($visitor['company']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base">
                            </div>
                            
                            <div>
                                <label for="vehicle_number" class="block text-sm font-medium text-gray-700">Vehicle Number</label>
                                <input type="text" id="vehicle_number" name="vehicle_number" 
                                       value="<?php echo htmlspecialchars($visitor['vehicle_number']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base">
                            </div>
                            
                            <div class="md:col-span-2">
                                <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                                <select id="status" name="status" 
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base">
                                    <option value="active" <?php echo $visitor['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $visitor['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="blocked" <?php echo $visitor['status'] === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="flex flex-col sm:flex-row justify-end space-y-2 sm:space-y-0 sm:space-x-4">
                            <a href="visitors.php?action=view&id=<?php echo $visitor['visitor_id']; ?>" 
                               class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors text-center text-sm sm:text-base">
                                Cancel
                            </a>
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium transition-colors text-sm sm:text-base">
                                <i class="fas fa-save mr-2"></i>Update Visitor
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js"></script>
    
    <script>
        <?php if ($action == 'view' && isset($visitor)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const qrData = '<?php echo htmlspecialchars($visitor['qr_code']); ?>';
            
            console.log('Generating QR Code for:', qrData);
            
            try {
                const canvas = document.getElementById('qrcode');
                if (!canvas) {
                    console.error('QR Code canvas not found');
                    return;
                }
                
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
                
                console.log('QR Code generated successfully');
                
            } catch (error) {
                console.error('QR Code generation error:', error);
                
                const canvas = document.getElementById('qrcode');
                if (canvas) {
                    const ctx = canvas.getContext('2d');
                    ctx.fillStyle = '#FF0000';
                    ctx.font = '16px Arial';
                    ctx.textAlign = 'center';
                    ctx.fillText('QR Error', 100, 100);
                }
            }
        });

        function downloadQRCode() {
            const canvas = document.getElementById('qrcode');
            if (canvas) {
                const link = document.createElement('a');
                link.download = 'visitor-qr-<?php echo $visitor['visitor_id']; ?>.png';
                link.href = canvas.toDataURL();
                link.click();
            }
        }

        function quickAction(action) {
            const button = document.getElementById(action === 'check_in' ? 'checkin-btn' : 'checkout-btn');
            const originalText = button.innerHTML;
            
            button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
            button.disabled = true;
            
            const formData = new FormData();
            formData.append('qr_data', '<?php echo $visitor['qr_code']; ?>');
            formData.append('action', action);
            
            fetch('process-scan.php', {
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

        document.addEventListener('DOMContentLoaded', function() {
            const photoForm = document.getElementById('photo-upload-form');
            if (photoForm) {
                photoForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(this);
                    const fileInput = document.getElementById('photo');
                    const statusDiv = document.getElementById('upload-status');
                    const submitBtn = this.querySelector('button[type="submit"]');
                    
                    if (!fileInput.files[0]) {
                        showStatus('Please select a photo to upload', 'error');
                        return;
                    }
                    
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Uploading...';
                    submitBtn.disabled = true;
                    
                    fetch('upload-photo.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showStatus(data.message, 'success');
                            
                            const currentPhoto = document.getElementById('current-photo');
                            const placeholder = document.getElementById('photo-placeholder');
                            
                            if (currentPhoto) {
                                currentPhoto.src = data.photo_path + '?t=' + Date.now(); // Add timestamp to force reload
                            } else if (placeholder) {
                                placeholder.parentElement.innerHTML = `<img id="current-photo" src="${data.photo_path}" alt="Visitor Photo" class="w-full h-full object-cover">`;
                            }
                            
                            fileInput.value = '';
                        } else {
                            showStatus(data.message, 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Upload error:', error);
                        showStatus('Upload failed. Please try again.', 'error');
                    })
                    .finally(() => {
                        submitBtn.innerHTML = '<i class="fas fa-upload mr-2"></i>Upload Photo';
                        submitBtn.disabled = false;
                    });
                });
            }
        });

        function showStatus(message, type) {
            const statusDiv = document.getElementById('upload-status');
            if (statusDiv) {
                statusDiv.className = `mt-4 p-3 rounded-lg ${type === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}`;
                statusDiv.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} mr-2"></i>${message}`;
                statusDiv.classList.remove('hidden');
                
                setTimeout(() => {
                    statusDiv.classList.add('hidden');
                }, 5000);
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form:not(#photo-upload-form)');
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
                        }, 5000);
                    }
                });
            });
        });

        document.addEventListener('DOMContentLoaded', function() {
            const phoneInputs = document.querySelectorAll('input[type="tel"]');
            phoneInputs.forEach(input => {
                input.addEventListener('input', function() {
                    this.value = this.value.replace(/[^\d+\s-()]/g, '');
                });
            });
        });

        <?php if ($action == 'list'): ?>
        if (window.innerWidth > 768) {
            setInterval(function() {
                const activeElement = document.activeElement;
                if (!activeElement || activeElement.tagName !== 'INPUT') {
                    window.location.reload();
                }
            }, 60000); // Refresh every minute
        }
        <?php endif; ?>
    </script>
</body>
</html>