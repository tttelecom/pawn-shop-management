<?php
require_once 'config/database.php';
requireLogin();

$user = getCurrentUser();
$db = getDB();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_pawn':
                if (validateCSRFToken($_POST['csrf_token'])) {
                    $customer_id = $_POST['customer_id'];
                    $pawn_amount = $_POST['pawn_amount'];
                    $interest_rate = $_POST['interest_rate'];
                    $period_months = $_POST['period_months'];
                    $pawn_date = $_POST['pawn_date'];
                    $notes = $_POST['notes'];
                    $branch_id = $user['branch_id'] ?? 1;
                    
                    // Calculate due date
                    $due_date = date('Y-m-d', strtotime($pawn_date . " +{$period_months} months"));
                    
                    // Generate transaction code
                    do {
                        $transaction_code = generateCode('P');
                        $stmt = $db->prepare("SELECT id FROM pawn_transactions WHERE transaction_code = ?");
                        $stmt->execute([$transaction_code]);
                    } while ($stmt->fetch());
                    
                    try {
                        $db->beginTransaction();
                        
                        // Insert pawn transaction
                        $stmt = $db->prepare("
                            INSERT INTO pawn_transactions (transaction_code, customer_id, branch_id, user_id, 
                                                          pawn_amount, interest_rate, period_months, pawn_date, 
                                                          due_date, notes) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $transaction_code, $customer_id, $branch_id, $user['id'],
                            $pawn_amount, $interest_rate, $period_months, $pawn_date,
                            $due_date, $notes
                        ]);
                        
                        $transaction_id = $db->lastInsertId();
                        
                        // Insert pawn items
                        if (isset($_POST['items']) && is_array($_POST['items'])) {
                            $stmt = $db->prepare("
                                INSERT INTO pawn_items (transaction_id, category_id, item_name, description, 
                                                       weight, estimated_value, condition_notes) 
                                VALUES (?, ?, ?, ?, ?, ?, ?)
                            ");
                            
                            foreach ($_POST['items'] as $item) {
                                if (!empty($item['item_name'])) {
                                    $stmt->execute([
                                        $transaction_id,
                                        $item['category_id'],
                                        $item['item_name'],
                                        $item['description'],
                                        $item['weight'],
                                        $item['estimated_value'],
                                        $item['condition_notes']
                                    ]);
                                }
                            }
                        }
                        
                        $db->commit();
                        logActivity($user['id'], 'ADD_PAWN', "Added pawn: $transaction_code");
                        setFlash('success', 'สร้างรายการจำนำเรียบร้อยแล้ว');
                    } catch (PDOException $e) {
                        $db->rollBack();
                        setFlash('error', 'เกิดข้อผิดพลาดในการสร้างรายการจำนำ');
                    }
                }
                break;
        }
        header('Location: pawns.php');
        exit;
    }
}

// Pagination and filtering
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$customer_id = $_GET['customer_id'] ?? '';

// Build where clause
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(pt.transaction_code LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ?)";
    $search_term = "%$search%";
    $params = array_merge($params, [$search_term, $search_term, $search_term]);
}

if (!empty($status_filter)) {
    if ($status_filter === 'overdue') {
        $where_conditions[] = "pt.due_date < CURDATE() AND pt.status = 'active'";
    } else {
        $where_conditions[] = "pt.status = ?";
        $params[] = $status_filter;
    }
}

if (!empty($customer_id)) {
    $where_conditions[] = "pt.customer_id = ?";
    $params[] = $customer_id;
}

$where_clause = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);

// Get total count
$count_sql = "
    SELECT COUNT(*) as total 
    FROM pawn_transactions pt 
    JOIN customers c ON pt.customer_id = c.id 
    $where_clause
";
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_records = $stmt->fetch()['total'];

// Calculate pagination
$pagination = paginate($total_records, $page, $per_page);

// Get pawn transactions
$sql = "
    SELECT pt.*, 
           c.first_name, c.last_name, c.customer_code,
           b.name as branch_name,
           u.full_name as user_name,
           CASE 
               WHEN pt.due_date < CURDATE() AND pt.status = 'active' THEN 'overdue'
               ELSE pt.status 
           END as display_status,
           DATEDIFF(pt.due_date, CURDATE()) as days_remaining
    FROM pawn_transactions pt 
    JOIN customers c ON pt.customer_id = c.id 
    LEFT JOIN branches b ON pt.branch_id = b.id
    LEFT JOIN users u ON pt.user_id = u.id
    $where_clause
    ORDER BY pt.created_at DESC 
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$pawns = $stmt->fetchAll();

