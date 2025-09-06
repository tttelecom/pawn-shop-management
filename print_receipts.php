<?php
require_once 'config/database.php';
requireLogin();

$user = getCurrentUser();
$db = getDB();
$payment_id = $_GET['id'] ?? 0;

// Get payment details with related information
$stmt = $db->prepare("
    SELECT p.*, pt.transaction_code, pt.pawn_amount, pt.interest_rate, pt.pawn_date, pt.due_date,
           c.first_name, c.last_name, c.customer_code, c.id_card, c.phone, c.address,
           b.name as branch_name, b.address as branch_address, b.phone as branch_phone,
           u.full_name as user_name
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
    setFlash('error', 'ไม่พบข้อมูลการชำระเงิน');
    header('Location: payments.php');
    exit;
}

// Get company information
$company_name = getSetting('company_name', 'ร้านจำนำ');
$company_address = getSetting('company_address', '');
$company_phone = getSetting('company_phone', '');

// Calculate remaining balance
$stmt = $db->prepare("
    SELECT SUM(amount) as total_paid
    FROM payments 
    WHERE transaction_id = ? AND payment_date <= ?
");
$stmt->execute([$payment['transaction_id'], $payment['payment_date']]);
$total_paid = $stmt->fetch()['total_paid'] ?? 0;

// Calculate total amount due
$days_elapsed = ceil((strtotime($payment['payment_date']) - strtotime($payment['pawn_date'])) / (60*60*24));
$months_elapsed = ceil($days_elapsed / 30);
$interest_amount = $payment['pawn_amount'] * ($payment['interest_rate'] / 100) * $months_elapsed;
$total_due = $payment['pawn_amount'] + $interest_amount;
$remaining_balance = max(0, $total_due - $total_paid);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ใบเสร็จรับเงิน - <?= h($payment['transaction_code']) ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap');
        
        body {
            font-family: 'Sarabun', sans-serif;
            font-size: 14px;
            line-height: 1.4;
            margin: 0;
            padding: 20px;
            background: white;
        }
        
        .receipt {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border: 2px solid #333;
            padding: 0;
        }
        
        .header {
            text-align: center;
            padding: 20px;
            border-bottom: 2px solid #333;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .company-name {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .receipt-title {
            font-size: 20px;
            font-weight: bold;
            margin: 10px 0;
            background: white;
            color: #333;
            padding: 10px;
            border-radius: 5px;
        }
        
        .content {
            padding: 20px;
        }
        
        .section {
            margin-bottom: 20px;
        }
        
        .section-title {
            font-weight: bold;
            font-size: 16px;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #ddd;
            color: #333;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px dotted #ccc;
        }
        
        .info-label {
            font-weight: 500;
            color: #666;
        }
        
        .info-value {
            font-weight: bold;
            color: #333;
        }
        
        .amount-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border: 2px solid #007bff;
            margin: 20px 0;
        }
        
        .amount-paid {
            font-size: 24px;
            font-weight: bold;
            color: #28a745;
            text-align: center;
            margin: 10px 0;
        }
        
        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        
        .summary-table th,
        .summary-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .summary-table th {
            background: #f8f9fa;
            font-weight: bold;
        }
        
        .summary-table .amount {
            text-align: right;
            font-weight: bold;
        }
        
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #333;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
        }
        
        .signature-box {
            text-align: center;
        }
        
        .signature-line {
            border-top: 1px solid #333;
            margin-top: 50px;
            padding-top: 5px;
        }
        
        .notes {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        
        .barcode {
            text-align: center;
            margin: 20px 0;
        }
        
        @media print {
            body {
                padding: 0;
                background: white;
            }
            
            .receipt {
                border: none;
                box-shadow: none;
                max-width: none;
            }
            
            .no-print {
                display: none !important;
            }
        }
        
        @media (max-width: 600px) {
            .info-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .footer {
                grid-template-columns: 1fr;
                gap: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="receipt">
        <!-- Header -->
        <div class="header">
            <div class="company-name"><?= h($company_name) ?></div>
            <?php if ($company_address): ?>
                <div style="font-size: 14px; margin: 5px 0;"><?= h($company_address) ?></div>
            <?php endif; ?>
            <?php if ($company_phone): ?>
                <div style="font-size: 14px;">โทร: <?= h($company_phone) ?></div>
            <?php endif; ?>
            <div class="receipt-title">ใบเสร็จรับเงิน</div>
        </div>

        <div class="content">
            <!-- Receipt Information -->
            <div class="info-grid">
                <div>
                    <div class="info-item">
                        <span class="info-label">เลขที่ใบเสร็จ:</span>
                        <span class="info-value">R<?= str_pad($payment['id'], 6, '0', STR_PAD_LEFT) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">วันที่:</span>
                        <span class="info-value"><?= formatThaiDate($payment['payment_date']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">เวลา:</span>
                        <span class="info-value"><?= date('H:i น.', strtotime($payment['created_at'])) ?></span>
                    </div>
                </div>
                <div>
                    <div class="info-item">
                        <span class="info-label">รหัสรายการจำนำ:</span>
                        <span class="info-value"><?= h($payment['transaction_code']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">พนักงาน:</span>
                        <span class="info-value"><?= h($payment['user_name']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">สาขา:</span>
                        <span class="info-value"><?= h($payment['branch_name']) ?></span>
                    </div>
                </div>
            </div>

            <!-- Customer Information -->
            <div class="section">
                <div class="section-title">ข้อมูลลูกค้า</div>
                <div class="info-grid">
                    <div>
                        <div class="info-item">
                            <span class="info-label">รหัสลูกค้า:</span>
                            <span class="info-value"><?= h($payment['customer_code']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">ชื่อ-นามสกุล:</span>
                            <span class="info-value"><?= h($payment['first_name'] . ' ' . $payment['last_name']) ?></span>
                        </div>
                    </div>
                    <div>
                        <div class="info-item">
                            <span class="info-label">เลขบัตรประชาชน:</span>
                            <span class="info-value"><?= h($payment['id_card']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">เบอร์โทร:</span>
                            <span class="info-value"><?= h($payment['phone']) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Amount -->
            <div class="amount-section">
                <div style="text-align: center; margin-bottom: 15px;">
                    <div style="font-size: 18px; font-weight: bold; color: #666;">จำนวนเงินที่ชำระ</div>
                    <div class="amount-paid"><?= formatCurrency($payment['amount']) ?></div>
                    <div style="font-size: 16px; color: #666;">
                        (<?= convertNumberToThai($payment['amount']) ?>)
                    </div>
                </div>
                
                <div style="text-align: center;">
                    <span style="background: #007bff; color: white; padding: 5px 15px; border-radius: 20px; font-weight: bold;">
                        <?php
                        $payment_types = [
                            'interest' => 'ชำระดอกเบี้ย',
                            'partial_payment' => 'ชำระบางส่วน', 
                            'full_payment' => 'ไถ่คืนทั้งหมด'
                        ];
                        echo $payment_types[$payment['payment_type']] ?? h($payment['payment_type']);
                        ?>
                    </span>
                </div>
            </div>

            <!-- Payment Summary -->
            <div class="section">
                <div class="section-title">สรุปการชำระเงิน</div>
                <table class="summary-table">
                    <tr>
                        <th style="width: 60%;">รายการ</th>
                        <th style="width: 40%; text-align: right;">จำนวนเงิน</th>
                    </tr>
                    <tr>
                        <td>เงินต้น</td>
                        <td class="amount"><?= formatCurrency($payment['pawn_amount']) ?></td>
                    </tr>
                    <tr>
                        <td>ดอกเบี้ย (<?= $months_elapsed ?> เดือน @ <?= h($payment['interest_rate']) ?>%)</td>
                        <td class="amount"><?= formatCurrency($interest_amount) ?></td>
                    </tr>
                    <tr style="border-top: 2px solid #333; font-weight: bold;">
                        <td>ยอดรวม</td>
                        <td class="amount"><?= formatCurrency($total_due) ?></td>
                    </tr>
                    <tr style="color: #28a745; font-weight: bold;">
                        <td>ชำระครั้งนี้</td>
                        <td class="amount"><?= formatCurrency($payment['amount']) ?></td>
                    </tr>
                    <tr style="color: #dc3545; font-weight: bold;">
                        <td>คงเหลือ</td>
                        <td class="amount"><?= formatCurrency($remaining_balance) ?></td>
                    </tr>
                </table>
            </div>

            <!-- Pawn Information -->
            <div class="section">
                <div class="section-title">ข้อมูลการจำนำ</div>
                <div class="info-grid">
                    <div>
                        <div class="info-item">
                            <span class="info-label">วันที่จำนำ:</span>
                            <span class="info-value"><?= formatThaiDate($payment['pawn_date']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">วันครบกำหนด:</span>
                            <span class="info-value"><?= formatThaiDate($payment['due_date']) ?></span>
                        </div>
                    </div>
                    <div>
                        <div class="info-item">
                            <span class="info-label">อัตราดอกเบี้ย:</span>
                            <span class="info-value"><?= h($payment['interest_rate']) ?>% ต่อเดือน</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">วิธีการชำระ:</span>
                            <span class="info-value">
                                <?php
                                $payment_methods = [
                                    'cash' => 'เงินสด',
                                    'transfer' => 'โอนเงิน',
                                    'credit_card' => 'บัตรเครดิต',
                                    'debit_card' => 'บัตรเดบิต',
                                    'mobile_banking' => 'Mobile Banking'
                                ];
                                echo $payment_methods[$payment['payment_method']] ?? 'เงินสด';
                                ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Notes -->
            <?php if (!empty($payment['notes'])): ?>
            <div class="notes">
                <strong>หมายเหตุ:</strong> <?= h($payment['notes']) ?>
            </div>
            <?php endif; ?>

            <!-- Important Information -->
            <div class="notes">
                <strong>ข้อมูลสำคัญ:</strong>
                <ul style="margin: 10px 0; padding-left: 20px;">
                    <li>กรุณาเก็บใบเสร็จนี้ไว้เป็นหลักฐานการชำระเงิน</li>
                    <li>หากต้องการไถ่คืนสินค้า กรุณาแสดงใบเสร็จและบัตรประชาชน</li>
                    <li>สามารถชำระดอกเบี้ยล่วงหน้าได้</li>
                    <?php if ($remaining_balance > 0): ?>
                    <li style="color: #dc3545; font-weight: bold;">ยอดคงเหลือ: <?= formatCurrency($remaining_balance) ?></li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Barcode for receipt tracking -->
            <div class="barcode">
                <div style="font-family: 'Courier New', monospace; font-size: 24px; letter-spacing: 2px; border: 1px solid #333; padding: 10px; background: #f8f9fa;">
                    R<?= str_pad($payment['id'], 6, '0', STR_PAD_LEFT) ?><?= $payment['transaction_id'] ?>
                </div>
                <div style="font-size: 12px; color: #666; margin-top: 5px;">รหัสอ้างอิง</div>
            </div>

            <!-- Signatures -->
            <div class="footer">
                <div class="signature-box">
                    <div>ลายเซ็นลูกค้า</div>
                    <div class="signature-line"><?= h($payment['first_name'] . ' ' . $payment['last_name']) ?></div>
                </div>
                <div class="signature-box">
                    <div>ลายเซ็นพนักงาน</div>
                    <div class="signature-line"><?= h($payment['user_name']) ?></div>
                </div>
            </div>

            <!-- Print timestamp -->
            <div style="text-align: center; margin-top: 30px; font-size: 12px; color: #666;">
                พิมพ์เมื่อ: <?= date('d/m/Y H:i:s') ?> | ระบบจำนำ v<?= h(APP_VERSION) ?>
            </div>
        </div>
    </div>

    <!-- Print Controls -->
    <div class="no-print" style="text-align: center; margin: 20px 0;">
        <button onclick="window.print()" style="background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; margin-right: 10px;">
            <i class="fas fa-print"></i> พิมพ์ใบเสร็จ
        </button>
        <button onclick="window.close()" style="background: #6c757d; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;">
            <i class="fas fa-times"></i> ปิด
        </button>
    </div>

    <script>
        // Auto print when page loads (optional)
        // window.onload = function() { window.print(); }
        
        // Print shortcut
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
        });
    </script>
</body>
</html>

<?php
/**
 * Convert number to Thai text
 */
function convertNumberToThai($number) {
    $thai_numbers = [
        '', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า'
    ];
    
    $thai_units = [
        '', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน', 'ล้าน'
    ];
    
    if ($number == 0) return 'ศูนย์บาท';
    
    $baht = floor($number);
    $satang = round(($number - $baht) * 100);
    
    $baht_text = convertIntegerToThai($baht);
    $result = $baht_text . 'บาท';
    
    if ($satang > 0) {
        $satang_text = convertIntegerToThai($satang);
        $result .= $satang_text . 'สตางค์';
    } else {
        $result .= 'ถ้วน';
    }
    
    return $result;
}

function convertIntegerToThai($number) {
    if ($number == 0) return '';
    
    $thai_numbers = [
        '', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า'
    ];
    
    $result = '';
    $number_str = strval($number);
    $length = strlen($number_str);
    
    for ($i = 0; $i < $length; $i++) {
        $digit = intval($number_str[$i]);
        $position = $length - $i;
        
        if ($digit != 0) {
            if ($position == 2 && $digit == 1) {
                $result .= 'สิบ';
            } elseif ($position == 2 && $digit == 2) {
                $result .= 'ยี่สิบ';
            } elseif ($position == 1 && $digit == 1 && $length > 1) {
                $result .= 'เอ็ด';
            } else {
                $result .= $thai_numbers[$digit];
                
                if ($position == 3) $result .= 'ร้อย';
                elseif ($position == 4) $result .= 'พัน';
                elseif ($position == 5) $result .= 'หมื่น';
                elseif ($position == 6) $result .= 'แสน';
                elseif ($position == 7) $result .= 'ล้าน';
                elseif ($position == 2) $result .= 'สิบ';
            }
        }
    }
    
    return $result;
}
?>