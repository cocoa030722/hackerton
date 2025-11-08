<?php
// 데이터베이스 연결 설정
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'hackerton_tourism');

// 데이터베이스 연결
function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    $conn->set_charset("utf8mb4");
    return $conn;
}

// 데이터베이스 초기화
function initializeDatabase() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    // 데이터베이스 생성
    $sql = "CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    $conn->query($sql);
    
    $conn->select_db(DB_NAME);
    
    // 사용자 테이블 생성
    $sql = "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        user_type ENUM('tourist', 'attraction_manager', 'attraction_staff', 'admin') NOT NULL DEFAULT 'tourist',
        full_name VARCHAR(100) NOT NULL,
        phone VARCHAR(20),
        status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user_type (user_type),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $conn->query($sql);
    
    // 관광객 추가 정보 테이블
    $sql = "CREATE TABLE IF NOT EXISTS tourist_info (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        national_id VARCHAR(50),
        birth_date DATE,
        verification_method ENUM('email', 'phone', 'id_card') DEFAULT 'email',
        is_verified BOOLEAN DEFAULT FALSE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $conn->query($sql);
    
    // 관광지 관리인 추가 정보 테이블
    $sql = "CREATE TABLE IF NOT EXISTS attraction_manager_info (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        attraction_name VARCHAR(200) NOT NULL,
        business_registration_number VARCHAR(50),
        employee_id VARCHAR(50),
        verification_document VARCHAR(255),
        verification_status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
        verified_at TIMESTAMP NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $conn->query($sql);
    
    // 관광지 직원 정보 테이블
    $sql = "CREATE TABLE IF NOT EXISTS attraction_staff_info (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        attraction_id INT NOT NULL,
        staff_name VARCHAR(100) NOT NULL,
        staff_position VARCHAR(100) NULL COMMENT '직급/직책',
        employee_number VARCHAR(50) NULL COMMENT '직원번호',
        phone VARCHAR(20) NULL,
        hired_date DATE NULL COMMENT '입사일',
        status ENUM('active', 'inactive') DEFAULT 'active' COMMENT '재직상태',
        registered_by INT NOT NULL COMMENT '등록한 책임자 ID',
        registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (attraction_id) REFERENCES attractions(id) ON DELETE CASCADE,
        FOREIGN KEY (registered_by) REFERENCES users(id) ON DELETE RESTRICT,
        UNIQUE KEY unique_user_attraction (user_id, attraction_id),
        INDEX idx_attraction (attraction_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $conn->query($sql);
    
    // 관리자(공무원) 추가 정보 테이블
    $sql = "CREATE TABLE IF NOT EXISTS admin_info (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        department VARCHAR(200) NOT NULL,
        position VARCHAR(100),
        employee_number VARCHAR(50),
        government_email VARCHAR(100),
        verification_document VARCHAR(255),
        approved_by INT NULL,
        approved_at TIMESTAMP NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $conn->query($sql);
    
    // 슈퍼 관리자 계정 생성 (초기 관리자)
    // 비밀번호: admin123
    $default_password = password_hash('admin123', PASSWORD_DEFAULT);
    $sql = "INSERT IGNORE INTO users (username, email, password, user_type, full_name, phone, status) 
            VALUES ('superadmin', 'superadmin@government.go.kr', '$default_password', 'admin', '시스템 관리자', '02-1234-5678', 'approved')";
    if ($conn->query($sql)) {
        // 관리자 추가 정보 저장
        $user_result = $conn->query("SELECT id FROM users WHERE username = 'superadmin'");
        if ($user_result && $user_result->num_rows > 0) {
            $user_id = $user_result->fetch_assoc()['id'];
            $sql = "INSERT IGNORE INTO admin_info (user_id, department, position, employee_number, government_email, approved_at) 
                    VALUES ($user_id, '시스템관리팀', '관리자', 'SYS001', 'superadmin@government.go.kr', NOW())";
            $conn->query($sql);
        }
    }
    
    $conn->close();
}
?>
