<?php
require_once 'config/database.php';
requireLogin();

$user = getCurrentUser();
$db = getDB();
$pawn_id = $_GET['id'] ?? 0;

// Get pawn transaction details with all related information
$stmt = $db->prepare("
    SELECT pt.*, 
           c.first_name, c.last_name, c.customer_code, c.id_card, c.phone, c.address,
           b.name as branch_name, b.address as branch_address, b.phone as branch_phone,
           u.full_name as user_name
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

// Get company information
$company_name = getSetting('company_name', 'ร้านจำนำ');
$company_address = getSetting('company_address', '');
$company_phone = getSetting('company_phone', '');
$tax_id = getSetting('tax_id', '');

// Calculate total estimated value
$total_estimated_value = array_sum(array_column($items, 'estimated_value'));
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ใบรับจำนำ - <?= h($pawn['transaction_code']) ?></title>
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
            border: 3px solid #333;
            padding: 0;
        }
        
        .header {
            text-align: center;
            padding: 20px;
            border-bottom: 3px solid #333;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .company-name {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 8px;
        }
        
        .receipt-title {
            font-size: 22px;
            font-weight: bold;
            margin: 15px 0;
            background: white;
            color: #333;
            padding: 12px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .content {
            padding: 25px;
        }
        
        .section {
            margin-bottom: 25px;
        }
        
        .section-title {
            font-weight: bold;
            font-size: 18px;
            margin-bottom: 15px;
            padding: 8px 0;
            border-bottom: 2px solid #667eea;
            color: #333;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px dotted #ccc;
        }
        
        .info-label {
            font-weight: 600;
            color: #555;
            min-width: 120px;
        }
        
        .info-value {
            font-weight: bold;
            color: #333;
            flex: 1;
            text-align: right;
        }
        
        .amount-highlight {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            margin: 25px 0;
            box-shadow: 0 8px 16px rgba(79, 172, 254, 0.3);
        }
        
        .amount-number {
            font-size: 32px;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .items-table th,
        .items-table td {
            padding: 12px;
            text-align: left;
            border: 1px solid #ddd;
        }
        
        .items-table th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-weight: bold;
        }
        
        .items-table tr:nth-child(even) {
            background: #f8f9fa;
        }
        
        .items-table tr:hover {
            background: #e9ecef;
        }
        
        .items-table .amount {
            text-align: right;
            font-weight: bold;
        }
        
        .terms-conditions {
            background: #fff3cd;
            border: 2px solid #ffeaa7;
            padding: 20px;
            border-radius: 10px;
            margin: 25px 0;
        }
        
        .terms-title {
            font-weight: bold;
            color: #856404;
            font-size: 16px;
            margin-bottom: 15px;
        }
        
        .terms-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .terms-list li {
            padding: 5px 0;
            padding-left: 20px;
            position: relative;
            color: #856404;
        }
        
        .terms-list li:before {
            content: "•";
            color: #ff6b6b;
            font-weight: bold;
            position: absolute;
            left: 0;
        }
        
        .footer {
            margin-top: 40px;
            padding-top: 25px;
            border-top: 3px solid #333;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 50px;
        }
        
        .signature-box {
            text-align: center;
        }
        
        .signature-line {
            border-top: 2px solid #333;
            margin-top: 60px;
            padding-top: 8px;
            font-weight: bold;
        }
        
        .important-notice {
            background: #fee;
            border: 2px solid #ffcccb;
            padding: 20px;
            border-radius: 10px;
            margin: 25px 0;
            text-align: center;
        }
        
        .qr-code {
            text-align: center;
            margin: 25px 0;
        }
        
        .transaction-code {
            font-family: 'Courier New', monospace;
            font-size: 24px;
            font-weight: bold;
            letter-spacing: 2px;
            background: #f8f9fa;
            border: 2px solid #333;
            padding: 15px;
            border-radius: 8px;
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
                gap: 15px;
            }
            
            .footer {
                grid-template-columns: 1fr;
                gap: 30px;
            }
            
            .items-table {
                font-size: 12px;
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
                <div style="font-size: 16px; margin: 8px 0;"><?= h($company_address) ?></div>
            <?php endif; ?>
            <?php if ($company_phone): ?>
                <div style="font-size: 16px;">โทร: <?= h($company_phone) ?></div>
            <?php endif; ?>
            <?php if ($tax_id): ?>
                <div style="font-size: 14px;">เลขประจำตัวผู้เสียภาษี: <?= h($tax_id) ?></div>
            <?php endif; ?>
            <div class="receipt-title">ใบรับจำนำ / PAWN TICKET</div>
        </div>

        <div class="content">
            <!-- Transaction Code -->
            <div class="transaction-code" style="text-align: center;">
                <?= h($pawn['transaction_code']) ?>
            </div>

            <!-- Basic Information -->
            <div class="info-grid">
                <div>
                    <div class="info-item">
                        <span class="info-label">วันที่จำนำ:</span>
                        <span class="info-value"><?= formatThaiDate($pawn['pawn_date']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">วันครบกำหนด:</span>
                        <span class="info-value" style="color: #dc3545; font-weight: bold;">
                            <?= formatThaiDate($pawn['due_date']) ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">ระยะเวลา:</span>
                        <span class="info-value"><?= h($pawn['period_months']) ?> เดือน</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">อัตราดอกเบี้ย:</span>
                        <span class="info-value"><?= h($pawn['interest_rate']) ?>% ต่อเดือน</span>
                    </div>
                </div>
                <div>
                    <div class="info-item">
                        <span class="info-label">สาขา:</span>
                        <span class="info-value"><?= h($pawn['branch_name']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">พนักงาน:</span>
                        <span class="info-value"><?= h($pawn['user_name']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">เวลา:</span>
                        <span class="info-value"><?= date('H:i น.', strtotime($pawn['created_at'])) ?></span>
                    </div>
                </div>
            </div>

            <!-- Customer Information -->
            <div class="section">
                <div class="section-title">ข้อมูลผู้จำนำ</div>
                <div class="info-grid">
                    <div>
                        <div class="info-item">
                            <span class="info-label">รหัสลูกค้า:</span>
                            <span class="info-value"><?= h($pawn['customer_code']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">ชื่อ-นามสกุล:</span>
                            <span class="info-value"><?= h($pawn['first_name'] . ' ' . $pawn['last_name']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">เลขบัตรประชาชน:</span>
                            <span class="info-value"><?= h($pawn['id_card']) ?></span>
                        </div>
                    </div>
                    <div>
                        <div class="info-item">
                            <span class="info-label">เบอร์โทร:</span>
                            <span class="info-value"><?= h($pawn['phone']) ?></span>
                        </div>
                        <?php if (!empty($pawn['address'])): ?>
                        <div style="grid-column: 1 / -1; padding: 10px 0; border-bottom: 1px dotted #ccc;">
                            <div class="info-label" style="margin-bottom: 5px;">ที่อยู่:</div>
                            <div style="color: #333; font-weight: bold;"><?= h($pawn['address']) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Pawn Amount -->
            <div class="amount-highlight">
                <div style="font-size: 18px; margin-bottom: 5px;">จำนวนเงินที่ได้รับ</div>
                <div class="amount-number"><?= formatCurrency($pawn['pawn_amount']) ?></div>
                <div style="font-size: 16px; margin-top: 10px;">
                    (<?= convertNumberToThai($pawn['pawn_amount']) ?>)
                </div>
            </div>

            <!-- Pawn Items -->
            <div class="section">
                <div class="section-title">รายการสินค้าที่จำนำ</div>
                <table class="items-table">
                    <thead>
                        <tr>
                            <th style="width: 5%;">ลำดับ</th>
                            <th style="width: 25%;">ชื่อสินค้า</th>
                            <th style="width: 15%;">หมวดหมู่</th>
                            <th style="width: 30%;">รายละเอียด</th>
                            <th style="width: 10%;">น้ำหนัก (กรัม)</th>
                            <th style="width: 15%;">มูลค่าประเมิน</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $index => $item): ?>
                        <tr>
                            <td style="text-align: center;"><?= $index + 1 ?></td>
                            <td style="font-weight: bold;"><?= h($item['item_name']) ?></td>
                            <td><?= h($item['category_name']) ?></td>
                            <td>
                                <?php if (!empty($item['description'])): ?>
                                    <div style="margin-bottom: 5px;"><?= h($item['description']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($item['condition_notes'])): ?>
                                    <div style="font-size: 12px; color: #666; font-style: italic;">
                                        สภาพ: <?= h($item['condition_notes']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">
                                <?= $item['weight'] ? number_format($item['weight'], 3) : '-' ?>
                            </td>
                            <td class="amount"><?= formatCurrency($item['estimated_value']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="background: #e9ecef; font-weight: bold;">
                            <td colspan="5" style="text-align: right; padding-right: 20px;">รวมมูลค่าประเมิน:</td>
                            <td class="amount" style="color: #28a745; font-size: 16px;">
                                <?= formatCurrency($total_estimated_value) ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Notes -->
            <?php if (!empty($pawn['notes'])): ?>
            <div class="section">
                <div class="section-title">หมายเหตุ</div>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #667eea;">
                    <?= h($pawn['notes']) ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Terms and Conditions -->
            <div class="terms-conditions">
                <div class="terms-title">📋 เงื่อนไขการจำนำ</div>
                <ul class="terms-list">
                    <li>ลูกค้าต้องนำใบรับจำนำและบัตรประชาชนมาแสดงเมื่อไถ่คืนสินค้า</li>
                    <li>สามารถชำระดอกเบี้ยเพื่อขยายระยะเวลาได้ก่อนวันครบกำหนด</li>
                    <li>หากไม่มาไถ่คืนภายในกำหนด ทางร้านขอสงวนสิทธิ์ในการขายทอดตลาด</li>
                    <li>ดอกเบี้ยคำนวณเป็นรายเดือน โดยเศษของเดือนให้ถือเป็น 1 เดือน</li>
                    <li>ร้านไม่รับผิดชอบต่อความเสียหายที่เกิดจากภัยธรรมชาติ</li>
                    <li>การจำนำนี้อยู่ภายใต้พระราชบัญญัติการจำนำ พ.ศ. 2505</li>
                </ul>
            </div>

            <!-- Important Notice -->
            <div class="important-notice">
                <div style="font-size: 16px; font-weight: bold; color: #721c24; margin-bottom: 10px;">
                    ⚠️ ข้อควรระวัง
                </div>
                <div style="color: #721c24;">
                    กรุณาเก็บใบรับจำนำนี้ไว้อย่างดี หากสูญหายจะต้องดำเนินการแจ้งความก่อน<br>
                    และต้องชำระค่าธรรมเนียมออกใบแทน
                </div>
            </div>

            <!-- QR Code for tracking -->
            <div class="qr-code">
                <div style="display: inline-block; border: 2px solid #333; padding: 15px; background: #f8f9fa;">
                    <div style="font-family: monospace; font-size: 12px; text-align: center;">
                        QR CODE: <?= h($pawn['transaction_code']) ?>
                    </div>
                    <div style="height: 80px; width: 80px; margin: 10px auto; background: linear-gradient(45deg, #333 25%, transparent 25%, transparent 75%, #333 75%), linear-gradient(45deg, #333 25%, transparent 25%, transparent 75%, #333 75%); background-size: 8px 8px; background-position: 0 0, 4px 4px;"></div>
                </div>
            </div>

            <!-- Signatures -->
            <div class="footer">
                <div class="signature-box">
                    <div style="font-size: 16px; margin-bottom: 10px;">ลายเซ็นผู้จำนำ</div>
                    <div class="signature-line"><?= h($pawn['first_name'] . ' ' . $pawn['last_name']) ?></div>
                    <div style="font-size: 12px; color: #666; margin-top: 5px;">ผู้รับเงิน</div>
                </div>
                <div class="signature-box">
                    <div style="font-size: 16px; margin-bottom: 10px;">ลายเซ็นพนักงาน</div>
                    <div class="signature-line"><?= h($pawn['user_name']) ?></div>
                    <div style="font-size: 12px; color: #666; margin-top: 5px;">ผู้รับจำนำ</div>
                </div>
            </div>

            <!-- Footer Information -->
            <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666;">
                <div>พิมพ์เมื่อ: <?= date('d/m/Y H:i:s') ?></div>
                <div>ระบบจำนำ เวอร์ชัน <?= h(APP_VERSION) ?></div>
                <div style="margin-top: 10px; font-weight: bold;">
                    ขอบคุณที่ใช้บริการ • Thank you for your business
                </div>
            </div>
        </div>
    </div>

    <!-- Print Controls -->
    <div class="no-print" style="text-align: center; margin: 20px 0;">
        <button onclick="window.print()" style="background: #007bff; color: white; padding: 12px 24px; border: none; border-radius: 8px; cursor: pointer; margin-right: 10px; font-size: 16px;">
            <i class="fas fa-print"></i> พิมพ์ใบรับจำนำ
        </button>
        <button onclick="window.close()" style="background: #6c757d; color: white; padding: 12px 24px; border: none; border-radius: 8px; cursor: pointer; font-size: 16px;">
            <i class="fas fa-times"></i> ปิดหน้าต่าง
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
        
        // Copy transaction code to clipboard
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'c' && e.target.tagName !== 'INPUT') {
                navigator.clipboard.writeText('<?= h($pawn['transaction_code']) ?>');
            }
        });
    </script>
</body>
</html>

<?php
// Convert number to Thai text function (if not already defined)
if (!function_exists('convertNumberToThai')) {
    function convertNumberToThai($number) {
        $thai_numbers = [
            '', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า'
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
}
?>