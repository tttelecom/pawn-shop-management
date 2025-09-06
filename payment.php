<?php
require_once 'config/database.php';
requireLogin();

$user = getCurrentUser();
$db = getDB();
$pawn_id = $_GET['pawn_id'] ?? 0;

// Get pawn transaction details
$stmt = $db->prepare("
    SELECT pt.*, c.first_name, c.last_name, c.customer_code,
           DATEDIFF(CURDATE(), pt.pawn_date) as days_elapsed,
           DATEDIFF(pt.due_date, CURDATE()) as days_remaining
    FROM pawn_transactions pt 
    JOIN customers c ON pt.customer_id = c.id 
    WHERE pt.id = ? AND pt.status IN ('active', 'overdue')
");
$stmt->execute([$pawn_id]);
$pawn = $stmt->fetch();

if (!$pawn) {
    setFlash('error', 'ไม่พบรายการจำนำหรือรายการนี้ไม่สามารถชำระได้');
    header('Location: pawns.php');
    exit;
}

// Calculate interest
$principal = $pawn['pawn_amount'];
$rate = $pawn['interest_rate'] / 100;
$months_elapsed = ceil($pawn['days_elapsed'] / 30);
$interest_amount = $principal * $rate * $months_elapsed;
$total_amount = $principal + $interest_amount;

// Get payment history
$stmt = $db->prepare("
    SELECT p.*, u.full_name as user_name 
    FROM payments p 
    LEFT JOIN users u ON p.user_id = u.id 
    WHERE p.transaction_id = ? 
    ORDER BY p.payment_date DESC, p.created_at DESC
");
$stmt->execute([$pawn_id]);
$payment_history = $stmt->fetchAll();

// Calculate total paid
$total_paid = array_sum(array_column($payment_history, 'amount'));
$remaining_amount = $total_amount - $total_paid;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (validateCSRFToken($_POST['csrf_token'])) {
        $payment_type = $_POST['payment_type'];
        $amount = floatval($_POST['amount']);
        $payment_date = $_POST['payment_date'];
        $notes = $_POST['notes'];
        
        if ($amount <= 0) {
            setFlash('error', 'จำนวนเงินต้องมากกว่า 0');
        } elseif ($payment_type === 'redemption' && $amount < $remaining_amount) {
            setFlash('error', 'จำนวนเงินไม่เพียงพอสำหรับการไถ่คืน');
        } else {
            try {
                $db->beginTransaction();
                
                // Insert payment record
                $stmt = $db->prepare("
                    INSERT INTO payments (transaction_id, payment_type, amount, payment_date, user_id, notes) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$pawn_id, $payment_type, $amount, $payment_date, $user['id'], $notes]);
                
                // Update transaction status if full payment
                if ($payment_type === 'redemption') {
                    $stmt = $db->prepare("UPDATE pawn_transactions SET status = 'paid' WHERE id = ?");
                    $stmt->execute([$pawn_id]);
                }
                
                $db->commit();
                
                logActivity($user['id'], 'PAYMENT', "Payment recorded for pawn {$pawn['transaction_code']}: " . formatCurrency($amount));
                setFlash('success', 'บันทึกการชำระเงินเรียบร้อยแล้ว');
                
                if ($payment_type === 'redemption') {
                    header('Location: pawns.php');
                } else {
                    header('Location: payment.php?pawn_id=' . $pawn_id);
                }
                exit;
                
            } catch (PDOException $e) {
                $db->rollBack();
                setFlash('error', 'เกิดข้อผิดพลาดในการบันทึกการชำระเงิน');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ชำระเงิน - <?= h(APP_NAME) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Sarabun', sans-serif; }
        .gradient-bg { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .card-hover:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Flash Messages -->
    <?php foreach (getFlash() as $flash): ?>
        <div class="fixed top-4 right-4 z-50 alert alert-<?= h($flash['type']) ?> p-4 rounded-lg shadow-lg
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
                    <a href="pawns.php" class="text-white hover:text-gray-200">
                        <i class="fas fa-arrow-left text-xl"></i>
                    </a>
                    <i class="fas fa-credit-card text-2xl"></i>
                    <h1 class="text-2xl font-bold">ชำระเงิน</h1>
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
        <!-- Pawn Transaction Info -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <h2 class="text-2xl font-bold text-gray-800 mb-4">ข้อมูลรายการจำนำ</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-3">
                    <div class="flex justify-between">
                        <span class="text-gray-600">รหัสรายการ:</span>
                        <span class="font-semibold"><?= h($pawn['transaction_code']) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">ลูกค้า:</span>
                        <span class="font-semibold"><?= h($pawn['first_name'] . ' ' . $pawn['last_name']) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">วันที่จำนำ:</span>
                        <span class="font-semibold"><?= formatThaiDate($pawn['pawn_date']) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">วันครบกำหนด:</span>
                        <span class="font-semibold <?= $pawn['days_remaining'] < 0 ? 'text-red-600' : '' ?>">
                            <?= formatThaiDate($pawn['due_date']) ?>
                        </span>
                    </div>
                </div>
                <div class="space-y-3">
                    <div class="flex justify-between">
                        <span class="text-gray-600">จำนวนเงินต้น:</span>
                        <span class="font-semibold text-blue-600"><?= formatCurrency($principal) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">ดอกเบี้ย (<?= $months_elapsed ?> เดือน):</span>
                        <span class="font-semibold text-orange-600"><?= formatCurrency($interest_amount) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">ยอดรวม:</span>
                        <span class="font-semibold text-red-600"><?= formatCurrency($total_amount) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">ชำระแล้ว:</span>
                        <span class="font-semibold text-green-600"><?= formatCurrency($total_paid) ?></span>
                    </div>
                    <hr>
                    <div class="flex justify-between text-lg">
                        <span class="text-gray-800 font-semibold">คงเหลือ:</span>
                        <span class="font-bold text-red-600"><?= formatCurrency($remaining_amount) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Payment Form -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-xl font-semibold mb-4 text-gray-800">บันทึกการชำระเงิน</h3>
                
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">ประเภทการชำระ</label>
                        <select name="payment_type" id="payment_type" required onchange="updatePaymentAmount()" 
                                class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <option value="">เลือกประเภท</option>
                            <option value="interest">ชำระดอกเบี้ย</option>
                            <option value="partial_payment">ชำระบางส่วน</option>
                            <option value="redemption">ไถ่คืน (ชำระเต็มจำนวน)</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">จำนวนเงิน (บาท)</label>
                        <input type="number" name="amount" id="amount" step="0.01" min="0" required 
                               class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <div class="mt-2 space-y-1">
                            <button type="button" onclick="setAmount(<?= $interest_amount ?>)" 
                                    class="text-sm bg-orange-100 text-orange-800 px-2 py-1 rounded hover:bg-orange-200">
                                ดอกเบี้ย: <?= formatCurrency($interest_amount) ?>
                            </button>
                            <button type="button" onclick="setAmount(<?= $remaining_amount ?>)" 
                                    class="text-sm bg-red-100 text-red-800 px-2 py-1 rounded hover:bg-red-200 ml-2">
                                ยอดคงเหลือ: <?= formatCurrency($remaining_amount) ?>
                            </button>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">วันที่ชำระ</label>
                        <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required 
                               class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">หมายเหตุ</label>
                        <textarea name="notes" rows="3" 
                                  class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent"></textarea>
                    </div>
                    
                    <div class="flex space-x-4">
                        <a href="pawns.php" class="flex-1 bg-gray-300 text-gray-700 py-3 rounded-lg hover:bg-gray-400 text-center">
                            ยกเลิก
                        </a>
                        <button type="submit" class="flex-1 gradient-bg text-white py-3 rounded-lg hover:opacity-90">
                            บันทึกการชำระ
                        </button>
                    </div>
                </form>
            </div>

            <!-- Payment History -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-xl font-semibold mb-4 text-gray-800">ประวัติการชำระเงิน</h3>
                
                <div class="space-y-4 max-h-96 overflow-y-auto">
                    <?php if (empty($payment_history)): ?>
                        <p class="text-gray-500 text-center py-4">ยังไม่มีการชำระเงิน</p>
                    <?php else: ?>
                        <?php foreach ($payment_history as $payment): ?>
                            <div class="border border-gray-200 rounded-lg p-4">
                                <div class="flex justify-between items-start mb-2">
                                    <div>
                                        <span class="inline-block px-2 py-1 text-xs rounded-full
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
                                                case 'interest': echo 'ชำระดอกเบี้ย'; break;
                                                case 'partial_payment': echo 'ชำระบางส่วน'; break;
                                                case 'redemption': echo 'ไถ่คืน'; break;
                                                default: echo h($payment['payment_type']);
                                            }
                                            ?>
                                        </span>
                                    </div>
                                    <span class="text-lg font-bold text-green-600"><?= formatCurrency($payment['amount']) ?></span>
                                </div>
                                <div class="text-sm text-gray-600">
                                    <div>วันที่: <?= formatThaiDate($payment['payment_date']) ?></div>
                                    <div>ผู้บันทึก: <?= h($payment['user_name']) ?></div>
                                    <?php if (!empty($payment['notes'])): ?>
                                        <div>หมายเหตุ: <?= h($payment['notes']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        function updatePaymentAmount() {
            const paymentType = document.getElementById('payment_type').value;
            const amountField = document.getElementById('amount');
            
            switch(paymentType) {
                case 'interest':
                    amountField.value = <?= $interest_amount ?>;
                    break;
                case 'redemption':
                    amountField.value = <?= $remaining_amount ?>;
                    break;
                case 'partial_payment':
                    amountField.value = '';
                    break;
            }
        }
        
        function setAmount(amount) {
            document.getElementById('amount').value = amount;
        }

        // Auto-hide flash messages
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>
</html>