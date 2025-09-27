<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $visitor_id = sanitizeInput($_POST['visitor_id']);
    $action = sanitizeInput($_POST['action']); // 'check_in' or 'check_out'
    $purpose_of_visit = sanitizeInput($_POST['purpose_of_visit'] ?? '');
    $host_name = sanitizeInput($_POST['host_name'] ?? '');
    $host_department = sanitizeInput($_POST['host_department'] ?? '');
    $notes = sanitizeInput($_POST['notes'] ?? '');
    
    $stmt = $db->prepare("SELECT * FROM visitors WHERE visitor_id = ? AND status = 'active'");
    $stmt->execute([$visitor_id]);
    $visitor = $stmt->fetch();
    
    if ($visitor && in_array($action, ['check_in', 'check_out'])) {
        $stmt = $db->prepare("INSERT INTO gate_logs (visitor_id, log_type, operator_id, purpose_of_visit, host_name, host_department, vehicle_number, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$visitor_id, $action, $session['operator_id'], $purpose_of_visit, $host_name, $host_department, $visitor['vehicle_number'], $notes]);
        
        try {
            $action_text = $action === 'check_in' ? 'checked in' : 'checked out';
            createNotification($db, $action, ucfirst(str_replace('_', ' ', $action)), "Visitor {$visitor['full_name']} has $action_text", $visitor['visitor_id'], $session['operator_id']);
        } catch (Exception $e) {
            error_log("Notification creation error: " . $e->getMessage());
        }
        
        logActivity($db, $session['operator_id'], 'manual_' . $action, "Manual $action for visitor: {$visitor['full_name']}", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => ucfirst(str_replace('_', ' ', $action)) . ' successful for ' . $visitor['full_name'],
            'visitor' => $visitor,
            'action' => $action
        ]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid visitor or action']);
    }
    exit;
}

