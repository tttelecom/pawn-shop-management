<?php
/**
 * Notification Widget Integration
 * ไฟล์สำหรับเรียกใช้ระบบแจ้งเตือนใน header
 */

// Include the enhanced notification system
require_once 'notification_enhanced.php';

// AJAX Handler for notification requests
if (isset($_GET['ajax'])) {
    requireLogin();
    $user = getCurrentUser();
    $db = getDB();
    $notificationSystem = new NotificationSystem($db, $user);
    
    switch ($_GET['ajax']) {
        case 'get_notifications':
            $notifications = $notificationSystem->getAllNotifications();
            header('Content-Type: application/json');
            echo json_encode($notifications);
            exit;
            
        case 'get_unread_count':
            $unread_count = $notificationSystem->getUnreadCount();
            header('Content-Type: application/json');
            echo json_encode(['unread_count' => $unread_count]);
            exit;
            
        case 'mark_read':
            if (isset($_POST['notification_id'])) {
                if (!isset($_SESSION['read_notifications'])) {
                    $_SESSION['read_notifications'] = [];
                }
                $_SESSION['read_notifications'][] = $_POST['notification_id'];
                echo json_encode(['success' => true]);
            }
            exit;
            
        case 'mark_all_read':
            $_SESSION['read_notifications'] = [];
            echo json_encode(['success' => true]);
            exit;
    }
}

/**
 * Function to include in header of pages
 */
function includeNotificationWidget() {
    if (!isLoggedIn()) return '';
    
    $user = getCurrentUser();
    $db = getDB();
    $notificationSystem = new NotificationSystem($db, $user);
    return $notificationSystem->generateNotificationWidget();
}

/**
 * Function to get notification summary for dashboard
 */
function getNotificationSummary() {
    if (!isLoggedIn()) return [];
    
    $user = getCurrentUser();
    $db = getDB();
    $notificationSystem = new NotificationSystem($db, $user);
    $notifications = $notificationSystem->getAllNotifications();
    
    // Group by type and priority
    $summary = [
        'total' => count($notifications),
        'unread' => $notificationSystem->getUnreadCount(),
        'critical' => count(array_filter($notifications, fn($n) => $n['priority'] === 'critical')),
        'high' => count(array_filter($notifications, fn($n) => $n['priority'] === 'high')),
        'by_type' => [
            'contract_expiry' => count(array_filter($notifications, fn($n) => $n['type'] === 'contract_expiry')),
            'interest_payment' => count(array_filter($notifications, fn($n) => $n['type'] === 'interest_payment')),
            'overdue' => count(array_filter($notifications, fn($n) => $n['type'] === 'overdue')),
            'inventory' => count(array_filter($notifications, fn($n) => $n['type'] === 'inventory')),
        ],
        'recent' => array_slice($notifications, 0, 5)
    ];
    
    return $summary;
}

/**
 * SMS/Email notification function (can be extended)
 */
function sendNotificationAlert($notification, $method = 'sms') {
    // This function can be extended to send actual SMS/Email
    // For now, it logs the notification
    
    $message = "แจ้งเตือน: {$notification['title']} - {$notification['message']}";
    
    if (isset($notification['phone']) && $method === 'sms') {
        // TODO: Integrate with SMS API
        error_log("SMS Alert to {$notification['phone']}: $message");
        return true;
    }
    
    if (isset($notification['email']) && $method === 'email') {
        // TODO: Integrate with Email API
        error_log("Email Alert to {$notification['email']}: $message");
        return true;
    }
    
    return false;
}

/**
 * Automatic notification checker (can be run via cron job)
 */
