<?php
require_once 'config/database.php';

/**
 * Enhanced Notification System
 * แจ้งเตือนสัญญาใกล้ครบกำหนดและการชำระดอกเบี้ย
 */
class NotificationSystem {
    private $db;
    private $user;
    
    public function __construct($db, $user) {
        $this->db = $db;
        $this->user = $user;
    }
    
    /**
     * ดึงการแจ้งเตือนทั้งหมด
     */
    public function getAllNotifications() {
        $notifications = [];
        
        // รวบรวมการแจ้งเตือนจากทุกประเภท
        $notifications = array_merge(
            $this->getContractExpiryNotifications(),
            $this->getInterestPaymentNotifications(),
            $this->getOverdueNotifications(),
            $this->getHighValueNotifications(),
            $this->getInventoryNotifications()
        );
        
        // เรียงลำดับตามความสำคัญและวันที่
        usort($notifications, function($a, $b) {
            $priority_order = ['critical' => 1, 'high' => 2, 'medium' => 3, 'low' => 4, 'info' => 5];
            
            // เรียงตามความสำคัญก่อน
            $priority_diff = ($priority_order[$a['priority']] ?? 5) - ($priority_order[$b['priority']] ?? 5);
            if ($priority_diff !== 0) {
                return $priority_diff;
            }
            
            // ถ้าความสำคัญเท่ากัน เรียงตามวันที่
            return strtotime($a['date']) - strtotime($b['date']);
        });
        
        return $notifications;
    }
    
