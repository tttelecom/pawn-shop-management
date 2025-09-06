<?php
require_once 'config/database.php';
require_once 'notification_widget.php';
requireLogin();

$user = getCurrentUser();
$db = getDB();

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    switch ($_GET['ajax']) {
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
            $notificationSystem = new NotificationSystem($db, $user);
            $notifications = $notificationSystem->getAllNotifications();
            
            if (!isset($_SESSION['read_notifications'])) {
                $_SESSION['read_notifications'] = [];
            }
            
            foreach ($notifications as $notification) {
                $_SESSION['read_notifications'][] = $notification['id'];
            }
            
            echo json_encode(['success' => true]);
            exit;
            
        case 'get_notifications':
            $notificationSystem = new NotificationSystem($db, $user);
            $notifications = $notificationSystem->getAllNotifications();
            header('Content-Type: application/json');
            echo json_encode($notifications);
            exit;
            
        case 'get_unread_count':
            $notificationSystem = new NotificationSystem($db, $user);
            $unread_count = $notificationSystem->getUnreadCount();
            $critical_count = 0;
            
            $notifications = $notificationSystem->getAllNotifications();
            foreach ($notifications as $notification) {
                if ($notification['priority'] === 'critical' && 
                    !in_array($notification['id'], $_SESSION['read_notifications'] ?? [])) {
                    $critical_count++;
                }
            }
            
            header('Content-Type: application/json');
            echo json_encode([
                'unread_count' => $unread_count,
                'critical_count' => $critical_count
            ]);
            exit;
    }
}

// Get all notifications
$notificationSystem = new NotificationSystem($db, $user);
$notifications = $notificationSystem->getAllNotifications();

// Filter notifications
$filter = $_GET['filter'] ?? 'all';
$priority_filter = $_GET['priority'] ?? '';
$type_filter = $_GET['type'] ?? '';

$filtered_notifications = $notifications;

if ($filter === 'unread') {
    $filtered_notifications = array_filter($notifications, function($n) {
        return !in_array($n['id'], $_SESSION['read_notifications'] ?? []);
    });
} elseif ($filter === 'read') {
    $filtered_notifications = array_filter($notifications, function($n) {
        return in_array($n['id'], $_SESSION['read_notifications'] ?? []);
    });
}

if (!empty($priority_filter)) {
    $filtered_notifications = array_filter($filtered_notifications, function($n) use ($priority_filter) {
        return $n['priority'] === $priority_filter;
    });
}

if (!empty($type_filter)) {
    $filtered_notifications = array_filter($filtered_notifications, function($n) use ($type_filter) {
        return $n['type'] === $type_filter;
    });
}

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$total_notifications = count($filtered_notifications);
$pagination = paginate($total_notifications, $page, $per_page);

$paginated_notifications = array_slice($filtered_notifications, $pagination['offset'], $pagination['per_page']);

// Count by status and priority
$unread_count = count(array_filter($notifications, function($n) {
    return !in_array($n['id'], $_SESSION['read_notifications'] ?? []);
}));

$priority_counts = [
    'critical' => 0,
    'high' => 0,
    'medium' => 0,
    'low' => 0,
    'info' => 0
];