function checkAndSendAlerts() {
    $db = getDB();
    
    // Get all active users
    $stmt = $db->prepare("SELECT * FROM users WHERE status = 'active'");
    $stmt->execute();
    $users = $stmt->fetchAll();
    
    foreach ($users as $user) {
        $notificationSystem = new NotificationSystem($db, $user);
        $notifications = $notificationSystem->getAllNotifications();
        
        foreach ($notifications as $notification) {
            // Send alerts for critical notifications
            if ($notification['priority'] === 'critical') {
                if (isset($notification['phone'])) {
                    sendNotificationAlert($notification, 'sms');
                }
            }
            
            // Send daily summary for high priority items
            if ($notification['priority'] === 'high') {
                // Can be extended to send daily summary emails
            }
        }
    }
}

/**
 * Dashboard notification cards
 */
function renderNotificationCards() {
    $summary = getNotificationSummary();
    if (empty($summary)) return '';
    
    ob_start();
    ?>
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <!-- Critical Notifications -->
        <div class="bg-white rounded-xl shadow-lg p-4 border-l-4 border-red-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-red-600 text-sm font-medium">แจ้งเตือนด่วน</p>
                    <p class="text-2xl font-bold text-red-700"><?= $summary['critical'] ?></p>
                </div>
                <i class="fas fa-exclamation-triangle text-red-500 text-2xl"></i>
            </div>
        </div>
        
        <!-- High Priority -->
        <div class="bg-white rounded-xl shadow-lg p-4 border-l-4 border-orange-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-orange-600 text-sm font-medium">ความสำคัญสูง</p>
                    <p class="text-2xl font-bold text-orange-700"><?= $summary['high'] ?></p>
                </div>
                <i class="fas fa-clock text-orange-500 text-2xl"></i>
            </div>
        </div>
        
        <!-- Contract Expiry -->
        <div class="bg-white rounded-xl shadow-lg p-4 border-l-4 border-yellow-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-yellow-600 text-sm font-medium">ใกล้ครบกำหนด</p>
                    <p class="text-2xl font-bold text-yellow-700"><?= $summary['by_type']['contract_expiry'] ?></p>
                </div>
                <i class="fas fa-calendar-alt text-yellow-500 text-2xl"></i>
            </div>
        </div>
        
        <!-- Interest Due -->
        <div class="bg-white rounded-xl shadow-lg p-4 border-l-4 border-blue-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-blue-600 text-sm font-medium">ต้องชำระดอกเบี้ย</p>
                    <p class="text-2xl font-bold text-blue-700"><?= $summary['by_type']['interest_payment'] ?></p>
                </div>
                <i class="fas fa-percentage text-blue-500 text-2xl"></i>
            </div>
        </div>
    </div>
    
    <?php if (!empty($summary['recent'])): ?>
    <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
        <h3 class="text-lg font-semibold mb-4">การแจ้งเตือนล่าสุด</h3>
        <div class="space-y-3">
            <?php foreach ($summary['recent'] as $notification): ?>
                <div class="flex items-start space-x-3 p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                    <div class="flex-shrink-0 p-2 rounded-full bg-<?= $notification['color'] ?>-100">
                        <i class="<?= $notification['icon'] ?> text-<?= $notification['color'] ?>-600"></i>
                    </div>
                    <div class="flex-1">
                        <div class="flex justify-between items-start">
                            <p class="font-medium text-gray-900"><?= h($notification['title']) ?></p>
                            <span class="text-xs px-2 py-1 rounded-full bg-<?= $notification['color'] ?>-100 text-<?= $notification['color'] ?>-800">
                                <?= ucfirst($notification['priority']) ?>
                            </span>
                        </div>
                        <p class="text-sm text-gray-600"><?= h($notification['message']) ?></p>
                        <p class="text-xs text-gray-400 mt-1"><?= formatThaiDate($notification['date']) ?></p>
                    </div>
                    <?php if (isset($notification['action_url'])): ?>
                        <a href="<?= h($notification['action_url']) ?>" 
                           class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                            ดู →
                        </a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="mt-4 text-center">
            <a href="notifications.php" class="text-blue-600 hover:text-blue-800 font-medium">
                ดูการแจ้งเตือนทั้งหมด →
            </a>
        </div>
    </div>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}
?>