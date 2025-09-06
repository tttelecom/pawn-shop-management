<?php
require_once 'config/database.php';
requireLogin();

$db = getDB();
$payment_id = $_GET['id'] ?? 0;

// Get payment details with related information
$stmt = $db->prepare("
    SELECT p.*, 
           pt.transaction_code, pt.pawn_amount, pt.interest_rate, pt.pawn_date, pt.due_date,
           c.first_name, c.last_name, c.customer_code, c.id_card, c.phone, c.address,
           b.name as branch_name, b.address as branch_address, b.phone as branch_phone,
           u.full_name as user_name,
           DATEDIFF(p.payment_date, pt.pawn_date) as days_from_pawn
    FROM payments p
    JOIN pawn_transactions pt ON p.transaction_id = pt.id
    JOIN customers c ON pt.customer_id = c.id
    LEFT JOIN branches b ON pt.branch_id = b.id
    LEFT JOIN users u ON p.user_id = u.id
    WHERE p.id = ?
");
$stmt->execute([$payment_id]);
$payment = $stmt->fetch();

if (!$payment) {
    setFlash('error', 'ไม่พบรายการชำระเงิน');
    header('Location: pawns.php');
    exit;
}

// Get company/shop information (assuming there's a settings table)
$stmt = $db->prepare("SELECT * FROM settings WHERE setting_key IN ('shop_name', 'shop_address', 'shop_phone', 'shop_tax_id') ORDER BY setting_key");
$stmt->execute();
$settings_raw = $stmt->fetchAll();
$settings = [];
foreach ($settings_raw as $setting) {
    $settings[$setting['setting_key']] = $setting['setting_value'];
}

// Default settings if not found in database
$shop_name = $settings['shop_name'] ?? 'ร้านจำนำ ABC';
$shop_address = $settings['shop_address'] ?? '123 ถนนสุขุมวิท กรุงเทพฯ 10110';
$shop_phone = $settings['shop_phone'] ?? '02-123-4567';
$shop_tax_id = $settings['shop_tax_id'] ?? '0-1234-56789-01-2';

// Calculate running totals up to this payment
$stmt = $db->prepare("
    SELECT SUM(amount) as total_paid_before
    FROM payments 
    WHERE transaction_id = ? AND payment_date < ? 
    ORDER BY payment_date, created_at
");
$stmt->execute([$payment['transaction_id'], $payment['payment_date']]);
$result = $stmt->fetch();
$total_paid_before = $result['total_paid_before'] ?? 0;

// Calculate interest and totals
$months_elapsed = ceil($payment['days_from_pawn'] / 30);
$interest_amount = $payment['pawn_amount'] * ($payment['interest_rate'] / 100) * $months_elapsed;
$total_amount = $payment['pawn_amount'] + $interest_amount;
$total_paid_after = $total_paid_before + $payment['amount'];
$remaining_balance = $total_amount - $total_paid_after;

// Generate receipt number if not exists
$receipt_number = $payment['receipt_number'] ?? 'R' . date('Ymd') . str_pad($payment['id'], 4, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ใบเสร็จรับเงิน - <?= h($receipt_number) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap');
        body { 
            font-family: 'Sarabun', sans-serif; 
            font-size: 14px;
        }
        
        @media print {
            body { 
                background: white !important; 
                -webkit-print-color-adjust: exact;
                color-adjust: exact;
            }
            .no-print { display: none !important; }
            .receipt { 
                max-width: 80mm; 
                margin: 0 auto;
                box-shadow: none !important;
            }
            @page {
                size: 80mm auto;
                margin: 5mm;
            }
        }
        
        .receipt {
            max-width: 300px;
            margin: 20px auto;
            background: white;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        .dashed-line {
            border-top: 1px dashed #666;
            margin: 10px 0;
        }
        
        .receipt-header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        
        .receipt-footer {
            text-align: center;
            border-top: 1px dashed #666;
            padding-top: 10px;
            margin-top: 15px;
        }
        
        .receipt-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .receipt-table td {
            padding: 2px 0;
            vertical-align: top;
        }
        
        .text-right {
            text-align: right;
        }
        
        .font-bold {
            font-weight: bold;
        }
        
        .text-lg {
            font-size: 16px;
        }
        
        .text-sm {
            font-size: 12px;
        }
        
        .text-xs {
            font-size: 11px;
        }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Print Controls -->
    <div class="no-print text-center py-4">
        <button onclick="window.print()" class="bg-blue-500 text-white px-6 py-2 rounded-lg hover:bg-blue-600 mr-4">
            <i class="fas fa-print mr-2"></i>พิมพ์ใบเสร็จ
        </button>
        <button onclick="window.close()" class="bg-gray-500 text-white px-6 py-2 rounded-lg hover:bg-gray-600">
            <i class="fas fa-times mr-2"></i>ปิด
        </button>
    </div>

    <!-- Receipt -->
    <div class="receipt p-4">
        <!-- Header -->
        <div class="receipt-header">
            <div class="font-bold text-lg"><?= h($shop_name) ?></div>
            <div class="text-sm"><?= h($shop_address) ?></div>
            <div class="text-sm">โทร: <?= h($shop_phone) ?></div>
            <div class="text-xs">เลขที่ผู้เสียภาษี: <?= h($shop_tax_id) ?></div>
        </div>

        <!-- Receipt Title -->
        <div class="text-center font-bold text-lg mb-3">ใบเสร็จรับเงิน</div>
        <div class="text-center text-sm mb-4">PAYMENT RECEIPT</div>

        <!-- Receipt Info -->
        <table class="receipt-table mb-3">
            <tr>
                <td class="text-sm">เลขที่ใบเสร็จ:</td>
                <td class="text-right font-bold"><?= h($receipt_number) ?></td>
            </tr>
            <tr>
                <td class="text-sm">วันที่:</td>
                <td class="text-right"><?= formatThaiDateTime($payment['payment_date']) ?></td>
            </tr>
            <tr>
                <td class="text-sm">รหัสจำนำ:</td>
                <td class="text-right font-bold"><?= h($payment['transaction_code']) ?></td>
            </tr>
        </table>

        <div class="dashed-line"></div>

        <!-- Customer Info -->
        <div class="text-sm font-bold mb-2">ข้อมูลลูกค้า</div>
        <table class="receipt-table mb-3">
            <tr>
                <td class="text-sm">รหัสลูกค้า:</td>
                <td class="text-right"><?= h($payment['customer_code']) ?></td>
            </tr>
            <tr>
                <td class="text-sm">ชื่อ-สกุล:</td>
                <td class="text-right"><?= h($payment['first_name'] . ' ' . $payment['last_name']) ?></td>
            </tr>
            <tr>
                <td class="text-sm">เบอร์โทร:</td>
                <td class="text-right"><?= h($payment['phone']) ?></td>
            </tr>
        </table>

        <div class="dashed-line"></div>

        <!-- Payment Details -->
        <div class="text-sm font-bold mb-2">รายละเอียดการชำระ</div>
        <table class="receipt-table mb-3">
            <tr>
                <td class="text-sm">ประเภทการชำระ:</td>
                <td class="text-right">
                    <?php
                    switch($payment['payment_type']) {
                        case 'interest': echo 'ดอกเบี้ย'; break;
                        case 'partial_payment': echo 'ชำระบางส่วน'; break;
                        case 'redemption': echo 'ไถ่คืน'; break;
                        default: echo h($payment['payment_type']);
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <td class="text-sm">จำนวนเงินต้น:</td>
                <td class="text-right"><?= formatCurrency($payment['pawn_amount']) ?></td>
            </tr>
            <tr>
                <td class="text-sm">อัตราดอกเบี้ย:</td>
                <td class="text-right"><?= h($payment['interest_rate']) ?>% ต่อเดือน</td>
            </tr>
            <tr>
                <td class="text-sm">จำนวนเดือน:</td>
                <td class="text-right"><?= $months_elapsed ?> เดือน</td>
            </tr>
            <tr>
                <td class="text-sm">ดอกเบี้ยสะสม:</td>
                <td class="text-right"><?= formatCurrency($interest_amount) ?></td>
            </tr>
        </table>

        <div class="dashed-line"></div>

        <!-- Payment Summary -->
        <div class="text-sm font-bold mb-2">สรุปการชำระเงิน</div>
        <table class="receipt-table mb-3">
            <tr>
                <td class="text-sm">ยอดรวมทั้งหมด:</td>
                <td class="text-right"><?= formatCurrency($total_amount) ?></td>
            </tr>
            <tr>
                <td class="text-sm">ชำระมาแล้ว:</td>
                <td class="text-right"><?= formatCurrency($total_paid_before) ?></td>
            </tr>
            <tr class="font-bold">
                <td class="text-sm">ชำระครั้งนี้:</td>
                <td class="text-right text-lg"><?= formatCurrency($payment['amount']) ?></td>
            </tr>
            <tr>
                <td class="text-sm">รวมชำระแล้ว:</td>
                <td class="text-right"><?= formatCurrency($total_paid_after) ?></td>
            </tr>
            <?php if ($remaining_balance > 0): ?>
            <tr class="font-bold">
                <td class="text-sm">คงเหลือ:</td>
                <td class="text-right text-lg"><?= formatCurrency($remaining_balance) ?></td>
            </tr>
            <?php else: ?>
            <tr class="font-bold">
                <td class="text-sm" colspan="2" style="text-align: center; color: green;">
                    ชำระครบแล้ว - สามารถไถ่คืนได้
                </td>
            </tr>
            <?php endif; ?>
        </table>

        <!-- Payment Method -->
        <div class="dashed-line"></div>
        <table class="receipt-table mb-3">
            <tr>
                <td class="text-sm">วิธีการชำระ:</td>
                <td class="text-right">
                    <?php
                    switch($payment['payment_method'] ?? 'cash') {
                        case 'cash': echo 'เงินสด'; break;
                        case 'transfer': echo 'โอนเงิน'; break;
                        case 'card': echo 'บัตรเครดิต'; break;
                        default: echo h($payment['payment_method']);
                    }
                    ?>
                </td>
            </tr>
            <?php if (!empty($payment['notes'])): ?>
            <tr>
                <td class="text-sm">หมายเหตุ:</td>
                <td class="text-right text-xs"><?= h($payment['notes']) ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <td class="text-sm">พนักงาน:</td>
                <td class="text-right"><?= h($payment['user_name']) ?></td>
            </tr>
        </table>

        <!-- Footer -->
        <div class="receipt-footer">
            <div class="text-xs mb-2">*** ขอบคุณที่ใช้บริการ ***</div>
            <div class="text-xs mb-1">กรุณาเก็บใบเสร็จนี้ไว้เป็นหลักฐาน</div>
            <div class="text-xs mb-2">Please keep this receipt for your record</div>
            
            <div class="dashed-line"></div>
            
            <div class="text-xs">
                <div>สาขา: <?= h($payment['branch_name']) ?></div>
                <div>พิมพ์เมื่อ: <?= formatThaiDate(date('Y-m-d')) ?> <?= date('H:i:s') ?> น.</div>
                <div>Ref: <?= h($payment['id']) ?></div>
            </div>
        </div>

        <!-- QR Code or Barcode placeholder -->
        <div class="text-center mt-4">
            <div class="text-xs">สำหรับติดตาม: <?= h($receipt_number) ?></div>
            <!-- You can add QR code generation here -->
            <div style="font-family: monospace; font-size: 10px; letter-spacing: 2px; margin-top: 5px;">
                *<?= strtoupper($receipt_number) ?>*
            </div>
        </div>
    </div>

    <script>
        // Auto print when page loads (optional)
        // window.onload = function() { window.print(); }
        
        // Auto close after printing (optional)
        window.onafterprint = function() {
            // setTimeout(() => window.close(), 1000);
        }
    </script>
</body>
</html>