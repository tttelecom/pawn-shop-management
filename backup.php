<?php
require_once 'config/database.php';
requireLogin();

$user = getCurrentUser();

// Only admin can access backup
if ($user['role'] !== 'admin') {
    setFlash('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    header('Location: index.php');
    exit;
}

$action = $_GET['action'] ?? '';

// Handle backup actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRFToken($_POST['csrf_token'])) {
    switch ($action) {
        case 'create_backup':
            $result = createDatabaseBackup();
            if ($result['success']) {
                setFlash('success', 'สำรองข้อมูลเรียบร้อยแล้ว');
                logActivity($user['id'], 'CREATE_BACKUP', 'Database backup created: ' . $result['filename']);
            } else {
                setFlash('error', 'เกิดข้อผิดพลาด: ' . $result['error']);
            }
            break;
            
        case 'restore_backup':
            if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
                $result = restoreDatabaseBackup($_FILES['backup_file']);
                if ($result['success']) {
                    setFlash('success', 'กู้คืนข้อมูลเรียบร้อยแล้ว');
                    logActivity($user['id'], 'RESTORE_BACKUP', 'Database restored from backup');
                } else {
                    setFlash('error', 'เกิดข้อผิดพลาด: ' . $result['error']);
                }
            } else {
                setFlash('error', 'กรุณาเลือกไฟล์สำรองข้อมูล');
            }
            break;
    }
    header('Location: backup.php');
    exit;
}

// Handle download backup
if ($action === 'download' && isset($_GET['file'])) {
    downloadBackupFile($_GET['file']);
    exit;
}

// Handle delete backup
if ($action === 'delete' && isset($_GET['file'])) {
    $result = deleteBackupFile($_GET['file']);
    if ($result['success']) {
        setFlash('success', 'ลบไฟล์สำรองข้อมูลเรียบร้อยแล้ว');
    } else {
        setFlash('error', 'เกิดข้อผิดพลาด: ' . $result['error']);
    }
    header('Location: backup.php');
    exit;
}

// Get backup files
$backup_files = getBackupFiles();

/**
 * Create database backup
 */
