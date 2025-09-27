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

function checkSystemHealth($db) {
    $health = [
        'overall' => 'good',
        'checks' => [],
        'warnings' => [],
        'errors' => []
    ];
    
    try {
        $stmt = $db->query("SELECT 1");
        $health['checks']['database'] = [
            'status' => 'good',
            'message' => 'Database connection is healthy'
        ];
    } catch (Exception $e) {
        $health['checks']['database'] = [
            'status' => 'error',
            'message' => 'Database connection failed: ' . $e->getMessage()
        ];
        $health['errors'][] = 'Database connection error';
        $health['overall'] = 'error';
    }
    
    $required_tables = ['visitors', 'gate_logs', 'gate_operators', 'settings'];
    $missing_tables = [];
    
    foreach ($required_tables as $table) {
        try {
            $stmt = $db->query("SHOW TABLES LIKE '$table'");
            if (!$stmt->fetch()) {
                $missing_tables[] = $table;
            }
        } catch (Exception $e) {
            $missing_tables[] = $table;
        }
    }
    
    if (empty($missing_tables)) {
        $health['checks']['tables'] = [
            'status' => 'good',
            'message' => 'All required tables exist'
        ];
    } else {
        $health['checks']['tables'] = [
            'status' => 'error',
            'message' => 'Missing tables: ' . implode(', ', $missing_tables)
        ];
        $health['errors'][] = 'Missing database tables';
        $health['overall'] = 'error';
    }
    
    $directories = ['uploads/', 'uploads/photos/', 'uploads/photos/visitors/', 'backups/'];
    $permission_issues = [];
    
    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                $permission_issues[] = $dir . ' (cannot create)';
            }
        } elseif (!is_writable($dir)) {
            $permission_issues[] = $dir . ' (not writable)';
        }
    }
    
    if (empty($permission_issues)) {
        $health['checks']['permissions'] = [
            'status' => 'good',
            'message' => 'File permissions are correct'
        ];
    } else {
        $health['checks']['permissions'] = [
            'status' => 'warning',
            'message' => 'Permission issues: ' . implode(', ', $permission_issues)
        ];
        $health['warnings'][] = 'File permission problems';
        if ($health['overall'] === 'good') {
            $health['overall'] = 'warning';
        }
    }
    
    $free_space = disk_free_space('.');
    $total_space = disk_total_space('.');
    $free_percentage = ($free_space / $total_space) * 100;
    
    if ($free_percentage > 20) {
        $health['checks']['disk_space'] = [
            'status' => 'good',
            'message' => 'Disk space is sufficient (' . round($free_percentage, 1) . '% free)'
        ];
    } elseif ($free_percentage > 10) {
        $health['checks']['disk_space'] = [
            'status' => 'warning',
            'message' => 'Disk space is getting low (' . round($free_percentage, 1) . '% free)'
        ];
        $health['warnings'][] = 'Low disk space';
        if ($health['overall'] === 'good') {
            $health['overall'] = 'warning';
        }
    } else {
        $health['checks']['disk_space'] = [
            'status' => 'error',
            'message' => 'Disk space is critically low (' . round($free_percentage, 1) . '% free)'
        ];
        $health['errors'][] = 'Critical disk space';
        $health['overall'] = 'error';
    }
    
    $php_version = phpversion();
    if (version_compare($php_version, '7.4.0', '>=')) {
        $health['checks']['php_version'] = [
            'status' => 'good',
            'message' => 'PHP version is supported (' . $php_version . ')'
        ];
    } else {
        $health['checks']['php_version'] = [
            'status' => 'warning',
            'message' => 'PHP version is outdated (' . $php_version . ')'
        ];
        $health['warnings'][] = 'Outdated PHP version';
        if ($health['overall'] === 'good') {
            $health['overall'] = 'warning';
        }
    }
    
    $required_extensions = ['pdo', 'pdo_mysql', 'gd', 'zip'];
    $missing_extensions = [];
    
    foreach ($required_extensions as $ext) {
        if (!extension_loaded($ext)) {
            $missing_extensions[] = $ext;
        }
    }
    
    if (empty($missing_extensions)) {
        $health['checks']['extensions'] = [
            'status' => 'good',
            'message' => 'All required PHP extensions are loaded'
        ];
    } else {
        $health['checks']['extensions'] = [
            'status' => 'error',
            'message' => 'Missing extensions: ' . implode(', ', $missing_extensions)
        ];
        $health['errors'][] = 'Missing PHP extensions';
        $health['overall'] = 'error';
    }
    
    try {
        $stmt = $db->query("SELECT COUNT(*) as count FROM gate_logs WHERE log_timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $recent_activity = $stmt->fetch()['count'];
        
        $health['checks']['activity'] = [
            'status' => 'good',
            'message' => $recent_activity . ' gate activities in the last 24 hours'
        ];
    } catch (Exception $e) {
        $health['checks']['activity'] = [
            'status' => 'warning',
            'message' => 'Cannot check recent activity'
        ];
        $health['warnings'][] = 'Activity check failed';
        if ($health['overall'] === 'good') {
            $health['overall'] = 'warning';
        }
    }
    
    $backup_dir = 'backups/';
    $recent_backup = false;
    
    if (is_dir($backup_dir)) {
        $backups = glob($backup_dir . '*');
        foreach ($backups as $backup) {
            if (filemtime($backup) > (time() - 7 * 24 * 3600)) { // 7 days
                $recent_backup = true;
                break;
            }
        }
    }
    
    if ($recent_backup) {
        $health['checks']['backup'] = [
            'status' => 'good',
            'message' => 'Recent backup found (within 7 days)'
        ];
    } else {
        $health['checks']['backup'] = [
            'status' => 'warning',
            'message' => 'No recent backup found (create backup recommended)'
        ];
        $health['warnings'][] = 'No recent backup';
        if ($health['overall'] === 'good') {
            $health['overall'] = 'warning';
        }
    }
    
    return $health;
}

