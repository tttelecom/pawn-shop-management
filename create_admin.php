<?php
// create_admin.php - ไฟล์ชั่วคราวสำหรับสร้าง admin
require_once 'config/database.php';

$username = 'hs4tpt';
$password = '29PichiT';
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

$db = getDB();

// ลบ admin เก่า (ถ้ามี)
$stmt = $db->prepare("DELETE FROM users WHERE username = ?");
$stmt->execute([$username]);

// สร้าง admin ใหม่
$stmt = $db->prepare("
    INSERT INTO users (username, email, password, full_name, phone, role, branch_id, status) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([
    $username,
    'hs4tpt@gmail.com',
    $hashed_password,
    'ผู้ดูแลระบบ',
    '081-234-5678',
    'admin',
    1,
    'active'
]);

echo "สร้างบัญชี admin เรียบร้อยแล้ว<br>";
echo "ชื่อผู้ใช้: admin<br>";
echo "รหัสผ่าน: admin123<br>";
?>