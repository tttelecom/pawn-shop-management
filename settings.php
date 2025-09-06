<?php
require_once 'config/database.php';
requireLogin();

$user = getCurrentUser();
$db = getDB();

// Only admin can access settings
if ($user['role'] !== 'admin') {
    setFlash('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    header('Location: index.php');
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (validateCSRFToken($_POST['csrf_token'])) {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'update_settings':
                    $settings = [
                        'shop_name' => $_POST['shop_name'],
                        'shop_address' => $_POST['shop_address'],
                        'shop_phone' => $_POST['shop_phone'],
                        'shop_tax_id' => $_POST['shop_tax_id'],
                        'shop_email' => $_POST['shop_email'],
                        'default_interest_rate' => $_POST['default_interest_rate'],
                        'default_period_months' => $_POST['default_period_months'],
                        'service_fee' => $_POST['service_fee'],
                        'late_fee_per_day' => $_POST['late_fee_per_day'],
                        'min_pawn_amount' => $_POST['min_pawn_amount'],
                        'max_pawn_amount' => $_POST['max_pawn_amount']
                    ];
                    
                    try {
                        // Update existing settings or insert new ones
                        $stmt = $db->prepare("
                            INSERT INTO settings (setting_key, setting_value) 
                            VALUES (?, ?) 
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                        ");
                        
                        foreach ($settings as $key => $value) {
                            $stmt->execute([$key, $value]);
                        }
                        
                        logActivity($user['id'], 'UPDATE_SETTINGS', 'Updated system settings');
                        setFlash('success', 'บันทึกการตั้งค่าเรียบร้อยแล้ว');
                    } catch (PDOException $e) {
                        setFlash('error', 'เกิดข้อผิดพลาดในการบันทึกการตั้งค่า');
                    }
                    break;
                    
                case 'add_user':
                    $username = trim($_POST['username']);
                    $email = trim($_POST['email']);
                    $password = $_POST['password'];
                    $full_name = trim($_POST['full_name']);
                    $phone = trim($_POST['phone']);
                    $role = $_POST['role'];
                    $branch_id = $_POST['branch_id'] ?: null;
                    
                    // Validate password strength
                    if (strlen($password) < 6) {
                        setFlash('error', 'รหัสผ่านต้องมีความยาวอย่างน้อย 6 ตัวอักษร');
                    } else {
                        try {
                            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                            $stmt = $db->prepare("
                                INSERT INTO users (username, email, password, full_name, phone, role, branch_id) 
                                VALUES (?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([$username, $email, $hashed_password, $full_name, $phone, $role, $branch_id]);
                            
                            logActivity($user['id'], 'ADD_USER', "Added user: $username");
                            setFlash('success', 'เพิ่มผู้ใช้งานเรียบร้อยแล้ว');
                        } catch (PDOException $e) {
                            if ($e->getCode() == 23000) {
                                setFlash('error', 'ชื่อผู้ใช้หรืออีเมลนี้มีอยู่ในระบบแล้ว');
                            } else {
                                setFlash('error', 'เกิดข้อผิดพลาดในการเพิ่มผู้ใช้งาน');
                            }
                        }
                    }
                    break;
                    
                case 'edit_user':
                    $user_id = $_POST['user_id'];
                    $full_name = trim($_POST['full_name']);
                    $email = trim($_POST['email']);
                    $phone = trim($_POST['phone']);
                    $role = $_POST['role'];
                    $branch_id = $_POST['branch_id'] ?: null;
                    $status = $_POST['status'];
                    $new_password = trim($_POST['new_password']);
                    
                    try {
                        if (!empty($new_password)) {
                            if (strlen($new_password) < 6) {
                                setFlash('error', 'รหัสผ่านใหม่ต้องมีความยาวอย่างน้อย 6 ตัวอักษร');
                                break;
                            }
                            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                            $stmt = $db->prepare("
                                UPDATE users 
                                SET full_name = ?, email = ?, phone = ?, role = ?, branch_id = ?, status = ?, password = ?
                                WHERE id = ?
                            ");
                            $stmt->execute([$full_name, $email, $phone, $role, $branch_id, $status, $hashed_password, $user_id]);
                        } else {
                            $stmt = $db->prepare("
                                UPDATE users 
                                SET full_name = ?, email = ?, phone = ?, role = ?, branch_id = ?, status = ?
                                WHERE id = ?
                            ");
                            $stmt->execute([$full_name, $email, $phone, $role, $branch_id, $status, $user_id]);
                        }
                        
                        logActivity($user['id'], 'EDIT_USER', "Edited user ID: $user_id");
                        setFlash('success', 'แก้ไขข้อมูลผู้ใช้งานเรียบร้อยแล้ว');
                    } catch (PDOException $e) {
                        setFlash('error', 'เกิดข้อผิดพลาดในการแก้ไขข้อมูลผู้ใช้งาน');
                    }
                    break;
                    
                case 'backup_database':
                    try {
                        $backup_file = createDatabaseBackup();
                        if ($backup_file) {
                            logActivity($user['id'], 'DATABASE_BACKUP', "Created backup: $backup_file");
                            setFlash('success', 'สำรองข้อมูลเรียบร้อยแล้ว');
                            
                            // Force download
                            header('Content-Description: File Transfer');
                            header('Content-Type: application/octet-stream');
                            header('Content-Disposition: attachment; filename="' . basename($backup_file) . '"');
                            header('Expires: 0');
                            header('Cache-Control: must-revalidate');
                            header('Pragma: public');
                            header('Content-Length: ' . filesize($backup_file));
                            readfile($backup_file);
                            unlink($backup_file); // Delete temporary file
                            exit;
                        } else {
                            setFlash('error', 'ไม่สามารถสร้างไฟล์สำรองข้อมูลได้');
                        }
                    } catch (Exception $e) {
                        setFlash('error', 'เกิดข้อผิดพลาดในการสำรองข้อมูล: ' . $e->getMessage());
                    }
                    break;
            }
        }
        header('Location: settings.php');
        exit;
    }
}

// Handle AJAX requests for user data
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_user' && isset($_GET['id'])) {
    $user_id = $_GET['id'];
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch();
    
    header('Content-Type: application/json');
    echo json_encode($user_data);
    exit;
}

// Get current settings
$stmt = $db->prepare("SELECT setting_key, setting_value FROM settings");
$stmt->execute();
$settings_raw = $stmt->fetchAll();
$settings = [];
foreach ($settings_raw as $setting) {
    $settings[$setting['setting_key']] = $setting['setting_value'];
}

// Set default values if not exist
$default_settings = [
    'shop_name' => 'ร้านจำนำ ABC',
    'shop_address' => '',
    'shop_phone' => '',
    'shop_tax_id' => '',
    'shop_email' => '',
    'default_interest_rate' => '5.00',
    'default_period_months' => '3',
    'service_fee' => '100',
    'late_fee_per_day' => '50',
    'min_pawn_amount' => '1000',
    'max_pawn_amount' => '500000'
];

foreach ($default_settings as $key => $default_value) {
    if (!isset($settings[$key])) {
        $settings[$key] = $default_value;
    }
}

// Get users
$stmt = $db->prepare("
    SELECT u.*, b.name as branch_name 
    FROM users u 
    LEFT JOIN branches b ON u.branch_id = b.id 
    ORDER BY u.created_at DESC
");
$stmt->execute();
$users = $stmt->fetchAll();

// Get branches for dropdown
$stmt = $db->prepare("SELECT * FROM branches WHERE status = 'active' ORDER BY name");
$stmt->execute();
$branches = $stmt->fetchAll();

// Get system statistics
$stats = [];
$stmt = $db->prepare("SELECT COUNT(*) as total FROM customers");
$stmt->execute();
$stats['total_customers'] = $stmt->fetch()['total'];

$stmt = $db->prepare("SELECT COUNT(*) as total FROM pawn_transactions WHERE status IN ('active', 'overdue')");
$stmt->execute();
$stats['active_pawns'] = $stmt->fetch()['total'];

$stmt = $db->prepare("SELECT SUM(pawn_amount) as total FROM pawn_transactions WHERE status IN ('active', 'overdue')");
$stmt->execute();
$stats['total_pawn_amount'] = $stmt->fetch()['total'] ?? 0;

// Create backup function
function createDatabaseBackup() {
    global $db;
    
    $backup_dir = 'backups';
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }
    
    $filename = $backup_dir . '/backup_' . date('Y-m-d_H-i-s') . '.sql';
    
    try {
        $tables = [];
        $stmt = $db->query("SHOW TABLES");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        
        $sql_dump = "-- Database Backup Created on " . date('Y-m-d H:i:s') . "\n\n";
        $sql_dump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
        
        foreach ($tables as $table) {
            // Get table structure
            $stmt = $db->query("SHOW CREATE TABLE `$table`");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $sql_dump .= "-- Table structure for `$table`\n";
            $sql_dump .= "DROP TABLE IF EXISTS `$table`;\n";
            $sql_dump .= $row['Create Table'] . ";\n\n";
            
            // Get table data
            $stmt = $db->query("SELECT * FROM `$table`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if ($rows) {
                $sql_dump .= "-- Data for table `$table`\n";
                foreach ($rows as $row) {
                    $values = array_map(function($value) use ($db) {
                        return $value === null ? 'NULL' : $db->quote($value);
                    }, array_values($row));
                    $sql_dump .= "INSERT INTO `$table` VALUES (" . implode(', ', $values) . ");\n";
                }
                $sql_dump .= "\n";
            }
        }
        
        $sql_dump .= "SET FOREIGN_KEY_CHECKS=1;\n";
        
        file_put_contents($filename, $sql_dump);
        return $filename;
    } catch (Exception $e) {
        return false;
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งค่าระบบ - <?= h(APP_NAME) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Sarabun', sans-serif; }
        .gradient-bg { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .card-hover:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .tab-button.active { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
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
                    <a href="index.php" class="text-white hover:text-gray-200">
                        <i class="fas fa-arrow-left text-xl"></i>
                    </a>
                    <i class="fas fa-cog text-2xl"></i>
                    <h1 class="text-2xl font-bold">ตั้งค่าระบบ</h1>
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
        <!-- Page Header -->
        <div class="mb-6">
            <h2 class="text-3xl font-bold text-gray-800 mb-2">ตั้งค่าระบบ</h2>
            <p class="text-gray-600">การตั้งค่าระบบและข้อมูลบริษัท</p>
        </div>

        <!-- Tab Navigation -->
        <div class="mb-6">
            <div class="flex space-x-1 bg-gray-200 p-1 rounded-lg">
                <button onclick="showTab('general')" id="tab-general" class="tab-button active flex-1 py-2 px-4 rounded-md transition-all">
                    <i class="fas fa-cog mr-2"></i>การตั้งค่าทั่วไป
                </button>
                <button onclick="showTab('users')" id="tab-users" class="tab-button flex-1 py-2 px-4 rounded-md transition-all">
                    <i class="fas fa-users mr-2"></i>จัดการผู้ใช้
                </button>
                <button onclick="showTab('system')" id="tab-system" class="tab-button flex-1 py-2 px-4 rounded-md transition-all">
                    <i class="fas fa-server mr-2"></i>ระบบ
                </button>
                <button onclick="showTab('backup')" id="tab-backup" class="tab-button flex-1 py-2 px-4 rounded-md transition-all">
                    <i class="fas fa-database mr-2"></i>สำรองข้อมูล
                </button>
            </div>
        </div>

        <!-- General Settings Tab -->
        <div id="content-general" class="tab-content">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Company Information -->
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-xl font-semibold mb-4 text-gray-800">ข้อมูลร้าน/บริษัท</h3>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="update_settings">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">ชื่อร้าน/บริษัท</label>
                            <input type="text" name="shop_name" value="<?= h($settings['shop_name']) ?>" required
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">ที่อยู่</label>
                            <textarea name="shop_address" rows="3" required
                                      class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent"><?= h($settings['shop_address']) ?></textarea>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">เบอร์โทร</label>
                                <input type="text" name="shop_phone" value="<?= h($settings['shop_phone']) ?>" required
                                       class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">อีเมล</label>
                                <input type="email" name="shop_email" value="<?= h($settings['shop_email']) ?>"
                                       class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">เลขประจำตัวผู้เสียภาษี</label>
                            <input type="text" name="shop_tax_id" value="<?= h($settings['shop_tax_id']) ?>" 
                                   pattern="[0-9\-]{10,15}" placeholder="0-1234-56789-01-2"
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        
                        <hr class="my-6">
                        
                        <h4 class="text-lg font-medium text-gray-800 mb-4">การตั้งค่าดอกเบี้ยและค่าธรรมเนียม</h4>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">อัตราดอกเบี้ยต่อเดือน (%)</label>
                                <input type="number" name="default_interest_rate" step="0.01" min="0" max="100"
                                       value="<?= h($settings['default_interest_rate']) ?>" required
                                       class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">ระยะเวลาจำนำ (เดือน)</label>
                                <input type="number" name="default_period_months" min="1" max="12"
                                       value="<?= h($settings['default_period_months']) ?>" required
                                       class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">ค่าธรรมเนียม (บาท)</label>
                                <input type="number" name="service_fee" min="0"
                                       value="<?= h($settings['service_fee']) ?>" required
                                       class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">ค่าปรับต่อวัน (บาท)</label>
                                <input type="number" name="late_fee_per_day" min="0"
                                       value="<?= h($settings['late_fee_per_day']) ?>" required
                                       class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">จำนวนเงินขั้นต่ำ (บาท)</label>
                                <input type="number" name="min_pawn_amount" min="0"
                                       value="<?= h($settings['min_pawn_amount']) ?>" required
                                       class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">จำนวนเงินสูงสุด (บาท)</label>
                                <input type="number" name="max_pawn_amount" min="0"
                                       value="<?= h($settings['max_pawn_amount']) ?>" required
                                       class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            </div>
                        </div>
                        
                        <button type="submit" class="w-full bg-blue-500 text-white py-3 rounded-lg hover:bg-blue-600 transition-colors">
                            <i class="fas fa-save mr-2"></i>บันทึกข้อมูล
                        </button>
                    </form>
                </div>

                <!-- System Statistics -->
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-xl font-semibold mb-4 text-gray-800">สถิติระบบ</h3>
                    <div class="space-y-4">
                        <div class="flex justify-between items-center p-4 bg-blue-50 rounded-lg">
                            <div class="flex items-center">
                                <i class="fas fa-users text-blue-500 text-2xl mr-3"></i>
                                <div>
                                    <p class="text-sm text-gray-600">จำนวนลูกค้า</p>
                                    <p class="text-2xl font-bold text-blue-600"><?= number_format($stats['total_customers']) ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex justify-between items-center p-4 bg-green-50 rounded-lg">
                            <div class="flex items-center">
                                <i class="fas fa-handshake text-green-500 text-2xl mr-3"></i>
                                <div>
                                    <p class="text-sm text-gray-600">รายการจำนำที่ใช้งานอยู่</p>
                                    <p class="text-2xl font-bold text-green-600"><?= number_format($stats['active_pawns']) ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex justify-between items-center p-4 bg-yellow-50 rounded-lg">
                            <div class="flex items-center">
                                <i class="fas fa-coins text-yellow-500 text-2xl mr-3"></i>
                                <div>
                                    <p class="text-sm text-gray-600">มูลค่าจำนำรวม</p>
                                    <p class="text-2xl font-bold text-yellow-600"><?= formatCurrency($stats['total_pawn_amount']) ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex justify-between items-center p-4 bg-purple-50 rounded-lg">
                            <div class="flex items-center">
                                <i class="fas fa-users-cog text-purple-500 text-2xl mr-3"></i>
                                <div>
                                    <p class="text-sm text-gray-600">จำนวนผู้ใช้งาน</p>
                                    <p class="text-2xl font-bold text-purple-600"><?= number_format(count($users)) ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Users Management Tab -->
        <div id="content-users" class="tab-content hidden">
            <div class="bg-white rounded-xl shadow-lg p-6">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-semibold text-gray-800">จัดการผู้ใช้งาน</h3>
                    <button onclick="openUserModal()" class="bg-green-500 text-white px-4 py-2 rounded-lg hover:bg-green-600">
                        <i class="fas fa-plus mr-2"></i>เพิ่มผู้ใช้ใหม่
                    </button>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="gradient-bg text-white">
                            <tr>
                                <th class="px-6 py-3 text-left">ชื่อผู้ใช้</th>
                                <th class="px-6 py-3 text-left">ชื่อ-นามสกุล</th>
                                <th class="px-6 py-3 text-left">อีเมล</th>
                                <th class="px-6 py-3 text-left">บทบาท</th>
                                <th class="px-6 py-3 text-left">สาขา</th>
                                <th class="px-6 py-3 text-left">สถานะ</th>
                                <th class="px-6 py-3 text-center">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($users as $user_item): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4">
                                        <div class="font-medium"><?= h($user_item['username']) ?></div>
                                        <div class="text-sm text-gray-500"><?= h($user_item['phone']) ?></div>
                                    </td>
                                    <td class="px-6 py-4"><?= h($user_item['full_name']) ?></td>
                                    <td class="px-6 py-4"><?= h($user_item['email']) ?></td>
                                    <td class="px-6 py-4">
                                        <?php
                                        $role_labels = [
                                            'admin' => 'ผู้ดูแลระบบ',
                                            'manager' => 'ผู้จัดการ',
                                            'employee' => 'พนักงาน'
                                        ];
                                        $role_colors = [
                                            'admin' => 'bg-red-100 text-red-800',
                                            'manager' => 'bg-blue-100 text-blue-800',
                                            'employee' => 'bg-green-100 text-green-800'
                                        ];
                                        ?>
                                        <span class="px-2 py-1 text-xs rounded-full <?= $role_colors[$user_item['role']] ?? 'bg-gray-100 text-gray-800' ?>">
                                            <?= $role_labels[$user_item['role']] ?? h($user_item['role']) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4"><?= h($user_item['branch_name'] ?? '-') ?></td>
                                    <td class="px-6 py-4">
                                        <span class="px-2 py-1 text-xs rounded-full 
                                            <?= $user_item['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                            <?= $user_item['status'] === 'active' ? 'ใช้งาน' : 'ไม่ใช้งาน' ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <?php if ($user_item['id'] !== $user['id']): ?>
                                            <button onclick="editUser(<?= $user_item['id'] ?>)" 
                                                    class="text-blue-600 hover:text-blue-800 mr-2" title="แก้ไข">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-gray-400">ตัวเอง</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- System Information Tab -->
        <div id="content-system" class="tab-content hidden">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-xl font-semibold mb-4 text-gray-800">ข้อมูลระบบ</h3>
                    <div class="space-y-3">
                        <div class="flex justify-between py-2 border-b border-gray-100">
                            <span class="text-gray-600">เวอร์ชันระบบ:</span>
                            <span class="font-medium"><?= h(APP_VERSION ?? '1.0.0') ?></span>
                        </div>
                        <div class="flex justify-between py-2 border-b border-gray-100">
                            <span class="text-gray-600">เวอร์ชัน PHP:</span>
                            <span class="font-medium"><?= phpversion() ?></span>
                        </div>
                        <div class="flex justify-between py-2 border-b border-gray-100">
                            <span class="text-gray-600">เซิร์ฟเวอร์:</span>
                            <span class="font-medium"><?= h($_SERVER['SERVER_SOFTWARE'] ?? 'ไม่ทราบ') ?></span>
                        </div>
                        <div class="flex justify-between py-2 border-b border-gray-100">
                            <span class="text-gray-600">Timezone:</span>
                            <span class="font-medium"><?= date_default_timezone_get() ?></span>
                        </div>
                        <div class="flex justify-between py-2 border-b border-gray-100">
                            <span class="text-gray-600">เวลาปัจจุบัน:</span>
                            <span class="font-medium"><?= date('Y-m-d H:i:s') ?></span>
                        </div>
                        <div class="flex justify-between py-2 border-b border-gray-100">
                            <span class="text-gray-600">Database:</span>
                            <span class="font-medium">MySQL</span>
                        </div>
                        <div class="flex justify-between py-2">
                            <span class="text-gray-600">พื้นที่ดิสก์:</span>
                            <span class="font-medium"><?= formatBytes(disk_free_space('.')) ?> ว่าง</span>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-xl font-semibold mb-4 text-gray-800">การตั้งค่า PHP</h3>
                    <div class="space-y-3">
                        <div class="flex justify-between py-2 border-b border-gray-100">
                            <span class="text-gray-600">Memory Limit:</span>
                            <span class="font-medium"><?= ini_get('memory_limit') ?></span>
                        </div>
                        <div class="flex justify-between py-2 border-b border-gray-100">
                            <span class="text-gray-600">Max Execution Time:</span>
                            <span class="font-medium"><?= ini_get('max_execution_time') ?>s</span>
                        </div>
                        <div class="flex justify-between py-2 border-b border-gray-100">
                            <span class="text-gray-600">Upload Max Size:</span>
                            <span class="font-medium"><?= ini_get('upload_max_filesize') ?></span>
                        </div>
                        <div class="flex justify-between py-2 border-b border-gray-100">
                            <span class="text-gray-600">Post Max Size:</span>
                            <span class="font-medium"><?= ini_get('post_max_size') ?></span>
                        </div>
                        <div class="flex justify-between py-2">
                            <span class="text-gray-600">Error Reporting:</span>
                            <span class="font-medium"><?= error_reporting() ? 'เปิด' : 'ปิด' ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Backup Tab -->
        <div id="content-backup" class="tab-content hidden">
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-xl font-semibold mb-4 text-gray-800">สำรองข้อมูล</h3>
                
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="p-6 bg-blue-50 rounded-lg">
                        <div class="flex items-center mb-4">
                            <i class="fas fa-download text-blue-500 text-3xl mr-4"></i>
                            <div>
                                <h4 class="text-lg font-semibold text-blue-900">สำรองข้อมูล</h4>
                                <p class="text-sm text-blue-700">ดาวน์โหลดข้อมูลทั้งหมดในระบบ</p>
                            </div>
                        </div>
                        
                        <form method="POST" onsubmit="return confirm('คุณต้องการสำรองข้อมูลหรือไม่?')">
                            <input type="hidden" name="action" value="backup_database">
                            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                            <button type="submit" class="w-full bg-blue-500 text-white py-3 rounded-lg hover:bg-blue-600 transition-colors">
                                <i class="fas fa-download mr-2"></i>สำรองข้อมูลตอนนี้
                            </button>
                        </form>
                        
                        <div class="mt-4 text-xs text-blue-600">
                            <p><strong>หมายเหตุ:</strong> การสำรองข้อมูลจะรวมทั้งข้อมูลลูกค้า รายการจำนำ และการตั้งค่าระบบ</p>
                        </div>
                    </div>

                    <div class="p-6 bg-orange-50 rounded-lg">
                        <div class="flex items-center mb-4">
                            <i class="fas fa-upload text-orange-500 text-3xl mr-4"></i>
                            <div>
                                <h4 class="text-lg font-semibold text-orange-900">กู้คืนข้อมูล</h4>
                                <p class="text-sm text-orange-700">อัปโหลดไฟล์สำรองเพื่อกู้คืนข้อมูล</p>
                            </div>
                        </div>
                        
                        <div class="border-2 border-dashed border-orange-300 rounded-lg p-4 text-center">
                            <input type="file" id="restore-file" accept=".sql" class="hidden" onchange="handleRestoreFile(this)">
                            <button onclick="document.getElementById('restore-file').click()" 
                                    class="w-full bg-orange-500 text-white py-3 rounded-lg hover:bg-orange-600 transition-colors">
                                <i class="fas fa-upload mr-2"></i>เลือกไฟล์สำรอง
                            </button>
                        </div>
                        
                        <div class="mt-4 text-xs text-orange-600">
                            <p><strong>คำเตือน:</strong> การกู้คืนข้อมูลจะแทนที่ข้อมูลปัจจุบันทั้งหมด กรุณาสำรองข้อมูลก่อนดำเนินการ</p>
                        </div>
                    </div>
                </div>

                <!-- Backup History -->
                <div class="mt-8">
                    <h4 class="text-lg font-semibold mb-4 text-gray-800">ประวัติการสำรองข้อมูล</h4>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <p class="text-gray-600 text-center">ยังไม่มีการสำรองข้อมูลในระบบ</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div id="userModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl p-6 w-full max-w-md mx-4 max-h-96 overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold">เพิ่มผู้ใช้ใหม่</h3>
                <button onclick="closeModal('userModal')" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="add_user">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">ชื่อผู้ใช้</label>
                    <input type="text" name="username" required 
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">อีเมล</label>
                    <input type="email" name="email" required 
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">รหัสผ่าน</label>
                    <input type="password" name="password" required minlength="6"
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">ชื่อ-นามสกุล</label>
                    <input type="text" name="full_name" required 
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">เบอร์โทร</label>
                    <input type="tel" name="phone" 
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">บทบาท</label>
                    <select name="role" required 
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="">เลือกบทบาท</option>
                        <option value="admin">ผู้ดูแลระบบ</option>
                        <option value="manager">ผู้จัดการ</option>
                        <option value="employee">พนักงาน</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">สาขา</label>
                    <select name="branch_id" 
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="">เลือกสาขา</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= h($branch['id']) ?>"><?= h($branch['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="flex space-x-4">
                    <button type="button" onclick="closeModal('userModal')" 
                            class="flex-1 bg-gray-300 text-gray-700 py-2 rounded-lg hover:bg-gray-400">
                        ยกเลิก
                    </button>
                    <button type="submit" class="flex-1 gradient-bg text-white py-2 rounded-lg hover:opacity-90">
                        เพิ่มผู้ใช้
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editUserModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl p-6 w-full max-w-md mx-4 max-h-96 overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold">แก้ไขข้อมูลผู้ใช้</h3>
                <button onclick="closeModal('editUserModal')" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="user_id" id="edit_user_id">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">ชื่อ-นามสกุล</label>
                    <input type="text" name="full_name" id="edit_full_name" required 
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">อีเมล</label>
                    <input type="email" name="email" id="edit_email" required 
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">เบอร์โทร</label>
                    <input type="tel" name="phone" id="edit_phone"
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">รหัสผ่านใหม่ (เว้นว่างไว้หากไม่ต้องการเปลี่ยน)</label>
                    <input type="password" name="new_password" minlength="6"
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">บทบาท</label>
                    <select name="role" id="edit_role" required 
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="admin">ผู้ดูแลระบบ</option>
                        <option value="manager">ผู้จัดการ</option>
                        <option value="employee">พนักงาน</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">สาขา</label>
                    <select name="branch_id" id="edit_branch_id"
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="">เลือกสาขา</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= h($branch['id']) ?>"><?= h($branch['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">สถานะ</label>
                    <select name="status" id="edit_status" required 
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="active">ใช้งาน</option>
                        <option value="inactive">ไม่ใช้งาน</option>
                    </select>
                </div>
                
                <div class="flex space-x-4">
                    <button type="button" onclick="closeModal('editUserModal')" 
                            class="flex-1 bg-gray-300 text-gray-700 py-2 rounded-lg hover:bg-gray-400">
                        ยกเลิก
                    </button>
                    <button type="submit" class="flex-1 gradient-bg text-white py-2 rounded-lg hover:opacity-90">
                        บันทึก
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Tab functionality
        function showTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.add('hidden');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById('content-' + tabName).classList.remove('hidden');
            
            // Add active class to selected tab button
            document.getElementById('tab-' + tabName).classList.add('active');
            
            // Update URL hash for bookmarking
            window.location.hash = tabName;
        }

        // Check URL hash on page load
        document.addEventListener('DOMContentLoaded', function() {
            const hash = window.location.hash.substring(1);
            if (hash && ['general', 'users', 'system', 'backup', 'logs'].includes(hash)) {
                showTab(hash);
            }
        });

        // Modal functions
        function openUserModal() {
            document.getElementById('userModal').classList.remove('hidden');
            document.getElementById('userModal').classList.add('flex');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
            document.getElementById(modalId).classList.remove('flex');
        }

        // Edit user function
        async function editUser(userId) {
            try {
                const response = await fetch(`settings.php?ajax=get_user&id=${userId}`);
                const userData = await response.json();
                
                if (userData) {
                    document.getElementById('edit_user_id').value = userData.id;
                    document.getElementById('edit_full_name').value = userData.full_name;
                    document.getElementById('edit_email').value = userData.email;
                    document.getElementById('edit_phone').value = userData.phone || '';
                    document.getElementById('edit_role').value = userData.role;
                    document.getElementById('edit_branch_id').value = userData.branch_id || '';
                    document.getElementById('edit_status').value = userData.status;
                    
                    document.getElementById('editUserModal').classList.remove('hidden');
                    document.getElementById('editUserModal').classList.add('flex');
                }
            } catch (error) {
                alert('เกิดข้อผิดพลาดในการโหลดข้อมูล');
            }
        }

        // Restore file handler
        function handleRestoreFile(input) {
            const file = input.files[0];
            if (file) {
                if (!file.name.endsWith('.sql')) {
                    alert('กรุณาเลือกไฟล์ .sql เท่านั้น');
                    return;
                }
                
                if (confirm('คุณต้องการกู้คืนข้อมูลจากไฟล์นี้หรือไม่?\n\n' + file.name + '\n\nการกู้คืนจะแทนที่ข้อมูลปัจจุบันทั้งหมด')) {
                    // Create form and submit
                    const formData = new FormData();
                    formData.append('action', 'restore_database');
                    formData.append('csrf_token', '<?= generateCSRFToken() ?>');
                    formData.append('restore_file', file);
                    
                    fetch('settings.php', {
                        method: 'POST',
                        body: formData
                    }).then(response => {
                        if (response.ok) {
                            alert('กู้คืนข้อมูลเรียบร้อยแล้ว');
                            location.reload();
                        } else {
                            alert('เกิดข้อผิดพลาดในการกู้คืนข้อมูล');
                        }
                    }).catch(error => {
                        alert('เกิดข้อผิดพลาด: ' + error.message);
                    });
                }
                
                // Clear input
                input.value = '';
            }
        }

        // Format bytes function
        <?php
        function formatBytes($size, $precision = 2) {
            $units = array('B', 'KB', 'MB', 'GB', 'TB');
            for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
                $size /= 1024;
            }
            return round($size, $precision) . ' ' . $units[$i];
        }
        ?>

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('[id$="Modal"]');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                }
            });
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