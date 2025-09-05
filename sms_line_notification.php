<?php
/**
 * SMS & Line Notification System
 * ระบบส่ง SMS และ Line แจ้งเตือนลูกค้า
 */

require_once 'config/database.php';

class SMSLineNotification {
    private $db;
    private $sms_api_key;
    private $line_token;
    
    public function __construct($db) {
        $this->db = $db;
        // ใส่ API keys ของ SMS และ Line Notify
        $this->sms_api_key = 'YOUR_SMS_API_KEY'; // เช่น Thaibluk SMS
        $this->line_token = 'YOUR_LINE_NOTIFY_TOKEN';
    }
    
    /**
     * ส่ง SMS แจ้งเตือนลูกค้า
     */
    public function sendSMS($phone, $message) {
        // Example with Thaibluk SMS API
        $url = 'https://api.thaibluk.com/sms/send';
        
        $data = [
            'username' => 'YOUR_USERNAME',
            'password' => 'YOUR_PASSWORD',
            'from' => 'PAWNSHOP',
            'to' => $phone,
            'message' => $message
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Log การส่ง SMS
        $this->logNotification('SMS', $phone, $message, $http_code == 200);
        
        return $http_code == 200;
    }
    
    /**
     * ส่ง Line Notify
     */
    public function sendLineNotify($message) {
        $url = 'https://notify-api.line.me/api/notify';
        
        $headers = [
            'Authorization: Bearer ' . $this->line_token,
            'Content-Type: application/x-www-form-urlencoded'
        ];
        
        $data = ['message' => $message];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $http_code == 200;
    }
    
    /**
     * แจ้งเตือนสัญญาใกล้ครบกำหนด
     */
    public function sendExpiryReminders() {
        // สัญญาที่ครบกำหนดใน 3 วัน
        $stmt = $this->db->prepare("
            SELECT pt.*, c.first_name, c.last_name, c.phone,
                   DATEDIFF(pt.due_date, CURDATE()) as days_remaining
            FROM pawn_transactions pt
            JOIN customers c ON pt.customer_id = c.id
            WHERE pt.status = 'active' 
            AND pt.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
            AND c.phone IS NOT NULL
        ");
        $stmt->execute();
        $expiring_contracts = $stmt->fetchAll();
        
        foreach ($expiring_contracts as $contract) {
            $customer_name = $contract['first_name'] . ' ' . $contract['last_name'];
            $days_left = $contract['days_remaining'];
            $amount = formatCurrency($contract['pawn_amount']);
            
            $message = "เรียน คุณ{$customer_name}\n";
            $message .= "สัญญาจำนำ {$contract['transaction_code']}\n";
            $message .= "จำนวน {$amount}\n";
            
            if ($days_left == 0) {
                $message .= "ครบกำหนดวันนี้\n";
            } else {
                $message .= "เหลือ {$days_left} วัน จะครบกำหนด\n";
            }
            
            $message .= "กรุณาติดต่อร้านเพื่อชำระหรือต่ออายุ\n";
            $message .= "โทร: " . $this->getShopPhone();
            
            $this->sendSMS($contract['phone'], $message);
            
            // แจ้งเตือนผ่าน Line ด้วย (สำหรับเจ้าของร้าน)
            if ($days_left <= 1) {
                $line_message = "⚠️ แจ้งเตือนสัญญาครบกำหนด\n";
                $line_message .= "รายการ: {$contract['transaction_code']}\n";
                $line_message .= "ลูกค้า: {$customer_name}\n";
                $line_message .= "จำนวน: {$amount}\n";
                $line_message .= "เหลือ: {$days_left} วัน";
                
                $this->sendLineNotify($line_message);
            }
        }
    }
    
    /**
     * แจ้งเตือนดอกเบี้ยค้างชำระ
     */
    public function sendInterestReminders() {
        $stmt = $this->db->prepare("
            SELECT pt.*, c.first_name, c.last_name, c.phone,
                   DATEDIFF(CURDATE(), COALESCE(
                       (SELECT MAX(payment_date) FROM payments WHERE transaction_id = pt.id),
                       pt.pawn_date
                   )) as days_since_payment
            FROM pawn_transactions pt
            JOIN customers c ON pt.customer_id = c.id
            WHERE pt.status = 'active'
            AND DATEDIFF(CURDATE(), COALESCE(
                (SELECT MAX(payment_date) FROM payments WHERE transaction_id = pt.id),
                pt.pawn_date
            )) >= 30
            AND c.phone IS NOT NULL
        ");
        $stmt->execute();
        $interest_due = $stmt->fetchAll();
        
        foreach ($interest_due as $contract) {
            $customer_name = $contract['first_name'] . ' ' . $contract['last_name'];
            $months_overdue = ceil($contract['days_since_payment'] / 30);
            $interest_amount = $contract['pawn_amount'] * ($contract['interest_rate'] / 100) * $months_overdue;
            
            $message = "เรียน คุณ{$customer_name}\n";
            $message .= "สัญญาจำนำ {$contract['transaction_code']}\n";
            $message .= "ค้างชำระดอกเบี้ย {$contract['days_since_payment']} วัน\n";
            $message .= "จำนวน " . formatCurrency($interest_amount) . "\n";
            $message .= "กรุณาติดต่อร้านเพื่อชำระดอกเบี้ย\n";
            $message .= "โทร: " . $this->getShopPhone();
            
            $this->sendSMS($contract['phone'], $message);
        }
    }
    
    /**
     * ส่งข้อความขอบคุณหลังการชำระ
     */
    public function sendPaymentThankYou($customer_phone, $customer_name, $transaction_code, $amount) {
        $message = "ขอบคุณ คุณ{$customer_name}\n";
        $message .= "ที่ชำระเงิน {$transaction_code}\n";
        $message .= "จำนวน " . formatCurrency($amount) . "\n";
        $message .= "ขอบคุณที่ใช้บริการครับ/ค่ะ";
        
        $this->sendSMS($customer_phone, $message);
    }
    
    /**
     * แจ้งเตือนรายงานประจำวัน
     */
    public function sendDailyReport() {
        $today = date('Y-m-d');
        
        // สถิติประจำวัน
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(CASE WHEN pt.pawn_date = ? THEN 1 END) as new_pawns,
                COUNT(CASE WHEN p.payment_date = ? THEN 1 END) as payments_today,
                SUM(CASE WHEN p.payment_date = ? THEN p.amount ELSE 0 END) as revenue_today,
                COUNT(CASE WHEN pt.due_date = ? AND pt.status = 'active' THEN 1 END) as expiring_today
            FROM pawn_transactions pt
            LEFT JOIN payments p ON pt.id = p.transaction_id
        ");
        $stmt->execute([$today, $today, $today, $today]);
        $stats = $stmt->fetch();
        
        $message = "📊 รายงานประจำวัน " . formatThaiDate($today) . "\n\n";
        $message .= "💎 รายการจำนำใหม่: {$stats['new_pawns']} รายการ\n";
        $message .= "💰 การชำระเงิน: {$stats['payments_today']} ครั้ง\n";
        $message .= "💵 รายได้วันนี้: " . formatCurrency($stats['revenue_today']) . "\n";
        $message .= "⏰ ครบกำหนดวันนี้: {$stats['expiring_today']} รายการ";
        
        $this->sendLineNotify($message);
    }
    
    /**
     * บันทึก log การส่งการแจ้งเตือน
     */
    private function logNotification($type, $recipient, $message, $success) {
        $stmt = $this->db->prepare("
            INSERT INTO notification_logs (type, recipient, message, success, sent_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$type, $recipient, $message, $success ? 1 : 0]);
    }
    
    /**
     * ดึงเบอร์โทรร้าน
     */
    private function getShopPhone() {
        $stmt = $this->db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'company_phone'");
        $stmt->execute();
        $result = $stmt->fetch();
        return $result ? $result['setting_value'] : '02-xxx-xxxx';
    }
}

// สร้างตาราง notification_logs
/*
CREATE TABLE notification_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('SMS', 'LINE', 'EMAIL') NOT NULL,
    recipient VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    success BOOLEAN DEFAULT FALSE,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sent_at (sent_at),
    INDEX idx_type (type)
);
*/

// Cron job script - ใส่ใน crontab
/*
# แจ้งเตือนทุกวันเวลา 09:00
0 9 * * * /usr/bin/php /path/to/notification_cron.php

# แจ้งเตือนดอกเบี้ยทุกสัปดาห์ วันจันทร์ 10:00
0 10 * * 1 /usr/bin/php /path/to/interest_reminder_cron.php

# รายงานประจำวัน ทุกวันเวลา 18:00
0 18 * * * /usr/bin/php /path/to/daily_report_cron.php
*/

// การใช้งาน
if (basename(__FILE__) == 'sms_notification.php') {
    require_once 'config/database.php';
    
    $db = getDB();
    $notification = new SMSLineNotification($db);
    
    // ตัวอย่างการใช้งาน
    if (isset($_GET['action'])) {
        switch ($_GET['action']) {
            case 'send_expiry_reminders':
                $notification->sendExpiryReminders();
                echo "ส่งการแจ้งเตือนครบกำหนดเรียบร้อย";
                break;
                
            case 'send_interest_reminders':
                $notification->sendInterestReminders();
                echo "ส่งการแจ้งเตือนดอกเบี้ยเรียบร้อย";
                break;
                
            case 'daily_report':
                $notification->sendDailyReport();
                echo "ส่งรายงานประจำวันเรียบร้อย";
                break;
        }
    }
}
?>