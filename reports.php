<?php
require_once 'config/database.php';
requireLogin();

$user = getCurrentUser();
$db = getDB();

$report_type = $_GET['type'] ?? 'overview';
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // Today
$branch_id = $_GET['branch_id'] ?? '';

// Get branches for filter
$stmt = $db->prepare("SELECT * FROM branches WHERE status = 'active' ORDER BY name");
$stmt->execute();
$branches = $stmt->fetchAll();

// Build where clause for branch filter
$branch_where = '';
$branch_params = [];
if (!empty($branch_id)) {
    $branch_where = ' AND pt.branch_id = ?';
    $branch_params = [$branch_id];
}

$report_data = [];

switch ($report_type) {
    case 'overview':
        // Daily revenue report
        $stmt = $db->prepare("
            SELECT DATE(p.payment_date) as date,
                   SUM(p.amount) as daily_revenue,
                   COUNT(DISTINCT p.transaction_id) as transactions_count
            FROM payments p
            JOIN pawn_transactions pt ON p.transaction_id = pt.id
            WHERE p.payment_date BETWEEN ? AND ? $branch_where
            GROUP BY DATE(p.payment_date)
            ORDER BY date DESC
        ");
        $stmt->execute(array_merge([$date_from, $date_to], $branch_params));
        $report_data['daily_revenue'] = $stmt->fetchAll();
        
        // Summary statistics
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_pawns,
                SUM(pawn_amount) as total_amount,
                AVG(pawn_amount) as avg_amount
            FROM pawn_transactions pt
            WHERE pt.created_at BETWEEN ? AND ? $branch_where
        ");
        $stmt->execute(array_merge([$date_from . ' 00:00:00', $date_to . ' 23:59:59'], $branch_params));
        $report_data['summary'] = $stmt->fetch();
        
        // Payment summary
        $stmt = $db->prepare("
            SELECT 
                SUM(amount) as total_received,
                COUNT(*) as payment_count
            FROM payments p
            JOIN pawn_transactions pt ON p.transaction_id = pt.id
            WHERE p.payment_date BETWEEN ? AND ? $branch_where
        ");
        $stmt->execute(array_merge([$date_from, $date_to], $branch_params));
        $report_data['payments'] = $stmt->fetch();
        break;
        
    case 'overdue':
        // Overdue items report
        $stmt = $db->prepare("
            SELECT pt.*, c.first_name, c.last_name, c.phone,
                   DATEDIFF(CURDATE(), pt.due_date) as days_overdue,
                   (pt.pawn_amount * pt.interest_rate / 100 * CEILING(DATEDIFF(CURDATE(), pt.pawn_date) / 30)) as interest_amount
            FROM pawn_transactions pt
            JOIN customers c ON pt.customer_id = c.id
            WHERE pt.status = 'active' 
            AND pt.due_date < CURDATE() $branch_where
            ORDER BY pt.due_date ASC
        ");
        $stmt->execute($branch_params);
        $report_data['overdue'] = $stmt->fetchAll();
        break;
        
    case 'expiring':
        // Items expiring within 7 days
        $stmt = $db->prepare("
            SELECT pt.*, c.first_name, c.last_name, c.phone,
                   DATEDIFF(pt.due_date, CURDATE()) as days_remaining
            FROM pawn_transactions pt
            JOIN customers c ON pt.customer_id = c.id
            WHERE pt.status = 'active' 
            AND pt.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) $branch_where
            ORDER BY pt.due_date ASC
        ");
        $stmt->execute($branch_params);
        $report_data['expiring'] = $stmt->fetchAll();
        break;
        
    case 'inventory':
        // Inventory report
        $stmt = $db->prepare("
            SELECT i.*, ic.name as category_name, b.name as branch_name,
                   DATEDIFF(CURDATE(), i.created_at) as days_in_stock
            FROM inventory i
            LEFT JOIN item_categories ic ON i.category_id = ic.id
            LEFT JOIN branches b ON i.branch_id = b.id
            WHERE i.status = 'available' $branch_where
            ORDER BY i.created_at DESC
        ");
        $params = $branch_id ? [$branch_id] : [];
        $stmt->execute($params);
        $report_data['inventory'] = $stmt->fetchAll();
        break;
        
    case 'customer':
        // Customer activity report
        $stmt = $db->prepare("
            SELECT c.*, 
                   COUNT(pt.id) as total_pawns,
                   SUM(pt.pawn_amount) as total_amount,
                   MAX(pt.created_at) as last_pawn_date,
                   SUM(CASE WHEN pt.status = 'active' THEN 1 ELSE 0 END) as active_pawns
            FROM customers c
            LEFT JOIN pawn_transactions pt ON c.id = pt.customer_id
            WHERE c.created_at BETWEEN ? AND ? $branch_where
            GROUP BY c.id
            ORDER BY total_amount DESC
        ");
        $where_branch = $branch_id ? ' AND c.branch_id = ?' : '';
        $stmt = $db->prepare("
            SELECT c.*, 
                   COUNT(pt.id) as total_pawns,
                   SUM(pt.pawn_amount) as total_amount,
                   MAX(pt.created_at) as last_pawn_date,
                   SUM(CASE WHEN pt.status = 'active' THEN 1 ELSE 0 END) as active_pawns
            FROM customers c
            LEFT JOIN pawn_transactions pt ON c.id = pt.customer_id
            WHERE c.created_at BETWEEN ? AND ? $where_branch
            GROUP BY c.id
            ORDER BY total_amount DESC
        ");
        $params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];
        if ($branch_id) $params[] = $branch_id;
        $stmt->execute($params);
        $report_data['customers'] = $stmt->fetchAll();
        break;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายงาน - <?= h(APP_NAME) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Sarabun', sans-serif; }
        .gradient-bg { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .card-hover:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Header -->
    <header class="gradient-bg text-white shadow-lg no-print">
        <div class="container mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <a href="index.php" class="text-white hover:text-gray-200">
                        <i class="fas fa-arrow-left text-xl"></i>
                    </a>
                    <i class="fas fa-chart-bar text-2xl"></i>
                    <h1 class="text-2xl font-bold">รายงาน</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <button onclick="window.print()" class="bg-white bg-opacity-20 hover:bg-opacity-30 px-4 py-2 rounded-lg transition-all">
                        <i class="fas fa-print mr-2"></i>พิมพ์รายงาน
                    </button>
                    <span class="text-sm opacity-90"><?= h($user['full_name']) ?></span>
                    <a href="logout.php" class="text-white hover:text-gray-200">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="container mx-auto px-6 py-8">
        <!-- Report Navigation -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6 no-print">
            <div class="flex flex-wrap gap-4 mb-6">
                <a href="?type=overview&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&branch_id=<?= $branch_id ?>" 
                   class="px-4 py-2 rounded-lg <?= $report_type === 'overview' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">
                    <i class="fas fa-chart-line mr-2"></i>ภาพรวม
                </a>
                <a href="?type=overdue&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&branch_id=<?= $branch_id ?>" 
                   class="px-4 py-2 rounded-lg <?= $report_type === 'overdue' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">
                    <i class="fas fa-exclamation-triangle mr-2"></i>เกินกำหนด
                </a>
                <a href="?type=expiring&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&branch_id=<?= $branch_id ?>" 
                   class="px-4 py-2 rounded-lg <?= $report_type === 'expiring' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">
                    <i class="fas fa-clock mr-2"></i>ใกล้ครบกำหนด
                </a>
                <a href="?type=inventory&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&branch_id=<?= $branch_id ?>" 
                   class="px-4 py-2 rounded-lg <?= $report_type === 'inventory' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">
                    <i class="fas fa-boxes mr-2"></i>สินค้าคงคลัง
                </a>
                <a href="?type=customer&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&branch_id=<?= $branch_id ?>" 
                   class="px-4 py-2 rounded-lg <?= $report_type === 'customer' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">
                    <i class="fas fa-users mr-2"></i>ลูกค้า
                </a>
            </div>

            <!-- Filters -->
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <input type="hidden" name="type" value="<?= h($report_type) ?>">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">จากวันที่</label>
                    <input type="date" name="date_from" value="<?= h($date_from) ?>" 
                           class="w-full border border-gray-300 rounded-lg px-4 py-2">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">ถึงวันที่</label>
                    <input type="date" name="date_to" value="<?= h($date_to) ?>" 
                           class="w-full border border-gray-300 rounded-lg px-4 py-2">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">สาขา</label>
                    <select name="branch_id" class="w-full border border-gray-300 rounded-lg px-4 py-2">
                        <option value="">ทุกสาขา</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= h($branch['id']) ?>" <?= $branch_id == $branch['id'] ? 'selected' : '' ?>>
                                <?= h($branch['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="flex items-end">
                    <button type="submit" class="w-full bg-blue-500 text-white py-2 rounded-lg hover:bg-blue-600">
                        <i class="fas fa-search mr-2"></i>ค้นหา
                    </button>
                </div>
            </form>
        </div>

        <!-- Report Header -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <div class="text-center">
                <h2 class="text-2xl font-bold text-gray-800 mb-2">
                    <?php
                    $report_titles = [
                        'overview' => 'รายงานภาพรวม',
                        'overdue' => 'รายงานรายการเกินกำหนด',
                        'expiring' => 'รายงานรายการใกล้ครบกำหนด',
                        'inventory' => 'รายงานสินค้าคงคลัง',
                        'customer' => 'รายงานลูกค้า'
                    ];
                    echo $report_titles[$report_type] ?? 'รายงาน';
                    ?>
                </h2>
                <p class="text-gray-600">
                    ช่วงเวลา: <?= formatThaiDate($date_from) ?> ถึง <?= formatThaiDate($date_to) ?>
                    <?php if (!empty($branch_id)): ?>
                        | สาขา: <?= h(current(array_filter($branches, fn($b) => $b['id'] == $branch_id))['name'] ?? 'ไม่ระบุ') ?>
                    <?php endif; ?>
                </p>
                <p class="text-sm text-gray-500">พิมพ์เมื่อ: <?= date('d/m/Y H:i:s') ?></p>
            </div>
        </div>

        <!-- Report Content -->
        <?php switch ($report_type): 
            case 'overview': ?>
                <!-- Summary Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-gray-500 text-sm">รายการจำนำใหม่</p>
                                <p class="text-3xl font-bold text-blue-600"><?= number_format($report_data['summary']['total_pawns']) ?></p>
                            </div>
                            <i class="fas fa-handshake text-blue-500 text-3xl"></i>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-gray-500 text-sm">ยอดเงินจำนำ</p>
                                <p class="text-3xl font-bold text-green-600"><?= formatCurrency($report_data['summary']['total_amount']) ?></p>
                            </div>
                            <i class="fas fa-coins text-green-500 text-3xl"></i>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-gray-500 text-sm">รายได้รับ</p>
                                <p class="text-3xl font-bold text-purple-600"><?= formatCurrency($report_data['payments']['total_received']) ?></p>
                            </div>
                            <i class="fas fa-money-bill text-purple-500 text-3xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Daily Revenue Chart -->
                <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
                    <h3 class="text-xl font-semibold mb-4">รายได้รายวัน</h3>
                    <canvas id="revenueChart" height="100"></canvas>
                </div>

                <!-- Daily Revenue Table -->
                <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                    <table class="w-full">
                        <thead class="gradient-bg text-white">
                            <tr>
                                <th class="px-6 py-4 text-left">วันที่</th>
                                <th class="px-6 py-4 text-right">รายได้</th>
                                <th class="px-6 py-4 text-right">จำนวนรายการ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php $total_revenue = 0; $total_transactions = 0; ?>
                            <?php foreach ($report_data['daily_revenue'] as $row): ?>
                                <?php $total_revenue += $row['daily_revenue']; $total_transactions += $row['transactions_count']; ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4"><?= formatThaiDate($row['date']) ?></td>
                                    <td class="px-6 py-4 text-right font-medium text-green-600"><?= formatCurrency($row['daily_revenue']) ?></td>
                                    <td class="px-6 py-4 text-right"><?= number_format($row['transactions_count']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="bg-gray-50 font-bold">
                                <td class="px-6 py-4">รวม</td>
                                <td class="px-6 py-4 text-right text-green-600"><?= formatCurrency($total_revenue) ?></td>
                                <td class="px-6 py-4 text-right"><?= number_format($total_transactions) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <script>
                // Revenue Chart
                const ctx = document.getElementById('revenueChart').getContext('2d');
                const revenueData = <?= json_encode(array_reverse($report_data['daily_revenue'])) ?>;
                
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: revenueData.map(item => new Date(item.date).toLocaleDateString('th-TH')),
                        datasets: [{
                            label: 'รายได้รายวัน',
                            data: revenueData.map(item => item.daily_revenue),
                            borderColor: 'rgb(59, 130, 246)',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            tension: 0.1
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return new Intl.NumberFormat('th-TH').format(value);
                                    }
                                }
                            }
                        }
                    }
                });
                </script>
                
            <?php break; 
            case 'overdue': ?>
                <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-red-500 text-white">
                            <tr>
                                <th class="px-6 py-4 text-left">รหัส</th>
                                <th class="px-6 py-4 text-left">ลูกค้า</th>
                                <th class="px-6 py-4 text-left">เบอร์โทร</th>
                                <th class="px-6 py-4 text-right">จำนวนเงิน</th>
                                <th class="px-6 py-4 text-right">ดอกเบี้ย</th>
                                <th class="px-6 py-4 text-center">เกินกำหนด (วัน)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php $total_amount = 0; $total_interest = 0; ?>
                            <?php foreach ($report_data['overdue'] as $item): ?>
                                <?php $total_amount += $item['pawn_amount']; $total_interest += $item['interest_amount']; ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 font-medium"><?= h($item['transaction_code']) ?></td>
                                    <td class="px-6 py-4"><?= h($item['first_name'] . ' ' . $item['last_name']) ?></td>
                                    <td class="px-6 py-4"><?= h($item['phone']) ?></td>
                                    <td class="px-6 py-4 text-right"><?= formatCurrency($item['pawn_amount']) ?></td>
                                    <td class="px-6 py-4 text-right text-orange-600"><?= formatCurrency($item['interest_amount']) ?></td>
                                    <td class="px-6 py-4 text-center">
                                        <span class="bg-red-100 text-red-800 px-2 py-1 rounded-full text-sm">
                                            <?= number_format($item['days_overdue']) ?> วัน
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($report_data['overdue'])): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                        <i class="fas fa-check-circle text-green-500 text-4xl mb-2 block"></i>
                                        ไม่มีรายการเกินกำหนด
                                    </td>
                                </tr>
                            <?php else: ?>
                                <tr class="bg-gray-50 font-bold">
                                    <td colspan="3" class="px-6 py-4">รวม (<?= count($report_data['overdue']) ?> รายการ)</td>
                                    <td class="px-6 py-4 text-right"><?= formatCurrency($total_amount) ?></td>
                                    <td class="px-6 py-4 text-right text-orange-600"><?= formatCurrency($total_interest) ?></td>
                                    <td class="px-6 py-4"></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
            <?php break; 
            case 'expiring': ?>
                <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-yellow-500 text-white">
                            <tr>
                                <th class="px-6 py-4 text-left">รหัส</th>
                                <th class="px-6 py-4 text-left">ลูกค้า</th>
                                <th class="px-6 py-4 text-left">เบอร์โทร</th>
                                <th class="px-6 py-4 text-right">จำนวนเงิน</th>
                                <th class="px-6 py-4 text-center">วันครบกำหนด</th>
                                <th class="px-6 py-4 text-center">เหลือ (วัน)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($report_data['expiring'] as $item): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 font-medium"><?= h($item['transaction_code']) ?></td>
                                    <td class="px-6 py-4"><?= h($item['first_name'] . ' ' . $item['last_name']) ?></td>
                                    <td class="px-6 py-4"><?= h($item['phone']) ?></td>
                                    <td class="px-6 py-4 text-right"><?= formatCurrency($item['pawn_amount']) ?></td>
                                    <td class="px-6 py-4 text-center"><?= formatThaiDate($item['due_date']) ?></td>
                                    <td class="px-6 py-4 text-center">
                                        <span class="bg-yellow-100 text-yellow-800 px-2 py-1 rounded-full text-sm">
                                            <?= number_format($item['days_remaining']) ?> วัน
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($report_data['expiring'])): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                        <i class="fas fa-calendar-check text-green-500 text-4xl mb-2 block"></i>
                                        ไม่มีรายการใกล้ครบกำหนดใน 7 วันข้างหน้า
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
            <?php break; 
            case 'inventory': ?>
                <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-purple-500 text-white">
                            <tr>
                                <th class="px-6 py-4 text-left">รหัสสินค้า</th>
                                <th class="px-6 py-4 text-left">ชื่อสินค้า</th>
                                <th class="px-6 py-4 text-left">หมวดหมู่</th>
                                <th class="px-6 py-4 text-right">ราคาทุน</th>
                                <th class="px-6 py-4 text-right">ราคาขาย</th>
                                <th class="px-6 py-4 text-center">อยู่ในสต็อก (วัน)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php $total_cost = 0; $total_selling = 0; ?>
                            <?php foreach ($report_data['inventory'] as $item): ?>
                                <?php $total_cost += $item['cost_price']; $total_selling += $item['selling_price']; ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 font-medium"><?= h($item['item_code']) ?></td>
                                    <td class="px-6 py-4"><?= h($item['item_name']) ?></td>
                                    <td class="px-6 py-4"><?= h($item['category_name']) ?></td>
                                    <td class="px-6 py-4 text-right"><?= formatCurrency($item['cost_price']) ?></td>
                                    <td class="px-6 py-4 text-right text-green-600"><?= formatCurrency($item['selling_price']) ?></td>
                                    <td class="px-6 py-4 text-center">
                                        <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-sm">
                                            <?= number_format($item['days_in_stock']) ?> วัน
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($report_data['inventory'])): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                        <i class="fas fa-box-open text-gray-400 text-4xl mb-2 block"></i>
                                        ไม่มีสินค้าในสต็อก
                                    </td>
                                </tr>
                            <?php else: ?>
                                <tr class="bg-gray-50 font-bold">
                                    <td colspan="3" class="px-6 py-4">รวม (<?= count($report_data['inventory']) ?> รายการ)</td>
                                    <td class="px-6 py-4 text-right"><?= formatCurrency($total_cost) ?></td>
                                    <td class="px-6 py-4 text-right text-green-600"><?= formatCurrency($total_selling) ?></td>
                                    <td class="px-6 py-4"></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
            <?php break; 
            case 'customer': ?>
                <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-indigo-500 text-white">
                            <tr>
                                <th class="px-6 py-4 text-left">รหัสลูกค้า</th>
                                <th class="px-6 py-4 text-left">ชื่อ-นามสกุล</th>
                                <th class="px-6 py-4 text-left">เบอร์โทร</th>
                                <th class="px-6 py-4 text-right">จำนวนครั้ง</th>
                                <th class="px-6 py-4 text-right">ยอดรวม</th>
                                <th class="px-6 py-4 text-center">รายการปัจจุบัน</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php $total_customers = 0; $total_amount = 0; $total_pawns = 0; ?>
                            <?php foreach ($report_data['customers'] as $customer): ?>
                                <?php $total_customers++; $total_amount += $customer['total_amount']; $total_pawns += $customer['total_pawns']; ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 font-medium"><?= h($customer['customer_code']) ?></td>
                                    <td class="px-6 py-4"><?= h($customer['first_name'] . ' ' . $customer['last_name']) ?></td>
                                    <td class="px-6 py-4"><?= h($customer['phone']) ?></td>
                                    <td class="px-6 py-4 text-right"><?= number_format($customer['total_pawns']) ?></td>
                                    <td class="px-6 py-4 text-right text-green-600"><?= formatCurrency($customer['total_amount']) ?></td>
                                    <td class="px-6 py-4 text-center">
                                        <?php if ($customer['active_pawns'] > 0): ?>
                                            <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-sm">
                                                <?= number_format($customer['active_pawns']) ?> รายการ
                                            </span>
                                        <?php else: ?>
                                            <span class="text-gray-500">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($report_data['customers'])): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                        <i class="fas fa-users text-gray-400 text-4xl mb-2 block"></i>
                                        ไม่มีข้อมูลลูกค้าในช่วงเวลาที่เลือก
                                    </td>
                                </tr>
                            <?php else: ?>
                                <tr class="bg-gray-50 font-bold">
                                    <td colspan="3" class="px-6 py-4">รวม (<?= number_format($total_customers) ?> คน)</td>
                                    <td class="px-6 py-4 text-right"><?= number_format($total_pawns) ?></td>
                                    <td class="px-6 py-4 text-right text-green-600"><?= formatCurrency($total_amount) ?></td>
                                    <td class="px-6 py-4"></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
            <?php break; 
        endswitch; ?>
    </div>
</body>
</html>