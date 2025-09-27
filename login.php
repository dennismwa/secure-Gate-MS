<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

if (isset($_SESSION['operator_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $operator_code = sanitizeInput($_POST['operator_code'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($operator_code) || empty($password)) {
        $error_message = 'Please enter both operator code and password';
    } else {
        $stmt = $db->prepare("SELECT id, operator_name, password_hash, role FROM gate_operators WHERE operator_code = ? AND is_active = 1");
        $stmt->execute([$operator_code]);
        $operator = $stmt->fetch();
        
        if ($operator && password_verify($password, $operator['password_hash'])) {
            $stmt = $db->prepare("UPDATE gate_operators SET last_login = NOW() WHERE id = ?");
            $stmt->execute([$operator['id']]);
            
            createSession($db, $operator['id']);
            
            logActivity($db, $operator['id'], 'login', 'Operator logged in', $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
            
            header('Location: dashboard.php');
            exit;
        } else {
            $error_message = 'Invalid operator code or password';
            logActivity($db, null, 'failed_login', "Failed login attempt for code: $operator_code", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        }
    }
}

$settings = getSettings($db);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management System'); ?> - Login</title>
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
<body class="bg-gray-50 min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-8">
        <div class="text-center">
            <div class="mx-auto h-16 w-16 bg-blue-600 rounded-full flex items-center justify-center">
                <i class="fas fa-shield-alt text-white text-2xl"></i>
            </div>
            <h2 class="mt-6 text-3xl font-bold text-gray-900">
                <?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management'); ?>
            </h2>
            <p class="mt-2 text-sm text-gray-600">
                Sign in to access the gate system
            </p>
        </div>
        
        <form class="mt-8 space-y-6" method="POST">
            <?php if ($error_message): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="space-y-4">
                <div>
                    <label for="operator_code" class="block text-sm font-medium text-gray-700">
                        Operator Code
                    </label>
                    <div class="mt-1 relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-user text-gray-400"></i>
                        </div>
                        <input id="operator_code" name="operator_code" type="text" required 
                               class="appearance-none relative block w-full pl-10 pr-3 py-3 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 focus:z-10 sm:text-sm" 
                               placeholder="Enter your operator code" 
                               value="<?php echo htmlspecialchars($_POST['operator_code'] ?? ''); ?>">
                    </div>
                </div>
                
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700">
                        Password
                    </label>
                    <div class="mt-1 relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-lock text-gray-400"></i>
                        </div>
                        <input id="password" name="password" type="password" required 
                               class="appearance-none relative block w-full pl-10 pr-3 py-3 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 focus:z-10 sm:text-sm" 
                               placeholder="Enter your password">
                    </div>
                </div>
            </div>

            <div>
                <button type="submit" 
                        class="group relative w-full flex justify-center py-3 px-4 border border-transparent text-sm font-medium rounded-lg text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200">
                    <span class="absolute left-0 inset-y-0 flex items-center pl-3">
                        <i class="fas fa-sign-in-alt text-blue-500 group-hover:text-blue-400"></i>
                    </span>
                    Sign In
                </button>
            </div>
            
            <div class="text-center">
                <p class="text-xs text-gray-500">
                    Session will timeout after 1 hour of inactivity
                </p>
            </div>
        </form>
        
        <div class="text-center text-xs text-gray-400">
            <p>&copy; <?php echo date('Y'); ?> Gate Management System</p>
        </div>
    </div>

    <script>
        document.getElementById('operator_code').focus();
        
        document.querySelector('form').addEventListener('submit', function(e) {
            const button = this.querySelector('button[type="submit"]');
            button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Signing in...';
            button.disabled = true;
        });
    </script>
</body>
</html>