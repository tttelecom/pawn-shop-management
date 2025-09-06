<?php
require_once 'config/database.php';
requireLogin();

$user = getCurrentUser();
$db = getDB();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_customer':
                if (validateCSRFToken($_POST['csrf_token'])) {
                    $first_name = trim($_POST['first_name']);
                    $last_name = trim($_POST['last_name']);
                    $id_card = trim($_POST['id_card']);
                    $phone = trim($_POST['phone']);
                    $address = trim($_POST['address']);
                    $branch_id = $user['branch_id'] ?? 1;
                    
                    // Generate customer code
                    do {
                        $customer_code = generateCode('C');
                        $stmt = $db->prepare("SELECT id FROM customers WHERE customer_code = ?");
                        $stmt->execute([$customer_code]);
                    } while ($stmt->fetch());
                    
                    try {
                        $stmt = $db->prepare("
                            INSERT INTO customers (customer_code, first_name, last_name, id_card, phone, address, branch_id) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$customer_code, $first_name, $last_name, $id_card, $phone, $address, $branch_id]);
                        
                        logActivity($user['id'], 'ADD_CUSTOMER', "Added customer: $customer_code");
                        setFlash('success', 'เพิ่มข้อมูลลูกค้าเรียบร้อยแล้ว');
                    } catch (PDOException $e) {
                        if ($e->getCode() == 23000) { // Duplicate entry
                            setFlash('error', 'เลขบัตรประชาชนนี้มีอยู่ในระบบแล้ว');
                        } else {
                            setFlash('error', 'เกิดข้อผิดพลาดในการเพิ่มข้อมูล');
                        }
                    }
                }
                break;
                
            case 'edit_customer':
                if (validateCSRFToken($_POST['csrf_token'])) {
                    $customer_id = $_POST['customer_id'];
                    $first_name = trim($_POST['first_name']);
                    $last_name = trim($_POST['last_name']);
                    $phone = trim($_POST['phone']);
                    $address = trim($_POST['address']);
                    $status = $_POST['status'];
                    
                    try {
                        $stmt = $db->prepare("
                            UPDATE customers 
                            SET first_name = ?, last_name = ?, phone = ?, address = ?, status = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$first_name, $last_name, $phone, $address, $status, $customer_id]);
                        
                        logActivity($user['id'], 'EDIT_CUSTOMER', "Edited customer ID: $customer_id");
                        setFlash('success', 'แก้ไขข้อมูลลูกค้าเรียบร้อยแล้ว');
                    } catch (PDOException $e) {
                        setFlash('error', 'เกิดข้อผิดพลาดในการแก้ไขข้อมูล');
                    }
                }
                break;
        }
        header('Location: customers.php');
        exit;
    }
}

// Handle AJAX requests for customer data
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_customer' && isset($_GET['id'])) {
    $customer_id = $_GET['id'];
    $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch();
    
    header('Content-Type: application/json');
    echo json_encode($customer);
    exit;
}

// Pagination and filtering
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$search = trim($_GET['search'] ?? '');
$branch_filter = $_GET['branch'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Build where clause
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(first_name LIKE ? OR last_name LIKE ? OR customer_code LIKE ? OR id_card LIKE ? OR phone LIKE ?)";
    $search_term = "%$search%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term, $search_term]);
}

if (!empty($branch_filter)) {
    $where_conditions[] = "branch_id = ?";
    $params[] = $branch_filter;
}

if (!empty($status_filter)) {
    $where_conditions[] = "status = ?";
    $params[] = $status_filter;
}

$where_clause = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM customers $where_clause";
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_records = $stmt->fetch()['total'];

// Calculate pagination
$pagination = paginate($total_records, $page, $per_page);

// Get customers data
$sql = "
    SELECT c.*, b.name as branch_name,
           (SELECT COUNT(*) FROM pawn_transactions WHERE customer_id = c.id) as total_pawns
    FROM customers c 
    LEFT JOIN branches b ON c.branch_id = b.id 
    $where_clause
    ORDER BY c.created_at DESC 
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

