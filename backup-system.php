<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);
$settings = getSettings($db);

// Only admin can access
if ($session['role'] !== 'admin') {
    setMessage('Access denied. Admin privileges required.', 'error');
    header('Location: dashboard.php');
    exit;
}

// Handle backup creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_backup'])) {
    $backup_type = sanitizeInput($_POST['backup_type']);
    $include_photos = isset($_POST['include_photos']);
    
    try {
        $backup_dir = 'backups/';
        if (!is_dir($backup_dir)) {
            mkdir($backup_dir, 0755, true);
        }
        
        $timestamp = date('Y-m-d_H-i-s');
        $backup_filename = "gate_backup_{$timestamp}.sql";
        $backup_path = $backup_dir . $backup_filename;
        
        // Create database backup
        $tables = [];
        if ($backup_type === 'full') {
            $stmt = $db->query("SHOW TABLES");
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                $tables[] = $row[0];
            }
        } else {
            // Essential tables only
            $tables = ['visitors', 'gate_logs', 'pre_registrations', 'settings', 'gate_operators'];
        }
        
        $sql_content = "-- Gate Management System Backup\n";
        $sql_content .= "-- Created: " . date('Y-m-d H:i:s') . "\n";
        $sql_content .= "-- Type: " . ucfirst($backup_type) . "\n\n";
        
        $sql_content .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
        
        foreach ($tables as $table) {
            // Get table structure
            $stmt = $db->query("SHOW CREATE TABLE `$table`");
            $row = $stmt->fetch();
            $sql_content .= "DROP TABLE IF EXISTS `$table`;\n";
            $sql_content .= $row['Create Table'] . ";\n\n";
            
            // Get table data
            $stmt = $db->query("SELECT * FROM `$table`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($rows)) {
                $columns = array_keys($rows[0]);
                $sql_content .= "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES\n";
                
                $values = [];
                foreach ($rows as $row) {
                    $escaped_values = [];
                    foreach ($row as $value) {
                        if ($value === null) {
                            $escaped_values[] = 'NULL';
                        } else {
                            $escaped_values[] = "'" . addslashes($value) . "'";
                        }
                    }
                    $values[] = "(" . implode(', ', $escaped_values) . ")";
                }
                
                $sql_content .= implode(",\n", $values) . ";\n\n";
            }
        }
        
        $sql_content .= "SET FOREIGN_KEY_CHECKS=1;\n";
        
        // Save backup file
        if (file_put_contents($backup_path, $sql_content)) {
            // Create zip if including photos
            if ($include_photos) {
                $zip_filename = "gate_backup_with_photos_{$timestamp}.zip";
                $zip_path = $backup_dir . $zip_filename;
                
                $zip = new ZipArchive();
                if ($zip->open($zip_path, ZipArchive::CREATE) === TRUE) {
                    // Add SQL backup
                    $zip->addFile($backup_path, $backup_filename);
                    
                    // Add photos directory
                    if (is_dir('uploads/photos/visitors/')) {
                        $photos = glob('uploads/photos/visitors/*');
                        foreach ($photos as $photo) {
                            if (is_file($photo)) {
                                $zip->addFile($photo, 'photos/' . basename($photo));
                            }
                        }
                    }
                    
                    $zip->close();
                    unlink($backup_path); // Remove SQL file as it's in the zip
                    
                    logActivity($db, $session['operator_id'], 'backup_create', "Created full backup with photos: $zip_filename", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
                    setMessage("Backup created successfully: $zip_filename", 'success');
                } else {
                    logActivity($db, $session['operator_id'], 'backup_create', "Created database backup: $backup_filename", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
                    setMessage("Database backup created successfully: $backup_filename", 'success');
                }
            } else {
                logActivity($db, $session['operator_id'], 'backup_create', "Created database backup: $backup_filename", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
                setMessage("Database backup created successfully: $backup_filename", 'success');
            }
        } else {
            setMessage('Failed to create backup file', 'error');
        }
        
    } catch (Exception $e) {
        error_log("Backup error: " . $e->getMessage());
        setMessage('Backup failed: ' . $e->getMessage(), 'error');
    }
    
    header('Location: backup-system.php');
    exit;
}

// Handle backup download
if (isset($_GET['download'])) {
    $filename = sanitizeInput($_GET['download']);
    $filepath = 'backups/' . $filename;
    
    if (file_exists($filepath) && strpos($filename, '..') === false) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    } else {
        setMessage('Backup file not found', 'error');
    }
}