foreach ($notifications as $notification) {
    if (isset($priority_counts[$notification['priority']])) {
        $priority_counts[$notification['priority']]++;
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>การแจ้งเตือน - <?= h(APP_NAME) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Sarabun', sans-serif; }
        .gradient-bg { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .notification-item { transition: all 0.2s ease; }
        .notification-item:hover { transform: translateY(-1px); }
        .pulse-animation { animation: pulse 2s infinite; }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Header -->
    <header class="gradient-bg text-white shadow-lg">
        <div class="container mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <a href="index.php" class="text-white hover:text-gray-200">
                        <i class="fas fa-arrow-left text-xl"></i>
                    </a>
                    <i class="fas fa-bell text-2xl"></i>
                    <h1 class="text-2xl font-bold">การแจ้งเตือน</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <?php if ($unread_count > 0): ?>
                    <button onclick="markAllAsRead()" 
                            class="bg-white bg-opacity-20 hover:bg-opacity-30 px-4 py-2 rounded-lg transition-all">
                        <i class="fas fa-check-double mr-2"></i>อ่านทั้งหมด
                    </button>
                    <?php endif; ?>
                    <span class="text-sm opacity-90"><?= h($user['full_name']) ?></span>
                    <a href="logout.php" class="text-white hover:text-gray-200">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="container mx-auto px-6 py-8">
        <!-- Summary Cards -->
        <div class="grid grid-cols-2 md:grid-cols-6 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <div class="text-2xl font-bold text-blue-600"><?= count($notifications) ?></div>
                <div class="text-sm text-gray-600">ทั้งหมด</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <div class="text-2xl font-bold text-orange-600"><?= $unread_count ?></div>
                <div class="text-sm text-gray-600">ยังไม่อ่าน</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <div class="text-2xl font-bold text-red-600"><?= $priority_counts['critical'] ?></div>
                <div class="text-sm text-gray-600">ด่วนมาก</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <div class="text-2xl font-bold text-yellow-600"><?= $priority_counts['high'] ?></div>
                <div class="text-sm text-gray-600">ด่วน</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <div class="text-2xl font-bold text-green-600"><?= $priority_counts['medium'] ?></div>
                <div class="text-sm text-gray-600">ปกติ</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <div class="text-2xl font-bold text-gray-600"><?= $priority_counts['low'] + $priority_counts['info'] ?></div>
                <div class="text-sm text-gray-600">ต่ำ/ข้อมูล</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <div class="flex flex-wrap gap-4 items-center">
                <!-- Status Filter -->
                <div class="flex space-x-2">
                    <a href="?filter=all&priority=<?= urlencode($priority_filter) ?>&type=<?= urlencode($type_filter) ?>" 
                       class="px-4 py-2 rounded-lg <?= $filter === 'all' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">
                        ทั้งหมด
                    </a>
                    <a href="?filter=unread&priority=<?= urlencode($priority_filter) ?>&type=<?= urlencode($type_filter) ?>" 
                       class="px-4 py-2 rounded-lg <?= $filter === 'unread' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">
                        ยังไม่อ่าน (<?= $unread_count ?>)
                    </a>
                    <a href="?filter=read&priority=<?= urlencode($priority_filter) ?>&type=<?= urlencode($type_filter) ?>" 
                       class="px-4 py-2 rounded-lg <?= $filter === 'read' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">
                        อ่านแล้ว
                    </a>
                </div>

                <!-- Priority Filter -->
                <select onchange="window.location.href='?filter=<?= urlencode($filter) ?>&priority=' + this.value + '&type=<?= urlencode($type_filter) ?>'" 
                        class="border border-gray-300 rounded-lg px-3 py-2">
                    <option value="">ทุกระดับความสำคัญ</option>
                    <option value="critical" <?= $priority_filter === 'critical' ? 'selected' : '' ?>>ด่วนมาก</option>
                    <option value="high" <?= $priority_filter === 'high' ? 'selected' : '' ?>>ด่วน</option>
                    <option value="medium" <?= $priority_filter === 'medium' ? 'selected' : '' ?>>ปกติ</option>
                    <option value="low" <?= $priority_filter === 'low' ? 'selected' : '' ?>>ต่ำ</option>
                    <option value="info" <?= $priority_filter === 'info' ? 'selected' : '' ?>>ข้อมูล</option>
                </select>

                <!-- Type Filter -->
                <select onchange="window.location.href='?filter=<?= urlencode($filter) ?>&priority=<?= urlencode($priority_filter) ?>&type=' + this.value" 
                        class="border border-gray-300 rounded-lg px-3 py-2">
                    <option value="">ทุกประเภท</option>
                    <option value="contract_expiry" <?= $type_filter === 'contract_expiry' ? 'selected' : '' ?>>ใกล้ครบกำหนด</option>
                    <option value="interest_payment" <?= $type_filter === 'interest_payment' ? 'selected' : '' ?>>ดอกเบี้ย</option>
                    <option value="overdue" <?= $type_filter === 'overdue' ? 'selected' : '' ?>>เกินกำหนด</option>
                    <option value="high_value" <?= $type_filter === 'high_value' ? 'selected' : '' ?>>มูลค่าสูง</option>
                    <option value="inventory" <?= $type_filter === 'inventory' ? 'selected' : '' ?>>สินค้าคงคลัง</option>
                </select>
            </div>
        </div>

        <!-- Notifications List -->
        <div class="bg-white rounded-xl shadow-lg">
            <div class="p-6 border-b">
                <h3 class="text-xl font-semibold text-gray-800">
                    การแจ้งเตือน (<?= number_format($total_notifications) ?> รายการ)
                </h3>
            </div>

            <div class="divide-y divide-gray-200">
                <?php if (empty($paginated_notifications)): ?>
                    <div class="p-8 text-center">
                        <i class="fas fa-bell-slash text-gray-400 text-4xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-600 mb-2">ไม่มีการแจ้งเตือน</h3>
                        <p class="text-gray-500">ไม่พบการแจ้งเตือนตามเงื่อนไขที่เลือก</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($paginated_notifications as $notification): ?>
                        <?php $is_read = in_array($notification['id'], $_SESSION['read_notifications'] ?? []); ?>
                        <div class="notification-item p-6 hover:bg-gray-50 cursor-pointer <?= !$is_read ? 'bg-blue-50 border-l-4 border-l-blue-500' : '' ?>"
                             onclick="handleNotificationClick('<?= $notification['id'] ?>', '<?= $notification['action_url'] ?? '' ?>')">
                            
                            <div class="flex items-start space-x-4">
                                <!-- Icon -->
                                <div class="flex-shrink-0 p-3 rounded-full bg-<?= $notification['color'] ?>-100 <?= $notification['priority'] === 'critical' ? 'pulse-animation' : '' ?>">
                                    <i class="<?= $notification['icon'] ?> text-<?= $notification['color'] ?>-600 text-xl"></i>
                                </div>

                                <!-- Content -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-start justify-between mb-2">
                                        <h4 class="text-lg font-semibold text-gray-900">
                                            <?= h($notification['title']) ?>
                                        </h4>
                                        <div class="flex items-center space-x-2 ml-4">
                                            <?php if (!$is_read): ?>
                                                <span class="w-3 h-3 bg-blue-500 rounded-full"></span>
                                            <?php endif; ?>
                                            <span class="text-xs px-2 py-1 rounded-full bg-<?= $notification['color'] ?>-100 text-<?= $notification['color'] ?>-800 font-medium">
                                                <?= ucfirst($notification['priority']) ?>
                                            </span>
                                        </div>
                                    </div>

                                    <p class="text-gray-700 mb-3"><?= h($notification['message']) ?></p>

                                    <!-- Details -->
                                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
                                        <?php if (isset($notification['customer'])): ?>
                                        <div class="flex items-center text-gray-600">
                                            <i class="fas fa-user mr-2 text-blue-500"></i>
                                            <span><?= h($notification['customer']) ?></span>
                                        </div>
                                        <?php endif; ?>

                                        <?php if (isset($notification['phone'])): ?>
                                        <div class="flex items-center text-gray-600">
                                            <i class="fas fa-phone mr-2 text-green-500"></i>
                                            <a href="tel:<?= h($notification['phone']) ?>" class="text-blue-600 hover:text-blue-800">
                                                <?= h($notification['phone']) ?>
                                            </a>
                                        </div>
                                        <?php endif; ?>

                                        <?php if (isset($notification['amount'])): ?>
                                        <div class="flex items-center text-gray-600">
                                            <i class="fas fa-money-bill mr-2 text-green-500"></i>
                                            <span class="font-medium"><?= formatCurrency($notification['amount']) ?></span>
                                        </div>
                                        <?php endif; ?>

                                        <?php if (isset($notification['days_overdue'])): ?>
                                        <div class="flex items-center text-red-600">
                                            <i class="fas fa-exclamation-triangle mr-2"></i>
                                            <span class="font-medium">เกิน <?= $notification['days_overdue'] ?> วัน</span>
                                        </div>
                                        <?php endif; ?>

                                        <?php if (isset($notification['days_in_stock'])): ?>
                                        <div class="flex items-center text-gray-600">
                                            <i class="fas fa-clock mr-2 text-orange-500"></i>
                                            <span>ในสต็อก <?= $notification['days_in_stock'] ?> วัน</span>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Footer -->
                                    <div class="flex items-center justify-between mt-4 pt-3 border-t border-gray-200">
                                        <div class="flex items-center text-sm text-gray-500">
                                            <i class="fas fa-clock mr-1"></i>
                                            <?= formatThaiDate($notification['date']) ?>
                                        </div>
                                        
                                        <?php if (isset($notification['action_text'])): ?>
                                        <span class="text-sm text-blue-600 font-medium">
                                            <?= h($notification['action_text']) ?> →
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($pagination['total_pages'] > 1): ?>
            <div class="mt-6 flex items-center justify-between">
                <div class="text-sm text-gray-700">
                    แสดง <?= number_format($pagination['offset'] + 1) ?> ถึง <?= number_format(min($pagination['offset'] + $pagination['per_page'], $pagination['total_records'])) ?> 
                    จาก <?= number_format($pagination['total_records']) ?> รายการ
                </div>
                <div class="flex space-x-2">
                    <?php if ($pagination['has_prev']): ?>
                        <a href="?page=<?= $pagination['prev_page'] ?>&filter=<?= urlencode($filter) ?>&priority=<?= urlencode($priority_filter) ?>&type=<?= urlencode($type_filter) ?>" 
                           class="px-3 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php endif; ?>
                    
                    <?php
                    $start_page = max(1, $pagination['page'] - 2);
                    $end_page = min($pagination['total_pages'], $pagination['page'] + 2);
                    for ($i = $start_page; $i <= $end_page; $i++):
                    ?>
                        <a href="?page=<?= $i ?>&filter=<?= urlencode($filter) ?>&priority=<?= urlencode($priority_filter) ?>&type=<?= urlencode($type_filter) ?>" 
                           class="px-3 py-2 border rounded-lg <?= $i === $pagination['page'] ? 'bg-blue-500 text-white border-blue-500' : 'bg-white border-gray-300 hover:bg-gray-50' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($pagination['has_next']): ?>
                        <a href="?page=<?= $pagination['next_page'] ?>&filter=<?= urlencode($filter) ?>&priority=<?= urlencode($priority_filter) ?>&type=<?= urlencode($type_filter) ?>" 
                           class="px-3 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function handleNotificationClick(notificationId, actionUrl) {
            // Mark as read
            fetch('notifications.php?ajax=mark_read', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'notification_id=' + encodeURIComponent(notificationId)
            }).then(() => {
                // Navigate to action URL or refresh page
                if (actionUrl) {
                    window.location.href = actionUrl;
                } else {
                    location.reload();
                }
            });
        }

        function markAllAsRead() {
            if (confirm('ยืนยันการทำเครื่องหมายอ่านทั้งหมด?')) {
                fetch('notifications.php?ajax=mark_all_read', {
                    method: 'POST'
                }).then(() => {
                    location.reload();
                });
            }
        }

        // Auto-refresh every 2 minutes
        setInterval(function() {
            location.reload();
        }, 120000);

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'a') {
                e.preventDefault();
                markAllAsRead();
            }
        });
    </script>
</body>
</html>