function getSystemStats($db) {
    $stats = [];
    
    try {
        $stmt = $db->query("SELECT 
            ROUND(SUM(data_length + index_length) / 1024 / 1024, 1) AS size_mb 
            FROM information_schema.tables 
            WHERE table_schema = DATABASE()");
        $stats['db_size'] = $stmt->fetch()['size_mb'] . ' MB';
    } catch (Exception $e) {
        $stats['db_size'] = 'Unknown';
    }
    
    try {
        $stmt = $db->query("SELECT COUNT(*) as count FROM visitors");
        $stats['total_visitors'] = number_format($stmt->fetch()['count']);
    } catch (Exception $e) {
        $stats['total_visitors'] = 'Unknown';
    }
    
    try {
        $stmt = $db->query("SELECT COUNT(*) as count FROM gate_logs");
        $stats['total_logs'] = number_format($stmt->fetch()['count']);
    } catch (Exception $e) {
        $stats['total_logs'] = 'Unknown';
    }
    
    try {
        $stmt = $db->query("SELECT COUNT(*) as count FROM operator_sessions WHERE expires_at > NOW()");
        $stats['active_sessions'] = $stmt->fetch()['count'];
    } catch (Exception $e) {
        $stats['active_sessions'] = 'Unknown';
    }
    
    $photo_dir = 'uploads/photos/visitors/';
    $photo_size = 0;
    $photo_count = 0;
    
    if (is_dir($photo_dir)) {
        $photos = glob($photo_dir . '*');
        $photo_count = count($photos);
        foreach ($photos as $photo) {
            if (is_file($photo)) {
                $photo_size += filesize($photo);
            }
        }
    }
    
    $stats['photo_count'] = number_format($photo_count);
    $stats['photo_size'] = formatBytes($photo_size);
    
    return $stats;
}

$health = checkSystemHealth($db);
$stats = getSystemStats($db);

$message = getMessage();

function formatBytes($size, $precision = 2) {
    if ($size == 0) return '0 B';
    $base = log($size, 1024);
    $suffixes = array('B', 'KB', 'MB', 'GB', 'TB');
    return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management System'); ?> - System Health</title>
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
</head>
<body class="bg-gray-50">
    
    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <a href="settings.php" class="text-gray-600 hover:text-gray-900 mr-4">
                        <i class="fas fa-arrow-left"></i>
                    </a>
                    <div class="h-10 w-10 bg-<?php echo $health['overall'] === 'good' ? 'green' : ($health['overall'] === 'warning' ? 'yellow' : 'red'); ?>-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-heartbeat text-white"></i>
                    </div>
                    <div class="ml-3">
                        <h1 class="text-xl font-semibold text-gray-900">System Health Monitor</h1>
                        <p class="text-sm text-gray-500">Monitor system performance and health</p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-4">
                    <button onclick="location.reload()" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-sync-alt"></i>
                        <span class="ml-1">Refresh</span>
                    </button>
                    <a href="settings.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-cog"></i>
                        <span class="ml-1">Settings</span>
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

        
        <div class="mb-8">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-16 h-16 rounded-full flex items-center justify-center <?php 
                            echo $health['overall'] === 'good' ? 'bg-green-100 text-green-600' : 
                                ($health['overall'] === 'warning' ? 'bg-yellow-100 text-yellow-600' : 'bg-red-100 text-red-600'); ?>">
                            <i class="fas <?php 
                                echo $health['overall'] === 'good' ? 'fa-check-circle' : 
                                    ($health['overall'] === 'warning' ? 'fa-exclamation-triangle' : 'fa-times-circle'); ?> text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <h2 class="text-2xl font-bold text-gray-900">
                                System Status: <?php echo ucfirst($health['overall']); ?>
                            </h2>
                            <p class="text-gray-600">
                                <?php if ($health['overall'] === 'good'): ?>
                                    All systems are operating normally
                                <?php elseif ($health['overall'] === 'warning'): ?>
                                    Some issues detected that need attention
                                <?php else: ?>
                                    Critical issues detected that require immediate attention
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-sm text-gray-500">Last checked</div>
                        <div class="text-lg font-medium text-gray-900"><?php echo date('M j, Y g:i A'); ?></div>
                    </div>
                </div>
                
                <?php if (!empty($health['errors']) || !empty($health['warnings'])): ?>
                    <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php if (!empty($health['errors'])): ?>
                            <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                                <h4 class="font-medium text-red-800 mb-2">
                                    <i class="fas fa-times-circle mr-2"></i>Critical Issues (<?php echo count($health['errors']); ?>)
                                </h4>
                                <ul class="text-sm text-red-700 space-y-1">
                                    <?php foreach ($health['errors'] as $error): ?>
                                        <li>• <?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($health['warnings'])): ?>
                            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                                <h4 class="font-medium text-yellow-800 mb-2">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>Warnings (<?php echo count($health['warnings']); ?>)
                                </h4>
                                <ul class="text-sm text-yellow-700 space-y-1">
                                    <?php foreach ($health['warnings'] as $warning): ?>
                                        <li>• <?php echo htmlspecialchars($warning); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            
            <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Health Checks</h3>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <?php foreach ($health['checks'] as $check_name => $check): ?>
                            <div class="flex items-center justify-between p-4 rounded-lg border <?php 
                                echo $check['status'] === 'good' ? 'border-green-200 bg-green-50' : 
                                    ($check['status'] === 'warning' ? 'border-yellow-200 bg-yellow-50' : 'border-red-200 bg-red-50'); ?>">
                                <div class="flex items-center">
                                    <div class="w-8 h-8 rounded-full flex items-center justify-center <?php 
                                        echo $check['status'] === 'good' ? 'bg-green-100 text-green-600' : 
                                            ($check['status'] === 'warning' ? 'bg-yellow-100 text-yellow-600' : 'bg-red-100 text-red-600'); ?>">
                                        <i class="fas <?php 
                                            echo $check['status'] === 'good' ? 'fa-check' : 
                                                ($check['status'] === 'warning' ? 'fa-exclamation-triangle' : 'fa-times'); ?> text-sm"></i>
                                    </div>
                                    <div class="ml-3">
                                        <div class="font-medium text-gray-900 capitalize">
                                            <?php echo str_replace('_', ' ', $check_name); ?>
                                        </div>
                                        <div class="text-sm text-gray-600">
                                            <?php echo htmlspecialchars($check['message']); ?>
                                        </div>
                                    </div>
                                </div>
                                <span class="px-2 py-1 text-xs font-medium rounded-full <?php 
                                    echo $check['status'] === 'good' ? 'bg-green-100 text-green-800' : 
                                        ($check['status'] === 'warning' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800'); ?>">
                                    <?php echo ucfirst($check['status']); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            
            <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">System Statistics</h3>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-center">
                            <div class="text-2xl font-bold text-blue-600"><?php echo $stats['total_visitors']; ?></div>
                            <div class="text-sm text-blue-800">Total Visitors</div>
                        </div>
                        
                        <div class="bg-green-50 border border-green-200 rounded-lg p-4 text-center">
                            <div class="text-2xl font-bold text-green-600"><?php echo $stats['total_logs']; ?></div>
                            <div class="text-sm text-green-800">Gate Logs</div>
                        </div>
                        
                        <div class="bg-purple-50 border border-purple-200 rounded-lg p-4 text-center">
                            <div class="text-2xl font-bold text-purple-600"><?php echo $stats['photo_count']; ?></div>
                            <div class="text-sm text-purple-800">Photos Stored</div>
                        </div>
                        
                        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 text-center">
                            <div class="text-2xl font-bold text-yellow-600"><?php echo $stats['active_sessions']; ?></div>
                            <div class="text-sm text-yellow-800">Active Sessions</div>
                        </div>
                    </div>
                    
                    <div class="mt-6 space-y-4">
                        <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                            <span class="text-sm font-medium text-gray-700">Database Size</span>
                            <span class="text-sm text-gray-900"><?php echo $stats['db_size']; ?></span>
                        </div>
                        
                        <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                            <span class="text-sm font-medium text-gray-700">Photo Storage</span>
                            <span class="text-sm text-gray-900"><?php echo $stats['photo_size']; ?></span>
                        </div>
                        
                        <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                            <span class="text-sm font-medium text-gray-700">PHP Version</span>
                            <span class="text-sm text-gray-900"><?php echo phpversion(); ?></span>
                        </div>
                        
                        <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                            <span class="text-sm font-medium text-gray-700">Server Time</span>
                            <span class="text-sm text-gray-900"><?php echo date('Y-m-d H:i:s T'); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        
        <div class="mt-8 grid grid-cols-1 md:grid-cols-4 gap-4">
            <a href="backup-system.php" class="bg-green-600 hover:bg-green-700 text-white p-4 rounded-xl text-center transition-colors">
                <i class="fas fa-database text-2xl mb-2"></i>
                <h4 class="font-semibold">Create Backup</h4>
                <p class="text-sm opacity-90">Backup system data</p>
            </a>
            
            <a href="manage-departments.php" class="bg-blue-600 hover:bg-blue-700 text-white p-4 rounded-xl text-center transition-colors">
                <i class="fas fa-building text-2xl mb-2"></i>
                <h4 class="font-semibold">Departments</h4>
                <p class="text-sm opacity-90">Manage departments</p>
            </a>
            
            <a href="operators.php" class="bg-purple-600 hover:bg-purple-700 text-white p-4 rounded-xl text-center transition-colors">
                <i class="fas fa-users-cog text-2xl mb-2"></i>
                <h4 class="font-semibold">Operators</h4>
                <p class="text-sm opacity-90">Manage operators</p>
            </a>
            
            <a href="reports.php" class="bg-orange-600 hover:bg-orange-700 text-white p-4 rounded-xl text-center transition-colors">
                <i class="fas fa-chart-bar text-2xl mb-2"></i>
                <h4 class="font-semibold">Reports</h4>
                <p class="text-sm opacity-90">View system reports</p>
            </a>
        </div>
    </div>

    <script>
        setTimeout(() => {
            window.location.reload();
        }, 30000);
        
        function showRefreshIndicator() {
            const button = document.querySelector('button[onclick="location.reload()"]');
            const originalHtml = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span class="ml-1">Refreshing...</span>';
            button.disabled = true;
            
            setTimeout(() => {
                button.innerHTML = originalHtml;
                button.disabled = false;
            }, 2000);
        }
        
        document.querySelector('button[onclick="location.reload()"]').addEventListener('click', showRefreshIndicator);
    </script>
</body>
</html>