// Get branches for filter
$stmt = $db->prepare("SELECT * FROM branches WHERE status = 'active' ORDER BY name");
$stmt->execute();
$branches = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการลูกค้า - <?= h(APP_NAME) ?></title>
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
                    <i class="fas fa-users text-2xl"></i>
                    <h1 class="text-2xl font-bold">จัดการลูกค้า</h1>
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
                <h2 class="text-3xl font-bold text-gray-800 mb-2">จัดการลูกค้า</h2>
                <p class="text-gray-600">ข้อมูลลูกค้าทั้งหมด (<?= number_format($total_records) ?> รายการ)</p>
            </div>
            <button onclick="openCustomerModal()" class="gradient-bg text-white px-6 py-3 rounded-lg hover:opacity-90 transition-opacity">
                <i class="fas fa-plus mr-2"></i>เพิ่มลูกค้าใหม่
            </button>
        </div>


        <!-- Search and Filter -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                <input type="text" name="search" value="<?= h($search) ?>" 
                       placeholder="ค้นหาชื่อ, รหัสลูกค้า, เลขบัตร..." 
                       class="border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                
                <select name="branch" class="border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">ทุกสาขา</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= h($branch['id']) ?>" <?= $branch_filter == $branch['id'] ? 'selected' : '' ?>>
                            <?= h($branch['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <select name="status" class="border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">ทุกสถานะ</option>
                    <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>ลูกค้าปกติ</option>
                    <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>ไม่ใช้งาน</option>
                    <option value="blacklist" <?= $status_filter === 'blacklist' ? 'selected' : '' ?>>บัญชีดำ</option>
                </select>
                
                <button type="submit" class="bg-blue-500 text-white px-6 py-2 rounded-lg hover:bg-blue-600 transition-colors">
                    <i class="fas fa-search mr-2"></i>ค้นหา
                </button>
                
                <a href="customers.php" class="bg-gray-500 text-white px-6 py-2 rounded-lg hover:bg-gray-600 transition-colors text-center">
                    <i class="fas fa-refresh mr-2"></i>รีเซ็ต
                </a>
            </form>
        </div>

        <!-- Customers Table -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <table class="w-full">
                <thead class="gradient-bg text-white">
                    <tr>
                        <th class="px-6 py-4 text-left">รหัสลูกค้า</th>
                        <th class="px-6 py-4 text-left">ชื่อ-นามสกุล</th>
                        <th class="px-6 py-4 text-left">เบอร์โทร</th>
                        <th class="px-6 py-4 text-left">รายการจำนำ</th>
                        <th class="px-6 py-4 text-left">สถานะ</th>
                        <th class="px-6 py-4 text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php if (empty($customers)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                <i class="fas fa-users text-4xl mb-2 block"></i>
                                ไม่พบข้อมูลลูกค้า
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($customers as $customer): ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4 font-medium"><?= h($customer['customer_code']) ?></td>
                                <td class="px-6 py-4">
                                    <div>
                                        <div class="font-medium"><?= h($customer['first_name'] . ' ' . $customer['last_name']) ?></div>
                                        <div class="text-sm text-gray-500">บัตร: <?= h($customer['id_card']) ?></div>
                                    </div>
                                </td>
                                <td class="px-6 py-4"><?= h($customer['phone']) ?></td>
                                <td class="px-6 py-4">
                                    <span class="font-medium"><?= number_format($customer['total_pawns']) ?> รายการ</span>
                                </td>
                                <td class="px-6 py-4">
                                    <?php
                                    $status_classes = [
                                        'active' => 'bg-green-100 text-green-800',
                                        'inactive' => 'bg-gray-100 text-gray-800',
                                        'blacklist' => 'bg-red-100 text-red-800'
                                    ];
                                    $status_labels = [
                                        'active' => 'ลูกค้าปกติ',
                                        'inactive' => 'ไม่ใช้งาน',
                                        'blacklist' => 'บัญชีดำ'
                                    ];
                                    ?>
                                    <span class="px-3 py-1 rounded-full text-sm <?= $status_classes[$customer['status']] ?? 'bg-gray-100 text-gray-800' ?>">
                                        <?= $status_labels[$customer['status']] ?? h($customer['status']) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <button onclick="editCustomer(<?= $customer['id'] ?>)" class="text-blue-600 hover:text-blue-800 mr-3" title="แก้ไข">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <a href="pawns.php?customer_id=<?= $customer['id'] ?>" class="text-green-600 hover:text-green-800 mr-3" title="ดูรายการจำนำ">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <button onclick="viewCustomerDetail(<?= $customer['id'] ?>)" class="text-purple-600 hover:text-purple-800" title="รายละเอียด">
                                        <i class="fas fa-info-circle"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($pagination['total_pages'] > 1): ?>
            <div class="mt-6 flex items-center justify-between">
                <div class="text-sm text-gray-700">
                    แสดง <?= number_format($pagination['offset'] + 1) ?> ถึง <?= number_format(min($pagination['offset'] + $pagination['per_page'], $pagination['total_records'])) ?> 
                    จาก <?= number_format($pagination['total_records']) ?> รายการ
                </div>
                <div class="flex space-x-2">
                    <?php if ($pagination['has_prev']): ?>
                        <a href="?page=<?= $pagination['prev_page'] ?>&search=<?= urlencode($search) ?>&branch=<?= urlencode($branch_filter) ?>&status=<?= urlencode($status_filter) ?>" 
                           class="px-3 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php endif; ?>
                    
                    <?php
                    $start_page = max(1, $pagination['page'] - 2);
                    $end_page = min($pagination['total_pages'], $pagination['page'] + 2);
                    for ($i = $start_page; $i <= $end_page; $i++):
                    ?>
                        <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&branch=<?= urlencode($branch_filter) ?>&status=<?= urlencode($status_filter) ?>" 
                           class="px-3 py-2 border rounded-lg <?= $i === $pagination['page'] ? 'bg-blue-500 text-white border-blue-500' : 'bg-white border-gray-300 hover:bg-gray-50' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($pagination['has_next']): ?>
                        <a href="?page=<?= $pagination['next_page'] ?>&search=<?= urlencode($search) ?>&branch=<?= urlencode($branch_filter) ?>&status=<?= urlencode($status_filter) ?>" 
                           class="px-3 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Add Customer Modal -->
    <div id="customerModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl p-6 w-full max-w-md mx-4 max-h-96 overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold">เพิ่มลูกค้าใหม่</h3>
                <button onclick="closeModal('customerModal')" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="add_customer">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">ชื่อ</label>
                        <input type="text" name="first_name" required class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">นามสกุล</label>
                        <input type="text" name="last_name" required class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">เลขบัตรประชาชน</label>
                    <input type="text" name="id_card" pattern="[0-9]{13}" maxlength="13" required 
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                           placeholder="1234567890123">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">เบอร์โทร</label>
                    <input type="tel" name="phone" required class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">ที่อยู่</label>
                    <textarea name="address" rows="3" class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent"></textarea>
                </div>
                
                <div class="flex space-x-4">
                    <button type="button" onclick="closeModal('customerModal')" class="flex-1 bg-gray-300 text-gray-700 py-2 rounded-lg hover:bg-gray-400">
                        ยกเลิก
                    </button>
                    <button type="submit" class="flex-1 gradient-bg text-white py-2 rounded-lg hover:opacity-90">
                        บันทึก
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Customer Modal -->
    <div id="editCustomerModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl p-6 w-full max-w-md mx-4 max-h-96 overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold">แก้ไขข้อมูลลูกค้า</h3>
                <button onclick="closeModal('editCustomerModal')" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="edit_customer">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="customer_id" id="edit_customer_id">
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">ชื่อ</label>
                        <input type="text" name="first_name" id="edit_first_name" required class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">นามสกุล</label>
                        <input type="text" name="last_name" id="edit_last_name" required class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">เบอร์โทร</label>
                    <input type="tel" name="phone" id="edit_phone" required class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">ที่อยู่</label>
                    <textarea name="address" id="edit_address" rows="3" class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent"></textarea>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">สถานะ</label>
                    <select name="status" id="edit_status" class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="active">ลูกค้าปกติ</option>
                        <option value="inactive">ไม่ใช้งาน</option>
                        <option value="blacklist">บัญชีดำ</option>
                    </select>
                </div>
                
                <div class="flex space-x-4">
                    <button type="button" onclick="closeModal('editCustomerModal')" class="flex-1 bg-gray-300 text-gray-700 py-2 rounded-lg hover:bg-gray-400">
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
        // Modal functions
        function openCustomerModal() {
            document.getElementById('customerModal').classList.remove('hidden');
            document.getElementById('customerModal').classList.add('flex');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
            document.getElementById(modalId).classList.remove('flex');
        }

        // Edit customer function
        async function editCustomer(customerId) {
            try {
                const response = await fetch(`customers.php?ajax=get_customer&id=${customerId}`);
                const customer = await response.json();
                
                if (customer) {
                    document.getElementById('edit_customer_id').value = customer.id;
                    document.getElementById('edit_first_name').value = customer.first_name;
                    document.getElementById('edit_last_name').value = customer.last_name;
                    document.getElementById('edit_phone').value = customer.phone;
                    document.getElementById('edit_address').value = customer.address;
                    document.getElementById('edit_status').value = customer.status;
                    
                    document.getElementById('editCustomerModal').classList.remove('hidden');
                    document.getElementById('editCustomerModal').classList.add('flex');
                }
            } catch (error) {
                alert('เกิดข้อผิดพลาดในการโหลดข้อมูล');
            }
        }

        function viewCustomerDetail(customerId) {
            window.location.href = `customer_detail.php?id=${customerId}`;
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

        // ID card input formatting
        document.querySelector('input[name="id_card"]').addEventListener('input', function(e) {
            this.value = this.value.replace(/\D/g, '');
        });
    </script>
</body>
</html>