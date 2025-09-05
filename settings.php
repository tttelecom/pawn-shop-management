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
                        'default_interest_rate' => $_POST['default_interest_rate'],
                        'default_period_months' => $_POST['default_period_months'],
                        'service_fee' => $_POST['service_fee'],
                        'company_name' => $_POST['company_name'],
                        'company_address' => $_POST['company_address'],
                        'company_phone' => $_POST['company_phone']
                    ];
                    
                    try {
                        $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
                        foreach ($settings as $key => $value) {
                            $stmt->execute([$value, $key]);
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
                    $branch_id = $_POST['branch_id'];
                    
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
            }
        }
        header('Location: settings.php');
        exit;
    }
}

// Get current settings
$stmt = $db->prepare("SELECT setting_key, setting_value FROM settings");
$stmt->execute();
$settings_raw = $stmt->fetchAll();
$settings = [];
foreach ($settings_raw as $setting) {
    $settings[$setting['setting_key']] = $setting['setting_value'];
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
        <div class="mb-6">
            <h2 class="text-3xl font-bold text-gray-800 mb-2">ตั้งค่าระบบ</h2>
            <p class="text-gray-600">การตั้งค่าระบบและข้อมูลบริษัท</p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Company Settings -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-xl font-semibold mb-4 text-gray-800">ข้อมูลบริษัท</h3>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="update_settings">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">ชื่อบริษัท</label>
                        <input type="text" name="company_name" value="<?= h($settings['company_name'] ?? '') ?>" 
                               class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">ที่อยู่</label>
                        <textarea name="company_address" rows="3" 
                                  class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent"><?= h($settings['company_address'] ?? '') ?></textarea>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">เบอร์โทร</label>
                        <input type="text" name="company_phone" value="<?= h($settings['company_phone'] ?? '') ?>" 
                               class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    
                    <h4 class="text-lg font-medium text-gray-800 mt-6 mb-4">การตั้งค่าดอกเบี้ย</h4>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">อัตราดอกเบี้ยต่อเดือน (%)</label>
                        <input type="number" name="default_interest_rate" step="0.01" 
                               value="<?= h($settings['default_interest_rate'] ?? '5.00') ?>" 
                               class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">ระยะเวลาจำนำ (เดือน)</label>
                        <input type="number" name="default_period_months" 
                               value="<?= h($settings['default_period_months'] ?? '3') ?>" 
                               class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">ค่าธรรมเนียม (บาท)</label>
                        <input type="number" name="service_fee" 
                               value="<?= h($settings['service_fee'] ?? '100') ?>" 
                               class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    
                    <button type="submit" class="w-full bg-blue-500 text-white py-2 rounded-lg hover:bg-blue-600 transition-colors">
                        <i class="fas fa-save mr-2"></i>บันทึกข้อมูล
                    </button>
                </form>
            </div>

            <!-- User Management -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-xl font-semibold text-gray-800">จัดการผู้ใช้</h3>
                    <button onclick="openUserModal()" class="bg-green-500 text-white px-4 py-2 rounded-lg hover:bg-green-600">
                        <i class="fas fa-plus mr-2"></i>เพิ่มผู้ใช้
                    </button>
                </div>
                
                <div class="space-y-4 max-h-96 overflow-y-auto">
                    <?php foreach ($users as $user_item): ?>
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                            <div>
                                <p class="font-medium"><?= h($user_item['full_name']) ?></p>
                                <p class="text-sm text-gray-500">
                                    <?= h($user_item['username']) ?> - 
                                    <?php
                                    $role_labels = [
                                        'admin' => 'ผู้ดูแลระบบ',
                                        'manager' => 'ผู้จัดการ',
                                        'employee' => 'พนักงาน'
                                    ];
                                    echo $role_labels[$user_item['role']] ?? h($user_item['role']);
                                    ?>
                                </p>
                                <?php if (!empty($user_item['branch_name'])): ?>
                                    <p class="text-xs text-gray-400">สาขา: <?= h($user_item['branch_name']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="flex space-x-2">
                                <span class="px-2 py-1 text-xs rounded-full 
                                    <?= $user_item['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= $user_item['status'] === 'active' ? 'ใช้งาน' : 'ไม่ใช้งาน' ?>
                                </span>
                                <?php if ($user_item['id'] !== $user['id']): ?>
                                    <button class="text-blue-600 hover:text-blue-800" title="แก้ไข">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- System Information -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-xl font-semibold mb-4 text-gray-800">ข้อมูลระบบ</h3>
                <div class="space-y-3">
                    <div class="flex justify-between">
                        <span class="text-gray-600">เวอร์ชันระบบ:</span>
                        <span class="font-medium"><?= h(APP_VERSION) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">เวอร์ชัน PHP:</span>
                        <span class="font-medium"><?= phpversion() ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">เซิร์ฟเวอร์:</span>
                        <span class="font-medium"><?= h($_SERVER['SERVER_SOFTWARE'] ?? 'ไม่ทราบ') ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Timezone:</span>
                        <span class="font-medium"><?= date_default_timezone_get() ?></span>
                    </div>
                </div>
            </div>

            <!-- Backup Section -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-xl font-semibold mb-4 text-gray-800">สำรองข้อมูล</h3>
                <div class="space-y-4">
                    <div class="p-4 bg-blue-50 rounded-lg">
                        <p class="text-sm text-blue-800 mb-2">การสำรองข้อมูลล่าสุด</p>
                        <p class="font-medium text-blue-900">ยังไม่มีการสำรองข้อมูล</p>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <button class="bg-blue-500 text-white py-2 rounded-lg hover:bg-blue-600">
                            <i class="fas fa-download mr-2"></i>สำรองข้อมูล
                        </button>
                        <button class="bg-gray-500 text-white py-2 rounded-lg hover:bg-gray-600">
                            <i class="fas fa-upload mr-2"></i>กู้คืนข้อมูล
                        </button>
                    </div>
                    
                    <div class="text-xs text-gray-500">
                        <p><strong>หมายเหตุ:</strong> การสำรองข้อมูลจะรวมทั้งข้อมูลลูกค้า รายการจำนำ และการตั้งค่าระบบ</p>
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

    <script>
        function openUserModal() {
            document.getElementById('userModal').classList.remove('hidden');
            document.getElementById('userModal').classList.add('flex');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
            document.getElementById(modalId).classList.remove('flex');
        }

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