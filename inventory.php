<?php
require_once 'config/database.php';
requireLogin();

$user = getCurrentUser();
$db = getDB();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (validateCSRFToken($_POST['csrf_token'])) {
        switch ($_POST['action']) {
            case 'add_item':
                $item_name = trim($_POST['item_name']);
                $category_id = $_POST['category_id'];
                $description = trim($_POST['description']);
                $cost_price = floatval($_POST['cost_price']);
                $selling_price = floatval($_POST['selling_price']);
                $weight = $_POST['weight'] ? floatval($_POST['weight']) : null;
                $branch_id = $user['branch_id'] ?? 1;
                
                // Generate item code
                do {
                    $item_code = generateCode('I');
                    $stmt = $db->prepare("SELECT id FROM inventory WHERE item_code = ?");
                    $stmt->execute([$item_code]);
                } while ($stmt->fetch());
                
                try {
                    $stmt = $db->prepare("
                        INSERT INTO inventory (item_code, item_name, category_id, description, 
                                             cost_price, selling_price, weight, branch_id) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$item_code, $item_name, $category_id, $description, 
                                   $cost_price, $selling_price, $weight, $branch_id]);
                    
                    logActivity($user['id'], 'ADD_INVENTORY', "Added inventory item: $item_code");
                    setFlash('success', 'เพิ่มสินค้าเรียบร้อยแล้ว');
                } catch (PDOException $e) {
                    setFlash('error', 'เกิดข้อผิดพลาดในการเพิ่มสินค้า');
                }
                break;
                
            case 'edit_item':
                $item_id = $_POST['item_id'];
                $item_name = trim($_POST['item_name']);
                $category_id = $_POST['category_id'];
                $description = trim($_POST['description']);
                $cost_price = floatval($_POST['cost_price']);
                $selling_price = floatval($_POST['selling_price']);
                $weight = $_POST['weight'] ? floatval($_POST['weight']) : null;
                $status = $_POST['status'];
                
                try {
                    $stmt = $db->prepare("
                        UPDATE inventory 
                        SET item_name = ?, category_id = ?, description = ?, 
                            cost_price = ?, selling_price = ?, weight = ?, status = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$item_name, $category_id, $description, 
                                   $cost_price, $selling_price, $weight, $status, $item_id]);
                    
                    logActivity($user['id'], 'EDIT_INVENTORY', "Edited inventory item ID: $item_id");
                    setFlash('success', 'แก้ไขข้อมูลสินค้าเรียบร้อยแล้ว');
                } catch (PDOException $e) {
                    setFlash('error', 'เกิดข้อผิดพลาดในการแก้ไขข้อมูล');
                }
                break;
                
            case 'sell_item':
                $item_id = $_POST['item_id'];
                $customer_id = $_POST['customer_id'] ?: null;
                $sale_price = floatval($_POST['sale_price']);
                $payment_method = $_POST['payment_method'];
                $notes = trim($_POST['notes']);
                $sale_date = $_POST['sale_date'];
                $branch_id = $user['branch_id'] ?? 1;
                
                // Generate sale code
                do {
                    $sale_code = generateCode('S');
                    $stmt = $db->prepare("SELECT id FROM sales WHERE sale_code = ?");
                    $stmt->execute([$sale_code]);
                } while ($stmt->fetch());
                
                try {
                    $db->beginTransaction();
                    
                    // Insert sale record
                    $stmt = $db->prepare("
                        INSERT INTO sales (sale_code, inventory_id, customer_id, branch_id, user_id, 
                                         sale_price, sale_date, payment_method, notes) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$sale_code, $item_id, $customer_id, $branch_id, $user['id'],
                                   $sale_price, $sale_date, $payment_method, $notes]);
                    
                    // Update inventory status
                    $stmt = $db->prepare("UPDATE inventory SET status = 'sold' WHERE id = ?");
                    $stmt->execute([$item_id]);
                    
                    $db->commit();
                    
                    logActivity($user['id'], 'SELL_ITEM', "Sold item: $sale_code");
                    setFlash('success', 'บันทึกการขายเรียบร้อยแล้ว');
                } catch (PDOException $e) {
                    $db->rollBack();
                    setFlash('error', 'เกิดข้อผิดพลาดในการบันทึกการขาย');
                }
                break;
                
            case 'forfeit_to_inventory':
                $transaction_id = $_POST['transaction_id'];
                
                try {
                    $db->beginTransaction();
                    
                    // Get pawn transaction details
                    $stmt = $db->prepare("
                        SELECT pt.*, pi.* 
                        FROM pawn_transactions pt
                        JOIN pawn_items pi ON pt.id = pi.transaction_id
                        WHERE pt.id = ?
                    ");
                    $stmt->execute([$transaction_id]);
                    $pawn_items = $stmt->fetchAll();
                    
                    foreach ($pawn_items as $item) {
                        // Generate item code
                        do {
                            $item_code = generateCode('I');
                            $stmt = $db->prepare("SELECT id FROM inventory WHERE item_code = ?");
                            $stmt->execute([$item_code]);
                        } while ($stmt->fetch());
                        
                        // Calculate cost price (pawn amount divided by number of items)
                        $cost_price = $item['pawn_amount'] / count($pawn_items);
                        $selling_price = $cost_price * 1.3; // 30% markup
                        
                        // Insert to inventory
                        $stmt = $db->prepare("
                            INSERT INTO inventory (item_code, transaction_id, pawn_item_id, item_name, 
                                                 category_id, description, cost_price, selling_price, 
                                                 weight, branch_id) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $item_code, $transaction_id, $item['id'], $item['item_name'],
                            $item['category_id'], $item['description'], $cost_price, $selling_price,
                            $item['weight'], $item['branch_id']
                        ]);
                    }
                    
                    // Update pawn transaction status
                    $stmt = $db->prepare("UPDATE pawn_transactions SET status = 'forfeited' WHERE id = ?");
                    $stmt->execute([$transaction_id]);
                    
                    $db->commit();
                    
                    logActivity($user['id'], 'FORFEIT_TO_INVENTORY', "Forfeited pawn transaction: $transaction_id");
                    setFlash('success', 'ยึดสินค้าเข้าสต็อกเรียบร้อยแล้ว');
                } catch (PDOException $e) {
                    $db->rollBack();
                    setFlash('error', 'เกิดข้อผิดพลาดในการยึดสินค้า');
                }
                break;
        }
        header('Location: inventory.php');
        exit;
    }
}

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    switch ($_GET['ajax']) {
        case 'get_item':
            if (isset($_GET['id'])) {
                $stmt = $db->prepare("SELECT * FROM inventory WHERE id = ?");
                $stmt->execute([$_GET['id']]);
                $item = $stmt->fetch();
                header('Content-Type: application/json');
                echo json_encode($item);
            }
            exit;
    }
}

