<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();
$session = requireAuth($db);

// Only admin can access
if ($session['role'] !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $updates = [
        'organization_name' => sanitizeInput($_POST['organization_name']),
        'security_contact' => sanitizeInput($_POST['security_contact']),
        'card_expiry_days' => intval($_POST['card_expiry_days']),
        'primary_color' => sanitizeInput($_POST['primary_color']),
        'accent_color' => sanitizeInput($_POST['accent_color'])
    ];
    
    foreach ($updates as $key => $value) {
        updateSetting($db, $key, $value);
    }
    
    setMessage('Card settings updated successfully', 'success');
    header('Location: card-settings.php');
    exit;
}

$settings = getSettings($db);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Card Settings</title>
    <script src="https://cdn.tailwindcss.com"></script>
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
                    <h1 class="text-xl font-semibold text-gray-900">ID Card Settings</h1>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <form method="POST" class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-6">Customize ID Card Appearance</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="organization_name" class="block text-sm font-medium text-gray-700">Organization Name</label>
                    <input type="text" id="organization_name" name="organization_name" 
                           value="<?php echo htmlspecialchars($settings['organization_name'] ?? ''); ?>"
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div>
                    <label for="security_contact" class="block text-sm font-medium text-gray-700">Security Contact</label>
                    <input type="text" id="security_contact" name="security_contact" 
                           value="<?php echo htmlspecialchars($settings['security_contact'] ?? ''); ?>"
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div>
                    <label for="card_expiry_days" class="block text-sm font-medium text-gray-700">Card Validity (Days)</label>
                    <input type="number" id="card_expiry_days" name="card_expiry_days" 
                           value="<?php echo htmlspecialchars($settings['card_expiry_days'] ?? '30'); ?>"
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div>
                    <label for="primary_color" class="block text-sm font-medium text-gray-700">Primary Color</label>
                    <input type="color" id="primary_color" name="primary_color" 
                           value="<?php echo htmlspecialchars($settings['primary_color'] ?? '#2563eb'); ?>"
                           class="mt-1 block w-full h-10 border border-gray-300 rounded-lg">
                </div>
                
                <div>
                    <label for="accent_color" class="block text-sm font-medium text-gray-700">Accent Color</label>
                    <input type="color" id="accent_color" name="accent_color" 
                           value="<?php echo htmlspecialchars($settings['accent_color'] ?? '#10b981'); ?>"
                           class="mt-1 block w-full h-10 border border-gray-300 rounded-lg">
                </div>
            </div>
            
            <div class="mt-6">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
                    <i class="fas fa-save mr-2"></i>Save Card Settings
                </button>
            </div>
        </form>
    </div>
</body>
</html>