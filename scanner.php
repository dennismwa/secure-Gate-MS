<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);
$settings = getSettings($db);

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['qr_data'])) {
    $qr_data = sanitizeInput($_POST['qr_data']);
    $purpose_of_visit = sanitizeInput($_POST['purpose_of_visit'] ?? '');
    $host_name = sanitizeInput($_POST['host_name'] ?? '');
    $host_department = sanitizeInput($_POST['host_department'] ?? '');
    $notes = sanitizeInput($_POST['notes'] ?? '');
    
    $stmt = $db->prepare("SELECT * FROM visitors WHERE qr_code = ? AND status = 'active'");
    $stmt->execute([$qr_data]);
    $visitor = $stmt->fetch();
    
    if (!$visitor) {
        $stmt = $db->prepare("SELECT * FROM pre_registrations WHERE qr_code = ? AND status = 'approved' AND visit_date >= CURDATE()");
        $stmt->execute([$qr_data]);
        $pre_reg = $stmt->fetch();
        
        if ($pre_reg) {
            $visitor_id = generateUniqueId('VIS');
            $new_qr = generateQRCode($visitor_id . $pre_reg['phone']);
            
            $stmt = $db->prepare("INSERT INTO visitors (visitor_id, full_name, phone, email, company, vehicle_number, qr_code, is_pre_registered) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
            $stmt->execute([$visitor_id, $pre_reg['full_name'], $pre_reg['phone'], $pre_reg['email'], $pre_reg['company'], $pre_reg['vehicle_number'], $new_qr]);
            
            $stmt = $db->prepare("UPDATE pre_registrations SET status = 'used' WHERE id = ?");
            $stmt->execute([$pre_reg['id']]);
            
            $stmt = $db->prepare("SELECT * FROM visitors WHERE visitor_id = ?");
            $stmt->execute([$visitor_id]);
            $visitor = $stmt->fetch();
        }
    }
    
    if ($visitor) {
        $stmt = $db->prepare("SELECT log_type FROM gate_logs WHERE visitor_id = ? ORDER BY log_timestamp DESC LIMIT 1");
        $stmt->execute([$visitor['visitor_id']]);
        $last_log = $stmt->fetch();
        
        $next_action = (!$last_log || $last_log['log_type'] == 'check_out') ? 'check_in' : 'check_out';
        
        $stmt = $db->prepare("INSERT INTO gate_logs (visitor_id, log_type, operator_id, purpose_of_visit, host_name, host_department, vehicle_number, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$visitor['visitor_id'], $next_action, $session['operator_id'], $purpose_of_visit, $host_name, $host_department, $visitor['vehicle_number'], $notes]);
        
        try {
            $action_text = $next_action === 'check_in' ? 'checked in' : 'checked out';
            createNotification($db, $next_action, ucfirst(str_replace('_', ' ', $next_action)), "Visitor {$visitor['full_name']} has $action_text", $visitor['visitor_id'], $session['operator_id']);
        } catch (Exception $e) {
            error_log("Notification creation error: " . $e->getMessage());
        }
        
        logActivity($db, $session['operator_id'], 'gate_scan', "QR scan $next_action for visitor: {$visitor['full_name']}", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        
        $scan_result = [
            'success' => true,
            'visitor' => $visitor,
            'action' => $next_action,
            'message' => ucfirst(str_replace('_', ' ', $next_action)) . ' successful'
        ];
    } else {
        $scan_result = [
            'success' => false,
            'message' => 'Invalid QR code or visitor not found'
        ];
    }
}

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management System'); ?> - QR Scanner</title>
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
        /* Mobile responsive camera */
        @media (max-width: 768px) {
            .camera-container {
                height: 250px !important;
            }
            
            .scanner-frame {
                width: 200px !important;
                height: 200px !important;
            }
            
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
        }
        
        /* Scanner overlay */
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
            background: transparent;
        }
        
        /* Pulse animation */
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .animate-pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
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
                    <div class="h-8 w-8 sm:h-10 sm:w-10 bg-blue-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-qrcode text-white text-sm sm:text-base"></i>
                    </div>
                    <div>
                        <h1 class="text-lg sm:text-xl font-semibold text-gray-900">QR Scanner</h1>
                        <p class="text-xs sm:text-sm text-gray-500 hidden sm:block">Scan visitor QR codes</p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <span class="text-xs sm:text-sm text-gray-500 hidden sm:block">
                        <?php echo htmlspecialchars($session['operator_name']); ?>
                    </span>
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

        <?php if (isset($scan_result)): ?>
            
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
                        <?php if ($scan_result['success'] && isset($scan_result['visitor'])): ?>
                            <div class="mt-2 text-sm sm:text-base <?php echo $scan_result['success'] ? 'text-green-700' : 'text-red-700'; ?>">
                                <p class="font-medium"><?php echo htmlspecialchars($scan_result['visitor']['full_name']); ?></p>
                                <p><?php echo htmlspecialchars($scan_result['visitor']['phone']); ?>
                                    <?php if ($scan_result['visitor']['company']): ?>
                                        • <?php echo htmlspecialchars($scan_result['visitor']['company']); ?>
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
                                        <span class="font-medium text-gray-600">ID:</span>
                                        <div class="mt-1"><?php echo htmlspecialchars($scan_result['visitor']['visitor_id']); ?></div>
                                    </div>
                                    <?php if ($scan_result['visitor']['vehicle_number']): ?>
                                        <div>
                                            <span class="font-medium text-gray-600">Vehicle:</span>
                                            <div class="mt-1"><?php echo htmlspecialchars($scan_result['visitor']['vehicle_number']); ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-8">
            
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-4 sm:mb-6">
                    <i class="fas fa-camera mr-2"></i>Camera Scanner
                </h3>
                
                <div class="relative camera-container">
                    <video id="video" class="w-full h-48 sm:h-64 bg-gray-900 rounded-lg"></video>
                    <canvas id="canvas" class="hidden"></canvas>
                    
                    <div class="scanner-overlay">
                        <div class="scanner-frame"></div>
                    </div>
                    
                    <div id="scanning-indicator" class="absolute top-2 sm:top-4 right-2 sm:right-4 bg-red-500 text-white px-2 sm:px-3 py-1 rounded-full text-xs sm:text-sm font-medium hidden">
                        <i class="fas fa-circle animate-pulse mr-1"></i>Scanning...
                    </div>
                </div>
                
                <div class="mt-4 flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-4">
                    <button id="startCamera" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-3 sm:px-4 py-2 rounded-lg font-medium transition-colors text-sm sm:text-base">
                        <i class="fas fa-play mr-2"></i>Start Camera
                    </button>
                    <button id="stopCamera" class="flex-1 bg-gray-600 hover:bg-gray-700 text-white px-3 sm:px-4 py-2 rounded-lg font-medium transition-colors text-sm sm:text-base" disabled>
                        <i class="fas fa-stop mr-2"></i>Stop Camera
                    </button>
                </div>
                
                <div class="mt-4 text-xs sm:text-sm text-gray-600">
                    <div class="flex items-center mb-2">
                        <i class="fas fa-info-circle mr-2 text-blue-500"></i>
                        <span>Position QR code within the blue frame</span>
                    </div>
                    <div class="flex items-center">
                        <i class="fas fa-lightbulb mr-2 text-yellow-500"></i>
                        <span>Ensure good lighting for best results</span>
                    </div>
                </div>
            </div>

            
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-4 sm:mb-6">
                    <i class="fas fa-keyboard mr-2"></i>Additional Information
                </h3>
                
                <form id="scanForm" method="POST" class="space-y-4">
                    <input type="hidden" id="qr_data" name="qr_data">
                    
                    <div>
                        <label for="manual_qr" class="block text-sm font-medium text-gray-700">Manual QR Entry</label>
                        <input type="text" id="manual_qr" placeholder="Enter QR code manually" 
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base">
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
                    
                    <button type="button" id="processManualQR" class="w-full bg-green-600 hover:bg-green-700 text-white px-4 py-3 rounded-lg font-medium transition-colors text-sm sm:text-base">
                        <i class="fas fa-check mr-2"></i>Process Manual Entry
                    </button>
                </form>
            </div>
        </div>

        
        <div class="mt-6 sm:mt-8 grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6">
            <a href="visitors.php?action=register" class="bg-blue-600 hover:bg-blue-700 text-white p-4 rounded-xl text-center transition-colors">
                <i class="fas fa-user-plus text-xl sm:text-2xl mb-2"></i>
                <h4 class="font-semibold text-sm sm:text-base">Register Visitor</h4>
                <p class="text-xs sm:text-sm opacity-90">Add new visitor</p>
            </a>
            
            <a href="pre-register.php?action=create" class="bg-purple-600 hover:bg-purple-700 text-white p-4 rounded-xl text-center transition-colors">
                <i class="fas fa-calendar-plus text-xl sm:text-2xl mb-2"></i>
                <h4 class="font-semibold text-sm sm:text-base">Pre-Register</h4>
                <p class="text-xs sm:text-sm opacity-90">Schedule visit</p>
            </a>
            
            <a href="visitors.php" class="bg-green-600 hover:bg-green-700 text-white p-4 rounded-xl text-center transition-colors">
                <i class="fas fa-users text-xl sm:text-2xl mb-2"></i>
                <h4 class="font-semibold text-sm sm:text-base">View Visitors</h4>
                <p class="text-xs sm:text-sm opacity-90">Manage visitors</p>
            </a>
        </div>
    </div>

    
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
                
                showNotification('Camera started successfully', 'success');
                
            } catch (err) {
                console.error('Error accessing camera:', err);
                showNotification('Unable to access camera. Please ensure camera permissions are granted.', 'error');
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
            
            showNotification('Camera stopped', 'info');
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
                    processQRCode(code.data);
                    return;
                }
            }
            
            requestAnimationFrame(scanForQR);
        }

        function processQRCode(qrData) {
            stopCamera();
            document.getElementById('qr_data').value = qrData;
            document.getElementById('manual_qr').value = qrData;
            
            showNotification('QR Code detected! Processing...', 'success');
            
            setTimeout(() => {
                document.getElementById('scanForm').submit();
            }, 500);
        }

        document.getElementById('processManualQR').addEventListener('click', function() {
            const manualQR = document.getElementById('manual_qr').value.trim();
            if (manualQR) {
                document.getElementById('qr_data').value = manualQR;
                
                const button = this;
                const originalText = button.innerHTML;
                button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
                button.disabled = true;
                
                setTimeout(() => {
                    document.getElementById('scanForm').submit();
                }, 500);
            } else {
                showNotification('Please enter a QR code or scan using the camera', 'warning');
            }
        });

        document.getElementById('manual_qr').addEventListener('focus', function() {
            if (scanning) {
                stopCamera();
            }
        });

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
            
            setTimeout(() => {
                notification.classList.remove('translate-x-full', 'opacity-0');
            }, 100);
            
            setTimeout(() => {
                notification.classList.add('translate-x-full', 'opacity-0');
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }

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

        if (window.innerWidth > 768) {
            let lastActivity = Date.now();
            setInterval(() => {
                if (Date.now() - lastActivity > 60000 && !scanning) {
                    window.location.reload();
                }
            }, 60000);

            document.addEventListener('click', () => lastActivity = Date.now());
            document.addEventListener('keypress', () => lastActivity = Date.now());
        }

        window.addEventListener('orientationchange', function() {
            if (scanning) {
                setTimeout(() => {
                    stopCamera();
                    setTimeout(startCamera, 500);
                }, 500);
            }
        });

        document.addEventListener('visibilitychange', function() {
            if (document.hidden && scanning) {
                stopCamera();
            }
        });

        let touchStartY = 0;
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

        document.getElementById('scanForm').addEventListener('submit', function(e) {
            if (!document.getElementById('qr_data').value) {
                e.preventDefault();
                showNotification('Please scan a QR code or enter one manually', 'warning');
                return;
            }
        });

        if (window.innerWidth <= 768) {
            window.addEventListener('load', function() {
                setTimeout(() => {
                    if (confirm('Start camera for QR scanning?')) {
                        startCamera();
                    }
                }, 1000);
            });
        }
    </script>
</body>
</html>