// Pagination and filtering
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$search = trim($_GET['search'] ?? '');
$category_filter = $_GET['category'] ?? '';
$status_filter = $_GET['status'] ?? '';
$branch_filter = $_GET['branch'] ?? '';

// Build where clause
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(i.item_code LIKE ? OR i.item_name LIKE ? OR i.description LIKE ?)";
    $search_term = "%$search%";
    $params = array_merge($params, [$search_term, $search_term, $search_term]);
}

if (!empty($category_filter)) {
    $where_conditions[] = "i.category_id = ?";
    $params[] = $category_filter;
}

if (!empty($status_filter)) {
    $where_conditions[] = "i.status = ?";
    $params[] = $status_filter;
}

if (!empty($branch_filter)) {
    $where_conditions[] = "i.branch_id = ?";
    $params[] = $branch_filter;
}

$where_clause = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);

// Get total count
$count_sql = "
    SELECT COUNT(*) as total 
    FROM inventory i 
    LEFT JOIN item_categories ic ON i.category_id = ic.id 
    LEFT JOIN branches b ON i.branch_id = b.id 
    $where_clause
";
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_records = $stmt->fetch()['total'];

// Calculate pagination
$pagination = paginate($total_records, $page, $per_page);

// Get inventory data
$sql = "
    SELECT i.*, ic.name as category_name, b.name as branch_name,
           DATEDIFF(CURDATE(), i.created_at) as days_in_stock
    FROM inventory i 
    LEFT JOIN item_categories ic ON i.category_id = ic.id 
    LEFT JOIN branches b ON i.branch_id = b.id 
    $where_clause
    ORDER BY i.created_at DESC 
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$inventory_items = $stmt->fetchAll();

