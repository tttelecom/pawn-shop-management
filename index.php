<?php
require_once 'config/database.php';
require_once 'notification_widget.php';
requireLogin();

$user = getCurrentUser();
$current_page = $_GET['page'] ?? 'dashboard';

// Get dashboard statistics
if ($current_page === 'dashboard') {
    $db = getDB();
    
    // Total customers
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM customers WHERE status = 'active'");
    $stmt->execute();
    $total_customers = $stmt->fetch()['total'];
    
    // Total active pawns
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM pawn_transactions WHERE status IN ('active', 'overdue')");
    $stmt->execute();
    $total_pawns = $stmt->fetch()['total'];
    
    // Total amount
    $stmt = $db->prepare("SELECT SUM(pawn_amount) as total FROM pawn_transactions WHERE status IN ('active', 'overdue')");
    $stmt->execute();
    $total_amount = $stmt->fetch()['total'] ?? 0;
    
    // Total branches
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM branches WHERE status = 'active'");
    $stmt->execute();
    $total_branches = $stmt->fetch()['total'];
    
    // Recent transactions
    $stmt = $db->prepare("
        SELECT pt.*, c.first_name, c.last_name, 
               CASE 
                   WHEN pt.due_date < CURDATE() AND pt.status = 'active' THEN 'overdue'
                   ELSE pt.status 
               END as display_status
        FROM pawn_transactions pt 
        JOIN customers c ON pt.customer_id = c.id 
        ORDER BY pt.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recent_transactions = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h(APP_NAME) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Sarabun', sans-serif; }
        .sidebar-item:hover { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .card-hover:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .gradient-bg { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .status-active { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .status-overdue { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
        .status-completed { background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); }
        .status-paid { background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); }
        
        /* Notification animations */
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .notification-enter {
            animation: slideInRight 0.3s ease-out;
        }
        
        .pulse-red {
            animation: pulse-red 2s infinite;
        }
        
        @keyframes pulse-red {
            0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); }
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Flash Messages -->
    <?php foreach (getFlash() as $flash): ?>
        <div class="fixed top-4 right-4 z-50 alert alert-<?= h($flash['type']) ?> p-4 rounded-lg shadow-lg notification-enter
                    <?= $flash['type'] === 'success' ? 'bg-green-500 text-white' : 
                        ($flash['type'] === 'error' ? 'bg-red-500 text-white' : 'bg-blue-500 text-white') ?>">
            <?= h($flash['message']) ?>
            <button onclick="this.parentElement.remove()" class="ml-4 text-white hover:text-gray-200">
                <i class="fas fa-times"></i>
            </button>
        </div>
    <?php endforeach; ?>

    <!-- Header -->
    <header class="gradient-bg text-white shadow-lg">
        <div class="container mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <i class="fas fa-gem text-2xl"></i>
                    <h1 class="text-2xl font-bold"><?= h(APP_NAME) ?></h1>
                </div>
                <div class="flex items-center space-x-4">
                    <!-- Enhanced Notification Widget -->
                    <?= includeNotificationWidget() ?>
                    
                    <div class="text-right">
                        <p class="text-sm opacity-90">ผู้ดูแลระบบ</p>
                        <p class="font-semibold"><?= h($user['full_name']) ?></p>
                    </div>
                    <div class="w-10 h-10 bg-white bg-opacity-20 rounded-full flex items-center justify-center">
                        <i class="fas fa-user text-lg"></i>
                    </div>
                    <a href="logout.php" class="text-white hover:text-gray-200 ml-4">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="flex">
        <!-- Sidebar -->
        <aside class="w-64 bg-white shadow-lg min-h-screen">
            <nav class="p-4">
                <ul class="space-y-2">
                    <li>
                        <a href="?page=dashboard" class="sidebar-item w-full text-left px-4 py-3 rounded-lg transition-all duration-200 flex items-center space-x-3 
                           <?= $current_page === 'dashboard' ? 'bg-gradient-to-r from-blue-500 to-purple-600 text-white' : 'text-gray-700 hover:text-white' ?>">
                            <i class="fas fa-tachometer-alt"></i>
                            <span>แดชบอร์ด</span>
                        </a>
                    </li>
                    <li>
                        <a href="customers.php" class="sidebar-item w-full text-left px-4 py-3 rounded-lg transition-all duration-200 flex items-center space-x-3 text-gray-700 hover:text-white">
                            <i class="fas fa-users"></i>
                            <span>จัดการลูกค้า</span>
                        </a>
                    </li>
                    <li>
                        <a href="pawns.php" class="sidebar-item w-full text-left px-4 py-3 rounded-lg transition-all duration-200 flex items-center space-x-3 text-gray-700 hover:text-white">
                            <i class="fas fa-handshake"></i>
                            <span>การจำนำ</span>
                        </a>
                    </li>
                    <li>
                        <a href="inventory.php" class="sidebar-item w-full text-left px-4 py-3 rounded-lg transition-all duration-200 flex items-center space-x-3 text-gray-700 hover:text-white">
                            <i class="fas fa-boxes"></i>
                            <span>จัดการสินค้า</span>
                        </a>
                    </li>
                    <li>
                        <a href="payments.php" class="sidebar-item w-full text-left px-4 py-3 rounded-lg transition-all duration-200 flex items-center space-x-3 text-gray-700 hover:text-white">
                            <i class="fas fa-credit-card"></i>
                            <span>ชำระเงิน</span>
                        </a>
                    </li>
                    <li>
                        <a href="branches.php" class="sidebar-item w-full text-left px-4 py-3 rounded-lg transition-all duration-200 flex items-center space-x-3 text-gray-700 hover:text-white">
                            <i class="fas fa-building"></i>
                            <span>จัดการสาขา</span>
                        </a>
                    </li>
                    <li>
                        <a href="reports.php" class="sidebar-item w-full text-left px-4 py-3 rounded-lg transition-all duration-200 flex items-center space-x-3 text-gray-700 hover:text-white">
                            <i class="fas fa-chart-bar"></i>
                            <span>รายงาน</span>
                        </a>
                    </li>
                    <li>
                        <a href="settings.php" class="sidebar-item w-full text-left px-4 py-3 rounded-lg transition-all duration-200 flex items-center space-x-3 text-gray-700 hover:text-white">
                            <i class="fas fa-cog"></i>
                            <span>ตั้งค่า</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 p-6">
            <!-- Dashboard Page -->
            <?php if ($current_page === 'dashboard'): ?>
                <div class="mb-6">
                    <h2 class="text-3xl font-bold text-gray-800 mb-2">แดชบอร์ด</h2>
                    <p class="text-gray-600">ภาพรวมของร้านจำนำ</p>
                </div>

                <!-- Enhanced Notification Cards -->
                <?= renderNotificationCards() ?>

                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white rounded-xl shadow-lg p-6 card-hover transition-all duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-gray-500 text-sm">ลูกค้าทั้งหมด</p>
                                <p class="text-3xl font-bold text-gray-800"><?= number_format($total_customers) ?></p>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-users text-blue-600 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-lg p-6 card-hover transition-all duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-gray-500 text-sm">รายการจำนำ</p>
                                <p class="text-3xl font-bold text-gray-800"><?= number_format($total_pawns) ?></p>
                            </div>
                            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-handshake text-green-600 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-lg p-6 card-hover transition-all duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-gray-500 text-sm">ยอดเงินรวม</p>
                                <p class="text-3xl font-bold text-gray-800"><?= formatCurrency($total_amount) ?></p>
                            </div>
                            <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-coins text-yellow-600 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-lg p-6 card-hover transition-all duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-gray-500 text-sm">สาขา</p>
                                <p class="text-3xl font-bold text-gray-800"><?= number_format($total_branches) ?></p>
                            </div>
                            <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-building text-purple-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activities -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <h3 class="text-xl font-semibold mb-4 text-gray-800">รายการจำนำล่าสุด</h3>
                        <div class="space-y-4">
                            <?php if (empty($recent_transactions)): ?>
                                <p class="text-gray-500 text-center py-4">ไม่มีรายการจำนำ</p>
                            <?php else: ?>
                                <?php foreach ($recent_transactions as $transaction): ?>
                                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                        <div>
                                            <p class="font-medium"><?= h($transaction['transaction_code']) ?></p>
                                            <p class="text-sm text-gray-500"><?= h($transaction['first_name'] . ' ' . $transaction['last_name']) ?></p>
                                            <p class="text-sm text-green-600"><?= formatCurrency($transaction['pawn_amount']) ?></p>
                                        </div>
                                        <span class="status-<?= h($transaction['display_status']) ?> text-white px-3 py-1 rounded-full text-sm">
                                            <?php
                                            switch($transaction['display_status']) {
                                                case 'active': echo 'กำลังจำนำ'; break;
                                                case 'overdue': echo 'เกินกำหนด'; break;
                                                case 'paid': echo 'ไถ่คืนแล้ว'; break;
                                                default: echo h($transaction['display_status']);
                                            }
                                            ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="mt-4">
                            <a href="pawns.php" class="text-blue-600 hover:text-blue-800 text-sm">ดูทั้งหมด →</a>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <h3 class="text-xl font-semibold mb-4 text-gray-800">สถิติรายเดือน</h3>
                        <div class="space-y-4">
                            <?php
                            // Get monthly statistics
                            $stmt = $db->prepare("
                                SELECT COUNT(*) as new_pawns 
                                FROM pawn_transactions 
                                WHERE MONTH(created_at) = MONTH(CURDATE()) 
                                AND YEAR(created_at) = YEAR(CURDATE())
                            ");
                            $stmt->execute();
                            $monthly_pawns = $stmt->fetch()['new_pawns'];

                            $stmt = $db->prepare("
                                SELECT COUNT(*) as redeemed 
                                FROM pawn_transactions 
                                WHERE status = 'paid' 
                                AND MONTH(updated_at) = MONTH(CURDATE()) 
                                AND YEAR(updated_at) = YEAR(CURDATE())
                            ");
                            $stmt->execute();
                            $monthly_redeemed = $stmt->fetch()['redeemed'];

                            $stmt = $db->prepare("
                                SELECT COUNT(*) as new_customers 
                                FROM customers 
                                WHERE MONTH(created_at) = MONTH(CURDATE()) 
                                AND YEAR(created_at) = YEAR(CURDATE())
                            ");
                            $stmt->execute();
                            $monthly_customers = $stmt->fetch()['new_customers'];

                            $stmt = $db->prepare("
                                SELECT SUM(amount) as revenue 
                                FROM payments 
                                WHERE MONTH(payment_date) = MONTH(CURDATE()) 
                                AND YEAR(payment_date) = YEAR(CURDATE())
                            ");
                            $stmt->execute();
                            $monthly_revenue = $stmt->fetch()['revenue'] ?? 0;
                            ?>
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600">รายการจำนำใหม่</span>
                                <span class="font-semibold text-blue-600"><?= number_format($monthly_pawns) ?></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600">การไถ่คืน</span>
                                <span class="font-semibold text-green-600"><?= number_format($monthly_redeemed) ?></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600">ลูกค้าใหม่</span>
                                <span class="font-semibold text-purple-600"><?= number_format($monthly_customers) ?></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600">รายได้</span>
                                <span class="font-semibold text-yellow-600"><?= formatCurrency($monthly_revenue) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        // Auto-hide flash messages
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Notification sound (optional)
        function playNotificationSound() {
            // Create a subtle notification sound
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            
            oscillator.frequency.setValueAtTime(800, audioContext.currentTime);
            oscillator.frequency.setValueAtTime(600, audioContext.currentTime + 0.1);
            
            gainNode.gain.setValueAtTime(0.1, audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.3);
            
            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 0.3);
        }

        // Check for critical notifications and add visual indicators
        function checkCriticalNotifications() {
            fetch('notification_widget.php?ajax=get_unread_count')
                .then(response => response.json())
                .then(data => {
                    if (data.critical_count > 0) {
                        // Add pulsing effect to notification button
                        const notificationButton = document.getElementById('notificationButton');
                        if (notificationButton && !notificationButton.classList.contains('pulse-red')) {
                            notificationButton.classList.add('pulse-red');
                            playNotificationSound();
                        }
                    }
                })
                .catch(error => console.error('Error checking notifications:', error));
        }

        // Initialize notification checking
        document.addEventListener('DOMContentLoaded', function() {
            checkCriticalNotifications();
            
            // Check for critical notifications every 5 minutes
            setInterval(checkCriticalNotifications, 300000);
        });

        // Desktop notification permission (if supported)
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }

        // Show desktop notification for critical alerts
        function showDesktopNotification(title, message) {
            if ('Notification' in window && Notification.permission === 'granted') {
                new Notification(title, {
                    body: message,
                    icon: '/favicon.ico',
                    tag: 'pawn-shop-notification'
                });
            }
        }

        // Real-time notification updates (WebSocket can be added here)
        function initializeRealTimeNotifications() {
            // This can be extended with WebSocket or Server-Sent Events
            // For now, we use polling every 2 minutes
            setInterval(function() {
                fetch('notification_widget.php?ajax=get_notifications')
                    .then(response => response.json())
                    .then(notifications => {
                        const criticalNotifications = notifications.filter(n => n.priority === 'critical');
                        
                        criticalNotifications.forEach(notification => {
                            if (!sessionStorage.getItem('notified_' + notification.id)) {
                                showDesktopNotification(notification.title, notification.message);
                                sessionStorage.setItem('notified_' + notification.id, 'true');
                            }
                        });
                    })
                    .catch(error => console.error('Error fetching notifications:', error));
            }, 120000); // 2 minutes
        }

        // Initialize real-time notifications
        initializeRealTimeNotifications();
    </script>
</body>
</html>