function createDatabaseBackup() {
    try {
        $backup_dir = 'backups/';
        if (!is_dir($backup_dir)) {
            mkdir($backup_dir, 0755, true);
        }
        
        $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        $filepath = $backup_dir . $filename;
        
        // Create mysqldump command
        $command = sprintf(
            'mysqldump --host=%s --user=%s --password=%s --single-transaction --routines --triggers %s > %s',
            escapeshellarg(DB_HOST),
            escapeshellarg(DB_USER),
            escapeshellarg(DB_PASS),
            escapeshellarg(DB_NAME),
            escapeshellarg($filepath)
        );
        
        // Execute backup
        $output = [];
        $return_code = 0;
        exec($command . ' 2>&1', $output, $return_code);
        
        if ($return_code === 0 && file_exists($filepath) && filesize($filepath) > 0) {
            // Add metadata to backup
            $metadata = [
                'created_at' => date('Y-m-d H:i:s'),
                'created_by' => getCurrentUser()['full_name'],
                'database_name' => DB_NAME,
                'app_version' => APP_VERSION,
                'php_version' => phpversion()
            ];
            
            $backup_content = file_get_contents($filepath);
            $backup_with_metadata = "-- Backup Metadata: " . json_encode($metadata) . "\n" . $backup_content;
            file_put_contents($filepath, $backup_with_metadata);
            
            return ['success' => true, 'filename' => $filename];
        } else {
            return ['success' => false, 'error' => 'ไม่สามารถสร้างไฟล์สำรองข้อมูลได้: ' . implode('\n', $output)];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Restore database backup
 */
function restoreDatabaseBackup($file) {
    try {
        if ($file['size'] > 50 * 1024 * 1024) { // 50MB limit
            return ['success' => false, 'error' => 'ไฟล์ใหญ่เกินไป (จำกัด 50MB)'];
        }
        
        $temp_file = tempnam(sys_get_temp_dir(), 'restore_');
        if (!move_uploaded_file($file['tmp_name'], $temp_file)) {
            return ['success' => false, 'error' => 'ไม่สามารถอัปโหลดไฟล์ได้'];
        }
        
        // Validate SQL file
        $content = file_get_contents($temp_file);
        if (strpos($content, 'mysqldump') === false && strpos($content, 'CREATE TABLE') === false) {
            unlink($temp_file);
            return ['success' => false, 'error' => 'ไฟล์ไม่ใช่ไฟล์สำรองข้อมูลที่ถูกต้อง'];
        }
        
        // Create restore command
        $command = sprintf(
            'mysql --host=%s --user=%s --password=%s %s < %s',
            escapeshellarg(DB_HOST),
            escapeshellarg(DB_USER),
            escapeshellarg(DB_PASS),
            escapeshellarg(DB_NAME),
            escapeshellarg($temp_file)
        );
        
        // Execute restore
        $output = [];
        $return_code = 0;
        exec($command . ' 2>&1', $output, $return_code);
        
        unlink($temp_file);
        
        if ($return_code === 0) {
            return ['success' => true];
        } else {
            return ['success' => false, 'error' => 'ไม่สามารถกู้คืนข้อมูลได้: ' . implode('\n', $output)];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Get backup files
 */
function getBackupFiles() {
    $backup_dir = 'backups/';
    $files = [];
    
    if (is_dir($backup_dir)) {
        $scan = scandir($backup_dir);
        foreach ($scan as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
                $filepath = $backup_dir . $file;
                $files[] = [
                    'name' => $file,
                    'size' => filesize($filepath),
                    'created' => filemtime($filepath),
                    'path' => $filepath
                ];
            }
        }
        
        // Sort by creation date (newest first)
        usort($files, function($a, $b) {
            return $b['created'] - $a['created'];
        });
    }
    
    return $files;
}

/**
 * Download backup file
 */
function downloadBackupFile($filename) {
    $filepath = 'backups/' . basename($filename);
    
    if (!file_exists($filepath)) {
        http_response_code(404);
        die('ไฟล์ไม่พบ');
    }
    
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header('Content-Length: ' . filesize($filepath));
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: 0');
    
    readfile($filepath);
}

/**
 * Delete backup file
 */
function deleteBackupFile($filename) {
    try {
        $filepath = 'backups/' . basename($filename);
        
        if (!file_exists($filepath)) {
            return ['success' => false, 'error' => 'ไฟล์ไม่พบ'];
        }
        
        if (unlink($filepath)) {
            return ['success' => true];
        } else {
            return ['success' => false, 'error' => 'ไม่สามารถลบไฟล์ได้'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Format file size
 */
function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes > 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สำรองข้อมูล - <?= h(APP_NAME) ?></title>
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
                    <a href="settings.php" class="text-white hover:text-gray-200">
                        <i class="fas fa-arrow-left text-xl"></i>
                    </a>
                    <i class="fas fa-database text-2xl"></i>
                    <h1 class="text-2xl font-bold">สำรองข้อมูล</h1>
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
            <h2 class="text-3xl font-bold text-gray-800 mb-2">จัดการสำรองข้อมูล</h2>
            <p class="text-gray-600">สำรองและกู้คืนข้อมูลระบบ</p>
        </div>

        <!-- Action Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <!-- Create Backup -->
            <div class="bg-white rounded-xl shadow-lg p-6 card-hover transition-all duration-300">
                <div class="text-center">
                    <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-download text-blue-600 text-2xl"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-800 mb-2">สำรองข้อมูล</h3>
                    <p class="text-gray-600 mb-6">สร้างไฟล์สำรองข้อมูลฐานข้อมูลทั้งหมด</p>
                    
                    <form method="POST" action="?action=create_backup" onsubmit="return confirmBackup()">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <button type="submit" class="w-full bg-blue-500 text-white py-3 px-6 rounded-lg hover:bg-blue-600 transition-colors">
                            <i class="fas fa-download mr-2"></i>สำรองข้อมูลตอนนี้
                        </button>
                    </form>
                    
                    <p class="text-xs text-gray-500 mt-4">
                        * การสำรองข้อมูลจะรวมข้อมูลทั้งหมดในระบบ
                    </p>
                </div>
            </div>

            <!-- Restore Backup -->
            <div class="bg-white rounded-xl shadow-lg p-6 card-hover transition-all duration-300">
                <div class="text-center">
                    <div class="w-16 h-16 bg-orange-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-upload text-orange-600 text-2xl"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-800 mb-2">กู้คืนข้อมูล</h3>
                    <p class="text-gray-600 mb-6">กู้คืนข้อมูลจากไฟล์สำรองข้อมูล</p>
                    
                    <form method="POST" action="?action=restore_backup" enctype="multipart/form-data" onsubmit="return confirmRestore()">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <div class="mb-4">
                            <input type="file" name="backup_file" accept=".sql" required 
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm">
                        </div>
                        <button type="submit" class="w-full bg-orange-500 text-white py-3 px-6 rounded-lg hover:bg-orange-600 transition-colors">
                            <i class="fas fa-upload mr-2"></i>กู้คืนข้อมูล
                        </button>
                    </form>
                    
                    <p class="text-xs text-red-500 mt-4">
                        ⚠️ การกู้คืนจะแทนที่ข้อมูลปัจจุบันทั้งหมด
                    </p>
                </div>
            </div>
        </div>

        <!-- Backup Files List -->
        <div class="bg-white rounded-xl shadow-lg">
            <div class="p-6 border-b">
                <h3 class="text-xl font-semibold text-gray-800">ไฟล์สำรองข้อมูล</h3>
                <p class="text-gray-600">รายการไฟล์สำรองข้อมูลที่มีอยู่</p>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="gradient-bg text-white">
                        <tr>
                            <th class="px-6 py-4 text-left">ชื่อไฟล์</th>
                            <th class="px-6 py-4 text-left">ขนาด</th>
                            <th class="px-6 py-4 text-left">วันที่สร้าง</th>
                            <th class="px-6 py-4 text-center">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php if (empty($backup_files)): ?>
                            <tr>
                                <td colspan="4" class="px-6 py-8 text-center text-gray-500">
                                    <i class="fas fa-database text-4xl mb-2 block text-gray-400"></i>
                                    ไม่มีไฟล์สำรองข้อมูล
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($backup_files as $file): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center">
                                            <i class="fas fa-file-code text-blue-500 mr-3"></i>
                                            <span class="font-medium"><?= h($file['name']) ?></span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-gray-600">
                                        <?= formatFileSize($file['size']) ?>
                                    </td>
                                    <td class="px-6 py-4 text-gray-600">
                                        <?= date('d/m/Y H:i:s', $file['created']) ?>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <div class="flex justify-center space-x-2">
                                            <a href="?action=download&file=<?= urlencode($file['name']) ?>" 
                                               class="bg-blue-500 text-white px-3 py-1 rounded text-sm hover:bg-blue-600" 
                                               title="ดาวน์โหลด">
                                                <i class="fas fa-download"></i>
                                            </a>
                                            <button onclick="deleteBackup('<?= h($file['name']) ?>')" 
                                                    class="bg-red-500 text-white px-3 py-1 rounded text-sm hover:bg-red-600" 
                                                    title="ลบ">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Important Notes -->
        <div class="mt-8 bg-yellow-50 border border-yellow-200 rounded-xl p-6">
            <h4 class="text-lg font-semibold text-yellow-800 mb-3">
                <i class="fas fa-exclamation-triangle mr-2"></i>ข้อควรระวัง
            </h4>
            <ul class="text-yellow-700 space-y-2 text-sm">
                <li>• สำรองข้อมูลเป็นประจำอย่างน้อยสัปดาหล์ละ 1 ครั้ง</li>
                <li>• เก็บไฟล์สำรองข้อมูลไว้ในที่ปลอดภัยและแยกจากเซิร์ฟเวอร์หลัก</li>
                <li>• ทดสอบการกู้คืนข้อมูลเป็นประจำเพื่อให้แน่ใจว่าไฟล์สำรองใช้งานได้</li>
                <li>• การกู้คืนข้อมูลจะลบข้อมูลปัจจุบันทั้งหมดและแทนที่ด้วยข้อมูลจากไฟล์สำรอง</li>
                <li>• ควรสำรองข้อมูลก่อนการอัปเดตระบบหรือการเปลี่ยนแปลงสำคัญ</li>
            </ul>
        </div>
    </div>

    <script>
        function confirmBackup() {
            return confirm('คุณต้องการสำรองข้อมูลหรือไม่?\n\nการสำรองข้อมูลอาจใช้เวลาสักครู่ กรุณารอจนกว่าจะเสร็จสิ้น');
        }

        function confirmRestore() {
            return confirm('⚠️ คำเตือน: การกู้คืนข้อมูลจะลบข้อมูลปัจจุบันทั้งหมด!\n\nคุณแน่ใจหรือไม่ว่าต้องการดำเนินการต่อ?\n\nขอแนะนำให้สำรองข้อมูลปัจจุบันก่อนทำการกู้คืน');
        }

        function deleteBackup(filename) {
            if (confirm('คุณต้องการลบไฟล์สำรองข้อมูล "' + filename + '" หรือไม่?\n\nการลบไฟล์สำรองข้อมูลไม่สามารถยกเลิกได้')) {
                window.location.href = '?action=delete&file=' + encodeURIComponent(filename);
            }
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