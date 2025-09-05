-- Database: pawn_shop_management
-- Thai Pawn Shop Management System Database Schema

CREATE DATABASE IF NOT EXISTS pawn_shop_management DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pawn_shop_management;

-- Users table for system authentication
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    role ENUM('admin', 'manager', 'employee') DEFAULT 'employee',
    branch_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    status ENUM('active', 'inactive') DEFAULT 'active'
);

-- Branches table
CREATE TABLE branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    address TEXT NOT NULL,
    phone VARCHAR(20),
    manager_id INT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Customers table
CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_code VARCHAR(20) UNIQUE NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    id_card VARCHAR(13) UNIQUE NOT NULL,
    phone VARCHAR(20),
    address TEXT,
    branch_id INT NOT NULL,
    status ENUM('active', 'inactive', 'blacklist') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id)
);

-- Item categories
CREATE TABLE item_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Pawn transactions
CREATE TABLE pawn_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_code VARCHAR(20) UNIQUE NOT NULL,
    customer_id INT NOT NULL,
    branch_id INT NOT NULL,
    user_id INT NOT NULL,
    pawn_amount DECIMAL(15,2) NOT NULL,
    interest_rate DECIMAL(5,2) DEFAULT 5.00,
    period_months INT DEFAULT 3,
    pawn_date DATE NOT NULL,
    due_date DATE NOT NULL,
    status ENUM('active', 'paid', 'overdue', 'forfeited', 'extended') DEFAULT 'active',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Pawn items
CREATE TABLE pawn_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    category_id INT NOT NULL,
    item_name VARCHAR(200) NOT NULL,
    description TEXT,
    weight DECIMAL(10,3),
    estimated_value DECIMAL(15,2) NOT NULL,
    condition_notes TEXT,
    image_path VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_id) REFERENCES pawn_transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES item_categories(id)
);

-- Payments table
CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    payment_type ENUM('interest', 'partial_payment', 'full_payment', 'redemption') NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    payment_date DATE NOT NULL,
    user_id INT NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_id) REFERENCES pawn_transactions(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Inventory for items that are forfeited and available for sale
CREATE TABLE inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(20) UNIQUE NOT NULL,
    transaction_id INT,
    pawn_item_id INT,
    item_name VARCHAR(200) NOT NULL,
    category_id INT NOT NULL,
    description TEXT,
    cost_price DECIMAL(15,2) NOT NULL,
    selling_price DECIMAL(15,2) NOT NULL,
    weight DECIMAL(10,3),
    status ENUM('available', 'sold', 'reserved') DEFAULT 'available',
    branch_id INT NOT NULL,
    image_path VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_id) REFERENCES pawn_transactions(id),
    FOREIGN KEY (pawn_item_id) REFERENCES pawn_items(id),
    FOREIGN KEY (category_id) REFERENCES item_categories(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id)
);

-- Sales transactions
CREATE TABLE sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_code VARCHAR(20) UNIQUE NOT NULL,
    inventory_id INT NOT NULL,
    customer_id INT,
    branch_id INT NOT NULL,
    user_id INT NOT NULL,
    sale_price DECIMAL(15,2) NOT NULL,
    sale_date DATE NOT NULL,
    payment_method ENUM('cash', 'transfer', 'card') DEFAULT 'cash',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (inventory_id) REFERENCES inventory(id),
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- System settings
CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT NOT NULL,
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Add foreign key constraints
ALTER TABLE branches ADD FOREIGN KEY (manager_id) REFERENCES users(id);
ALTER TABLE users ADD FOREIGN KEY (branch_id) REFERENCES branches(id);

-- Insert default data
INSERT INTO item_categories (name, description) VALUES
('ทองคำ', 'เครื่องประดับทองคำและทองรูปพรรณ'),
('เครื่องใช้ไฟฟ้า', 'อุปกรณ์อิเล็กทรอนิกส์และเครื่องใช้ไฟฟ้า'),
('รถจักรยานยนต์', 'รถจักรยานยนต์และส่วนประกอบ'),
('อื่นๆ', 'สินค้าประเภทอื่นๆ');

INSERT INTO settings (setting_key, setting_value, description) VALUES
('default_interest_rate', '5.00', 'อัตราดอกเบี้ยเริ่มต้นต่อเดือน (%)'),
('default_period_months', '3', 'ระยะเวลาจำนำเริ่มต้น (เดือน)'),
('service_fee', '100', 'ค่าธรรมเนียมการบริการ (บาท)'),
('company_name', 'บริษัท จำนำทองคำ จำกัด', 'ชื่อบริษัท'),
('company_address', '123 ถนนสุขุมวิท แขวงคลองตัน เขตคลองตัน กรุงเทพฯ 10110', 'ที่อยู่บริษัท'),
('company_phone', '02-123-4567', 'เบอร์โทรบริษัท');

-- Insert sample branches
INSERT INTO branches (name, address, phone, status) VALUES
('สาขาหลัก', '123 ถนนสุขุมวิท แขวงคลองตัน เขตคลองตัน กรุงเทพฯ 10110', '02-123-4567', 'active'),
('สาขาย่อย 1', '456 ถนนรัชดา แขวงห้วยขวาง เขตห้วยขวาง กรุงเทพฯ 10310', '02-234-5678', 'active');

-- Insert sample admin user (password: admin123)
INSERT INTO users (username, email, password, full_name, phone, role, branch_id, status) VALUES
('admin', 'admin@pawnshop.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'ผู้ดูแลระบบ', '081-234-5678', 'admin', 1, 'active');

-- Insert sample customers
INSERT INTO customers (customer_code, first_name, last_name, id_card, phone, address, branch_id) VALUES
('C001', 'สมชาย', 'ใจดี', '1234567890123', '081-234-5678', '123/45 ถนนรามคำแหง เขตวังทองหลาง กรุงเทพฯ', 1),
('C002', 'มาลี', 'สวยงาม', '1234567890124', '089-876-5432', '678/90 ถนนพระราม 4 เขตคลองเตย กรุงเทพฯ', 1);

-- Create indexes for better performance
CREATE INDEX idx_customers_code ON customers(customer_code);
CREATE INDEX idx_customers_id_card ON customers(id_card);
CREATE INDEX idx_pawn_transactions_code ON pawn_transactions(transaction_code);
CREATE INDEX idx_pawn_transactions_status ON pawn_transactions(status);
CREATE INDEX idx_pawn_transactions_due_date ON pawn_transactions(due_date);
CREATE INDEX idx_payments_transaction_id ON payments(transaction_id);
CREATE INDEX idx_payments_date ON payments(payment_date);
CREATE INDEX idx_inventory_code ON inventory(item_code);
CREATE INDEX idx_inventory_status ON inventory(status);
CREATE INDEX idx_sales_code ON sales(sale_code);