$settings = getSettings($db);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management System'); ?> - Quick Check-in</title>
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
        }
        
        /* Action button animations */
        .action-btn {
            transition: all 0.2s ease;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
        }
        
        .action-btn.selected {
            transform: scale(1.05);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        /* Search results animations */
        .visitor-result {
            transition: all 0.2s ease;
        }
        
        .visitor-result:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
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
                    <div class="h-8 w-8 sm:h-10 sm:w-10 bg-orange-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-tachometer-alt text-white text-sm sm:text-base"></i>
                    </div>
                    <div>
                        <h1 class="text-lg sm:text-xl font-semibold text-gray-900">Quick Check-in/out</h1>
                        <p class="text-xs sm:text-sm text-gray-500 hidden sm:block">Manual visitor processing</p>
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

    <div class="max-w-6xl mx-auto px-2 sm:px-4 lg:px-8 py-4 sm:py-8">
        
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-8">
            
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-4 sm:mb-6">Find Visitor</h3>
                
                <div class="mb-4 sm:mb-6">
                    <label for="visitor_search" class="block text-sm font-medium text-gray-700 mb-2">Search by Name, Phone, or ID</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-search text-gray-400 text-sm"></i>
                        </div>
                        <input type="text" id="visitor_search" placeholder="Start typing to search..." 
                               class="w-full pl-10 pr-3 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base">
                    </div>
                </div>
                
                <div id="search_results" class="space-y-3 max-h-80 sm:max-h-96 overflow-y-auto">
                    <div class="text-center text-gray-500 py-6 sm:py-8">
                        <i class="fas fa-search text-2xl sm:text-3xl mb-2 text-gray-300"></i>
                        <p class="text-sm sm:text-base">Start typing to search for visitors</p>
                    </div>
                </div>
            </div>

            
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-4 sm:mb-6">Check-in/out Details</h3>
                
                <div id="selected_visitor" class="mb-4 sm:mb-6 p-3 sm:p-4 bg-gray-50 rounded-lg hidden">
                    <h4 class="font-medium text-gray-900 mb-2 text-sm sm:text-base">Selected Visitor:</h4>
                    <div id="visitor_info" class="text-sm sm:text-base"></div>
                </div>
                
                <form id="checkinForm" class="space-y-4">
                    <input type="hidden" id="visitor_id" name="visitor_id">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Action</label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
                            <button type="button" id="checkin_btn" class="action-btn p-3 sm:p-4 border-2 border-green-300 text-green-700 rounded-lg hover:bg-green-50 transition-all duration-200 text-sm sm:text-base">
                                <i class="fas fa-sign-in-alt mr-2"></i>Check In
                            </button>
                            <button type="button" id="checkout_btn" class="action-btn p-3 sm:p-4 border-2 border-red-300 text-red-700 rounded-lg hover:bg-red-50 transition-all duration-200 text-sm sm:text-base">
                                <i class="fas fa-sign-out-alt mr-2"></i>Check Out
                            </button>
                        </div>
                        <input type="hidden" id="action" name="action">
                    </div>
                    
                    <div>
                        <label for="purpose_of_visit" class="block text-sm font-medium text-gray-700">Purpose of Visit</label>
                        <input type="text" id="purpose_of_visit" name="purpose_of_visit" 
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" 
                               placeholder="Meeting, delivery, etc.">
                    </div>
                    
                    <div>
                        <label for="host_name" class="block text-sm font-medium text-gray-700">Host Name</label>
                        <input type="text" id="host_name" name="host_name" 
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" 
                               placeholder="Person being visited">
                    </div>
                    
                    <div>
                        <label for="host_department" class="block text-sm font-medium text-gray-700">Department</label>
                        <select id="host_department" name="host_department" 
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base">
                            <option value="">Select department</option>
                            <?php
                            $stmt = $db->query("SELECT department_name FROM departments WHERE is_active = 1 ORDER BY department_name");
                            while ($dept = $stmt->fetch()) {
                                echo "<option value='" . htmlspecialchars($dept['department_name']) . "'>" . htmlspecialchars($dept['department_name']) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="notes" class="block text-sm font-medium text-gray-700">Notes</label>
                        <textarea id="notes" name="notes" rows="3" 
                                  class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" 
                                  placeholder="Additional notes..."></textarea>
                    </div>
                    
                    <button type="submit" id="submit_btn" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-3 rounded-lg font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed text-sm sm:text-base" disabled>
                        <i class="fas fa-check mr-2"></i>Process Action
                    </button>
                </form>
            </div>
        </div>

        
        <div class="mt-6 sm:mt-8 grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-6">
            <div class="bg-white p-3 sm:p-4 rounded-lg shadow-sm border text-center">
                <div class="text-lg sm:text-2xl font-bold text-blue-600" id="inside-count">-</div>
                <div class="text-xs sm:text-sm text-gray-600">Currently Inside</div>
            </div>
            <div class="bg-white p-3 sm:p-4 rounded-lg shadow-sm border text-center">
                <div class="text-lg sm:text-2xl font-bold text-green-600" id="today-checkins">-</div>
                <div class="text-xs sm:text-sm text-gray-600">Today's Check-ins</div>
            </div>
            <div class="bg-white p-3 sm:p-4 rounded-lg shadow-sm border text-center">
                <div class="text-lg sm:text-2xl font-bold text-red-600" id="today-checkouts">-</div>
                <div class="text-xs sm:text-sm text-gray-600">Today's Check-outs</div>
            </div>
            <div class="bg-white p-3 sm:p-4 rounded-lg shadow-sm border text-center">
                <div class="text-lg sm:text-2xl font-bold text-purple-600" id="unique-visitors">-</div>
                <div class="text-xs sm:text-sm text-gray-600">Unique Visitors</div>
            </div>
        </div>
    </div>

    
    <div id="successModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50">
        <div class="relative top-10 sm:top-20 mx-auto p-4 sm:p-5 border w-11/12 sm:w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3 text-center">
                <div class="mx-auto flex items-center justify-center h-10 w-10 sm:h-12 sm:w-12 rounded-full bg-green-100">
                    <i class="fas fa-check text-green-600 text-lg sm:text-xl"></i>
                </div>
                <h3 class="text-base sm:text-lg font-medium text-gray-900 mt-2" id="success_title">Success!</h3>
                <div class="mt-2 px-4 sm:px-7 py-3">
                    <p class="text-sm text-gray-500" id="success_message"></p>
                </div>
                <div class="items-center px-4 py-3">
                    <button onclick="closeModal()" class="px-4 py-2 bg-green-500 text-white text-sm sm:text-base font-medium rounded-md w-full shadow-sm hover:bg-green-600 focus:outline-none focus:ring-2 focus:ring-green-300">
                        OK
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let searchTimeout;
        let selectedAction = null;

        loadQuickStats();

        document.getElementById('visitor_search').addEventListener('input', function() {
            const query = this.value.trim();
            clearTimeout(searchTimeout);
            
            if (query.length < 2) {
                document.getElementById('search_results').innerHTML = `
                    <div class="text-center text-gray-500 py-6 sm:py-8">
                        <i class="fas fa-search text-2xl sm:text-3xl mb-2 text-gray-300"></i>
                        <p class="text-sm sm:text-base">Start typing to search for visitors</p>
                    </div>
                `;
                return;
            }
            
            document.getElementById('search_results').innerHTML = `
                <div class="text-center text-gray-500 py-6 sm:py-8">
                    <i class="fas fa-spinner fa-spin text-2xl sm:text-3xl mb-2 text-gray-400"></i>
                    <p class="text-sm sm:text-base">Searching...</p>
                </div>
            `;
            
            searchTimeout = setTimeout(() => {
                fetch(`ajax-visitor-search.php?q=${encodeURIComponent(query)}`)
                    .then(response => response.json())
                    .then(data => {
                        const resultsContainer = document.getElementById('search_results');
                        
                        if (data.visitors && data.visitors.length > 0) {
                            resultsContainer.innerHTML = data.visitors.map(visitor => `
                                <div class="visitor-result p-3 sm:p-4 border rounded-lg cursor-pointer hover:bg-blue-50 hover:border-blue-300 transition-all duration-200" 
                                     onclick="selectVisitor('${visitor.visitor_id}', '${visitor.full_name}', '${visitor.phone}', '${visitor.company || ''}', '${visitor.vehicle_number || ''}', '${visitor.current_status}')">
                                    <div class="flex flex-col sm:flex-row sm:justify-between sm:items-start space-y-2 sm:space-y-0">
                                        <div class="flex-1">
                                            <h4 class="font-medium text-gray-900 text-sm sm:text-base">${visitor.full_name}</h4>
                                            <p class="text-xs sm:text-sm text-gray-600">${visitor.phone}</p>
                                            ${visitor.company ? `<p class="text-xs sm:text-sm text-gray-500">${visitor.company}</p>` : ''}
                                        </div>
                                        <div class="text-right">
                                            <span class="px-2 py-1 text-xs rounded-full ${visitor.current_status === 'Inside' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800'}">
                                                ${visitor.current_status}
                                            </span>
                                            <p class="text-xs text-gray-500 mt-1">${visitor.last_activity}</p>
                                        </div>
                                    </div>
                                </div>
                            `).join('');
                        } else {
                            resultsContainer.innerHTML = `
                                <div class="text-center text-gray-500 py-6 sm:py-8">
                                    <i class="fas fa-user-slash text-2xl sm:text-3xl mb-2 text-gray-300"></i>
                                    <p class="text-sm sm:text-base">No visitors found</p>
                                    <p class="text-xs sm:text-sm text-gray-400 mt-1">Try a different search term</p>
                                </div>
                            `;
                        }
                    })
                    .catch(error => {
                        console.error('Search error:', error);
                        document.getElementById('search_results').innerHTML = `
                            <div class="text-center text-red-500 py-6 sm:py-8">
                                <i class="fas fa-exclamation-triangle text-2xl sm:text-3xl mb-2"></i>
                                <p class="text-sm sm:text-base">Search failed</p>
                                <p class="text-xs sm:text-sm text-red-400 mt-1">Please try again</p>
                            </div>
                        `;
                    });
            }, 300);
        });

        function selectVisitor(id, name, phone, company, vehicle, status) {
            document.getElementById('visitor_id').value = id;
            document.getElementById('selected_visitor').classList.remove('hidden');
            document.getElementById('visitor_info').innerHTML = `
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 sm:gap-4 text-xs sm:text-sm">
                    <div><span class="font-medium">Name:</span> ${name}</div>
                    <div><span class="font-medium">Phone:</span> ${phone}</div>
                    ${company ? `<div><span class="font-medium">Company:</span> ${company}</div>` : ''}
                    ${vehicle ? `<div><span class="font-medium">Vehicle:</span> ${vehicle}</div>` : ''}
                    <div><span class="font-medium">Status:</span> 
                        <span class="px-2 py-1 text-xs rounded-full ${status === 'Inside' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800'}">
                            ${status}
                        </span>
                    </div>
                </div>
            `;
            
            resetActionButtons();
        }

        document.getElementById('checkin_btn').addEventListener('click', function() {
            selectAction('check_in', this);
        });

        document.getElementById('checkout_btn').addEventListener('click', function() {
            selectAction('check_out', this);
        });

        function selectAction(action, button) {
            selectedAction = action;
            document.getElementById('action').value = action;
            
            resetActionButtons();
            
            if (action === 'check_in') {
                button.classList.remove('border-green-300', 'text-green-700');
                button.classList.add('bg-green-500', 'text-white', 'selected');
            } else {
                button.classList.remove('border-red-300', 'text-red-700');
                button.classList.add('bg-red-500', 'text-white', 'selected');
            }
            
            if (document.getElementById('visitor_id').value) {
                document.getElementById('submit_btn').disabled = false;
            }
        }

        function resetActionButtons() {
            const checkinBtn = document.getElementById('checkin_btn');
            const checkoutBtn = document.getElementById('checkout_btn');
            
            checkinBtn.classList.remove('bg-green-500', 'text-white', 'selected');
            checkinBtn.classList.add('border-green-300', 'text-green-700');
            
            checkoutBtn.classList.remove('bg-red-500', 'text-white', 'selected');
            checkoutBtn.classList.add('border-red-300', 'text-red-700');
            
            selectedAction = null;
            document.getElementById('submit_btn').disabled = true;
        }

        document.getElementById('checkinForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            if (!selectedAction || !document.getElementById('visitor_id').value) {
                showNotification('Please select a visitor and action', 'error');
                return;
            }
            
            const formData = new FormData(this);
            const submitBtn = document.getElementById('submit_btn');
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
            
            fetch('quick-checkin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('success_title').textContent = 
                        data.action === 'check_in' ? 'Check-in Successful!' : 'Check-out Successful!';
                    document.getElementById('success_message').textContent = data.message;
                    document.getElementById('successModal').classList.remove('hidden');
                    
                    resetForm();
                    
                    loadQuickStats();
                } else {
                    showNotification('Error: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred. Please try again.', 'error');
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-check mr-2"></i>Process Action';
            });
        });

        function resetForm() {
            document.getElementById('checkinForm').reset();
            document.getElementById('selected_visitor').classList.add('hidden');
            resetActionButtons();
            document.getElementById('visitor_search').value = '';
            document.getElementById('search_results').innerHTML = `
                <div class="text-center text-gray-500 py-6 sm:py-8">
                    <i class="fas fa-search text-2xl sm:text-3xl mb-2 text-gray-300"></i>
                    <p class="text-sm sm:text-base">Start typing to search for visitors</p>
                </div>
            `;
        }

        function closeModal() {
            document.getElementById('successModal').classList.add('hidden');
        }

        function loadQuickStats() {
            fetch('api-stats.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('inside-count').textContent = data.stats.currently_inside;
                        document.getElementById('today-checkins').textContent = data.stats.today_check_ins;
                        document.getElementById('today-checkouts').textContent = data.stats.today_check_outs;
                        document.getElementById('unique-visitors').textContent = data.stats.today_unique_visitors;
                    }
                })
                .catch(error => {
                    console.error('Stats loading error:', error);
                });
        }

        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 z-50 p-3 sm:p-4 rounded-lg border max-w-sm transform transition-all duration-300 translate-x-full opacity-0 ${
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
                    <button onclick="this.parentElement.parentElement.remove()" class="ml-2 text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.classList.remove('translate-x-full', 'opacity-0');
            }, 100);
            
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

        document.getElementById('visitor_search').focus();

        setInterval(loadQuickStats, 30000);

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                resetForm();
            } else if (e.key === 'Enter' && document.activeElement.id === 'visitor_search') {
                e.preventDefault();
            } else if (e.key === '1' && e.ctrlKey) {
                e.preventDefault();
                document.getElementById('checkin_btn').click();
            } else if (e.key === '2' && e.ctrlKey) {
                e.preventDefault();
                document.getElementById('checkout_btn').click();
            }
        });

        if (window.innerWidth <= 768) {
            document.getElementById('visitor_search').addEventListener('focus', function() {
                setTimeout(() => {
                    this.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 300);
            });
        }

        let isSubmitting = false;
        document.getElementById('checkinForm').addEventListener('submit', function(e) {
            if (isSubmitting) {
                e.preventDefault();
                return;
            }
            isSubmitting = true;
            setTimeout(() => { isSubmitting = false; }, 3000);
        });
    </script>
</body>
</html>