<?php
// Error Handler for Gate Management System

// Get error details
$error_code = $_GET['error'] ?? '500';
$error_message = $_GET['message'] ?? '';

// Sanitize inputs
$error_code = filter_var($error_code, FILTER_SANITIZE_NUMBER_INT);
$error_message = htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8');

// Define error types
$errors = [
    '400' => [
        'title' => 'Bad Request',
        'description' => 'The request could not be understood by the server.',
        'icon' => 'fa-exclamation-triangle',
        'color' => 'yellow'
    ],
    '401' => [
        'title' => 'Unauthorized',
        'description' => 'You need to login to access this resource.',
        'icon' => 'fa-lock',
        'color' => 'red'
    ],
    '403' => [
        'title' => 'Access Forbidden',
        'description' => 'You don\'t have permission to access this resource.',
        'icon' => 'fa-ban',
        'color' => 'red'
    ],
    '404' => [
        'title' => 'Page Not Found',
        'description' => 'The page you\'re looking for doesn\'t exist.',
        'icon' => 'fa-search',
        'color' => 'blue'
    ],
    '500' => [
        'title' => 'Server Error',
        'description' => 'An internal server error occurred.',
        'icon' => 'fa-server',
        'color' => 'red'
    ],
    'database' => [
        'title' => 'Database Error',
        'description' => 'Unable to connect to the database.',
        'icon' => 'fa-database',
        'color' => 'red'
    ],
    'maintenance' => [
        'title' => 'System Maintenance',
        'description' => 'The system is currently under maintenance.',
        'icon' => 'fa-tools',
        'color' => 'orange'
    ]
];

$current_error = $errors[$error_code] ?? $errors['500'];

