<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);
$settings = getSettings($db);

$visitor_id = $_GET['id'] ?? '';
if (empty($visitor_id)) {
    setMessage('Visitor ID is required', 'error');
    header('Location: visitors.php');
    exit;
}

$stmt = $db->prepare("SELECT * FROM visitors WHERE visitor_id = ?");
$stmt->execute([$visitor_id]);
$visitor = $stmt->fetch();

if (!$visitor) {
    setMessage('Visitor not found', 'error');
    header('Location: visitors.php');
    exit;
}

try {
    $stmt = $db->prepare("INSERT INTO card_print_logs (visitor_id, printed_by, print_quality, copies_printed) VALUES (?, ?, 'high', 1)");
    $stmt->execute([$visitor_id, $session['operator_id']]);
    
    logActivity($db, $session['operator_id'], 'print_card', "Printed professional card for visitor: {$visitor['full_name']}", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
} catch (Exception $e) {
    error_log("Card print logging error: " . $e->getMessage());
}

$expiry_days = (int)getSetting($db, 'card_expiry_days', '30');
$expiry_date = date('d/m/Y', strtotime("+$expiry_days days"));
$issue_date = date('d/m/Y');
$issue_time = date('H:i');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Professional ID Card - <?php echo htmlspecialchars($visitor['full_name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        * {
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', Arial, sans-serif;
            margin: 0;
            padding: 0;
            background: #f5f5f5;
        }
        
        @page {
            size: A4 landscape;
            margin: 0.5in;
        }
        
        @media print {
            body { 
                margin: 0; 
                background: white;
                font-family: 'Inter', Arial, sans-serif;
            }
            
            .no-print { 
                display: none !important; 
            }
            
            .print-area {
                width: 100%;
                height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 1in;
            }
            
            .id-card { 
                width: 3.375in; 
                height: 2.125in; 
                border: 2px solid #000;
                border-radius: 12px;
                overflow: hidden;
                background: white;
                box-shadow: none;
                page-break-inside: avoid;
                position: relative;
                font-size: 10px;
                line-height: 1.2;
            }
        }
        
        .id-card {
            width: 405px;
            height: 255px;
            border: 3px solid #333;
            border-radius: 15px;
            overflow: hidden;
            background: white;
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            position: relative;
            font-family: 'Inter', Arial, sans-serif;
            margin: 20px;
        }
        
        .card-header {
            background: linear-gradient(135deg, <?php echo $settings['primary_color'] ?? '#1e40af'; ?> 0%, <?php echo $settings['accent_color'] ?? '#059669'; ?> 100%);
            color: white;
            padding: 12px 15px;
            position: relative;
            overflow: hidden;
        }
        
        .photo-container {
            width: 75px;
            height: 90px;
            border: 3px solid white;
            border-radius: 10px;
            overflow: hidden;
            background: linear-gradient(145deg, #f0f0f0, #e0e0e0);
            box-shadow: inset 0 2px 8px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        
        .photo-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
        }
        
        .visitor-name {
            font-size: 16px;
            font-weight: 800;
            color: #1a1a1a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            line-height: 1.1;
            margin-bottom: 3px;
        }
        
        .visitor-id {
            font-family: 'Courier New', monospace;
            font-size: 11px;
            font-weight: 600;
            color: #666;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }
        
        .info-row {
            display: flex;
            align-items: center;
            margin-bottom: 3px;
            font-size: 11px;
        }
        
        .info-label {
            font-weight: 600;
            color: #555;
            width: 45px;
            flex-shrink: 0;
        }
        
        .info-value {
            color: #1a1a1a;
            font-weight: 500;
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .validity-section {
            border-top: 1px solid #e0e0e0;
            padding-top: 8px;
            margin-top: 8px;
            display: flex;
            justify-content: space-between;
            font-size: 10px;
        }
        
        .validity-item {
            text-align: center;
        }
        
        .validity-label {
            color: #666;
            font-weight: 500;
            margin-bottom: 1px;
        }
        
        .validity-value {
            color: #1a1a1a;
            font-weight: 700;
        }
        
        .security-strip {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 8px;
            background: repeating-linear-gradient(
                90deg,
                #ff6b6b 0px,
                #ff6b6b 8px,
                #4ecdc4 8px,
                #4ecdc4 16px,
                #ffd93d 16px,
                #ffd93d 24px
            );
        }
        
        .hologram {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 24px;
            height: 24px;
            background: radial-gradient(circle, #ff6b6b, #4ecdc4, #45b7d1);
            border-radius: 50%;
            opacity: 0.4;
            animation: shimmer 3s linear infinite;
        }
        
        @keyframes shimmer {
            0%, 100% { opacity: 0.4; }
            50% { opacity: 0.8; }
        }
        
        .card-back {
            background: linear-gradient(145deg, #f8f9fa, #e9ecef);
            position: relative;
        }
        
        .back-header {
            background: #2d3748;
            color: white;
            padding: 10px 15px;
            text-align: center;
        }
        
        .qr-section {
            padding: 15px;
            text-align: center;
            background: white;
            margin: 12px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .qr-container {
            width: 80px;
            height: 80px;
            margin: 0 auto 8px auto;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 4px;
            background: white;
        }
        
        .instructions {
            background: white;
            margin: 0 12px 12px 12px;
            padding: 10px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .emergency-info {
            background: #fed7d7;
            border: 1px solid #fc8181;
            margin: 0 12px;
            padding: 8px;
            border-radius: 6px;
            text-align: center;
        }
        
        /* Mobile responsiveness */
        @media (max-width: 768px) {
            .print-area {
                flex-direction: column;
                gap: 20px;
            }
            
            .id-card {
                transform: scale(0.8);
                margin: 10px;
            }
        }
    </style>
</head>
<body>
    
    <div class="no-print bg-white shadow-lg border-b sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 py-4">
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">ID Card System</h1>
                    <p class="text-sm sm:text-base text-gray-600">Visitor identification for <strong><?php echo htmlspecialchars($visitor['full_name']); ?></strong></p>
                </div>
                <div class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-4 w-full sm:w-auto">
                    <button onclick="window.print()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-3 rounded-lg font-semibold shadow-lg transition-all duration-200 hover:shadow-xl text-center">
                        <i class="fas fa-print mr-2"></i>Print Cards
                    </button>
                    <a href="visitors.php?action=view&id=<?php echo $visitor['visitor_id']; ?>" 
                       class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-3 rounded-lg font-semibold shadow-lg transition-all duration-200 text-center">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Visitor
                    </a>
                </div>
            </div>
        </div>
    </div>

    
    <div class="no-print bg-gray-100 py-6 sm:py-12">
        <div class="max-w-7xl mx-auto px-4">
            <div class="bg-white rounded-2xl shadow-xl p-4 sm:p-8">
                <h2 class="text-xl sm:text-2xl font-bold text-gray-900 mb-6 sm:mb-8 text-center">Card Preview - Both Sides</h2>
                
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 sm:gap-12 justify-items-center">
                    
                    <div class="text-center">
                        <h3 class="text-base sm:text-lg font-semibold text-gray-700 mb-4">Front Side</h3>
                        <div class="transform scale-90 sm:scale-100 lg:scale-110">
                            <div class="id-card">
                                
                                <div class="card-header">
                                    <div class="hologram"></div>
                                    <div class="flex items-center justify-between relative z-10">
                                        <div>
                                            <h2 class="text-sm font-bold"><?php echo strtoupper(htmlspecialchars($settings['organization_name'] ?? 'VISITOR ACCESS')); ?></h2>
                                            <p class="text-xs opacity-90">AUTHORIZED PERSONNEL</p>
                                        </div>
                                        <div class="text-white opacity-75">
                                            <i class="fas fa-shield-alt text-lg"></i>
                                        </div>
                                    </div>
                                </div>
                                
                                
                                <div class="p-3 relative">
                                    <div class="flex items-start gap-3">
                                        
                                        <div class="photo-container">
                                            <?php if ($visitor['photo_path'] && file_exists($visitor['photo_path'])): ?>
                                                <img src="<?php echo htmlspecialchars($visitor['photo_path']); ?>" alt="Visitor Photo">
                                            <?php else: ?>
                                                <div class="text-center text-gray-400">
                                                    <i class="fas fa-user text-xl"></i>
                                                    <div class="text-xs mt-1">PHOTO</div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        
                                        <div class="flex-1 min-w-0">
                                            <div class="visitor-name">
                                                <?php echo htmlspecialchars($visitor['full_name']); ?>
                                            </div>
                                            <div class="visitor-id">
                                                ID: <?php echo htmlspecialchars($visitor['visitor_id']); ?>
                                            </div>
                                            
                                            <div class="space-y-1">
                                                <div class="info-row">
                                                    <span class="info-label">PHONE:</span>
                                                    <span class="info-value"><?php echo htmlspecialchars($visitor['phone']); ?></span>
                                                </div>
                                                
                                                <?php if ($visitor['company']): ?>
                                                <div class="info-row">
                                                    <span class="info-label">ORG:</span>
                                                    <span class="info-value"><?php echo htmlspecialchars($visitor['company']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                
                                                <?php if ($visitor['vehicle_number']): ?>
                                                <div class="info-row">
                                                    <span class="info-label">CAR:</span>
                                                    <span class="info-value"><?php echo strtoupper(htmlspecialchars($visitor['vehicle_number'])); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                
                                                <?php if ($visitor['email']): ?>
                                                <div class="info-row">
                                                    <span class="info-label">EMAIL:</span>
                                                    <span class="info-value"><?php echo htmlspecialchars($visitor['email']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    
                                    <div class="validity-section">
                                        <div class="validity-item">
                                            <div class="validity-label">ISSUED</div>
                                            <div class="validity-value"><?php echo $issue_date; ?></div>
                                        </div>
                                        <div class="validity-item">
                                            <div class="validity-label">STATUS</div>
                                            <div class="validity-value" style="color: #059669;">ACTIVE</div>
                                        </div>
                                        <div class="validity-item">
                                            <div class="validity-label">EXPIRES</div>
                                            <div class="validity-value" style="color: #dc2626;"><?php echo $expiry_date; ?></div>
                                        </div>
                                    </div>
                                </div>
                                
                                
                                <div class="security-strip"></div>
                            </div>
                        </div>
                    </div>

                    
                    <div class="text-center">
                        <h3 class="text-base sm:text-lg font-semibold text-gray-700 mb-4">Back Side</h3>
                        <div class="transform scale-90 sm:scale-100 lg:scale-110">
                            <div class="id-card card-back">
                                
                                <div class="back-header">
                                    <h3 class="text-sm font-bold">ACCESS VERIFICATION</h3>
                                    <p class="text-xs">SCAN QR CODE FOR INSTANT VERIFICATION</p>
                                </div>
                                
                                
                                <div class="qr-section">
                                    <div class="qr-container">
                                        <canvas id="qrcode-preview" width="72" height="72"></canvas>
                                    </div>
                                    <div class="text-xs font-semibold text-gray-700">SCAN FOR ACCESS</div>
                                </div>
                                
                                
                                <div class="instructions">
                                    <h4 class="text-xs font-bold text-gray-900 mb-2">ACCESS PROCEDURES:</h4>
                                    <ul class="text-xs text-gray-700 space-y-1">
                                        <li>• Present card at security checkpoint</li>
                                        <li>• Allow QR code scanning verification</li>
                                        <li>• Follow escort and safety protocols</li>
                                        <li>• Return card when departing premises</li>
                                    </ul>
                                </div>
                                
                                
                                <div class="emergency-info">
                                    <div class="text-xs font-bold text-red-800 mb-1">24/7 SECURITY EMERGENCY</div>
                                    <div class="text-sm font-bold text-red-800"><?php echo htmlspecialchars($settings['security_contact'] ?? '+1-800-SECURITY'); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    
    <div class="print-area">
        
        <div class="id-card">
            
            <div class="card-header">
                <div class="hologram"></div>
                <div class="flex items-center justify-between relative z-10">
                    <div>
                        <h2 class="text-sm font-bold"><?php echo strtoupper(htmlspecialchars($settings['organization_name'] ?? 'VISITOR ACCESS')); ?></h2>
                        <p class="text-xs opacity-90">AUTHORIZED PERSONNEL</p>
                    </div>
                    <div class="text-white opacity-75">
                        <i class="fas fa-shield-alt text-lg"></i>
                    </div>
                </div>
            </div>
            
            
            <div class="p-3 relative">
                <div class="flex items-start gap-3">
                    
                    <div class="photo-container">
                        <?php if ($visitor['photo_path'] && file_exists($visitor['photo_path'])): ?>
                            <img src="<?php echo htmlspecialchars($visitor['photo_path']); ?>" alt="Visitor Photo">
                        <?php else: ?>
                            <div class="text-center text-gray-400">
                                <i class="fas fa-user text-xl"></i>
                                <div class="text-xs mt-1">PHOTO</div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    
                    <div class="flex-1 min-w-0">
                        <div class="visitor-name">
                            <?php echo htmlspecialchars($visitor['full_name']); ?>
                        </div>
                        <div class="visitor-id">
                            ID: <?php echo htmlspecialchars($visitor['visitor_id']); ?>
                        </div>
                        
                        <div class="space-y-1">
                            <div class="info-row">
                                <span class="info-label">PHONE:</span>
                                <span class="info-value"><?php echo htmlspecialchars($visitor['phone']); ?></span>
                            </div>
                            
                            <?php if ($visitor['company']): ?>
                            <div class="info-row">
                                <span class="info-label">ORG:</span>
                                <span class="info-value"><?php echo htmlspecialchars($visitor['company']); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($visitor['vehicle_number']): ?>
                            <div class="info-row">
                                <span class="info-label">CAR:</span>
                                <span class="info-value"><?php echo strtoupper(htmlspecialchars($visitor['vehicle_number'])); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($visitor['email']): ?>
                            <div class="info-row">
                                <span class="info-label">EMAIL:</span>
                                <span class="info-value"><?php echo htmlspecialchars($visitor['email']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                
                <div class="validity-section">
                    <div class="validity-item">
                        <div class="validity-label">ISSUED</div>
                        <div class="validity-value"><?php echo $issue_date; ?></div>
                    </div>
                    <div class="validity-item">
                        <div class="validity-label">TIME</div>
                        <div class="validity-value"><?php echo $issue_time; ?></div>
                    </div>
                    <div class="validity-item">
                        <div class="validity-label">EXPIRES</div>
                        <div class="validity-value" style="color: #dc2626;"><?php echo $expiry_date; ?></div>
                    </div>
                </div>
            </div>
            
            
            <div class="security-strip"></div>
        </div>

        
        <div class="id-card card-back">
            
            <div class="back-header">
                <h3 class="text-sm font-bold">ACCESS VERIFICATION</h3>
                <p class="text-xs">SCAN QR CODE FOR INSTANT VERIFICATION</p>
            </div>
            
            
            <div class="qr-section">
                <div class="qr-container">
                    <canvas id="qrcode-print" width="72" height="72"></canvas>
                </div>
                <div class="text-xs font-semibold text-gray-700">SCAN FOR ACCESS</div>
            </div>
            
            
            <div class="instructions">
                <h4 class="text-xs font-bold text-gray-900 mb-2">ACCESS PROCEDURES:</h4>
                <ul class="text-xs text-gray-700 space-y-1">
                    <li>• Present card at security checkpoint</li>
                    <li>• Allow QR code scanning verification</li>
                    <li>• Follow escort and safety protocols</li>
                    <li>• Return card when departing premises</li>
                </ul>
            </div>
            
            
            <div class="emergency-info">
                <div class="text-xs font-bold text-red-800 mb-1">24/7 SECURITY EMERGENCY</div>
                <div class="text-sm font-bold text-red-800"><?php echo htmlspecialchars($settings['security_contact'] ?? '+1-800-SECURITY'); ?></div>
            </div>
        </div>
    </div>

    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const qrData = '<?php echo htmlspecialchars($visitor['qr_code']); ?>';
            
            console.log('QR Data:', qrData);
            
            function generateQR(canvasId, size = 72) {
                try {
                    const canvas = document.getElementById(canvasId);
                    if (!canvas) {
                        console.error('Canvas not found:', canvasId);
                        return;
                    }
                    
                    const ctx = canvas.getContext('2d');
                    canvas.width = size;
                    canvas.height = size;
                    
                    const qr = qrcode(0, 'M');
                    qr.addData(qrData);
                    qr.make();
                    
                    const cells = qr.getModuleCount();
                    const cellSize = size / cells;
                    
                    ctx.fillStyle = '#FFFFFF';
                    ctx.fillRect(0, 0, size, size);
                    
                    ctx.fillStyle = '#000000';
                    for (let row = 0; row < cells; row++) {
                        for (let col = 0; col < cells; col++) {
                            if (qr.isDark(row, col)) {
                                ctx.fillRect(col * cellSize, row * cellSize, cellSize, cellSize);
                            }
                        }
                    }
                    
                    console.log('QR code generated successfully for:', canvasId);
                    
                } catch (error) {
                    console.error('QR Generation Error for', canvasId, ':', error);
                    
                    const canvas = document.getElementById(canvasId);
                    if (canvas) {
                        const ctx = canvas.getContext('2d');
                        ctx.fillStyle = '#FF0000';
                        ctx.font = '10px Arial';
                        ctx.textAlign = 'center';
                        ctx.fillText('QR Error', size/2, size/2);
                    }
                }
            }
            
            generateQR('qrcode-preview', 72);
            generateQR('qrcode-print', 72);
        });

        function printCards() {
            window.print();
        }

        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printCards();
            }
        });

        window.addEventListener('beforeprint', function() {
            console.log('Preparing professional card print...');
        });

        window.addEventListener('afterprint', function() {
            console.log('Professional cards printed successfully');
        });
    </script>
</body>
</html>