// Get categories for filter
$stmt = $db->prepare("SELECT * FROM item_categories ORDER BY name");
$stmt->execute();
$categories = $stmt->fetchAll();

// Get branches for filter
$stmt = $db->prepare("SELECT * FROM branches WHERE status = 'active' ORDER BY name");
$stmt->execute();
$branches = $stmt->fetchAll();

// Get customers for sale dropdown
$stmt = $db->prepare("SELECT * FROM customers WHERE status = 'active' ORDER BY first_name, last_name");
$stmt->execute();
$customers = $stmt->fetchAll();

// Get statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_items,
        COUNT(CASE WHEN status = 'available' THEN 1 END) as available_items,
        COUNT(CASE WHEN status = 'sold' THEN 1 END) as sold_items,
        SUM(CASE WHEN status = 'available' THEN cost_price ELSE 0 END) as total_cost_value,
        SUM(CASE WHEN status = 'available' THEN selling_price ELSE 0 END) as total_selling_value
    FROM inventory
");
$stmt->execute();
$stats = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการสินค้าคงคลัง - <?= h(APP_NAME) ?></title>
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
                    <i class="fas fa-boxes text-2xl"></i>
                    <h1 class="text-2xl font-bold">จัดการสินค้าคงคลัง</h1>
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
                <h2 class="text-3xl font-bold text-gray-800 mb-2">จัดการสินค้าคงคลัง</h2>
                <p class="text-gray-600">สินค้าคงคลังทั้งหมด (<?= number_format($total_records) ?> รายการ)</p>
            </div>
            <button onclick="openItemModal()" class="gradient-bg text-white px-6 py-3 rounded-lg hover:opacity-90 transition-opacity">
                <i class="fas fa-plus mr-2"></i>เพิ่มสินค้า
            </button>
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
            <div class="bg-white rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">สินค้าทั้งหมด</p>
                        <p class="text-2xl font-bold text-gray-800"><?= number_format($stats['total_items']) ?></p>
                    </div>
                    <i class="fas fa-boxes text-blue-500 text-2xl"></i>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">พร้อมขาย</p>
                        <p class="text-2xl font-bold text-green-600"><?= number_format($stats['available_items']) ?></p>
                    </div>
                    <i class="fas fa-shopping-cart text-green-500 text-2xl"></i>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">ขายแล้ว</p>
                        <p class="text-2xl font-bold text-blue-600"><?= number_format($stats['sold_items']) ?></p>
                    </div>
                    <i class="fas fa-check-circle text-blue-500 text-2xl"></i>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">มูลค่าสต็อก</p>
                        <p class="text-2xl font-bold text-purple-600"><?= formatCurrency($stats['total_selling_value']) ?></p>
                    </div>
                    <i class="fas fa-money-bill text-purple-500 text-2xl"></i>
                </div>
            </div>
        </div>

        <!-- Search and Filter -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-6 gap-4">
                <input type="text" name="search" value="<?= h($search) ?>" 
                       placeholder="ค้นหารหัส, ชื่อสินค้า..." 
                       class="border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                
                <select name="category" class="border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">ทุกหมวดหมู่</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= h($category['id']) ?>" <?= $category_filter == $category['id'] ? 'selected' : '' ?>>
                            <?= h($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <select name="status" class="border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">ทุกสถานะ</option>
                    <option value="available" <?= $status_filter === 'available' ? 'selected' : '' ?>>พร้อมขาย</option>
                    <option value="sold" <?= $status_filter === 'sold' ? 'selected' : '' ?>>ขายแล้ว</option>
                    <option value="reserved" <?= $status_filter === 'reserved' ? 'selected' : '' ?>>จอง</option>
                </select>
                
                <select name="branch" class="border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">ทุกสาขา</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= h($branch['id']) ?>" <?= $branch_filter == $branch['id'] ? 'selected' : '' ?>>
                            <?= h($branch['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <button type="submit" class="bg-blue-500 text-white px-6 py-2 rounded-lg hover:bg-blue-600 transition-colors">
                    <i class="fas fa-search mr-2"></i>ค้นหา
                </button>
                
                <a href="inventory.php" class="bg-gray-500 text-white px-6 py-2 rounded-lg hover:bg-gray-600 transition-colors text-center">
                    <i class="fas fa-refresh mr-2"></i>รีเซ็ต
                </a>
            </form>
        </div>

        <!-- Inventory Table -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <table class="w-full">
                <thead class="gradient-bg text-white">
                    <tr>
                        <th class="px-6 py-4 text-left">รหัสสินค้า</th>
                        <th class="px-6 py-4 text-left">ชื่อสินค้า</th>
                        <th class="px-6 py-4 text-left">หมวดหมู่</th>
                        <th class="px-6 py-4 text-right">ราคาทุน</th>
                        <th class="px-6 py-4 text-right">ราคาขาย</th>
                        <th class="px-6 py-4 text-center">สถานะ</th>
                        <th class="px-6 py-4 text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php if (empty($inventory_items)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-gray-500">
                                <i class="fas fa-boxes text-4xl mb-2 block text-gray-400"></i>
                                ไม่พบสินค้าในสต็อก
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($inventory_items as $item): ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4 font-medium"><?= h($item['item_code']) ?></td>
                                <td class="px-6 py-4">
                                    <div>
                                        <p class="font-medium"><?= h($item['item_name']) ?></p>
                                        <?php if (!empty($item['description'])): ?>
                                            <p class="text-sm text-gray-500"><?= h($item['description']) ?></p>
                                        <?php endif; ?>
                                        <?php if ($item['weight']): ?>
                                            <p class="text-xs text-gray-400">น้ำหนัก: <?= number_format($item['weight'], 3) ?> กรัม</p>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4"><?= h($item['category_name']) ?></td>
                                <td class="px-6 py-4 text-right text-orange-600"><?= formatCurrency($item['cost_price']) ?></td>
                                <td class="px-6 py-4 text-right font-semibold text-green-600"><?= formatCurrency($item['selling_price']) ?></td>
                                <td class="px-6 py-4 text-center">
                                    <?php
                                    $status_classes = [
                                        'available' => 'bg-green-100 text-green-800',
                                        'sold' => 'bg-blue-100 text-blue-800',
                                        'reserved' => 'bg-yellow-100 text-yellow-800'
                                    ];
                                    $status_labels = [
                                        'available' => 'พร้อมขาย',
                                        'sold' => 'ขายแล้ว',
                                        'reserved' => 'จอง'
                                    ];
                                    ?>
                                    <span class="px-2 py-1 rounded-full text-xs font-medium <?= $status_classes[$item['status']] ?? 'bg-gray-100 text-gray-800' ?>">
                                        <?= $status_labels[$item['status']] ?? h($item['status']) ?>
                                    </span>
                                    <?php if ($item['status'] === 'available' && $item['days_in_stock'] > 90): ?>
                                        <p class="text-xs text-red-500 mt-1">นานกว่า 90 วัน</p>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <div class="flex justify-center space-x-2">
                                        <button onclick="editItem(<?= $item['id'] ?>)" class="text-blue-600 hover:text-blue-800" title="แก้ไข">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if ($item['status'] === 'available'): ?>
                                            <button onclick="sellItem(<?= $item['id'] ?>)" class="text-green-600 hover:text-green-800" title="ขาย">
                                                <i class="fas fa-shopping-cart"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button onclick="viewItemDetail(<?= $item['id'] ?>)" class="text-purple-600 hover:text-purple-800" title="รายละเอียด">
                                            <i class="fas fa-info-circle"></i>
                                        </button>
                                    </div>
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
                        <a href="?page=<?= $pagination['prev_page'] ?>&search=<?= urlencode($search) ?>&category=<?= urlencode($category_filter) ?>&status=<?= urlencode($status_filter) ?>&branch=<?= urlencode($branch_filter) ?>" 
                           class="px-3 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php endif; ?>
                    
                    <?php
                    $start_page = max(1, $pagination['page'] - 2);
                    $end_page = min($pagination['total_pages'], $pagination['page'] + 2);
                    for ($i = $start_page; $i <= $end_page; $i++):
                    ?>
                        <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&category=<?= urlencode($category_filter) ?>&status=<?= urlencode($status_filter) ?>&branch=<?= urlencode($branch_filter) ?>" 
                           class="px-3 py-2 border rounded-lg <?= $i === $pagination['page'] ? 'bg-blue-500 text-white border-blue-500' : 'bg-white border-gray-300 hover:bg-gray-50' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($pagination['has_next']): ?>
                        <a href="?page=<?= $pagination['next_page'] ?>&search=<?= urlencode($search) ?>&category=<?= urlencode($category_filter) ?>&status=<?= urlencode($status_filter) ?>&branch=<?= urlencode($branch_filter) ?>" 
                           class="px-3 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Add Item Modal -->
    <div id="itemModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl p-6 w-full max-w-md mx-4 max-h-96 overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold">เพิ่มสินค้าใหม่</h3>
                <button onclick="closeModal('itemModal')" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="add_item">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">ชื่อสินค้า</label>
                    <input type="text" name="item_name" required 
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">หมวดหมู่</label>
                    <select name="category_id" required 
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="">เลือกหมวดหมู่</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= h($category['id']) ?>"><?= h($category['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">รายละเอียด</label>
                    <textarea name="description" rows="3" 
                              class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent"></textarea>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">ราคาทุน</label>
                        <input type="number" name="cost_price" step="0.01" min="0" required 
                               class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">ราคาขาย</label>
                        <input type="number" name="selling_price" step="0.01" min="0" required 
                               class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">น้ำหนัก (กรัม)</label>
                    <input type="number" name="weight" step="0.001" min="0" 
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div class="flex space-x-4">
                    <button type="button" onclick="closeModal('itemModal')" 
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

    <!-- Edit Item Modal -->
    <div id="editItemModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl p-6 w-full max-w-md mx-4 max-h-96 overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold">แก้ไขสินค้า</h3>
                <button onclick="closeModal('editItemModal')" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="edit_item">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="item_id" id="edit_item_id">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">ชื่อสินค้า</label>
                    <input type="text" name="item_name" id="edit_item_name" required 
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">หมวดหมู่</label>
                    <select name="category_id" id="edit_category_id" required 
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="">เลือกหมวดหมู่</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= h($category['id']) ?>"><?= h($category['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">รายละเอียด</label>
                    <textarea name="description" id="edit_description" rows="3" 
                              class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent"></textarea>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">ราคาทุน</label>
                        <input type="number" name="cost_price" id="edit_cost_price" step="0.01" min="0" required 
                               class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">ราคาขาย</label>
                        <input type="number" name="selling_price" id="edit_selling_price" step="0.01" min="0" required 
                               class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">น้ำหนัก (กรัม)</label>
                    <input type="number" name="weight" id="edit_weight" step="0.001" min="0" 
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">สถานะ</label>
                    <select name="status" id="edit_status" 
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="available">พร้อมขาย</option>
                        <option value="sold">ขายแล้ว</option>
                        <option value="reserved">จอง</option>
                    </select>
                </div>
                
                <div class="flex space-x-4">
                    <button type="button" onclick="closeModal('editItemModal')" 
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

    <!-- Sell Item Modal -->
    <div id="sellModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl p-6 w-full max-w-md mx-4 max-h-96 overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold">ขายสินค้า</h3>
                <button onclick="closeModal('sellModal')" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="sell_item">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="item_id" id="sell_item_id">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">ลูกค้า (ไม่บังคับ)</label>
                    <select name="customer_id" 
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="">ไม่ระบุลูกค้า</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= h($customer['id']) ?>">
                                <?= h($customer['customer_code'] . ' - ' . $customer['first_name'] . ' ' . $customer['last_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">ราคาขาย</label>
                    <input type="number" name="sale_price" id="sell_price" step="0.01" min="0" required 
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">วิธีการชำระเงิน</label>
                    <select name="payment_method" required 
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="cash">เงินสด</option>
                        <option value="transfer">โอนเงิน</option>
                        <option value="card">บัตรเครดิต</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">วันที่ขาย</label>
                    <input type="date" name="sale_date" value="<?= date('Y-m-d') ?>" required 
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">หมายเหตุ</label>
                    <textarea name="notes" rows="3" 
                              class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent"></textarea>
                </div>
                
                <div class="flex space-x-4">
                    <button type="button" onclick="closeModal('sellModal')" 
                            class="flex-1 bg-gray-300 text-gray-700 py-2 rounded-lg hover:bg-gray-400">
                        ยกเลิก
                    </button>
                    <button type="submit" class="flex-1 bg-green-500 text-white py-2 rounded-lg hover:bg-green-600">
                        บันทึกการขาย
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openItemModal() {
            document.getElementById('itemModal').classList.remove('hidden');
            document.getElementById('itemModal').classList.add('flex');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
            document.getElementById(modalId).classList.remove('flex');
        }

        // Edit item function
        async function editItem(itemId) {
            try {
                const response = await fetch(`inventory.php?ajax=get_item&id=${itemId}`);
                const item = await response.json();
                
                if (item) {
                    document.getElementById('edit_item_id').value = item.id;
                    document.getElementById('edit_item_name').value = item.item_name;
                    document.getElementById('edit_category_id').value = item.category_id;
                    document.getElementById('edit_description').value = item.description || '';
                    document.getElementById('edit_cost_price').value = item.cost_price;
                    document.getElementById('edit_selling_price').value = item.selling_price;
                    document.getElementById('edit_weight').value = item.weight || '';
                    document.getElementById('edit_status').value = item.status;
                    
                    document.getElementById('editItemModal').classList.remove('hidden');
                    document.getElementById('editItemModal').classList.add('flex');
                }
            } catch (error) {
                alert('เกิดข้อผิดพลาดในการโหลดข้อมูล');
            }
        }

        // Sell item function
        async function sellItem(itemId) {
            try {
                const response = await fetch(`inventory.php?ajax=get_item&id=${itemId}`);
                const item = await response.json();
                
                if (item) {
                    document.getElementById('sell_item_id').value = item.id;
                    document.getElementById('sell_price').value = item.selling_price;
                    
                    document.getElementById('sellModal').classList.remove('hidden');
                    document.getElementById('sellModal').classList.add('flex');
                }
            } catch (error) {
                alert('เกิดข้อผิดพลาดในการโหลดข้อมูล');
            }
        }

        function viewItemDetail(itemId) {
            // This could open a detailed view or redirect to a detail page
            alert('ฟีเจอร์นี้กำลังพัฒนา');
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

        // Auto-calculate selling price (cost + 30% markup)
        document.querySelector('input[name="cost_price"]').addEventListener('input', function() {
            const costPrice = parseFloat(this.value) || 0;
            const sellingPrice = costPrice * 1.3;
            const sellingPriceField = document.querySelector('input[name="selling_price"]');
            if (sellingPriceField && !sellingPriceField.value) {
                sellingPriceField.value = sellingPrice.toFixed(2);
            }
        });
    </script>
</body>
</html>