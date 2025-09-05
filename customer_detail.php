<?php
require_once 'config/database.php';
requireLogin();

$user = getCurrentUser();
$db = getDB();
$customer_id = $_GET['id'] ?? 0;

// Get customer details
$stmt = $db->prepare("
    SELECT c.*, b.name as branch_name 
    FROM customers c 
    LEFT JOIN branches b ON c.branch_id = b.id 
    WHERE c.id = ?
");
$stmt->execute([$customer_id]);
$customer = $stmt->fetch();

if (!$customer) {
    setFlash('error', 'ไม่พบข้อมูลลูกค้า');
    header('Location: customers.php');
    exit;
}

// Get customer pawn transactions
$stmt = $db->prepare("
    SELECT pt.*, 
           CASE 
               WHEN pt.due_date < CURDATE() AND pt.status = 'active' THEN 'overdue'
               ELSE pt.status 
           END as display_status,
           DATEDIFF(pt.due_date, CURDATE()) as days_remaining
    FROM pawn_transactions pt 
    WHERE pt.customer_id = ? 
    ORDER BY pt.created_at DESC
");
$stmt->execute([$customer_id]);
$transactions = $stmt->fetchAll();

// Calculate statistics
$total_transactions = count($transactions);
$active_transactions = count(array_filter($transactions, fn($t) => $t['status'] === 'active'));
$total_amount = array_sum(array_column($transactions, 'pawn_amount'));
$avg_amount = $total_transactions > 0 ? $total_amount / $total_transactions : 0;

// Get payment statistics
$stmt = $db->prepare("
    SELECT SUM(p.amount) as total_paid, COUNT(p.id) as payment_count
    FROM payments p 
    JOIN pawn_transactions pt ON p.transaction_id = pt.id 
    WHERE pt.customer_id = ?
");
$stmt->execute([$customer_id]);
$payment_stats = $stmt->fetch();

$overdue_count = count(array_filter($transactions, fn($t) => $t['display_status'] === 'overdue'));
$paid_count = count(array_filter($transactions, fn($t) => $t['status'] === 'paid'));
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ข้อมูลลูกค้า - <?= h($customer['first_name'] . ' ' . $customer['last_name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Sarabun', sans-serif; }
        .gradient-bg { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .status-active { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .status-overdue { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
        .status-paid { background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Header -->
    <header class="gradient-bg text-white shadow-lg">
        <div class="container mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <a href="customers.php" class="text-white hover:text-gray-200">
                        <i class="fas fa-arrow-left text-xl"></i>
                    </a>
                    <i class="fas fa-user text-2xl"></i>
                    <h1 class="text-2xl font-bold">ข้อมูลลูกค้า</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm opacity-90"><?= h($user['full_name']) ?></span>
                    <a href="logout.php" class="text-white hover:text-gray-200">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="container mx-auto px-6 py-8">
        <!-- Customer Header -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-6">
                    <div class="w-20 h-20 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center">
                        <i class="fas fa-user text-white text-3xl"></i>
                    </div>
                    <div>
                        <h2 class="text-3xl font-bold text-gray-800"><?= h($customer['first_name'] . ' ' . $customer['last_name']) ?></h2>
                        <p class="text-gray-600">รหัสลูกค้า: <?= h($customer['customer_code']) ?></p>
                        <div class="flex items-center mt-2">
                            <span class="px-3 py-1 rounded-full text-sm <?= $customer['status'] === 'active' ? 'bg-green-100 text-green-800' : 
                                ($customer['status'] === 'inactive' ? 'bg-gray-100 text-gray-800' : 'bg-red-100 text-red-800') ?>">
                                <?= $customer['status'] === 'active' ? 'ลูกค้าปกติ' : 
                                    ($customer['status'] === 'inactive' ? 'ไม่ใช้งาน' : 'บัญชีดำ') ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="text-right">
                    <p class="text-sm text-gray-600">สมาชิกตั้งแต่</p>
                    <p class="text-lg font-semibold"><?= formatThaiDate($customer['created_at']) ?></p>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex space-x-4 mb-6">
            <a href="pawns.php?customer_id=<?= $customer['id'] ?>" class="bg-blue-500 text-white px-6 py-3 rounded-lg hover:bg-blue-600 transition-colors">
                <i class="fas fa-handshake mr-2"></i>สร้างรายการจำนำ
            </a>
            <a href="customers.php?search=<?= urlencode($customer['customer_code']) ?>" class="bg-gray-500 text-white px-6 py-3 rounded-lg hover:bg-gray-600 transition-colors">
                <i class="fas fa-list mr-2"></i>กลับรายการลูกค้า
            </a>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Customer Information -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
                    <h3 class="text-xl font-semibold mb-4 text-gray-800">ข้อมูลส่วนตัว</h3>
                    
                    <div class="space-y-4">
                        <div class="flex items-center space-x-3 p-3 bg-gray-50 rounded-lg">
                            <i class="fas fa-id-card text-blue-500"></i>
                            <div>
                                <p class="text-sm text-gray-600">เลขบัตรประชาชน</p>
                                <p class="font-semibold"><?= h($customer['id_card']) ?></p>
                            </div>
                        </div>
                        
                        <div class="flex items-center space-x-3 p-3 bg-gray-50 rounded-lg">
                            <i class="fas fa-phone text-green-500"></i>
                            <div>
                                <p class="text-sm text-gray-600">เบอร์โทร</p>
                                <p class="font-semibold">
                                    <a href="tel:<?= h($customer['phone']) ?>" class="text-blue-600 hover:text-blue-800">
                                        <?= h($customer['phone']) ?>
                                    </a>
                                </p>
                            </div>
                        </div>
                        
                        <div class="flex items-center space-x-3 p-3 bg-gray-50 rounded-lg">
                            <i class="fas fa-building text-purple-500"></i>
                            <div>
                                <p class="text-sm text-gray-600">สาขา</p>
                                <p class="font-semibold"><?= h($customer['branch_name']) ?></p>
                            </div>
                        </div>
                        
                        <?php if (!empty($customer['address'])): ?>
                        <div class="p-3 bg-gray-50 rounded-lg">
                            <div class="flex items-start space-x-3">
                                <i class="fas fa-map-marker-alt text-red-500 mt-1"></i>
                                <div>
                                    <p class="text-sm text-gray-600">ที่อยู่</p>
                                    <p class="font-semibold mt-1"><?= h($customer['address']) ?></p>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Statistics -->
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-xl font-semibold mb-4 text-gray-800">สถิติ</h3>
                    
                    <div class="space-y-4">
                        <div class="flex justify-between items-center p-3 bg-blue-50 rounded-lg">
                            <div class="flex items-center space-x-3">
                                <i class="fas fa-handshake text-blue-500"></i>
                                <span class="text-blue-800">รายการทั้งหมด</span>
                            </div>
                            <span class="text-xl font-bold text-blue-600"><?= number_format($total_transactions) ?></span>
                        </div>
                        
                        <div class="flex justify-between items-center p-3 bg-green-50 rounded-lg">
                            <div class="flex items-center space-x-3">
                                <i class="fas fa-check-circle text-green-500"></i>
                                <span class="text-green-800">ไถ่คืนแล้ว</span>
                            </div>
                            <span class="text-xl font-bold text-green-600"><?= number_format($paid_count) ?></span>
                        </div>
                        
                        <div class="flex justify-between items-center p-3 bg-yellow-50 rounded-lg">
                            <div class="flex items-center space-x-3">
                                <i class="fas fa-clock text-yellow-500"></i>
                                <span class="text-yellow-800">กำลังจำนำ</span>
                            </div>
                            <span class="text-xl font-bold text-yellow-600"><?= number_format($active_transactions) ?></span>
                        </div>
                        
                        <?php if ($overdue_count > 0): ?>
                        <div class="flex justify-between items-center p-3 bg-red-50 rounded-lg">
                            <div class="flex items-center space-x-3">
                                <i class="fas fa-exclamation-triangle text-red-500"></i>
                                <span class="text-red-800">เกินกำหนด</span>
                            </div>
                            <span class="text-xl font-bold text-red-600"><?= number_format($overdue_count) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="mt-6 pt-4 border-t border-gray-200">
                        <div class="text-center">
                            <p class="text-sm text-gray-600">ยอดรวมที่เคยจำนำ</p>
                            <p class="text-2xl font-bold text-purple-600"><?= formatCurrency($total_amount) ?></p>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4 mt-4">
                            <div class="text-center">
                                <p class="text-sm text-gray-600">เฉลี่ยต่อครั้ง</p>
                                <p class="text-lg font-semibold"><?= formatCurrency($avg_amount) ?></p>
                            </div>
                            <div class="text-center">
                                <p class="text-sm text-gray-600">รายได้รับ</p>
                                <p class="text-lg font-semibold text-green-600"><?= formatCurrency($payment_stats['total_paid']) ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Transaction History -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-xl shadow-lg">
                    <div class="p-6 border-b">
                        <h3 class="text-xl font-semibold text-gray-800">ประวัติการจำนำ</h3>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="gradient-bg text-white">
                                <tr>
                                    <th class="px-6 py-4 text-left">รหัส</th>
                                    <th class="px-6 py-4 text-left">วันที่จำนำ</th>
                                    <th class="px-6 py-4 text-right">จำนวนเงิน</th>
                                    <th class="px-6 py-4 text-left">วันครบกำหนด</th>
                                    <th class="px-6 py-4 text-center">สถานะ</th>
                                    <th class="px-6 py-4 text-center">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php if (empty($transactions)): ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                            <i class="fas fa-handshake text-4xl mb-2 block text-gray-400"></i>
                                            ลูกค้ารายนี้ยังไม่เคยใช้บริการจำนำ
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($transactions as $transaction): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 font-medium">
                                                <a href="pawn_detail.php?id=<?= $transaction['id'] ?>" class="text-blue-600 hover:text-blue-800">
                                                    <?= h($transaction['transaction_code']) ?>
                                                </a>
                                            </td>
                                            <td class="px-6 py-4"><?= formatThaiDate($transaction['pawn_date']) ?></td>
                                            <td class="px-6 py-4 text-right font-semibold text-green-600">
                                                <?= formatCurrency($transaction['pawn_amount']) ?>
                                            </td>
                                            <td class="px-6 py-4 <?= $transaction['display_status'] === 'overdue' ? 'text-red-600 font-semibold' : '' ?>">
                                                <?= formatThaiDate($transaction['due_date']) ?>
                                                <?php if ($transaction['display_status'] === 'active' && $transaction['days_remaining'] <= 7): ?>
                                                    <span class="block text-xs text-yellow-600">เหลือ <?= $transaction['days_remaining'] ?> วัน</span>
                                                <?php elseif ($transaction['display_status'] === 'overdue'): ?>
                                                    <span class="block text-xs text-red-600">เกิน <?= abs($transaction['days_remaining']) ?> วัน</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 text-center">
                                                <?php
                                                $status_class = '';
                                                $status_text = '';
                                                switch($transaction['display_status']) {
                                                    case 'active':
                                                        $status_class = 'bg-blue-100 text-blue-800';
                                                        $status_text = 'กำลังจำนำ';
                                                        break;
                                                    case 'overdue':
                                                        $status_class = 'bg-red-100 text-red-800';
                                                        $status_text = 'เกินกำหนด';
                                                        break;
                                                    case 'paid':
                                                        $status_class = 'bg-green-100 text-green-800';
                                                        $status_text = 'ไถ่คืนแล้ว';
                                                        break;
                                                    case 'forfeited':
                                                        $status_class = 'bg-orange-100 text-orange-800';
                                                        $status_text = 'ยึดสินค้า';
                                                        break;
                                                    default:
                                                        $status_class = 'bg-gray-100 text-gray-800';
                                                        $status_text = h($transaction['display_status']);
                                                }
                                                ?>
                                                <span class="px-2 py-1 rounded-full text-xs font-medium <?= $status_class ?>">
                                                    <?= $status_text ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 text-center">
                                                <div class="flex justify-center space-x-2">
                                                    <a href="pawn_detail.php?id=<?= $transaction['id'] ?>" 
                                                       class="text-blue-600 hover:text-blue-800" title="ดูรายละเอียด">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if ($transaction['display_status'] === 'active' || $transaction['display_status'] === 'overdue'): ?>
                                                        <a href="payment.php?pawn_id=<?= $transaction['id'] ?>" 
                                                           class="text-green-600 hover:text-green-800" title="ชำระเงิน">
                                                            <i class="fas fa-money-bill"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <a href="print_pawn_receipt.php?id=<?= $transaction['id'] ?>" target="_blank"
                                                       class="text-purple-600 hover:text-purple-800" title="พิมพ์ใบรับ">
                                                        <i class="fas fa-print"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if (!empty($transactions)): ?>
                    <div class="p-6 bg-gray-50 border-t">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-center">
                            <div>
                                <p class="text-sm text-gray-600">รายการทั้งหมด</p>
                                <p class="text-xl font-bold text-gray-800"><?= number_format($total_transactions) ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">ยอดรวม</p>
                                <p class="text-xl font-bold text-green-600"><?= formatCurrency($total_amount) ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">การชำระ</p>
                                <p class="text-xl font-bold text-blue-600"><?= number_format($payment_stats['payment_count']) ?> ครั้ง</p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>