// Handle backup deletion
if (isset($_GET['delete'])) {
    $filename = sanitizeInput($_GET['delete']);
    $filepath = 'backups/' . $filename;
    
    if (file_exists($filepath) && strpos($filename, '..') === false) {
        if (unlink($filepath)) {
            logActivity($db, $session['operator_id'], 'backup_delete', "Deleted backup: $filename", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
            setMessage('Backup deleted successfully', 'success');
        } else {
            setMessage('Failed to delete backup', 'error');
        }
    } else {
        setMessage('Backup file not found', 'error');
    }
    
    header('Location: backup-system.php');
    exit;
}

// Get existing backups
$backups = [];
if (is_dir('backups/')) {
    $files = glob('backups/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            $backups[] = [
                'filename' => basename($file),
                'size' => filesize($file),
                'created' => filemtime($file),
                'type' => pathinfo($file, PATHINFO_EXTENSION)
            ];
        }
    }
    // Sort by creation time (newest first)
    usort($backups, function($a, $b) {
        return $b['created'] - $a['created'];
    });
}

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management System'); ?> - Backup System</title>
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
    <!-- Navigation -->
    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <a href="settings.php" class="text-gray-600 hover:text-gray-900 mr-4">
                        <i class="fas fa-arrow-left"></i>
                    </a>
                    <div class="h-10 w-10 bg-green-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-database text-white"></i>
                    </div>
                    <div class="ml-3">
                        <h1 class="text-xl font-semibold text-gray-900">Backup System</h1>
                        <p class="text-sm text-gray-500">Create and manage system backups</p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-4">
                    <a href="settings.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-cog"></i>
                        <span class="ml-1">Settings</span>
                    </a>
                    <a href="dashboard.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-home"></i>
                        <span class="ml-1">Dashboard</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
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

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Create Backup -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-6">Create New Backup</h3>
                    
                    <form method="POST" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Backup Type</label>
                            <div class="space-y-2">
                                <label class="flex items-center">
                                    <input type="radio" name="backup_type" value="essential" checked 
                                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300">
                                    <span class="ml-2 text-sm text-gray-900">Essential Data Only</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="radio" name="backup_type" value="full" 
                                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300">
                                    <span class="ml-2 text-sm text-gray-900">Complete Database</span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="flex items-center">
                            <input type="checkbox" id="include_photos" name="include_photos" 
                                   class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <label for="include_photos" class="ml-2 block text-sm text-gray-900">
                                Include visitor photos
                            </label>
                        </div>
                        
                        <button type="submit" name="create_backup" 
                                class="w-full bg-green-600 hover:bg-green-700 text-white px-4 py-3 rounded-lg font-medium transition-colors">
                            <i class="fas fa-database mr-2"></i>Create Backup
                        </button>
                    </form>
                    
                    <div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                        <div class="flex">
                            <i class="fas fa-info-circle text-blue-600 mt-0.5 mr-2"></i>
                            <div class="text-sm text-blue-800">
                                <p class="font-medium mb-1">Backup Information:</p>
                                <ul class="list-disc list-inside space-y-1">
                                    <li>Essential: Visitors, logs, settings</li>
                                    <li>Complete: All database tables</li>
                                    <li>Photos: Add visitor images to backup</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Existing Backups -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">
                            Existing Backups (<?php echo count($backups); ?> files)
                        </h3>
                    </div>
                    
                    <?php if (empty($backups)): ?>
                        <div class="px-6 py-12 text-center text-gray-500">
                            <i class="fas fa-database text-4xl mb-4 text-gray-300"></i>
                            <p class="text-lg mb-2">No backups found</p>
                            <p>Create your first backup to get started</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Backup File</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Size</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($backups as $backup): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4">
                                                <div class="flex items-center">
                                                    <div class="flex-shrink-0">
                                                        <i class="fas <?php echo $backup['type'] === 'zip' ? 'fa-file-archive' : 'fa-database'; ?> 
                                                              text-<?php echo $backup['type'] === 'zip' ? 'purple' : 'blue'; ?>-600"></i>
                                                    </div>
                                                    <div class="ml-3">
                                                        <div class="text-sm font-medium text-gray-900">
                                                            <?php echo htmlspecialchars($backup['filename']); ?>
                                                        </div>
                                                        <div class="text-sm text-gray-500">
                                                            <?php echo strtoupper($backup['type']); ?> file
                                                            <?php if ($backup['type'] === 'zip'): ?>
                                                                <span class="ml-1 px-2 py-1 text-xs bg-purple-100 text-purple-800 rounded-full">
                                                                    With Photos
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                <?php echo formatBytes($backup['size']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo date('M j, Y g:i A', $backup['created']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                <div class="flex space-x-3">
                                                    <a href="backup-system.php?download=<?php echo urlencode($backup['filename']); ?>" 
                                                       class="text-blue-600 hover:text-blue-900">
                                                        <i class="fas fa-download mr-1"></i>Download
                                                    </a>
                                                    <a href="backup-system.php?delete=<?php echo urlencode($backup['filename']); ?>" 
                                                       onclick="return confirm('Are you sure you want to delete this backup?')"
                                                       class="text-red-600 hover:text-red-900">
                                                        <i class="fas fa-trash mr-1"></i>Delete
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Form submission handling
        document.querySelector('form').addEventListener('submit', function(e) {
            const button = this.querySelector('button[type="submit"]');
            button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Creating Backup...';
            button.disabled = true;
        });
    </script>
</body>
</html>

<?php
function formatBytes($size, $precision = 2) {
    $base = log($size, 1024);
    $suffixes = array('B', 'KB', 'MB', 'GB', 'TB');
    return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
}
?>