<?php
require_once 'config/database.php';
requireLogin();

$user = getCurrentUser();
$db = getDB();

// Only admin and managers can access branches
if (!in_array($user['role'], ['admin', 'manager'])) {
    setFlash('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    header('Location: index.php');
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (validateCSRFToken($_POST['csrf_token'])) {
        switch ($_POST['action']) {
            case 'add_branch':
                $name = trim($_POST['name']);
                $address = trim($_POST['address']);
                $phone = trim($_POST['phone']);
                $manager_id = $_POST['manager_id'] ?: null;
                
                try {
                    $stmt = $db->prepare("
                        INSERT INTO branches (name, address, phone, manager_id) 
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([$name, $address, $phone, $manager_id]);
                    
                    logActivity($user['id'], 'ADD_BRANCH', "Added branch: $name");
                    setFlash('success', 'เพิ่มสาขาเรียบร้อยแล้ว');
                } catch (PDOException $e) {
                    setFlash('error', 'เกิดข้อผิดพลาดในการเพิ่มสาขา');
                }
                break;
                
            case 'edit_branch':
                $branch_id = $_POST['branch_id'];
                $name = trim($_POST['name']);
                $address = trim($_POST['address']);
                $phone = trim($_POST['phone']);
                $manager_id = $_POST['manager_id'] ?: null;
                $status = $_POST['status'];
                
                try {
                    $stmt = $db->prepare("
                        UPDATE branches 
                        SET name = ?, address = ?, phone = ?, manager_id = ?, status = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$name, $address, $phone, $manager_id, $status, $branch_id]);
                    
                    logActivity($user['id'], 'EDIT_BRANCH', "Edited branch ID: $branch_id");
                    setFlash('success', 'แก้ไขข้อมูลสาขาเรียบร้อยแล้ว');
                } catch (PDOException $e) {
                    setFlash('error', 'เกิดข้อผิดพลาดในการแก้ไขข้อมูล');
                }
                break;
        }
        header('Location: branches.php');
        exit;
    }
}

// Handle AJAX requests for branch data
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_branch' && isset($_GET['id'])) {
    $branch_id = $_GET['id'];
    $stmt = $db->prepare("SELECT * FROM branches WHERE id = ?");
    $stmt->execute([$branch_id]);
    $branch = $stmt->fetch();
    
    header('Content-Type: application/json');
    echo json_encode($branch);
    exit;
}

