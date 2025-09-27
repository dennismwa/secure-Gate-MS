<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);
$settings = getSettings($db);

if ($session['role'] !== 'admin') {
    setMessage('Access denied. Admin privileges required.', 'error');
    header('Location: dashboard.php');
    exit;
}

$action = $_GET['action'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $action == 'create') {
    $department_name = sanitizeInput($_POST['department_name']);
    $description = sanitizeInput($_POST['description']);
    $contact_person = sanitizeInput($_POST['contact_person']);
    $contact_phone = sanitizeInput($_POST['contact_phone']);
    $contact_email = sanitizeInput($_POST['contact_email']);
    
    $errors = [];
    if (empty($department_name)) $errors[] = 'Department name is required';
    
    $stmt = $db->prepare("SELECT id FROM departments WHERE department_name = ?");
    $stmt->execute([$department_name]);
    if ($stmt->fetch()) {
        $errors[] = 'Department already exists';
    }
    
    if (empty($errors)) {
        $stmt = $db->prepare("INSERT INTO departments (department_name, description, contact_person, contact_phone, contact_email) VALUES (?, ?, ?, ?, ?)");
        if ($stmt->execute([$department_name, $description, $contact_person, $contact_phone, $contact_email])) {
            logActivity($db, $session['operator_id'], 'department_create', "Created department: $department_name", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
            setMessage("Department '$department_name' created successfully", 'success');
            header('Location: manage-departments.php');
            exit;
        } else {
            $errors[] = 'Failed to create department';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $action == 'update') {
    $dept_id = intval($_POST['dept_id']);
    $department_name = sanitizeInput($_POST['department_name']);
    $description = sanitizeInput($_POST['description']);
    $contact_person = sanitizeInput($_POST['contact_person']);
    $contact_phone = sanitizeInput($_POST['contact_phone']);
    $contact_email = sanitizeInput($_POST['contact_email']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    $stmt = $db->prepare("UPDATE departments SET department_name = ?, description = ?, contact_person = ?, contact_phone = ?, contact_email = ?, is_active = ? WHERE id = ?");
    if ($stmt->execute([$department_name, $description, $contact_person, $contact_phone, $contact_email, $is_active, $dept_id])) {
        logActivity($db, $session['operator_id'], 'department_update', "Updated department: $department_name", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        setMessage("Department updated successfully", 'success');
    } else {
        setMessage('Failed to update department', 'error');
    }
    
    header('Location: manage-departments.php');
    exit;
}

if ($action == 'edit') {
    $dept_id = intval($_GET['id'] ?? 0);
    $stmt = $db->prepare("SELECT * FROM departments WHERE id = ?");
    $stmt->execute([$dept_id]);
    $edit_dept = $stmt->fetch();
    
    if (!$edit_dept) {
        setMessage('Department not found', 'error');
        header('Location: manage-departments.php');
        exit;
    }
}

$stmt = $db->query("SELECT * FROM departments ORDER BY department_name ASC");
$departments = $stmt->fetchAll();

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management System'); ?> - Manage Departments</title>
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
            }
            
            .mobile-full {
                width: 100% !important;
            }
            
            .mobile-text-sm {
                font-size: 0.875rem !important;
            }
            
            .mobile-hidden {
                display: none !important;
            }
            
            .mobile-p-2 {
                padding: 0.5rem !important;
            }
            
            .mobile-text-xs {
                font-size: 0.75rem !important;
            }
            
            .mobile-overflow {
                overflow-x: auto !important;
            }
        }
        
        /* Table responsive */
        .responsive-table {
            font-size: 0.875rem;
        }
        
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
<body class="bg-gray-50 pb-16 sm:pb-0">
    
    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-2 sm:px-4 lg:px-8">
            <div class="flex justify-between h-14 sm:h-16">
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <a href="settings.php" class="text-gray-600 hover:text-gray-900">
                        <i class="fas fa-arrow-left"></i>
                    </a>
                    <div class="h-8 w-8 sm:h-10 sm:w-10 bg-indigo-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-building text-white text-sm sm:text-base"></i>
                    </div>
                    <div>
                        <h1 class="text-base sm:text-xl font-semibold text-gray-900">Departments</h1>
                        <p class="text-xs sm:text-sm text-gray-500 hidden sm:block">Manage departments</p>
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

        <?php if ($action == 'create' || $action == 'edit'): ?>
            
            <div class="max-w-2xl mx-auto">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                    <div class="mb-4 sm:mb-6">
                        <h3 class="text-base sm:text-lg font-semibold text-gray-900">
                            <?php echo $action == 'create' ? 'Create New Department' : 'Edit Department'; ?>
                        </h3>
                        <p class="text-sm text-gray-600">
                            <?php echo $action == 'create' ? 'Add a new department to the system' : 'Update department information'; ?>
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
                    
                    <form method="POST" class="space-y-4 sm:space-y-6">
                        <?php if ($action == 'edit'): ?>
                            <input type="hidden" name="dept_id" value="<?php echo $edit_dept['id']; ?>">
                        <?php endif; ?>
                        
                        <div>
                            <label for="department_name" class="block text-sm font-medium text-gray-700">Department Name *</label>
                            <input type="text" id="department_name" name="department_name" required 
                                   value="<?php echo htmlspecialchars($action == 'edit' ? $edit_dept['department_name'] : ($_POST['department_name'] ?? '')); ?>"
                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm sm:text-base" 
                                   placeholder="Enter department name">
                        </div>
                        
                        <div>
                            <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                            <textarea id="description" name="description" rows="3"
                                      class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm sm:text-base" 
                                      placeholder="Department description"><?php echo htmlspecialchars($action == 'edit' ? $edit_dept['description'] : ($_POST['description'] ?? '')); ?></textarea>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                            <div>
                                <label for="contact_person" class="block text-sm font-medium text-gray-700">Contact Person</label>
                                <input type="text" id="contact_person" name="contact_person" 
                                       value="<?php echo htmlspecialchars($action == 'edit' ? $edit_dept['contact_person'] : ($_POST['contact_person'] ?? '')); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm sm:text-base" 
                                       placeholder="Contact person name">
                            </div>
                            
                            <div>
                                <label for="contact_phone" class="block text-sm font-medium text-gray-700">Contact Phone</label>
                                <input type="tel" id="contact_phone" name="contact_phone" 
                                       value="<?php echo htmlspecialchars($action == 'edit' ? $edit_dept['contact_phone'] : ($_POST['contact_phone'] ?? '')); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm sm:text-base" 
                                       placeholder="Contact phone number">
                            </div>
                        </div>
                        
                        <div>
                            <label for="contact_email" class="block text-sm font-medium text-gray-700">Contact Email</label>
                            <input type="email" id="contact_email" name="contact_email" 
                                   value="<?php echo htmlspecialchars($action == 'edit' ? $edit_dept['contact_email'] : ($_POST['contact_email'] ?? '')); ?>"
                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm sm:text-base" 
                                   placeholder="Contact email address">
                        </div>
                        
                        <?php if ($action == 'edit'): ?>
                            <div class="flex items-center">
                                <input type="checkbox" id="is_active" name="is_active" 
                                       <?php echo $edit_dept['is_active'] ? 'checked' : ''; ?>
                                       class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded">
                                <label for="is_active" class="ml-2 block text-sm text-gray-900">
                                    Active (department is available for selection)
                                </label>
                            </div>
                        <?php endif; ?>
                        
                        <div class="flex flex-col sm:flex-row justify-end space-y-2 sm:space-y-0 sm:space-x-4">
                            <a href="manage-departments.php" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors text-center text-sm sm:text-base">
                                Cancel
                            </a>
                            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg font-medium transition-colors text-sm sm:text-base">
                                <i class="fas <?php echo $action == 'create' ? 'fa-plus' : 'fa-save'; ?> mr-2"></i>
                                <?php echo $action == 'create' ? 'Create Department' : 'Update Department'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        <?php else: ?>
            
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-4 sm:mb-6 space-y-3 sm:space-y-0">
                <div>
                    <h2 class="text-xl sm:text-2xl font-bold text-gray-900">Departments</h2>
                    <p class="text-sm sm:text-base text-gray-600">Manage organizational departments</p>
                </div>
                <a href="manage-departments.php?action=create" class="w-full sm:w-auto bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-3 rounded-lg font-medium transition-colors inline-flex items-center justify-center text-sm sm:text-base">
                    <i class="fas fa-plus mr-2"></i>Add Department
                </a>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-3 sm:px-6 py-3 sm:py-4 border-b border-gray-200">
                    <h3 class="text-base sm:text-lg font-semibold text-gray-900">
                        All Departments (<?php echo count($departments); ?> total)
                    </h3>
                </div>
                
                <div class="mobile-overflow">
                    <table class="min-w-full divide-y divide-gray-200 responsive-table">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-2 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Department</th>
                                <th class="px-2 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider mobile-hidden">Contact Info</th>
                                <th class="px-2 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-2 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider mobile-hidden">Created</th>
                                <th class="px-2 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($departments as $dept): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-2 sm:px-6 py-2 sm:py-4">
                                        <div>
                                            <div class="text-xs sm:text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($dept['department_name']); ?>
                                            </div>
                                            <?php if ($dept['description']): ?>
                                                <div class="text-xs text-gray-500">
                                                    <?php echo htmlspecialchars($dept['description']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-2 sm:px-6 py-2 sm:py-4 mobile-hidden">
                                        <?php if ($dept['contact_person']): ?>
                                            <div class="text-sm text-gray-900">
                                                <?php echo htmlspecialchars($dept['contact_person']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($dept['contact_phone']): ?>
                                            <div class="text-sm text-gray-500">
                                                <i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($dept['contact_phone']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($dept['contact_email']): ?>
                                            <div class="text-sm text-gray-500">
                                                <i class="fas fa-envelope mr-1"></i><?php echo htmlspecialchars($dept['contact_email']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-2 sm:px-6 py-2 sm:py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-medium rounded-full <?php 
                                            echo $dept['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                            <?php echo $dept['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td class="px-2 sm:px-6 py-2 sm:py-4 whitespace-nowrap text-sm text-gray-500 mobile-hidden">
                                        <?php echo date('M j, Y', strtotime($dept['created_at'])); ?>
                                    </td>
                                    <td class="px-2 sm:px-6 py-2 sm:py-4 whitespace-nowrap text-sm font-medium">
                                        <a href="manage-departments.php?action=edit&id=<?php echo $dept['id']; ?>" 
                                           class="text-indigo-600 hover:text-indigo-900 text-xs sm:text-sm">
                                            <i class="fas fa-edit mr-1"></i>Edit
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    
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
            <a href="manage-departments.php" class="flex flex-col items-center py-2 text-indigo-600">
                <i class="fas fa-building text-lg"></i>
                <span class="text-xs mt-1">Departments</span>
            </a>
            <a href="operators.php" class="flex flex-col items-center py-2 text-gray-600">
                <i class="fas fa-users-cog text-lg"></i>
                <span class="text-xs mt-1">Operators</span>
            </a>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
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
                        }, 5000);
                    }
                });
            });
        });

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
    </script>
</body>
</html>