<?php
// 세션 시작
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 로그인 체크
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_type']);
}

// 사용자 타입 체크
function checkUserType($allowed_types) {
    if (!isLoggedIn()) {
        return false;
    }
    
    if (!is_array($allowed_types)) {
        $allowed_types = [$allowed_types];
    }
    
    return in_array($_SESSION['user_type'], $allowed_types);
}

// 로그인 필수 체크
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /hackerton/login.php');
        exit();
    }
}

// 사용자 타입 필수 체크
function requireUserType($allowed_types) {
    requireLogin();
    
    if (!checkUserType($allowed_types)) {
        header('Location: /hackerton/index.php?error=unauthorized');
        exit();
    }
}

// 승인된 사용자인지 체크
function isApproved() {
    return isset($_SESSION['status']) && $_SESSION['status'] === 'approved';
}

// 관광지 책임자인지 체크
function isAttractionOwner() {
    return isLoggedIn() && $_SESSION['user_type'] === 'attraction_manager';
}

// 관광지 직원인지 체크 (더 이상 사용 안 함 - 하위 호환성)
function isAttractionStaff() {
    return false; // attraction_staff 타입은 더 이상 존재하지 않음
}

// 관광지 관련 계정인지 체크 (책임자만)
function isAttractionAccount() {
    return isAttractionOwner(); // 직원 타입 제거로 책임자만 해당
}

// 특정 관광지에 대한 관리 권한이 있는지 체크
function canManageAttraction($attraction_id) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $conn = getDBConnection();
    $user_id = $_SESSION['user_id'];
    
    // 새로운 구조: attraction_managers 테이블 확인
    $stmt = $conn->prepare("SELECT id FROM attraction_managers WHERE attraction_id = ? AND user_id = ? AND status = 'active'");
    $stmt->bind_param("ii", $attraction_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $can_manage = $result->num_rows > 0;
    $stmt->close();
    
    // 하위 호환성: 기존 구조도 확인 (1:1 관계)
    if (!$can_manage && isAttractionOwner()) {
        $stmt = $conn->prepare("SELECT id FROM attractions WHERE id = ? AND manager_id = ?");
        $stmt->bind_param("ii", $attraction_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $can_manage = $result->num_rows > 0;
        $stmt->close();
    }
    
    // attraction_staff_info 테이블은 더 이상 사용하지 않음 (제거됨)
    
    return $can_manage;
}

// 특정 관광지의 소유자(책임자)인지 체크
function isAttractionOwnerOf($attraction_id) {
    if (!isAttractionOwner()) {
        return false;
    }
    
    $conn = getDBConnection();
    $user_id = $_SESSION['user_id'];
    
    // 새로운 구조: attraction_managers에서 primary 확인
    $stmt = $conn->prepare("SELECT id FROM attraction_managers WHERE attraction_id = ? AND user_id = ? AND role = 'primary' AND status = 'active'");
    $stmt->bind_param("ii", $attraction_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $is_owner = $result->num_rows > 0;
    $stmt->close();
    
    // 하위 호환성: 기존 구조도 확인 (1:1 관계)
    if (!$is_owner) {
        $stmt = $conn->prepare("SELECT id FROM attractions WHERE id = ? AND manager_id = ?");
        $stmt->bind_param("ii", $attraction_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $is_owner = $result->num_rows > 0;
        $stmt->close();
    }
    
    return $is_owner;
}

// 사용자가 소속된 관광지 ID 가져오기
function getUserAttractionId() {
    if (!isLoggedIn()) {
        return null;
    }
    
    $conn = getDBConnection();
    $user_id = $_SESSION['user_id'];
    
    // 새로운 구조: attraction_managers에서 조회
    $stmt = $conn->prepare("SELECT attraction_id FROM attraction_managers WHERE user_id = ? AND status = 'active' ORDER BY CASE role WHEN 'primary' THEN 1 WHEN 'co-manager' THEN 2 ELSE 3 END, added_at DESC LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $attraction_id = $row['attraction_id'];
        $stmt->close();
        return $attraction_id;
    }
    $stmt->close();
    
    // 하위 호환성: 기존 구조도 확인 (1:1 관계)
    if (isAttractionOwner()) {
        $stmt = $conn->prepare("SELECT id FROM attractions WHERE manager_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $attraction_id = $row['id'];
            $stmt->close();
            return $attraction_id;
        }
        $stmt->close();
    }
    
    // attraction_staff는 더 이상 사용하지 않음 (제거됨)
    
    return null;
}

// 관광지 책임자가 등록된 관광지가 있는지 확인
function hasAttractions() {
    if (!isAttractionOwner()) {
        return true; // 관광지 책임자가 아니면 체크하지 않음
    }
    
    $conn = getDBConnection();
    $user_id = $_SESSION['user_id'];
    
    // 새로운 구조: attraction_managers 테이블 확인
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM attraction_managers WHERE user_id = ? AND status = 'active'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    if ($row['count'] > 0) {
        return true;
    }
    
    // 하위 호환성: 기존 구조도 확인 (1:1 관계)
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM attractions WHERE manager_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['count'] > 0;
}

// 관광지 책임자가 관광지를 등록했는지 확인하고, 없으면 등록 페이지로 리다이렉트
function requireAttractionRegistration() {
    if (!isAttractionOwner()) {
        return; // 관광지 책임자가 아니면 체크하지 않음
    }
    
    if (!hasAttractions()) {
        // 현재 페이지가 register_attraction.php가 아닌 경우에만 리다이렉트
        $current_page = basename($_SERVER['PHP_SELF']);
        if ($current_page !== 'register_attraction.php') {
            header('Location: /hackerton/attraction/register_attraction.php');
            exit();
        }
    }
}

// 로그아웃
function logout() {
    session_unset();
    session_destroy();
    header('Location: /hackerton/index.php');
    exit();
}
?>