// Get branches with statistics
$stmt = $db->prepare("
    SELECT b.*, 
           u.full_name as manager_name,
           COUNT(DISTINCT pt.id) as total_pawns,
           COUNT(DISTINCT c.id) as total_customers,
           COALESCE(SUM(pt.pawn_amount), 0) as total_amount,
           COUNT(DISTINCT CASE WHEN pt.status = 'active' THEN pt.id END) as active_pawns
    FROM branches b
    LEFT JOIN users u ON b.manager_id = u.id
    LEFT JOIN pawn_transactions pt ON b.id = pt.branch_id
    LEFT JOIN customers c ON b.id = c.branch_id
    GROUP BY b.id
    ORDER BY b.created_at DESC
");
$stmt->execute();
$branches = $stmt->fetchAll();

// Get users for manager dropdown
$stmt = $db->prepare("
    SELECT id, full_name, username 
    FROM users 
    WHERE role IN ('admin', 'manager') AND status = 'active'
    ORDER BY full_name
");
$stmt->execute();
$managers = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการสาขา - <?= h(APP_NAME) ?></title>
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
                    <i class="fas fa-building text-2xl"></i>
                    <h1 class="text-2xl font-bold">จัดการสาขา</h1>
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
        <div class="mb-6 flex justify-between items-center">
            <div>
                <h2 class="text-3xl font-bold text-gray-800 mb-2">จัดการสาขา</h2>
                <p class="text-gray-600">ข้อมูลสาขาทั้งหมด (<?= count($branches) ?> สาขา)</p>
            </div>
            <?php if ($user['role'] === 'admin'): ?>
                <button onclick="openBranchModal()" class="gradient-bg text-white px-6 py-3 rounded-lg hover:opacity-90 transition-opacity">
                    <i class="fas fa-plus mr-2"></i>เพิ่มสาขาใหม่
                </button>
            <?php endif; ?>
        </div>

        <!-- Branches Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($branches as $branch): ?>
                <div class="bg-white rounded-xl shadow-lg p-6 card-hover transition-all duration-300">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-xl font-bold text-gray-800"><?= h($branch['name']) ?></h3>
                        <span class="px-3 py-1 rounded-full text-sm <?= $branch['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                            <?= $branch['status'] === 'active' ? 'เปิดทำการ' : 'ปิดทำการ' ?>
                        </span>
                    </div>
                    
                    <div class="space-y-3 mb-6">
                        <div class="flex items-start space-x-3 text-gray-600">
                            <i class="fas fa-map-marker-alt mt-1 text-red-500"></i>
                            <div class="flex-1">
                                <p class="text-sm"><?= h($branch['address']) ?></p>
                            </div>
                        </div>
                        
                        <?php if (!empty($branch['phone'])): ?>
                        <div class="flex items-center space-x-3 text-gray-600">
                            <i class="fas fa-phone text-green-500"></i>
                            <a href="tel:<?= h($branch['phone']) ?>" class="text-blue-600 hover:text-blue-800">
                                <?= h($branch['phone']) ?>
                            </a>
                        </div>
                        <?php endif; ?>
                        
                        <div class="flex items-center space-x-3 text-gray-600">
                            <i class="fas fa-user text-blue-500"></i>
                            <span>ผู้จัดการ: <?= h($branch['manager_name'] ?: 'ไม่ระบุ') ?></span>
                        </div>
                    </div>
                    
                    <!-- Statistics -->
                    <div class="grid grid-cols-2 gap-4 mb-6 pt-4 border-t border-gray-200">
                        <div class="text-center">
                            <p class="text-2xl font-bold text-blue-600"><?= number_format($branch['total_customers']) ?></p>
                            <p class="text-xs text-gray-500">ลูกค้า</p>
                        </div>
                        <div class="text-center">
                            <p class="text-2xl font-bold text-green-600"><?= number_format($branch['total_pawns']) ?></p>
                            <p class="text-xs text-gray-500">รายการจำนำ</p>
                        </div>
                        <div class="text-center">
                            <p class="text-lg font-bold text-purple-600"><?= number_format($branch['active_pawns']) ?></p>
                            <p class="text-xs text-gray-500">กำลังจำนำ</p>
                        </div>
                        <div class="text-center">
                            <p class="text-lg font-bold text-orange-600"><?= formatCurrency($branch['total_amount']) ?></p>
                            <p class="text-xs text-gray-500">ยอดรวม</p>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="flex space-x-2">
                        <?php if ($user['role'] === 'admin'): ?>
                            <button onclick="editBranch(<?= $branch['id'] ?>)" 
                                    class="flex-1 bg-blue-500 text-white py-2 rounded-lg hover:bg-blue-600 transition-colors">
                                <i class="fas fa-edit mr-1"></i>แก้ไข
                            </button>
                        <?php endif; ?>
                        
                        <button onclick="viewBranchReport(<?= $branch['id'] ?>)" 
                                class="flex-1 bg-green-500 text-white py-2 rounded-lg hover:bg-green-600 transition-colors">
                            <i class="fas fa-chart-line mr-1"></i>รายงาน
                        </button>
                    </div>
                    
                    <div class="text-xs text-gray-400 text-center mt-4">
                        สร้างเมื่อ: <?= formatThaiDate($branch['created_at']) ?>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <?php if (empty($branches)): ?>
                <div class="col-span-full text-center py-12">
                    <i class="fas fa-building text-gray-400 text-6xl mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-600 mb-2">ยังไม่มีสาขา</h3>
                    <p class="text-gray-500 mb-6">เริ่มต้นด้วยการเพิ่มสาขาแรกของคุณ</p>
                    <?php if ($user['role'] === 'admin'): ?>
                        <button onclick="openBranchModal()" class="gradient-bg text-white px-6 py-3 rounded-lg hover:opacity-90">
                            <i class="fas fa-plus mr-2"></i>เพิ่มสาขาใหม่
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Branch Modal -->
    <?php if ($user['role'] === 'admin'): ?>
    <div id="branchModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl p-6 w-full max-w-md mx-4 max-h-96 overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold">เพิ่มสาขาใหม่</h3>
                <button onclick="closeModal('branchModal')" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="add_branch">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">ชื่อสาขา</label>
                    <input type="text" name="name" required 
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">ที่อยู่</label>
                    <textarea name="address" rows="3" required 
                              class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent"></textarea>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">เบอร์โทร</label>
                    <input type="tel" name="phone" 
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">ผู้จัดการ</label>
                    <select name="manager_id" 
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="">ไม่ระบุ</option>
                        <?php foreach ($managers as $manager): ?>
                            <option value="<?= h($manager['id']) ?>"><?= h($manager['full_name']) ?> (<?= h($manager['username']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="flex space-x-4">
                    <button type="button" onclick="closeModal('branchModal')" 
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

    <!-- Edit Branch Modal -->
    <div id="editBranchModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl p-6 w-full max-w-md mx-4 max-h-96 overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold">แก้ไขข้อมูลสาขา</h3>
                <button onclick="closeModal('editBranchModal')" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="edit_branch">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="branch_id" id="edit_branch_id">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">ชื่อสาขา</label>
                    <input type="text" name="name" id="edit_name" required 
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">ที่อยู่</label>
                    <textarea name="address" id="edit_address" rows="3" required 
                              class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent"></textarea>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">เบอร์โทร</label>
                    <input type="tel" name="phone" id="edit_phone"
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">ผู้จัดการ</label>
                    <select name="manager_id" id="edit_manager_id"
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="">ไม่ระบุ</option>
                        <?php foreach ($managers as $manager): ?>
                            <option value="<?= h($manager['id']) ?>"><?= h($manager['full_name']) ?> (<?= h($manager['username']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">สถานะ</label>
                    <select name="status" id="edit_status"
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="active">เปิดทำการ</option>
                        <option value="inactive">ปิดทำการ</option>
                    </select>
                </div>
                
                <div class="flex space-x-4">
                    <button type="button" onclick="closeModal('editBranchModal')" 
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
    <?php endif; ?>

    <script>
        function openBranchModal() {
            document.getElementById('branchModal').classList.remove('hidden');
            document.getElementById('branchModal').classList.add('flex');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
            document.getElementById(modalId).classList.remove('flex');
        }

        // Edit branch function
        async function editBranch(branchId) {
            try {
                const response = await fetch(`branches.php?ajax=get_branch&id=${branchId}`);
                const branch = await response.json();
                
                if (branch) {
                    document.getElementById('edit_branch_id').value = branch.id;
                    document.getElementById('edit_name').value = branch.name;
                    document.getElementById('edit_address').value = branch.address;
                    document.getElementById('edit_phone').value = branch.phone || '';
                    document.getElementById('edit_manager_id').value = branch.manager_id || '';
                    document.getElementById('edit_status').value = branch.status;
                    
                    document.getElementById('editBranchModal').classList.remove('hidden');
                    document.getElementById('editBranchModal').classList.add('flex');
                }
            } catch (error) {
                alert('เกิดข้อผิดพลาดในการโหลดข้อมูล');
            }
        }

        function viewBranchReport(branchId) {
            window.location.href = `reports.php?type=overview&branch_id=${branchId}`;
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