// Set appropriate HTTP response code
if (is_numeric($error_code)) {
    http_response_code($error_code);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $current_error['title']; ?> - Gate Management System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .error-animation {
            animation: bounce 2s infinite;
        }
        
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {
                transform: translateY(0);
            }
            40% {
                transform: translateY(-10px);
            }
            60% {
                transform: translateY(-5px);
            }
        }
        
        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center">
    <div class="max-w-2xl w-full mx-auto px-4">
        <!-- Error Card -->
        <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
            <!-- Header -->
            <div class="gradient-bg px-8 py-12 text-center text-white">
                <div class="error-animation inline-block">
                    <i class="fas <?php echo $current_error['icon']; ?> text-6xl mb-4"></i>
                </div>
                <h1 class="text-4xl font-bold mb-2"><?php echo $error_code; ?></h1>
                <h2 class="text-2xl font-semibold"><?php echo $current_error['title']; ?></h2>
            </div>
            
            <!-- Content -->
            <div class="px-8 py-8">
                <div class="text-center mb-8">
                    <p class="text-lg text-gray-600 mb-4">
                        <?php echo $current_error['description']; ?>
                    </p>
                    
                    <?php if (!empty($error_message)): ?>
                        <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
                            <div class="flex items-center">
                                <i class="fas fa-exclamation-circle text-red-600 mr-2"></i>
                                <span class="text-red-800 font-medium">Error Details:</span>
                            </div>
                            <p class="text-red-700 mt-2"><?php echo $error_message; ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Suggested Actions -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mb-6">
                        <h3 class="text-lg font-semibold text-blue-900 mb-4">
                            <i class="fas fa-lightbulb mr-2"></i>What you can do:
                        </h3>
                        <ul class="text-left text-blue-800 space-y-2">
                            <?php if ($error_code === '404'): ?>
                                <li><i class="fas fa-check text-blue-600 mr-2"></i>Check the URL for typos</li>
                                <li><i class="fas fa-check text-blue-600 mr-2"></i>Use the navigation menu to find what you're looking for</li>
                                <li><i class="fas fa-check text-blue-600 mr-2"></i>Return to the homepage and start over</li>
                            <?php elseif ($error_code === '401' || $error_code === '403'): ?>
                                <li><i class="fas fa-check text-blue-600 mr-2"></i>Login with valid credentials</li>
                                <li><i class="fas fa-check text-blue-600 mr-2"></i>Contact your administrator for access</li>
                                <li><i class="fas fa-check text-blue-600 mr-2"></i>Check if your session has expired</li>
                            <?php elseif ($error_code === 'database'): ?>
                                <li><i class="fas fa-check text-blue-600 mr-2"></i>Wait a moment and try again</li>
                                <li><i class="fas fa-check text-blue-600 mr-2"></i>Contact the system administrator</li>
                                <li><i class="fas fa-check text-blue-600 mr-2"></i>Check your internet connection</li>
                            <?php else: ?>
                                <li><i class="fas fa-check text-blue-600 mr-2"></i>Refresh the page</li>
                                <li><i class="fas fa-check text-blue-600 mr-2"></i>Try again in a few minutes</li>
                                <li><i class="fas fa-check text-blue-600 mr-2"></i>Contact support if the problem persists</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="flex flex-col sm:flex-row gap-4 justify-center">
                    <button onclick="history.back()" 
                            class="bg-gray-600 hover:bg-gray-700 text-white px-6 py-3 rounded-lg font-medium transition-colors inline-flex items-center justify-center">
                        <i class="fas fa-arrow-left mr-2"></i>Go Back
                    </button>
                    
                    <a href="dashboard.php" 
                       class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-medium transition-colors inline-flex items-center justify-center">
                        <i class="fas fa-home mr-2"></i>Go to Dashboard
                    </a>
                    
                    <?php if ($error_code === '401' || $error_code === '403'): ?>
                        <a href="login.php" 
                           class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-lg font-medium transition-colors inline-flex items-center justify-center">
                            <i class="fas fa-sign-in-alt mr-2"></i>Login
                        </a>
                    <?php endif; ?>
                    
                    <button onclick="location.reload()" 
                            class="bg-orange-600 hover:bg-orange-700 text-white px-6 py-3 rounded-lg font-medium transition-colors inline-flex items-center justify-center">
                        <i class="fas fa-redo mr-2"></i>Retry
                    </button>
                </div>
                
                <!-- Support Information -->
                <div class="mt-8 pt-6 border-t border-gray-200 text-center">
                    <p class="text-sm text-gray-500 mb-2">Need help? Contact system support:</p>
                    <div class="flex flex-col sm:flex-row justify-center items-center space-y-2 sm:space-y-0 sm:space-x-6 text-sm text-gray-600">
                        <span><i class="fas fa-envelope mr-1"></i> support@yourdomain.com</span>
                        <span><i class="fas fa-phone mr-1"></i> +1 (555) 123-4567</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Additional Info -->
        <div class="mt-6 text-center text-xs text-gray-400">
            <p>Error ID: <?php echo uniqid(); ?> | Time: <?php echo date('Y-m-d H:i:s'); ?></p>
            <p class="mt-1">&copy; <?php echo date('Y'); ?> Gate Management System</p>
        </div>
    </div>

    <script>
        // Auto-retry for server errors after 30 seconds
        <?php if ($error_code === '500' || $error_code === 'database'): ?>
        setTimeout(function() {
            if (confirm('Would you like to automatically retry loading the page?')) {
                location.reload();
            }
        }, 30000);
        <?php endif; ?>
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                history.back();
            } else if (e.key === 'Enter') {
                location.reload();
            } else if (e.ctrlKey && e.key === 'h') {
                e.preventDefault();
                window.location.href = 'dashboard.php';
            }
        });
        
        // Show keyboard shortcuts hint
        setTimeout(function() {
            const hint = document.createElement('div');
            hint.className = 'fixed bottom-4 right-4 bg-black bg-opacity-75 text-white text-xs px-3 py-2 rounded-lg';
            hint.innerHTML = 'Press <kbd class="bg-gray-600 px-1 rounded">ESC</kbd> to go back, <kbd class="bg-gray-600 px-1 rounded">Enter</kbd> to retry, <kbd class="bg-gray-600 px-1 rounded">Ctrl+H</kbd> for home';
            document.body.appendChild(hint);
            
            setTimeout(function() {
                hint.style.opacity = '0';
                hint.style.transition = 'opacity 0.5s';
                setTimeout(function() {
                    hint.remove();
                }, 500);
            }, 5000);
        }, 2000);
    </script>
</body>
</html>