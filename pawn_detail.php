<?php
require_once 'config/database.php';
requireLogin();

$user = getCurrentUser();
$db = getDB();
$pawn_id = $_GET['id'] ?? 0;

// Get pawn transaction details
$stmt = $db->prepare("
    SELECT pt.*, 
           c.first_name, c.last_name, c.customer_code, c.id_card, c.phone, c.address,
           b.name as branch_name, b.address as branch_address, b.phone as branch_phone,
           u.full_name as user_name,
           CASE 
               WHEN pt.due_date < CURDATE() AND pt.status = 'active' THEN 'overdue'
               ELSE pt.status 
           END as display_status,
           DATEDIFF(pt.due_date, CURDATE()) as days_remaining,
           DATEDIFF(CURDATE(), pt.pawn_date) as days_elapsed
    FROM pawn_transactions pt 
    JOIN customers c ON pt.customer_id = c.id 
    LEFT JOIN branches b ON pt.branch_id = b.id
    LEFT JOIN users u ON pt.user_id = u.id
    WHERE pt.id = ?
");
$stmt->execute([$pawn_id]);
$pawn = $stmt->fetch();

if (!$pawn) {
    setFlash('error', 'ไม่พบรายการจำนำ');
    header('Location: pawns.php');
    exit;
}

// Get pawn items
$stmt = $db->prepare("
    SELECT pi.*, ic.name as category_name
    FROM pawn_items pi 
    LEFT JOIN item_categories ic ON pi.category_id = ic.id
    WHERE pi.transaction_id = ?
    ORDER BY pi.id
");
$stmt->execute([$pawn_id]);
$items = $stmt->fetchAll();

// Get payment history
$stmt = $db->prepare("
    SELECT p.*, u.full_name as user_name 
    FROM payments p 
    LEFT JOIN users u ON p.user_id = u.id 
    WHERE p.transaction_id = ? 
    ORDER BY p.payment_date DESC, p.created_at DESC
");
$stmt->execute([$pawn_id]);
$payments = $stmt->fetchAll();

// Calculate amounts
$principal = $pawn['pawn_amount'];
$months_elapsed = ceil($pawn['days_elapsed'] / 30);
$interest_amount = $principal * ($pawn['interest_rate'] / 100) * $months_elapsed;
$total_amount = $principal + $interest_amount;
$total_paid = array_sum(array_column($payments, 'amount'));
$remaining_amount = $total_amount - $total_paid;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายละเอียดจำนำ - <?= h($pawn['transaction_code']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Sarabun', sans-serif; }
        .gradient-bg { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .status-active { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .status-overdue { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
        .status-paid { background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); }
        .status-forfeited { background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%); }
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Header -->
    <header class="gradient-bg text-white shadow-lg no-print">
        <div class="container mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <button onclick="window.print()" class="bg-white bg-opacity-20 hover:bg-opacity-30 px-4 py-2 rounded-lg transition-all">
                        <i class="fas fa-print mr-2"></i>พิมพ์
                    </button>
                    <span class="text-sm opacity-90"><?= h($user['full_name']) ?></span>
                    <a href="logout.php" class="text-white hover:text-gray-200">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="container mx-auto px-6 py-8">
        <!-- Status Banner -->
        <?php
        $status_class = '';
        $status_text = '';
        $status_icon = '';
        switch($pawn['display_status']) {
            case 'active':
                $status_class = 'status-active';
                $status_text = 'กำลังจำนำ';
                $status_icon = 'fas fa-handshake';
                break;
            case 'overdue':
                $status_class = 'status-overdue';
                $status_text = 'เกินกำหนด ' . abs($pawn['days_remaining']) . ' วัน';
                $status_icon = 'fas fa-exclamation-triangle';
                break;
            case 'paid':
                $status_class = 'status-paid';
                $status_text = 'ไถ่คืนแล้ว';
                $status_icon = 'fas fa-check-circle';
                break;
            case 'forfeited':
                $status_class = 'status-forfeited';
                $status_text = 'ยึดสินค้า';
                $status_icon = 'fas fa-gavel';
                break;
        }
        ?>
        
        <div class="<?= $status_class ?> text-white rounded-xl shadow-lg p-6 mb-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <i class="<?= $status_icon ?> text-3xl"></i>
                    <div>
                        <h2 class="text-2xl font-bold"><?= h($pawn['transaction_code']) ?></h2>
                        <p class="opacity-90"><?= $status_text ?></p>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-lg font-semibold">จำนวนเงิน: <?= formatCurrency($pawn['pawn_amount']) ?></p>
                    <?php if ($pawn['display_status'] === 'active' || $pawn['display_status'] === 'overdue'): ?>
                        <p class="opacity-90">คงเหลือ: <?= formatCurrency($remaining_amount) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex space-x-4 mb-6 no-print">
            <?php if ($pawn['display_status'] === 'active' || $pawn['display_status'] === 'overdue'): ?>
                <a href="payment.php?pawn_id=<?= $pawn['id'] ?>" class="bg-green-500 text-white px-6 py-3 rounded-lg hover:bg-green-600 transition-colors">
                    <i class="fas fa-money-bill mr-2"></i>ชำระเงิน
                </a>
            <?php endif; ?>
            
            <a href="print_pawn_receipt.php?id=<?= $pawn['id'] ?>" target="_blank" class="bg-blue-500 text-white px-6 py-3 rounded-lg hover:bg-blue-600 transition-colors">
                <i class="fas fa-print mr-2"></i>พิมพ์ใบรับจำนำ
            </a>
            
            <a href="pawns.php?search=<?= urlencode($pawn['transaction_code']) ?>" class="bg-gray-500 text-white px-6 py-3 rounded-lg hover:bg-gray-600 transition-colors">
                <i class="fas fa-list mr-2"></i>กลับรายการ
            </a>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Transaction Information -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-xl font-semibold mb-4 text-gray-800">ข้อมูลรายการจำนำ</h3>
                
                <div class="space-y-3">
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-gray-600">รหัสรายการ:</span>
                        <span class="font-semibold"><?= h($pawn['transaction_code']) ?></span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-gray-600">วันที่จำนำ:</span>
                        <span class="font-semibold"><?= formatThaiDate($pawn['pawn_date']) ?></span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-gray-600">วันครบกำหนด:</span>
                        <span class="font-semibold <?= $pawn['display_status'] === 'overdue' ? 'text-red-600' : '' ?>">
                            <?= formatThaiDate($pawn['due_date']) ?>
                        </span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-gray-600">ระยะเวลา:</span>
                        <span class="font-semibold"><?= h($pawn['period_months']) ?> เดือน</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-gray-600">อัตราดอกเบี้ย:</span>
                        <span class="font-semibold"><?= h($pawn['interest_rate']) ?>% ต่อเดือน</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-gray-600">สาขา:</span>
                        <span class="font-semibold"><?= h($pawn['branch_name']) ?></span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-gray-600">พนักงาน:</span>
                        <span class="font-semibold"><?= h($pawn['user_name']) ?></span>
                    </div>
                    <?php if (!empty($pawn['notes'])): ?>
                    <div class="py-2">
                        <span class="text-gray-600">หมายเหตุ:</span>
                        <p class="mt-2 text-gray-800 bg-gray-50 p-3 rounded"><?= h($pawn['notes']) ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Customer Information -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-xl font-semibold mb-4 text-gray-800">ข้อมูลลูกค้า</h3>
                
                <div class="space-y-3">
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-gray-600">รหัสลูกค้า:</span>
                        <span class="font-semibold"><?= h($pawn['customer_code']) ?></span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-gray-600">ชื่อ-นามสกุล:</span>
                        <span class="font-semibold"><?= h($pawn['first_name'] . ' ' . $pawn['last_name']) ?></span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-gray-600">เลขบัตรประชาชน:</span>
                        <span class="font-semibold"><?= h($pawn['id_card']) ?></span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-gray-600">เบอร์โทร:</span>
                        <span class="font-semibold">
                            <a href="tel:<?= h($pawn['phone']) ?>" class="text-blue-600 hover:text-blue-800">
                                <?= h($pawn['phone']) ?>
                            </a>
                        </span>
                    </div>
                    <?php if (!empty($pawn['address'])): ?>
                    <div class="py-2">
                        <span class="text-gray-600">ที่อยู่:</span>
                        <p class="mt-2 text-gray-800 bg-gray-50 p-3 rounded"><?= h($pawn['address']) ?></p>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="mt-6">
                    <a href="customer_detail.php?id=<?= $pawn['customer_id'] ?>" 
                       class="w-full bg-blue-500 text-white py-2 px-4 rounded-lg hover:bg-blue-600 transition-colors text-center block">
                        <i class="fas fa-user mr-2"></i>ดูข้อมูลลูกค้า
                    </a>
                </div>
            </div>
        </div>

        <!-- Financial Summary -->
        <div class="bg-white rounded-xl shadow-lg p-6 mt-6">
            <h3 class="text-xl font-semibold mb-4 text-gray-800">สรุปทางการเงิน</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                <div class="text-center p-4 bg-blue-50 rounded-lg">
                    <i class="fas fa-coins text-blue-500 text-2xl mb-2"></i>
                    <p class="text-sm text-gray-600">เงินต้น</p>
                    <p class="text-xl font-bold text-blue-600"><?= formatCurrency($principal) ?></p>
                </div>
                
                <div class="text-center p-4 bg-orange-50 rounded-lg">
                    <i class="fas fa-percentage text-orange-500 text-2xl mb-2"></i>
                    <p class="text-sm text-gray-600">ดอกเบี้ย (<?= $months_elapsed ?> เดือน)</p>
                    <p class="text-xl font-bold text-orange-600"><?= formatCurrency($interest_amount) ?></p>
                </div>
                
                <div class="text-center p-4 bg-red-50 rounded-lg">
                    <i class="fas fa-calculator text-red-500 text-2xl mb-2"></i>
                    <p class="text-sm text-gray-600">ยอดรวม</p>
                    <p class="text-xl font-bold text-red-600"><?= formatCurrency($total_amount) ?></p>
                </div>
                
                <div class="text-center p-4 bg-green-50 rounded-lg">
                    <i class="fas fa-hand-holding-usd text-green-500 text-2xl mb-2"></i>
                    <p class="text-sm text-gray-600">ชำระแล้ว</p>
                    <p class="text-xl font-bold text-green-600"><?= formatCurrency($total_paid) ?></p>
                </div>
            </div>
            
            <?php if ($remaining_amount > 0): ?>
            <div class="mt-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-yellow-800 font-semibold">ยอดคงเหลือ</p>
                        <p class="text-2xl font-bold text-yellow-600"><?= formatCurrency($remaining_amount) ?></p>
                    </div>
                    <?php if ($pawn['display_status'] === 'active' || $pawn['display_status'] === 'overdue'): ?>
                    <a href="payment.php?pawn_id=<?= $pawn['id'] ?>" 
                       class="bg-yellow-500 text-white px-6 py-2 rounded-lg hover:bg-yellow-600 transition-colors">
                        ชำระเงิน
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Pawn Items -->
        <div class="bg-white rounded-xl shadow-lg mt-6">
            <div class="p-6 border-b">
                <h3 class="text-xl font-semibold text-gray-800">รายการสินค้าที่จำนำ</h3>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="gradient-bg text-white">
                        <tr>
                            <th class="px-6 py-4 text-left">ลำดับ</th>
                            <th class="px-6 py-4 text-left">ชื่อสินค้า</th>
                            <th class="px-6 py-4 text-left">หมวดหมู่</th>
                            <th class="px-6 py-4 text-right">น้ำหนัก (กรัม)</th>
                            <th class="px-6 py-4 text-right">มูลค่าประเมิน</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php $total_value = 0; ?>
                        <?php foreach ($items as $index => $item): ?>
                            <?php $total_value += $item['estimated_value']; ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 text-center"><?= $index + 1 ?></td>
                                <td class="px-6 py-4">
                                    <div>
                                        <p class="font-semibold"><?= h($item['item_name']) ?></p>
                                        <?php if (!empty($item['description'])): ?>
                                            <p class="text-sm text-gray-600 mt-1"><?= h($item['description']) ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($item['condition_notes'])): ?>
                                            <p class="text-xs text-gray-500 mt-1">สภาพ: <?= h($item['condition_notes']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4"><?= h($item['category_name']) ?></td>
                                <td class="px-6 py-4 text-right">
                                    <?= $item['weight'] ? number_format($item['weight'], 3) : '-' ?>
                                </td>
                                <td class="px-6 py-4 text-right font-semibold text-green-600">
                                    <?= formatCurrency($item['estimated_value']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="bg-gray-50 font-bold">
                            <td colspan="4" class="px-6 py-4 text-right">รวมมูลค่าประเมิน:</td>
                            <td class="px-6 py-4 text-right text-green-600">
                                <?= formatCurrency($total_value) ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Payment History -->
        <div class="bg-white rounded-xl shadow-lg mt-6">
            <div class="p-6 border-b">
                <h3 class="text-xl font-semibold text-gray-800">ประวัติการชำระเงิน</h3>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="gradient-bg text-white">
                        <tr>
                            <th class="px-6 py-4 text-left">วันที่</th>
                            <th class="px-6 py-4 text-left">ประเภท</th>
                            <th class="px-6 py-4 text-right">จำนวนเงิน</th>
                            <th class="px-6 py-4 text-left">พนักงาน</th>
                            <th class="px-6 py-4 text-left">หมายเหตุ</th>
                            <th class="px-6 py-4 text-center no-print">ใบเสร็จ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php if (empty($payments)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                    <i class="fas fa-credit-card text-4xl mb-2 block text-gray-400"></i>
                                    ยังไม่มีการชำระเงิน
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($payments as $payment): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4"><?= formatThaiDate($payment['payment_date']) ?></td>
                                    <td class="px-6 py-4">
                                        <span class="px-2 py-1 rounded-full text-xs
                                            <?php
                                            switch($payment['payment_type']) {
                                                case 'interest': echo 'bg-orange-100 text-orange-800'; break;
                                                case 'partial_payment': echo 'bg-blue-100 text-blue-800'; break;
                                                case 'redemption': echo 'bg-green-100 text-green-800'; break;
                                                default: echo 'bg-gray-100 text-gray-800';
                                            }
                                            ?>">
                                            <?php
                                            switch($payment['payment_type']) {
                                                case 'interest': echo 'ดอกเบี้ย'; break;
                                                case 'partial_payment': echo 'บางส่วน'; break;
                                                case 'redemption': echo 'ไถ่คืน'; break;
                                                default: echo h($payment['payment_type']);
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-right font-semibold text-green-600">
                                        <?= formatCurrency($payment['amount']) ?>
                                    </td>
                                    <td class="px-6 py-4"><?= h($payment['user_name']) ?></td>
                                    <td class="px-6 py-4"><?= h($payment['notes']) ?></td>
                                    <td class="px-6 py-4 text-center no-print">
                                        <a href="print_payment_receipt.php?id=<?= $payment['id'] ?>" target="_blank"
                                           class="text-blue-600 hover:text-blue-800" title="พิมพ์ใบเสร็จ">
                                            <i class="fas fa-print"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>-4">
                    <a href="pawns.php" class="text-white hover:text-gray-200">
                        <i class="fas fa-arrow-left text-xl"></i>
                    </a>
                    <i class="fas fa-handshake text-2xl"></i>
                    <h1 class="text-2xl font-bold">รายละเอียดจำนำ</h1>
                </div>
                <div class="flex items-center space-x