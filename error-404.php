<?php
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Not Found - Gate Management System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full text-center">
        <div class="mb-8">
            <i class="fas fa-exclamation-triangle text-6xl text-yellow-500 mb-4"></i>
            <h1 class="text-4xl font-bold text-gray-900 mb-2">404</h1>
            <h2 class="text-xl text-gray-600 mb-4">Page Not Found</h2>
            <p class="text-gray-500 mb-8">The page you're looking for doesn't exist or has been moved.</p>
        </div>
        
        <div class="space-y-4">
            <a href="dashboard.php" class="block w-full bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-medium transition-colors">
                <i class="fas fa-home mr-2"></i>Go to Dashboard
            </a>
            <button onclick="history.back()" class="block w-full bg-gray-600 hover:bg-gray-700 text-white px-6 py-3 rounded-lg font-medium transition-colors">
                <i class="fas fa-arrow-left mr-2"></i>Go Back
            </button>
        </div>
    </div>
</body>
</html>