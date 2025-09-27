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

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $updates = [
        'system_name' => sanitizeInput($_POST['system_name']),
        'primary_color' => sanitizeInput($_POST['primary_color']),
        'secondary_color' => sanitizeInput($_POST['secondary_color']),
        'accent_color' => sanitizeInput($_POST['accent_color']),
        'email_notifications' => isset($_POST['email_notifications']) ? 'true' : 'false',
        'smtp_host' => sanitizeInput($_POST['smtp_host']),
        'smtp_port' => sanitizeInput($_POST['smtp_port']),
        'smtp_username' => sanitizeInput($_POST['smtp_username']),
        'session_timeout' => intval($_POST['session_timeout'])
    ];
    
    if (!empty($_POST['smtp_password'])) {
        $updates['smtp_password'] = sanitizeInput($_POST['smtp_password']);
    }
    
    $success = true;
    foreach ($updates as $key => $value) {
        if (!updateSetting($db, $key, $value)) {
            $success = false;
            break;
        }
    }
    
    if ($success) {
        logActivity($db, $session['operator_id'], 'settings_update', 'Updated system settings', $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        setMessage('Settings updated successfully', 'success');
    } else {
        setMessage('Failed to update some settings', 'error');
    }
    
    header('Location: settings.php');
    exit;
}

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management System'); ?> - Settings</title>
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
            
            .mobile-scroll {
                overflow-x: auto !important;
            }
        }
        
        /* Color preview animation */
        .color-preview {
            transition: all 0.3s ease;
        }
        
        .color-preview:hover {
            transform: scale(1.1);
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
                    <div class="h-8 w-8 sm:h-10 sm:w-10 bg-gray-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-cog text-white text-sm sm:text-base"></i>
                    </div>
                    <div>
                        <h1 class="text-lg sm:text-xl font-semibold text-gray-900">System Settings</h1>
                        <p class="text-xs sm:text-sm text-gray-500 hidden sm:block">Configure preferences</p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-2 sm:space-x-4 mobile-scroll">
                    <a href="system-health.php" class="text-blue-600 hover:text-blue-800 text-xs sm:text-sm whitespace-nowrap">
                        <i class="fas fa-heartbeat"></i>
                        <span class="ml-1 hidden sm:inline">Health</span>
                    </a>
                    <a href="backup-system.php" class="text-green-600 hover:text-green-800 text-xs sm:text-sm whitespace-nowrap">
                        <i class="fas fa-database"></i>
                        <span class="ml-1 hidden sm:inline">Backup</span>
                    </a>
                    <a href="manage-departments.php" class="text-purple-600 hover:text-purple-800 text-xs sm:text-sm whitespace-nowrap">
                        <i class="fas fa-building"></i>
                        <span class="ml-1 hidden sm:inline">Departments</span>
                    </a>
                    <a href="operators.php" class="text-blue-600 hover:text-blue-800 text-xs sm:text-sm whitespace-nowrap">
                        <i class="fas fa-users-cog"></i>
                        <span class="ml-1 hidden sm:inline">Operators</span>
                    </a>
                    <a href="dashboard.php" class="text-blue-600 hover:text-blue-800 text-xs sm:text-sm whitespace-nowrap">
                        <i class="fas fa-home"></i>
                        <span class="ml-1 hidden sm:inline">Dashboard</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-4xl mx-auto px-2 sm:px-4 lg:px-8 py-4 sm:py-8">
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

        <form method="POST" class="space-y-6 sm:space-y-8">
            
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-4 sm:mb-6">General Settings</h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                    <div class="md:col-span-2">
                        <label for="system_name" class="block text-sm font-medium text-gray-700">System Name</label>
                        <input type="text" id="system_name" name="system_name" 
                               value="<?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management System'); ?>"
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" 
                               placeholder="Gate Management System">
                    </div>
                    
                    <div class="md:col-span-2">
                        <label for="session_timeout" class="block text-sm font-medium text-gray-700">Session Timeout</label>
                        <select id="session_timeout" name="session_timeout" 
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base">
                            <option value="1800" <?php echo ($settings['session_timeout'] ?? '3600') == '1800' ? 'selected' : ''; ?>>30 minutes</option>
                            <option value="3600" <?php echo ($settings['session_timeout'] ?? '3600') == '3600' ? 'selected' : ''; ?>>1 hour</option>
                            <option value="7200" <?php echo ($settings['session_timeout'] ?? '3600') == '7200' ? 'selected' : ''; ?>>2 hours</option>
                            <option value="14400" <?php echo ($settings['session_timeout'] ?? '3600') == '14400' ? 'selected' : ''; ?>>4 hours</option>
                        </select>
                    </div>
                </div>
            </div>

            
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-4 sm:mb-6">Theme & Colors</h3>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6">
                    <div>
                        <label for="primary_color" class="block text-sm font-medium text-gray-700 mb-2">Primary Color</label>
                        <div class="flex items-center space-x-3">
                            <input type="color" id="primary_color" name="primary_color" 
                                   value="<?php echo $settings['primary_color'] ?? '#2563eb'; ?>"
                                   class="h-10 w-12 sm:w-16 border border-gray-300 rounded cursor-pointer">
                            <input type="text" id="primary_color_text"
                                   value="<?php echo $settings['primary_color'] ?? '#2563eb'; ?>"
                                   class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                                   readonly>
                        </div>
                    </div>
                    
                    <div>
                        <label for="secondary_color" class="block text-sm font-medium text-gray-700 mb-2">Secondary Color</label>
                        <div class="flex items-center space-x-3">
                            <input type="color" id="secondary_color" name="secondary_color" 
                                   value="<?php echo $settings['secondary_color'] ?? '#1f2937'; ?>"
                                   class="h-10 w-12 sm:w-16 border border-gray-300 rounded cursor-pointer">
                            <input type="text" id="secondary_color_text"
                                   value="<?php echo $settings['secondary_color'] ?? '#1f2937'; ?>"
                                   class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                                   readonly>
                        </div>
                    </div>
                    
                    <div>
                        <label for="accent_color" class="block text-sm font-medium text-gray-700 mb-2">Accent Color</label>
                        <div class="flex items-center space-x-3">
                            <input type="color" id="accent_color" name="accent_color" 
                                   value="<?php echo $settings['accent_color'] ?? '#10b981'; ?>"
                                   class="h-10 w-12 sm:w-16 border border-gray-300 rounded cursor-pointer">
                            <input type="text" id="accent_color_text"
                                   value="<?php echo $settings['accent_color'] ?? '#10b981'; ?>"
                                   class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                                   readonly>
                        </div>
                    </div>
                </div>
                
                <div class="mt-4 sm:mt-6 p-4 bg-gray-50 rounded-lg">
                    <p class="text-sm font-medium text-gray-700 mb-3">Color Preview:</p>
                    <div class="flex flex-wrap gap-3 sm:gap-4">
                        <div class="flex flex-col items-center">
                            <div id="color_preview_primary" class="color-preview w-12 h-12 sm:w-16 sm:h-16 rounded-lg border-2 border-white shadow-md cursor-pointer" 
                                 style="background-color: <?php echo $settings['primary_color'] ?? '#2563eb'; ?>"></div>
                            <span class="text-xs text-gray-600 mt-1">Primary</span>
                        </div>
                        <div class="flex flex-col items-center">
                            <div id="color_preview_secondary" class="color-preview w-12 h-12 sm:w-16 sm:h-16 rounded-lg border-2 border-white shadow-md cursor-pointer" 
                                 style="background-color: <?php echo $settings['secondary_color'] ?? '#1f2937'; ?>"></div>
                            <span class="text-xs text-gray-600 mt-1">Secondary</span>
                        </div>
                        <div class="flex flex-col items-center">
                            <div id="color_preview_accent" class="color-preview w-12 h-12 sm:w-16 sm:h-16 rounded-lg border-2 border-white shadow-md cursor-pointer" 
                                 style="background-color: <?php echo $settings['accent_color'] ?? '#10b981'; ?>"></div>
                            <span class="text-xs text-gray-600 mt-1">Accent</span>
                        </div>
                    </div>
                </div>
            </div>

            
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-4 sm:mb-6">Email Notifications</h3>
                
                <div class="space-y-4 sm:space-y-6">
                    <div class="flex items-center">
                        <input type="checkbox" id="email_notifications" name="email_notifications" 
                               <?php echo ($settings['email_notifications'] ?? 'false') === 'true' ? 'checked' : ''; ?>
                               class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                        <label for="email_notifications" class="ml-2 block text-sm text-gray-900">
                            Enable email notifications
                        </label>
                    </div>
                    
                    <div id="smtp_settings" class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6">
                        <div>
                            <label for="smtp_host" class="block text-sm font-medium text-gray-700">SMTP Host</label>
                            <input type="text" id="smtp_host" name="smtp_host" 
                                   value="<?php echo htmlspecialchars($settings['smtp_host'] ?? ''); ?>"
                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" 
                                   placeholder="smtp.gmail.com">
                        </div>
                        
                        <div>
                            <label for="smtp_port" class="block text-sm font-medium text-gray-700">SMTP Port</label>
                            <input type="number" id="smtp_port" name="smtp_port" 
                                   value="<?php echo htmlspecialchars($settings['smtp_port'] ?? '587'); ?>"
                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" 
                                   placeholder="587">
                        </div>
                        
                        <div>
                            <label for="smtp_username" class="block text-sm font-medium text-gray-700">SMTP Username</label>
                            <input type="email" id="smtp_username" name="smtp_username" 
                                   value="<?php echo htmlspecialchars($settings['smtp_username'] ?? ''); ?>"
                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" 
                                   placeholder="your-email@gmail.com">
                        </div>
                        
                        <div>
                            <label for="smtp_password" class="block text-sm font-medium text-gray-700">SMTP Password</label>
                            <input type="password" id="smtp_password" name="smtp_password" 
                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" 
                                   placeholder="Leave blank to keep current password">
                        </div>
                    </div>
                    
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 sm:p-4">
                        <div class="flex">
                            <i class="fas fa-info-circle text-blue-600 mt-0.5 mr-2 flex-shrink-0"></i>
                            <div class="text-sm text-blue-800">
                                <p class="font-medium mb-1">Email Configuration Tips:</p>
                                <ul class="list-disc list-inside space-y-1 text-xs sm:text-sm">
                                    <li>For Gmail: Use app passwords instead of your regular password</li>
                                    <li>Common ports: 587 (TLS), 465 (SSL), 25 (unsecured)</li>
                                    <li>Test email functionality after saving settings</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            
            <div class="flex flex-col sm:flex-row justify-end space-y-3 sm:space-y-0 sm:space-x-4">
                <button type="button" onclick="resetToDefaults()" class="px-4 py-2 sm:px-6 sm:py-3 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 font-medium transition-colors text-sm sm:text-base">
                    <i class="fas fa-undo mr-2"></i>Reset to Defaults
                </button>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 sm:px-6 sm:py-3 rounded-lg font-medium transition-colors text-sm sm:text-base">
                    <i class="fas fa-save mr-2"></i>Save Settings
                </button>
            </div>
        </form>
    </div>

    <script>
        function setupColorPicker(colorName) {
            const picker = document.getElementById(colorName + '_color');
            const textInput = document.getElementById(colorName + '_color_text');
            const previewElement = document.getElementById('color_preview_' + colorName);
            
            picker.addEventListener('input', function() {
                textInput.value = this.value;
                if (previewElement) {
                    previewElement.style.backgroundColor = this.value;
                }
            });
            
            if (previewElement) {
                previewElement.addEventListener('click', function() {
                    picker.click();
                });
            }
        }

        setupColorPicker('primary');
        setupColorPicker('secondary');
        setupColorPicker('accent');

        document.getElementById('email_notifications').addEventListener('change', function() {
            const smtpSettings = document.getElementById('smtp_settings');
            const inputs = smtpSettings.querySelectorAll('input');
            
            if (this.checked) {
                smtpSettings.style.opacity = '1';
                inputs.forEach(input => input.disabled = false);
            } else {
                smtpSettings.style.opacity = '0.5';
                inputs.forEach(input => input.disabled = true);
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            const emailCheckbox = document.getElementById('email_notifications');
            emailCheckbox.dispatchEvent(new Event('change'));
        });

        function resetToDefaults() {
            if (confirm('Are you sure you want to reset all settings to default values?')) {
                document.getElementById('system_name').value = 'Gate Management System';
                
                document.getElementById('primary_color').value = '#2563eb';
                document.getElementById('secondary_color').value = '#1f2937';
                document.getElementById('accent_color').value = '#10b981';
                
                document.getElementById('session_timeout').value = '3600';
                
                document.getElementById('email_notifications').checked = false;
                document.getElementById('smtp_host').value = '';
                document.getElementById('smtp_port').value = '587';
                document.getElementById('smtp_username').value = '';
                document.getElementById('smtp_password').value = '';
                
                document.getElementById('primary_color_text').value = '#2563eb';
                document.getElementById('secondary_color_text').value = '#1f2937';
                document.getElementById('accent_color_text').value = '#10b981';
                
                document.getElementById('color_preview_primary').style.backgroundColor = '#2563eb';
                document.getElementById('color_preview_secondary').style.backgroundColor = '#1f2937';
                document.getElementById('color_preview_accent').style.backgroundColor = '#10b981';
                
                document.getElementById('email_notifications').dispatchEvent(new Event('change'));
                
                showNotification('Settings reset to defaults. Click "Save Settings" to apply changes.', 'info');
            }
        }

        document.querySelector('form').addEventListener('submit', function(e) {
            const button = this.querySelector('button[type="submit"]');
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving...';
            button.disabled = true;
            
            setTimeout(() => {
                button.innerHTML = originalText;
                button.disabled = false;
            }, 10000);
        });

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

        let hasChanges = false;
        const form = document.querySelector('form');
        const inputs = form.querySelectorAll('input, select, textarea');
        
        inputs.forEach(input => {
            input.addEventListener('change', () => {
                hasChanges = true;
            });
        });

        window.addEventListener('beforeunload', function(e) {
            if (hasChanges) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        form.addEventListener('submit', () => {
            hasChanges = false;
        });

        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                form.submit();
            } else if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                resetToDefaults();
            }
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

        function saveDraft() {
            const formData = new FormData(form);
            const draftData = {};
            for (let [key, value] of formData.entries()) {
                draftData[key] = value;
            }
            localStorage.setItem('settings_draft', JSON.stringify(draftData));
        }

        setInterval(() => {
            if (hasChanges) {
                saveDraft();
            }
        }, 30000);

        const savedDraft = localStorage.getItem('settings_draft');
        if (savedDraft) {
            try {
                const draftData = JSON.parse(savedDraft);
                showNotification('Unsaved changes detected. They will be preserved as you edit.', 'info');
            } catch (e) {
                localStorage.removeItem('settings_draft');
            }
        }

        form.addEventListener('submit', () => {
            localStorage.removeItem('settings_draft');
        });
    </script>
</body>
</html>