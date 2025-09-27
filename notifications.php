<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);
$settings = getSettings($db);

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['mark_read'])) {
    $notification_id = intval($_POST['notification_id']);
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
    $stmt->execute([$notification_id]);
    
    echo json_encode(['success' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['mark_all_read'])) {
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE is_read = 0");
    $stmt->execute();
    
    echo json_encode(['success' => true]);
    exit;
}

$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$stmt = $db->prepare("
    SELECT n.*, v.full_name as visitor_name, go.operator_name
    FROM notifications n
    LEFT JOIN visitors v ON n.visitor_id = v.visitor_id
    LEFT JOIN gate_operators go ON n.operator_id = go.id
    ORDER BY n.created_at DESC
    LIMIT $per_page OFFSET $offset
");
$stmt->execute();
$notifications = $stmt->fetchAll();

$stmt = $db->prepare("SELECT COUNT(*) as unread_count FROM notifications WHERE is_read = 0");
$stmt->execute();
$unread_count = $stmt->fetch()['unread_count'];

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management System'); ?> - Notifications</title>
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
                    <a href="dashboard.php" class="text-gray-600 hover:text-gray-900 mr-4">
                        <i class="fas fa-arrow-left"></i>
                    </a>
                    <div class="h-10 w-10 bg-yellow-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-bell text-white"></i>
                    </div>
                    <div class="ml-3">
                        <h1 class="text-xl font-semibold text-gray-900">Notifications</h1>
                        <p class="text-sm text-gray-500">System alerts and updates</p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-4">
                    <?php if ($unread_count > 0): ?>
                        <button onclick="markAllRead()" class="text-blue-600 hover:text-blue-800">
                            <i class="fas fa-check-double"></i>
                            <span class="ml-1">Mark All Read</span>
                        </button>
                    <?php endif; ?>
                    <a href="dashboard.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-home"></i>
                        <span class="ml-1">Dashboard</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
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

        
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900">
                        All Notifications
                        <?php if ($unread_count > 0): ?>
                            <span class="ml-2 px-2 py-1 text-xs bg-red-100 text-red-800 rounded-full">
                                <?php echo $unread_count; ?> unread
                            </span>
                        <?php endif; ?>
                    </h3>
                </div>
            </div>
            
            <?php if (empty($notifications)): ?>
                <div class="px-6 py-12 text-center text-gray-500">
                    <i class="fas fa-bell-slash text-4xl mb-4 text-gray-300"></i>
                    <p class="text-lg mb-2">No notifications</p>
                    <p>You're all caught up!</p>
                </div>
            <?php else: ?>
                <div class="divide-y divide-gray-200">
                    <?php foreach ($notifications as $notification): ?>
                        <div class="px-6 py-4 hover:bg-gray-50 <?php echo !$notification['is_read'] ? 'bg-blue-50' : ''; ?>">
                            <div class="flex items-start justify-between">
                                <div class="flex items-start">
                                    <div class="flex-shrink-0">
                                        <div class="w-10 h-10 rounded-full flex items-center justify-center <?php 
                                            echo $notification['type'] === 'check_in' ? 'bg-green-100 text-green-600' : 
                                                ($notification['type'] === 'check_out' ? 'bg-red-100 text-red-600' : 
                                                ($notification['type'] === 'pre_registration' ? 'bg-purple-100 text-purple-600' : 
                                                 'bg-yellow-100 text-yellow-600')); ?>">
                                            <i class="fas <?php 
                                                echo $notification['type'] === 'check_in' ? 'fa-sign-in-alt' : 
                                                    ($notification['type'] === 'check_out' ? 'fa-sign-out-alt' : 
                                                    ($notification['type'] === 'pre_registration' ? 'fa-calendar-plus' : 
                                                     'fa-exclamation-triangle')); ?>"></i>
                                        </div>
                                    </div>
                                    <div class="ml-4 flex-1">
                                        <h4 class="text-sm font-medium text-gray-900 <?php echo !$notification['is_read'] ? 'font-semibold' : ''; ?>">
                                            <?php echo htmlspecialchars($notification['title']); ?>
                                        </h4>
                                        <p class="text-sm text-gray-600 mt-1">
                                            <?php echo htmlspecialchars($notification['message']); ?>
                                        </p>
                                        <?php if ($notification['visitor_name']): ?>
                                            <p class="text-xs text-gray-500 mt-1">
                                                Visitor: <?php echo htmlspecialchars($notification['visitor_name']); ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if ($notification['operator_name']): ?>
                                            <p class="text-xs text-gray-500">
                                                Operator: <?php echo htmlspecialchars($notification['operator_name']); ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <div class="text-sm text-gray-500">
                                        <?php echo date('M j, g:i A', strtotime($notification['created_at'])); ?>
                                    </div>
                                    <?php if (!$notification['is_read']): ?>
                                        <button onclick="markAsRead(<?php echo $notification['id']; ?>)" 
                                                class="text-blue-600 hover:text-blue-800 text-sm">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function markAsRead(notificationId) {
            fetch('notifications.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `mark_read=1&notification_id=${notificationId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
        }

        function markAllRead() {
            fetch('notifications.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'mark_all_read=1'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
        }

        setInterval(() => {
            window.location.reload();
        }, 30000);
    </script>
</body>
</html>