    /**
     * แจ้งเตือนสัญญาใกล้ครบกำหนด
     */
    public function getContractExpiryNotifications() {
        $notifications = [];
        
        // สัญญาที่ครบกำหนดในวันนี้
        $stmt = $this->db->prepare("
            SELECT pt.*, c.first_name, c.last_name, c.phone,
                   DATEDIFF(pt.due_date, CURDATE()) as days_remaining
            FROM pawn_transactions pt
            JOIN customers c ON pt.customer_id = c.id
            WHERE pt.status = 'active' 
            AND pt.due_date = CURDATE()
            AND (pt.branch_id = ? OR ? = 'admin')
            ORDER BY pt.pawn_amount DESC
        ");
        $stmt->execute([$this->user['branch_id'], $this->user['role']]);
        $expiring_today = $stmt->fetchAll();
        
        foreach ($expiring_today as $item) {
            $notifications[] = [
                'id' => 'expiry_today_' . $item['id'],
                'type' => 'contract_expiry',
                'priority' => 'critical',
                'title' => 'สัญญาครบกำหนดวันนี้',
                'message' => "รายการ {$item['transaction_code']} ครบกำหนดวันนี้",
                'customer' => $item['first_name'] . ' ' . $item['last_name'],
                'phone' => $item['phone'],
                'amount' => $item['pawn_amount'],
                'date' => $item['due_date'],
                'action_url' => "pawn_detail.php?id=" . $item['id'],
                'action_text' => 'ติดต่อลูกค้า',
                'icon' => 'fas fa-exclamation-triangle',
                'color' => 'red'
            ];
        }
        
        // สัญญาที่ครบกำหนดพรุ่งนี้
        $stmt = $this->db->prepare("
            SELECT pt.*, c.first_name, c.last_name, c.phone,
                   DATEDIFF(pt.due_date, CURDATE()) as days_remaining
            FROM pawn_transactions pt
            JOIN customers c ON pt.customer_id = c.id
            WHERE pt.status = 'active' 
            AND pt.due_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
            AND (pt.branch_id = ? OR ? = 'admin')
            ORDER BY pt.pawn_amount DESC
        ");
        $stmt->execute([$this->user['branch_id'], $this->user['role']]);
        $expiring_tomorrow = $stmt->fetchAll();
        
        foreach ($expiring_tomorrow as $item) {
            $notifications[] = [
                'id' => 'expiry_tomorrow_' . $item['id'],
                'type' => 'contract_expiry',
                'priority' => 'high',
                'title' => 'สัญญาครบกำหนดพรุ่งนี้',
                'message' => "รายการ {$item['transaction_code']} ครบกำหนดพรุ่งนี้",
                'customer' => $item['first_name'] . ' ' . $item['last_name'],
                'phone' => $item['phone'],
                'amount' => $item['pawn_amount'],
                'date' => $item['due_date'],
                'action_url' => "pawn_detail.php?id=" . $item['id'],
                'action_text' => 'เตรียมติดต่อ',
                'icon' => 'fas fa-clock',
                'color' => 'orange'
            ];
        }
        
        // สัญญาที่ครบกำหนดใน 3-7 วัน
        $stmt = $this->db->prepare("
            SELECT pt.*, c.first_name, c.last_name, c.phone,
                   DATEDIFF(pt.due_date, CURDATE()) as days_remaining
            FROM pawn_transactions pt
            JOIN customers c ON pt.customer_id = c.id
            WHERE pt.status = 'active' 
            AND pt.due_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 2 DAY) 
                                AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            AND (pt.branch_id = ? OR ? = 'admin')
            ORDER BY pt.due_date ASC, pt.pawn_amount DESC
            LIMIT 10
        ");
        $stmt->execute([$this->user['branch_id'], $this->user['role']]);
        $expiring_week = $stmt->fetchAll();
        
        foreach ($expiring_week as $item) {
            $notifications[] = [
                'id' => 'expiry_week_' . $item['id'],
                'type' => 'contract_expiry',
                'priority' => 'medium',
                'title' => 'สัญญาใกล้ครบกำหนด',
                'message' => "รายการ {$item['transaction_code']} เหลือ {$item['days_remaining']} วัน",
                'customer' => $item['first_name'] . ' ' . $item['last_name'],
                'phone' => $item['phone'],
                'amount' => $item['pawn_amount'],
                'date' => $item['due_date'],
                'action_url' => "pawn_detail.php?id=" . $item['id'],
                'action_text' => 'ดูรายละเอียด',
                'icon' => 'fas fa-calendar-alt',
                'color' => 'yellow'
            ];
        }
        
        return $notifications;
    }
    
    /**
     * แจ้งเตือนการชำระดอกเบี้ย
     */
    public function getInterestPaymentNotifications() {
        $notifications = [];
        
        // ลูกค้าที่ไม่ได้ชำระดอกเบี้ยเกิน 30 วัน
        $stmt = $this->db->prepare("
            SELECT pt.*, c.first_name, c.last_name, c.phone,
                   DATEDIFF(CURDATE(), pt.pawn_date) as days_since_pawn,
                   COALESCE(last_payment.last_payment_date, pt.pawn_date) as last_payment_date,
                   DATEDIFF(CURDATE(), COALESCE(last_payment.last_payment_date, pt.pawn_date)) as days_since_payment
            FROM pawn_transactions pt
            JOIN customers c ON pt.customer_id = c.id
            LEFT JOIN (
                SELECT transaction_id, MAX(payment_date) as last_payment_date
                FROM payments 
                WHERE payment_type IN ('interest', 'partial_payment')
                GROUP BY transaction_id
            ) last_payment ON pt.id = last_payment.transaction_id
            WHERE pt.status = 'active'
            AND DATEDIFF(CURDATE(), COALESCE(last_payment.last_payment_date, pt.pawn_date)) >= 30
            AND (pt.branch_id = ? OR ? = 'admin')
            ORDER BY days_since_payment DESC
            LIMIT 15
        ");
        $stmt->execute([$this->user['branch_id'], $this->user['role']]);
        $interest_due = $stmt->fetchAll();
        
        foreach ($interest_due as $item) {
            $priority = 'medium';
            $title = 'ต้องชำระดอกเบี้ย';
            $color = 'blue';
            
            if ($item['days_since_payment'] >= 60) {
                $priority = 'high';
                $title = 'ค้างชำระดอกเบี้ยนาน';
                $color = 'red';
            } elseif ($item['days_since_payment'] >= 45) {
                $priority = 'medium';
                $color = 'orange';
            }
            
            // คำนวณดอกเบี้ยที่ค้าง
            $months_elapsed = ceil($item['days_since_payment'] / 30);
            $interest_amount = $item['pawn_amount'] * ($item['interest_rate'] / 100) * $months_elapsed;
            
            $notifications[] = [
                'id' => 'interest_due_' . $item['id'],
                'type' => 'interest_payment',
                'priority' => $priority,
                'title' => $title,
                'message' => "รายการ {$item['transaction_code']} ค้างชำระดอกเบี้ย {$item['days_since_payment']} วัน",
                'customer' => $item['first_name'] . ' ' . $item['last_name'],
                'phone' => $item['phone'],
                'amount' => $interest_amount,
                'pawn_amount' => $item['pawn_amount'],
                'date' => $item['last_payment_date'],
                'days_overdue' => $item['days_since_payment'],
                'action_url' => "payment.php?pawn_id=" . $item['id'],
                'action_text' => 'รับชำระดอกเบี้ย',
                'icon' => 'fas fa-percentage',
                'color' => $color
            ];
        }
        
        return $notifications;
    }
    
    /**
     * แจ้งเตือนรายการเกินกำหนด
     */
    public function getOverdueNotifications() {
        $notifications = [];
        
        $stmt = $this->db->prepare("
            SELECT pt.*, c.first_name, c.last_name, c.phone,
                   DATEDIFF(CURDATE(), pt.due_date) as days_overdue
            FROM pawn_transactions pt
            JOIN customers c ON pt.customer_id = c.id
            WHERE pt.status = 'active' 
            AND pt.due_date < CURDATE()
            AND (pt.branch_id = ? OR ? = 'admin')
            ORDER BY days_overdue DESC
            LIMIT 15
        ");
        $stmt->execute([$this->user['branch_id'], $this->user['role']]);
        $overdue = $stmt->fetchAll();
        
        foreach ($overdue as $item) {
            $priority = 'high';
            $title = 'เกินกำหนดชำระ';
            $color = 'red';
            
            if ($item['days_overdue'] >= 30) {
                $priority = 'critical';
                $title = 'เกินกำหนดมากกว่า 30 วัน';
            } elseif ($item['days_overdue'] >= 14) {
                $title = 'เกินกำหนดมากกว่า 2 สัปดาห์';
            }
            
            $notifications[] = [
                'id' => 'overdue_' . $item['id'],
                'type' => 'overdue',
                'priority' => $priority,
                'title' => $title,
                'message' => "รายการ {$item['transaction_code']} เกินกำหนด {$item['days_overdue']} วัน",
                'customer' => $item['first_name'] . ' ' . $item['last_name'],
                'phone' => $item['phone'],
                'amount' => $item['pawn_amount'],
                'date' => $item['due_date'],
                'days_overdue' => $item['days_overdue'],
                'action_url' => "pawn_detail.php?id=" . $item['id'],
                'action_text' => 'จัดการรายการ',
                'icon' => 'fas fa-exclamation-triangle',
                'color' => $color
            ];
        }
        
        return $notifications;
    }
    
    /**
     * แจ้งเตือนรายการมูลค่าสูง
     */
    public function getHighValueNotifications() {
        $notifications = [];
        
        if (in_array($this->user['role'], ['admin', 'manager'])) {
            $stmt = $this->db->prepare("
                SELECT pt.*, c.first_name, c.last_name
                FROM pawn_transactions pt
                JOIN customers c ON pt.customer_id = c.id
                WHERE pt.pawn_amount >= 100000
                AND pt.created_at >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
                AND (pt.branch_id = ? OR ? = 'admin')
                ORDER BY pt.created_at DESC
                LIMIT 5
            ");
            $stmt->execute([$this->user['branch_id'], $this->user['role']]);
            $high_value = $stmt->fetchAll();
            
            foreach ($high_value as $item) {
                $notifications[] = [
                    'id' => 'high_value_' . $item['id'],
                    'type' => 'high_value',
                    'priority' => 'info',
                    'title' => 'รายการมูลค่าสูง',
                    'message' => "รายการ {$item['transaction_code']} มูลค่า " . formatCurrency($item['pawn_amount']),
                    'customer' => $item['first_name'] . ' ' . $item['last_name'],
                    'amount' => $item['pawn_amount'],
                    'date' => $item['created_at'],
                    'action_url' => "pawn_detail.php?id=" . $item['id'],
                    'action_text' => 'ตรวจสอบ',
                    'icon' => 'fas fa-star',
                    'color' => 'purple'
                ];
            }
        }
        
        return $notifications;
    }
    
    /**
     * แจ้งเตือนสินค้าคงคลัง
     */
    public function getInventoryNotifications() {
        $notifications = [];
        
        // สินค้าที่อยู่ในสต็อกนานเกิน 90 วัน
        $stmt = $this->db->prepare("
            SELECT i.*, ic.name as category_name,
                   DATEDIFF(CURDATE(), i.created_at) as days_in_stock
            FROM inventory i
            LEFT JOIN item_categories ic ON i.category_id = ic.id
            WHERE i.status = 'available'
            AND DATEDIFF(CURDATE(), i.created_at) >= 90
            AND (i.branch_id = ? OR ? = 'admin')
            ORDER BY days_in_stock DESC
            LIMIT 10
        ");
        $stmt->execute([$this->user['branch_id'], $this->user['role']]);
        $old_inventory = $stmt->fetchAll();
        
        foreach ($old_inventory as $item) {
            $priority = 'low';
            $title = 'สินค้าค้างสต็อกนาน';
            
            if ($item['days_in_stock'] >= 180) {
                $priority = 'medium';
                $title = 'สินค้าค้างสต็อกมากกว่า 6 เดือน';
            }
            
            $notifications[] = [
                'id' => 'old_inventory_' . $item['id'],
                'type' => 'inventory',
                'priority' => $priority,
                'title' => $title,
                'message' => "สินค้า {$item['item_code']} อยู่ในสต็อก {$item['days_in_stock']} วัน",
                'item_name' => $item['item_name'],
                'category' => $item['category_name'],
                'amount' => $item['selling_price'],
                'date' => $item['created_at'],
                'days_in_stock' => $item['days_in_stock'],
                'action_url' => "inventory.php?search=" . urlencode($item['item_code']),
                'action_text' => 'จัดการสินค้า',
                'icon' => 'fas fa-boxes',
                'color' => 'gray'
            ];
        }
        
        return $notifications;
    }
    
    /**
     * นับจำนวนการแจ้งเตือนที่ยังไม่ได้อ่าน
     */
    public function getUnreadCount() {
        $notifications = $this->getAllNotifications();
        $unread_count = 0;
        
        foreach ($notifications as $notification) {
            if (!$this->isNotificationRead($notification['id'])) {
                $unread_count++;
            }
        }
        
        return $unread_count;
    }
    
    /**
     * ตรวจสอบว่าการแจ้งเตือนถูกอ่านแล้วหรือไม่
     */
    private function isNotificationRead($notification_id) {
        return isset($_SESSION['read_notifications']) && 
               in_array($notification_id, $_SESSION['read_notifications']);
    }
    
    /**
     * สร้าง Widget การแจ้งเตือนสำหรับ Header
     */
    public function generateNotificationWidget() {
        $notifications = $this->getAllNotifications();
        $unread_count = $this->getUnreadCount();
        
        ob_start();
        ?>
        <div class="relative">
            <button id="notificationButton" class="relative p-2 text-white hover:text-gray-200 focus:outline-none">
                <i class="fas fa-bell text-xl"></i>
                <?php if ($unread_count > 0): ?>
                    <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full w-6 h-6 flex items-center justify-center animate-pulse">
                        <?= $unread_count > 99 ? '99+' : $unread_count ?>
                    </span>
                <?php endif; ?>
            </button>
            
            <div id="notificationDropdown" class="hidden absolute right-0 mt-2 w-96 bg-white rounded-lg shadow-xl border z-50 max-h-96 overflow-hidden">
                <div class="p-4 border-b bg-gradient-to-r from-blue-500 to-purple-600 text-white">
                    <div class="flex justify-between items-center">
                        <h3 class="font-semibold text-lg">การแจ้งเตือน</h3>
                        <?php if ($unread_count > 0): ?>
                            <button onclick="markAllRead()" class="text-sm text-white hover:text-gray-200 underline">
                                อ่านทั้งหมด
                            </button>
                        <?php endif; ?>
                    </div>
                    <p class="text-sm opacity-90"><?= $unread_count ?> รายการใหม่</p>
                </div>
                
                <div class="max-h-80 overflow-y-auto">
                    <?php if (empty($notifications)): ?>
                        <div class="p-6 text-center text-gray-500">
                            <i class="fas fa-bell-slash text-3xl mb-3 block"></i>
                            <p>ไม่มีการแจ้งเตือน</p>
                            <p class="text-sm">ระบบทำงานปกติ</p>
                        </div>
                    <?php else: ?>
                        <?php foreach (array_slice($notifications, 0, 15) as $notif): ?>
                            <div class="notification-item p-4 border-b hover:bg-gray-50 cursor-pointer transition-colors <?= !$this->isNotificationRead($notif['id']) ? 'bg-blue-50 border-l-4 border-l-blue-500' : '' ?>"
                                 onclick="handleNotificationClick('<?= $notif['id'] ?>', '<?= $notif['action_url'] ?? '' ?>')">
                                <div class="flex items-start space-x-3">
                                    <div class="flex-shrink-0 p-2 rounded-full bg-<?= $notif['color'] ?>-100">
                                        <i class="<?= $notif['icon'] ?> text-<?= $notif['color'] ?>-600"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex justify-between items-start mb-1">
                                            <p class="text-sm font-semibold text-gray-900">
                                                <?= h($notif['title']) ?>
                                            </p>
                                            <div class="flex items-center space-x-2">
                                                <?php if (!$this->isNotificationRead($notif['id'])): ?>
                                                    <span class="w-2 h-2 bg-blue-500 rounded-full"></span>
                                                <?php endif; ?>
                                                <span class="text-xs px-2 py-1 rounded-full bg-<?= $notif['color'] ?>-100 text-<?= $notif['color'] ?>-800">
                                                    <?= ucfirst($notif['priority']) ?>
                                                </span>
                                            </div>
                                        </div>
                                        <p class="text-sm text-gray-600 mb-2"><?= h($notif['message']) ?></p>
                                        
                                        <?php if (isset($notif['customer'])): ?>
                                            <div class="text-xs text-gray-500 mb-1">
                                                <i class="fas fa-user mr-1"></i><?= h($notif['customer']) ?>
                                                <?php if (isset($notif['phone'])): ?>
                                                    | <i class="fas fa-phone mr-1"></i>
                                                    <a href="tel:<?= h($notif['phone']) ?>" class="text-blue-600 hover:text-blue-800">
                                                        <?= h($notif['phone']) ?>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if (isset($notif['amount'])): ?>
                                            <div class="text-xs text-gray-500 mb-1">
                                                <i class="fas fa-money-bill mr-1"></i><?= formatCurrency($notif['amount']) ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="flex justify-between items-center">
                                            <p class="text-xs text-gray-400">
                                                <i class="fas fa-clock mr-1"></i><?= formatThaiDate($notif['date']) ?>
                                            </p>
                                            <?php if (isset($notif['action_text'])): ?>
                                                <span class="text-xs text-blue-600 font-medium">
                                                    <?= h($notif['action_text']) ?> →
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <?php if (count($notifications) > 15): ?>
                    <div class="p-3 border-t text-center bg-gray-50">
                        <p class="text-sm text-gray-600">แสดง 15 จาก <?= count($notifications) ?> รายการ</p>
                    </div>
                <?php endif; ?>
                
                <div class="p-4 border-t text-center bg-gray-50">
                    <a href="notifications.php" class="text-sm text-blue-600 hover:text-blue-800 font-medium">
                        ดูการแจ้งเตือนทั้งหมด →
                    </a>
                </div>
            </div>
        </div>
        
        <script>
        // Notification dropdown toggle
        document.getElementById('notificationButton').addEventListener('click', function(e) {
            e.stopPropagation();
            const dropdown = document.getElementById('notificationDropdown');
            dropdown.classList.toggle('hidden');
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            const dropdown = document.getElementById('notificationDropdown');
            const button = document.getElementById('notificationButton');
            
            if (!dropdown.contains(e.target) && !button.contains(e.target)) {
                dropdown.classList.add('hidden');
            }
        });
        
        // Handle notification click
        function handleNotificationClick(notificationId, actionUrl) {
            // Mark as read
            fetch('notifications.php?ajax=mark_read', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'notification_id=' + encodeURIComponent(notificationId)
            }).then(() => {
                // Navigate to action URL
                if (actionUrl) {
                    window.location.href = actionUrl;
                }
            });
        }
        
        // Mark all as read
        function markAllRead() {
            fetch('notifications.php?ajax=mark_all_read', {
                method: 'POST'
            }).then(() => {
                location.reload();
            });
        }
        
        // Auto-refresh notifications every 2 minutes
        setInterval(function() {
            if (!document.getElementById('notificationDropdown').classList.contains('hidden')) {
                return; // Don't refresh if dropdown is open
            }
            
            fetch('notifications.php?ajax=get_unread_count')
                .then(response => response.json())
                .then(data => {
                    const button = document.getElementById('notificationButton');
                    const badge = button.querySelector('.bg-red-500');
                    
                    if (data.unread_count > 0) {
                        if (!badge) {
                            const newBadge = document.createElement('span');
                            newBadge.className = 'absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full w-6 h-6 flex items-center justify-center animate-pulse';
                            newBadge.textContent = data.unread_count > 99 ? '99+' : data.unread_count;
                            button.appendChild(newBadge);
                        } else {
                            badge.textContent = data.unread_count > 99 ? '99+' : data.unread_count;
                        }
                    } else if (badge) {
                        badge.remove();
                    }
                })
                .catch(error => console.error('Error refreshing notifications:', error));
        }, 120000); // 2 minutes
        </script>
        
        <style>
        .notification-item:hover {
            background-color: #f9fafb;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .animate-pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        </style>
        <?php
        return ob_get_clean();
    }
}

// การใช้งาน - เพิ่มใน header ของทุกหน้า
if (defined('INCLUDE_NOTIFICATIONS') && INCLUDE_NOTIFICATIONS === true) {
    requireLogin();
    $user = getCurrentUser();
    $db = getDB();
    $notificationSystem = new NotificationSystem($db, $user);
    echo $notificationSystem->generateNotificationWidget();
}
?>