// Get customers for dropdown
$stmt = $db->prepare("SELECT * FROM customers WHERE status = 'active' ORDER BY first_name, last_name");
$stmt->execute();
$customers = $stmt->fetchAll();

// Get categories for dropdown
$stmt = $db->prepare("SELECT * FROM item_categories ORDER BY name");
$stmt->execute();
$categories = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>การจำนำ - <?= h(APP_NAME) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Sarabun', sans-serif; }
        .gradient-bg { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .card-hover:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .status-active { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .status-overdue { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
        .status-paid { background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); }
        .status-forfeited { background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%); }
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
                    <i class="fas fa-handshake text-2xl"></i>
                    <h1 class="text-2xl font-bold">การจำนำ</h1>
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
                <h2 class="text-3xl font-bold text-gray-800 mb-2">การจำนำ</h2>
                <p class="text-gray-600">จัดการรายการจำนำทั้งหมด (<?= number_format($total_records) ?> รายการ)</p>
            </div>
            <button onclick="openPawnModal()" class="gradient-bg text-white px-6 py-3 rounded-lg hover:opacity-90 transition-opacity">
                <i class="fas fa-plus mr-2"></i>สร้างรายการจำนำ
            </button>
        </div>

        <!-- Filter Tabs -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <div class="flex space-x-4 mb-4">
                <a href="?status=" class="px-4 py-2 rounded-lg <?= empty($status_filter) ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">
                    ทั้งหมด
                </a>
                <a href="?status=active" class="px-4 py-2 rounded-lg <?= $status_filter === 'active' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">
                    กำลังจำนำ
                </a>
                <a href="?status=overdue" class="px-4 py-2 rounded-lg <?= $status_filter === 'overdue' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">
                    เกินกำหนด
                </a>
                <a href="?status=paid" class="px-4 py-2 rounded-lg <?= $status_filter === 'paid' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">
                    ไถ่คืนแล้ว
                </a>
            </div>
            
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <input type="hidden" name="status" value="<?= h($status_filter) ?>">
                <input type="text" name="search" value="<?= h($search) ?>" 
                       placeholder="รหัสรายการ, ชื่อลูกค้า..." 
                       class="border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                
                <select name="customer_id" class="border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">ทุกลูกค้า</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= h($customer['id']) ?>" <?= $customer_id == $customer['id'] ? 'selected' : '' ?>>
                            <?= h($customer['customer_code'] . ' - ' . $customer['first_name'] . ' ' . $customer['last_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <button type="submit" class="bg-blue-500 text-white px-6 py-2 rounded-lg hover:bg-blue-600 transition-colors">
                    <i class="fas fa-search mr-2"></i>ค้นหา
                </button>
                
                <a href="pawns.php" class="bg-gray-500 text-white px-6 py-2 rounded-lg hover:bg-gray-600 transition-colors text-center">
                    <i class="fas fa-refresh mr-2"></i>รีเซ็ต
                </a>
            </form>
        </div>

        <!-- Pawns Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-6">
            <?php if (empty($pawns)): ?>
                <div class="col-span-full text-center py-8">
                    <i class="fas fa-handshake text-gray-400 text-6xl mb-4"></i>
                    <p class="text-gray-500 text-xl">ไม่พบรายการจำนำ</p>
                </div>
            <?php else: ?>
                <?php foreach ($pawns as $pawn): ?>
                    <div class="bg-white rounded-xl shadow-lg p-6 card-hover transition-all duration-300">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h3 class="font-bold text-lg"><?= h($pawn['transaction_code']) ?></h3>
                                <p class="text-gray-600"><?= h($pawn['first_name'] . ' ' . $pawn['last_name']) ?></p>
                            </div>
                            <?php
                            $status_class = '';
                            $status_text = '';
                            switch($pawn['display_status']) {
                                case 'active':
                                    $status_class = 'status-active';
                                    $status_text = 'กำลังจำนำ';
                                    break;
                                case 'overdue':
                                    $status_class = 'status-overdue';
                                    $status_text = 'เกินกำหนด';
                                    break;
                                case 'paid':
                                    $status_class = 'status-paid';
                                    $status_text = 'ไถ่คืนแล้ว';
                                    break;
                                case 'forfeited':
                                    $status_class = 'status-forfeited';
                                    $status_text = 'ยึดสินค้า';
                                    break;
                                default:
                                    $status_class = 'bg-gray-500';
                                    $status_text = h($pawn['display_status']);
                            }
                            ?>
                            <span class="<?= $status_class ?> text-white px-3 py-1 rounded-full text-sm">
                                <?= $status_text ?>
                            </span>
                        </div>
                        
                        <div class="space-y-2 mb-4">
                            <div class="flex justify-between">
                                <span class="text-gray-600">จำนวนเงิน:</span>
                                <span class="font-medium text-green-600"><?= formatCurrency($pawn['pawn_amount']) ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">อัตราดอกเบี้ย:</span>
                                <span class="font-medium"><?= h($pawn['interest_rate']) ?>%/เดือน</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">วันที่ครบกำหนด:</span>
                                <span class="font-medium <?= $pawn['display_status'] === 'overdue' ? 'text-red-600' : '' ?>">
                                    <?= formatThaiDate($pawn['due_date']) ?>
                                </span>
                            </div>
                            <?php if ($pawn['display_status'] === 'active'): ?>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">เหลือเวลา:</span>
                                    <span class="font-medium <?= $pawn['days_remaining'] < 7 ? 'text-red-600' : 'text-blue-600' ?>">
                                        <?= $pawn['days_remaining'] >= 0 ? $pawn['days_remaining'] . ' วัน' : 'เกิน ' . abs($pawn['days_remaining']) . ' วัน' ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="flex space-x-2">
                            <button onclick="viewPawnDetail(<?= $pawn['id'] ?>)" class="flex-1 bg-blue-500 text-white py-2 rounded-lg hover:bg-blue-600 transition-colors">
                                <i class="fas fa-eye mr-1"></i>ดูรายละเอียด
                            </button>
                            <?php if ($pawn['display_status'] === 'active' || $pawn['display_status'] === 'overdue'): ?>
                                <button onclick="openPaymentModal(<?= $pawn['id'] ?>)" class="flex-1 bg-green-500 text-white py-2 rounded-lg hover:bg-green-600 transition-colors">
                                    <i class="fas fa-money-bill mr-1"></i>ชำระ
                                </button>
                            <?php else: ?>
                                <button class="flex-1 bg-gray-400 text-white py-2 rounded-lg cursor-not-allowed" disabled>
                                    <i class="fas fa-check mr-1"></i>เสร็จสิ้น
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
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
                        <a href="?page=<?= $pagination['prev_page'] ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>&customer_id=<?= urlencode($customer_id) ?>" 
                           class="px-3 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php endif; ?>
                    
                    <?php
                    $start_page = max(1, $pagination['page'] - 2);
                    $end_page = min($pagination['total_pages'], $pagination['page'] + 2);
                    for ($i = $start_page; $i <= $end_page; $i++):
                    ?>
                        <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>&customer_id=<?= urlencode($customer_id) ?>" 
                           class="px-3 py-2 border rounded-lg <?= $i === $pagination['page'] ? 'bg-blue-500 text-white border-blue-500' : 'bg-white border-gray-300 hover:bg-gray-50' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($pagination['has_next']): ?>
                        <a href="?page=<?= $pagination['next_page'] ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>&customer_id=<?= urlencode($customer_id) ?>" 
                           class="px-3 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Add Pawn Modal -->
    <div id="pawnModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl p-6 w-full max-w-4xl mx-4 max-h-screen overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold">สร้างรายการจำนำใหม่</h3>
                <button onclick="closeModal('pawnModal')" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST" class="space-y-6">
                <input type="hidden" name="action" value="add_pawn">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                
                <!-- Customer and Basic Info -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">ลูกค้า</label>
                        <select name="customer_id" required class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <option value="">เลือกลูกค้า</option>
                            <?php foreach ($customers as $customer): ?>
                                <option value="<?= h($customer['id']) ?>">
                                    <?= h($customer['customer_code'] . ' - ' . $customer['first_name'] . ' ' . $customer['last_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">จำนวนเงินจำนำ (บาท)</label>
                        <input type="number" name="pawn_amount" step="0.01" min="0" required 
                               class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">อัตราดอกเบี้ย (%/เดือน)</label>
                        <input type="number" name="interest_rate" step="0.01" min="0" value="5.00" required 
                               class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">ระยะเวลา (เดือน)</label>
                        <input type="number" name="period_months" min="1" value="3" required 
                               class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">วันที่จำนำ</label>
                        <input type="date" name="pawn_date" value="<?= date('Y-m-d') ?>" required 
                               class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>
                
                <!-- Notes -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">หมายเหตุ</label>
                    <textarea name="notes" rows="2" 
                              class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent"></textarea>
                </div>
                
                <!-- Items Section -->
                <div>
                    <div class="flex justify-between items-center mb-4">
                        <h4 class="text-lg font-medium">รายการสินค้าที่จำนำ</h4>
                        <button type="button" onclick="addItemRow()" class="bg-green-500 text-white px-4 py-2 rounded-lg hover:bg-green-600">
                            <i class="fas fa-plus mr-2"></i>เพิ่มสินค้า
                        </button>
                    </div>
                    
                    <div id="items-container">
                        <div class="item-row bg-gray-50 p-4 rounded-lg mb-4">
                            <div class="grid grid-cols-1 md:grid-cols-6 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">หมวดหมู่</label>
                                    <select name="items[0][category_id]" required class="w-full border border-gray-300 rounded-lg px-4 py-2">
                                        <option value="">เลือกหมวดหมู่</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?= h($category['id']) ?>"><?= h($category['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">ชื่อสินค้า</label>
                                    <input type="text" name="items[0][item_name]" required 
                                           class="w-full border border-gray-300 rounded-lg px-4 py-2">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">น้ำหนัก (กรัม)</label>
                                    <input type="number" name="items[0][weight]" step="0.001" 
                                           class="w-full border border-gray-300 rounded-lg px-4 py-2">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">มูลค่าประเมิน</label>
                                    <input type="number" name="items[0][estimated_value]" step="0.01" required 
                                           class="w-full border border-gray-300 rounded-lg px-4 py-2">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">สภาพสินค้า</label>
                                    <input type="text" name="items[0][condition_notes]" 
                                           class="w-full border border-gray-300 rounded-lg px-4 py-2">
                                </div>
                                
                                <div class="flex items-end">
                                    <button type="button" onclick="removeItemRow(this)" 
                                            class="w-full bg-red-500 text-white py-2 rounded-lg hover:bg-red-600">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="mt-4">
                                <label class="block text-sm font-medium text-gray-700 mb-2">รายละเอียดเพิ่มเติม</label>
                                <textarea name="items[0][description]" rows="2" 
                                          class="w-full border border-gray-300 rounded-lg px-4 py-2"></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="flex space-x-4">
                    <button type="button" onclick="closeModal('pawnModal')" 
                            class="flex-1 bg-gray-300 text-gray-700 py-3 rounded-lg hover:bg-gray-400">
                        ยกเลิก
                    </button>
                    <button type="submit" class="flex-1 gradient-bg text-white py-3 rounded-lg hover:opacity-90">
                        บันทึกรายการจำนำ
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let itemIndex = 1;
        
        function openPawnModal() {
            document.getElementById('pawnModal').classList.remove('hidden');
            document.getElementById('pawnModal').classList.add('flex');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
            document.getElementById(modalId).classList.remove('flex');
        }

        function addItemRow() {
            const container = document.getElementById('items-container');
            const newRow = document.querySelector('.item-row').cloneNode(true);
            
            // Update input names with new index
            const inputs = newRow.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                const name = input.getAttribute('name');
                if (name) {
                    input.setAttribute('name', name.replace('[0]', `[${itemIndex}]`));
                    input.value = '';
                }
            });
            
            container.appendChild(newRow);
            itemIndex++;
        }

        function removeItemRow(button) {
            const container = document.getElementById('items-container');
            if (container.children.length > 1) {
                button.closest('.item-row').remove();
            } else {
                alert('ต้องมีรายการสินค้าอย่างน้อย 1 รายการ');
            }
        }

        function viewPawnDetail(pawnId) {
            window.location.href = `pawn_detail.php?id=${pawnId}`;
        }

        function openPaymentModal(pawnId) {
            window.location.href = `payment.php?pawn_id=${pawnId}`;
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