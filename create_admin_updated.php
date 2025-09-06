<?php
/**
 * Create Admin User Script
 * ไฟล์สำหรับสร้างบัญชีผู้ดูแลระบบ
 * ควรลบออกหลังจากติดตั้งเสร็จแล้ว
 */

require_once 'config/database.php';

// ข้อมูลผู้ดูแลระบบ
$admin_data = [
    'username' => 'hs4tpt',
    'email' => 'hs4tpt@gmail.com', 
    'password' => '29PichiT',
    'full_name' => 'ผู้ดูแลระบบ',
    'phone' => '081-234-5678',
    'role' => 'admin',
    'status' => 'active'
];

try {
    $db = getDB();
    
    // ตรวจสอบว่ามีสาขาหรือยัง ถ้าไม่มีให้สร้าง
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM branches");
    $stmt->execute();
    $branch_count = $stmt->fetch()['count'];
    
    if ($branch_count == 0) {
        echo "สร้างสาขาหลัก...<br>";
        $stmt = $db->prepare("
            INSERT INTO branches (name, address, phone, status) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            'สาขาหลัก',
            '123 ถนนสุขุมวิท แขวงคลองตัน เขตคลองตัน กรุงเทพฯ 10110',
            '02-123-4567',
            'active'
        ]);
        $branch_id = $db->lastInsertId();
        echo "สร้างสาขาหลักเรียบร้อย (ID: {$branch_id})<br>";
    } else {
        // ใช้สาขาแรกที่มีอยู่
        $stmt = $db->prepare("SELECT id FROM branches LIMIT 1");
        $stmt->execute();
        $branch_id = $stmt->fetch()['id'];
    }
    
    // ลบ admin เก่าถ้ามี
    $stmt = $db->prepare("DELETE FROM users WHERE username = ?");
    $stmt->execute([$admin_data['username']]);
    
    // เข้ารหัสรหัสผ่าน
    $hashed_password = password_hash($admin_data['password'], PASSWORD_DEFAULT);
    
    // สร้าง admin ใหม่
    $stmt = $db->prepare("
        INSERT INTO users (username, email, password, full_name, phone, role, branch_id, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $admin_data['username'],
        $admin_data['email'],
        $hashed_password,
        $admin_data['full_name'],
        $admin_data['phone'],
        $admin_data['role'],
        $branch_id,
        $admin_data['status']
    ]);
    
    $user_id = $db->lastInsertId();
    
    // สร้างหมวดหมู่สินค้าถ้ายังไม่มี
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM item_categories");
    $stmt->execute();
    $category_count = $stmt->fetch()['count'];
    
    if ($category_count == 0) {
        echo "สร้างหมวดหมู่สินค้า...<br>";
        $categories = [
            ['ทองคำ', 'เครื่องประดับทองคำและทองรูปพรรณ'],
            ['เครื่องใช้ไฟฟ้า', 'อุปกรณ์อิเล็กทรอนิกส์และเครื่องใช้ไฟฟ้า'],
            ['รถจักรยานยนต์', 'รถจักรยานยนต์และส่วนประกอบ'],
            ['เครื่องประดับ', 'แหวน สร้อยคอ ต่างหู และเครื่องประดับอื่นๆ'],
            ['นาฬิกา', 'นาฬิกาข้อมือและนาฬิกาตั้งโต๊ะ'],
            ['อื่นๆ', 'สินค้าประเภทอื่นๆ']
        ];
        
        $stmt = $db->prepare("INSERT INTO item_categories (name, description) VALUES (?, ?)");
        foreach ($categories as $category) {
            $stmt->execute($category);
        }
        echo "สร้างหมวดหมู่สินค้าเรียบร้อย<br>";
    }
    
    // สร้างการตั้งค่าระบบถ้ายังไม่มี
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM settings");
    $stmt->execute();
    $settings_count = $stmt->fetch()['count'];
    
    if ($settings_count == 0) {
        echo "สร้างการตั้งค่าระบบ...<br>";
        $settings = [
            ['default_interest_rate', '5.00', 'อัตราดอกเบี้ยเริ่มต้นต่อเดือน (%)'],
            ['default_period_months', '3', 'ระยะเวลาจำนำเริ่มต้น (เดือน)'],
            ['service_fee', '100', 'ค่าธรรมเนียมการบริการ (บาท)'],
            ['company_name', 'ระบบจัดการร้านจำนำ', 'ชื่อบริษัท'],
            ['company_address', '123 ถนนสุขุมวิท แขวงคลองตัน เขตคลองตัน กรุงเทพฯ 10110', 'ที่อยู่บริษัท'],
            ['company_phone', '02-123-4567', 'เบอร์โทรบริษัท'],
            ['tax_id', '0123456789012', 'เลขประจำตัวผู้เสียภาษี'],
            ['max_loan_amount', '1000000', 'จำนวนเงินจำนำสูงสุด (บาท)'],
            ['min_loan_amount', '1000', 'จำนวนเงินจำนำต่ำสุด (บาท)']
        ];
        
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
        foreach ($settings as $setting) {
            $stmt->execute($setting);
        }
        echo "สร้างการตั้งค่าระบบเรียบร้อย<br>";
    }
    
    // สร้างลูกค้าตัวอย่าง
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM customers");
    $stmt->execute();
    $customer_count = $stmt->fetch()['count'];
    
    if ($customer_count == 0) {
        echo "สร้างลูกค้าตัวอย่าง...<br>";
        $customers = [
            [
                generateCode('C'), 'สมชาย', 'ใจดี', '1234567890123', '081-234-5678',
                '123/45 ถนนรามคำแหง เขตวังทองหลาง กรุงเทพฯ', $branch_id
            ],
            [
                generateCode('C'), 'มาลี', 'สวยงาม', '1234567890124', '089-876-5432',
                '678/90 ถนนพระราม 4 เขตคลองเตย กรุงเทพฯ', $branch_id
            ],
            [
                generateCode('C'), 'สมศรี', 'รื่นเริง', '1234567890125', '092-111-2222',
                '456/78 ถนนลาดพร้าว เขตจตุจักร กรุงเทพฯ', $branch_id
            ]
        ];
        
        $stmt = $db->prepare("
            INSERT INTO customers (customer_code, first_name, last_name, id_card, phone, address, branch_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($customers as $customer) {
            $stmt->execute($customer);
        }
        echo "สร้างลูกค้าตัวอย่างเรียบร้อย<br>";
    }
    
    // บันทึกกิจกรรม
    logActivity($user_id, 'SYSTEM_SETUP', 'Admin account created and system initialized');
    
    echo "<div style='background: #d4edda; color: #155724; padding: 20px; border: 1px solid #c3e6cb; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3>✅ สร้างบัญชีผู้ดูแลระบบเรียบร้อยแล้ว</h3>";
    echo "<p><strong>ชื่อผู้ใช้:</strong> {$admin_data['username']}</p>";
    echo "<p><strong>รหัสผ่าน:</strong> {$admin_data['password']}</p>";
    echo "<p><strong>อีเมล:</strong> {$admin_data['email']}</p>";
    echo "<p><strong>ชื่อ:</strong> {$admin_data['full_name']}</p>";
    echo "<p><strong>เบอร์โทร:</strong> {$admin_data['phone']}</p>";
    echo "</div>";
    
    echo "<div style='background: #fff3cd; color: #856404; padding: 15px; border: 1px solid #ffeaa7; border-radius: 5px; margin: 20px 0;'>";
    echo "<h4>⚠️ คำแนะนำด้านความปลอดภัย:</h4>";
    echo "<ul>";
    echo "<li>เปลี่ยนรหัสผ่านทันทีหลังจากเข้าสู่ระบบครั้งแรก</li>";
    echo "<li>ลบไฟล์ create_admin.php ออกจากเซิร์ฟเวอร์หลังจากใช้งานเสร็จ</li>";
    echo "<li>ตั้งค่าสิทธิ์การเข้าถึงไฟล์ให้เหมาะสม</li>";
    echo "<li>สำรองข้อมูลเป็นประจำ</li>";
    echo "<li>อัปเดตระบบและเปลี่ยนรหัสผ่านเป็นประจำ</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<div style='text-align: center; margin: 30px 0;'>";
    echo "<a href='login.php' style='background: #007bff; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;'>";
    echo "🔐 เข้าสู่ระบบ";
    echo "</a>";
    echo "</div>";
    
    // แสดงข้อมูลการตั้งค่าเพิ่มเติม
    echo "<hr style='margin: 30px 0;'>";
    echo "<h3>ข้อมูลระบบ</h3>";
    echo "<p><strong>เวอร์ชัน:</strong> " . APP_VERSION . "</p>";
    echo "<p><strong>PHP Version:</strong> " . phpversion() . "</p>";
    echo "<p><strong>ฐานข้อมูล:</strong> " . DB_NAME . "</p>";
    echo "<p><strong>เซิร์ฟเวอร์:</strong> " . ($_SERVER['SERVER_SOFTWARE'] ?? 'ไม่ทราบ') . "</p>";
    echo "<p><strong>วันที่สร้าง:</strong> " . date('d/m/Y H:i:s') . "</p>";
    
} catch (PDOException $e) {
    echo "<div style='background: #f8d7da; color: #721c24; padding: 20px; border: 1px solid #f5c6cb; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3>❌ เกิดข้อผิดพลาด</h3>";
    echo "<p>ไม่สามารถสร้างบัญชีผู้ดูแลระบบได้</p>";
    echo "<p><strong>ข้อความผิดพลาด:</strong> " . $e->getMessage() . "</p>";
    echo "<p><strong>แนะนำ:</strong></p>";
    echo "<ul>";
    echo "<li>ตรวจสอบการเชื่อมต่อฐานข้อมูล</li>";
    echo "<li>ตรวจสอบการตั้งค่าใน config/database.php</li>";
    echo "<li>ตรวจสอบว่าฐานข้อมูลถูกสร้างแล้ว</li>";
    echo "<li>ตรวจสอบสิทธิ์การเข้าถึงฐานข้อมูล</li>";
    echo "</ul>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; color: #721c24; padding: 20px; border: 1px solid #f5c6cb; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3>❌ เกิดข้อผิดพลาดระบบ</h3>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "</div>";
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สร้างบัญชีผู้ดูแลระบบ - <?= h(APP_NAME) ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap');
        body {
            font-family: 'Sarabun', sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 30px;
        }
        .warning {
            background: #fff3cd;
            color: #856404;
            padding: 15px;
            border: 1px solid #ffeaa7;
            border-radius: 5px;
            margin: 20px 0;
        }
        .success {
            background: #d4edda;
            color: #155724;
            padding: 20px;
            border: 1px solid #c3e6cb;
            border-radius: 5px;
            margin: 20px 0;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 20px;
            border: 1px solid #f5c6cb;
            border-radius: 5px;
            margin: 20px 0;
        }
        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            transition: background 0.3s;
        }
        .btn:hover {
            background: #0056b3;
        }
        .center {
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 ติดตั้งระบบจัดการร้านจำนำ</h1>
        
        <div class="warning">
            <h4>⚠️ หมายเหตุสำคัญ</h4>
            <p>ไฟล์นี้ใช้สำหรับการติดตั้งระบบครั้งแรกเท่านั้น กรุณาลบไฟล์นี้ออกจากเซิร์ฟเวอร์หลังจากการติดตั้งเสร็จสิ้น</p>
        </div>
    </div>
</body>
</html>