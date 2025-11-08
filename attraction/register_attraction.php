<?php
require_once '../config/database.php';
require_once '../config/session.php';

// 관광지 책임자만 접근 가능 (직원은 접근 불가)
requireUserType('attraction_manager');

// 승인된 사용자만 접근 가능
if (!isApproved()) {
    header('Location: ../index.php');
    exit();
}

$conn = getDBConnection();
$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

// 관광지 관리인 정보 확인
$stmt = $conn->prepare("SELECT * FROM attraction_manager_info WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$manager_info = $stmt->get_result()->fetch_assoc();
$stmt->close();

// 신규 관광지 등록 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'register_new') {
        $name = $_POST['name'] ?? '';
        $address = $_POST['address'] ?? '';
        $description = $_POST['description'] ?? '';
        $category = $_POST['category'] ?? '';
        $contact_phone = $_POST['contact_phone'] ?? '';
        $operating_hours = $_POST['operating_hours'] ?? '';
        $admission_fee = $_POST['admission_fee'] ?? '';
        $business_number = $_POST['business_number'] ?? '';
        
        if (empty($name) || empty($address)) {
            $error = '관광지명과 주소는 필수입니다.';
        } else {
            // 중복된 관광지명이 있는지 확인
            $stmt_check = $conn->prepare("SELECT COUNT(*) as count FROM attractions WHERE name = ?");
            $stmt_check->bind_param("s", $name);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            $name_exists = $result_check->fetch_assoc()['count'] > 0;
            $stmt_check->close();
            
            if ($name_exists) {
                $error = '이미 등록된 관광지명입니다. 다른 이름을 사용해주세요.';
            } else {
                // 관광지 등록
                $stmt = $conn->prepare("INSERT INTO attractions (name, address, description, category, contact_phone, operating_hours, admission_fee, manager_id, created_by, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
                $stmt->bind_param("sssssssii", $name, $address, $description, $category, $contact_phone, $operating_hours, $admission_fee, $user_id, $user_id);
                
                if ($stmt->execute()) {
                    // attraction_manager_info 업데이트
                    $stmt2 = $conn->prepare("UPDATE attraction_manager_info SET attraction_name = ?, business_registration_number = ? WHERE user_id = ?");
                    $stmt2->bind_param("ssi", $name, $business_number, $user_id);
                    $stmt2->execute();
                    $stmt2->close();
                    
                    $success = '관광지가 성공적으로 등록되었습니다. 이제 모든 기능을 이용하실 수 있습니다.';
                    
                    // 정보 새로고침
                    $stmt = $conn->prepare("SELECT * FROM attraction_manager_info WHERE user_id = ?");
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                    $manager_info = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                } else {
                    $error = '관광지 등록 중 오류가 발생했습니다.';
                }
                $stmt->close();
            }
        }
    }
    
    // 기존 관광지 직원 인증 처리
    if ($_POST['action'] === 'verify_employee') {
        $attraction_id = intval($_POST['attraction_id']);
        $verification_code = trim($_POST['verification_code'] ?? '');
        
        if (empty($attraction_id)) {
            $error = '관광지를 선택해주세요.';
        } elseif (empty($verification_code)) {
            $error = '인증코드를 입력해주세요.';
        } else {
            // 관광지 정보 조회
            $stmt = $conn->prepare("SELECT * FROM attractions WHERE id = ?");
            $stmt->bind_param("i", $attraction_id);
            $stmt->execute();
            $attraction = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if (!$attraction) {
                $error = '해당 관광지를 찾을 수 없습니다.';
            } else {
                // 인증 코드 검증 (형식: "VERIFY-{attraction_id}")
                $expected_code = "VERIFY-" . $attraction_id;
                
                if (strtoupper(trim($verification_code)) === strtoupper($expected_code)) {
                    // attraction_manager_info 업데이트
                    $stmt = $conn->prepare("UPDATE attraction_manager_info SET attraction_name = ? WHERE user_id = ?");
                    $stmt->bind_param("si", $attraction['name'], $user_id);
                    
                    if ($stmt->execute()) {
                        $success = '직원 인증이 완료되었습니다. 이제 모든 기능을 이용하실 수 있습니다.';
                        
                        // 정보 새로고침
                        $stmt->close();
                        $stmt = $conn->prepare("SELECT * FROM attraction_manager_info WHERE user_id = ?");
                        $stmt->bind_param("i", $user_id);
                        $stmt->execute();
                        $manager_info = $stmt->get_result()->fetch_assoc();
                    } else {
                        $error = '인증 처리 중 오류가 발생했습니다.';
                    }
                    $stmt->close();
                } else {
                    $error = '인증코드가 올바르지 않습니다. (예: VERIFY-' . $attraction_id . ')';
                }
            }
        }
    }
}

// 등록 가능한 관광지 목록 (검색용)
$all_attractions = [];
$stmt = $conn->prepare("SELECT id, name, address, category FROM attractions WHERE status = 'active' ORDER BY name");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $all_attractions[] = $row;
}
$stmt->close();

$conn->close();

// 이미 인증된 경우 리다이렉트
if ($manager_info && !empty($manager_info['attraction_name'])) {
    header('Location: my_attractions.php');
    exit();
}

$page_title = '관광지 등록/인증';
$base_url = '..';
include '../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>🏛️ 관광지 등록/인증</h1>
        <p>새로운 관광지를 등록하거나 기존 관광지의 직원임을 인증해주세요.</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error">⚠️ <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success">
            ✅ <?php echo htmlspecialchars($success); ?>
            <div style="margin-top: 1rem;">
                <a href="my_attractions.php" class="btn btn-primary">내 관광지로 이동</a>
                <a href="dashboard.php" class="btn btn-secondary">대시보드로 이동</a>
            </div>
        </div>
    <?php else: ?>

    <div class="alert alert-info" style="margin-bottom: 2rem;">
        <strong>📋 안내사항</strong>
        <ul style="margin: 0.5rem 0 0 1.5rem;">
            <li><strong>신규 관광지 등록:</strong> 새로운 관광지를 시스템에 등록합니다.</li>
            <li><strong>기존 관광지 직원 인증:</strong> 이미 등록된 관광지의 직원임을 인증합니다.</li>
            <li>인증 완료 후 관광지 관리 기능을 이용하실 수 있습니다.</li>
        </ul>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;">
        
        <!-- 신규 관광지 등록 -->
        <div class="card">
            <div class="card-header">
                <h2 style="margin: 0;">🆕 신규 관광지 등록</h2>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="register_new">
                    
                    <div class="form-group">
                        <label for="name" class="form-label">관광지명 <span style="color: var(--danger-color);">*</span></label>
                        <input type="text" id="name" name="name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="address" class="form-label">주소 <span style="color: var(--danger-color);">*</span></label>
                        <input type="text" id="address" name="address" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="category" class="form-label">카테고리</label>
                        <select id="category" name="category" class="form-control">
                            <option value="">선택하세요</option>
                            <option value="문화재">문화재</option>
                            <option value="자연">자연</option>
                            <option value="테마파크">테마파크</option>
                            <option value="박물관/미술관">박물관/미술관</option>
                            <option value="체험">체험</option>
                            <option value="기타">기타</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="description" class="form-label">설명</label>
                        <textarea id="description" name="description" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="contact_phone" class="form-label">연락처</label>
                        <input type="tel" id="contact_phone" name="contact_phone" class="form-control" placeholder="02-1234-5678">
                    </div>
                    
                    <div class="form-group">
                        <label for="operating_hours" class="form-label">운영시간</label>
                        <input type="text" id="operating_hours" name="operating_hours" class="form-control" placeholder="09:00 - 18:00">
                    </div>
                    
                    <div class="form-group">
                        <label for="admission_fee" class="form-label">입장료</label>
                        <input type="text" id="admission_fee" name="admission_fee" class="form-control" placeholder="성인 10,000원 / 청소년 5,000원">
                    </div>
                    
                    <div class="form-group">
                        <label for="business_number" class="form-label">사업자등록번호</label>
                        <input type="text" id="business_number" name="business_number" class="form-control" placeholder="000-00-00000">
                        <small style="color: var(--text-light);">재직 증명을 위한 정보</small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="width: 100%;">신규 관광지 등록</button>
                </form>
            </div>
        </div>
        
        <!-- 기존 관광지 직원 인증 -->
        <div class="card">
            <div class="card-header">
                <h2 style="margin: 0;">✅ 기존 관광지 직원 인증</h2>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="verify_employee">
                    
                    <div class="form-group">
                        <label for="attraction_id" class="form-label">관광지 선택 <span style="color: var(--danger-color);">*</span></label>
                        <select id="attraction_id" name="attraction_id" class="form-control" required>
                            <option value="">선택하세요</option>
                            <?php foreach ($all_attractions as $attr): ?>
                                <option value="<?php echo $attr['id']; ?>">
                                    <?php echo htmlspecialchars($attr['name']); ?> 
                                    (<?php echo htmlspecialchars($attr['address']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="employee_id_verify" class="form-label">직원번호/사번 <span style="color: var(--danger-color);">*</span></label>
                        <input type="text" id="employee_id_verify" name="employee_id" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="verification_code" class="form-label">인증코드 <span style="color: var(--danger-color);">*</span></label>
                        <input type="text" id="verification_code" name="verification_code" class="form-control" required>
                        <small style="color: var(--text-light);">관광지 관리자에게 받은 인증코드를 입력하세요</small>
                    </div>
                    
                    <div class="alert alert-info" style="font-size: 0.9rem;">
                        <strong>💡 인증코드 발급 방법</strong>
                        <p style="margin: 0.5rem 0 0 0;">
                            기존 관광지 관리자에게 연락하여 인증코드를 받으세요.<br>
                            형식: <code>VERIFY-{관광지ID}</code>
                        </p>
                    </div>
                    
                    <button type="submit" class="btn btn-success" style="width: 100%;">직원 인증하기</button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <h3 style="margin: 0;">📋 등록된 관광지 목록</h3>
        </div>
        <div class="card-body">
            <?php if (empty($all_attractions)): ?>
                <p style="text-align: center; color: var(--text-light); padding: 2rem;">등록된 관광지가 없습니다.</p>
            <?php else: ?>
                <div style="display: grid; gap: 1rem;">
                    <?php foreach ($all_attractions as $attr): ?>
                        <div style="padding: 1rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--bg-color);">
                            <div style="display: flex; justify-content: space-between; align-items: start;">
                                <div>
                                    <h4 style="margin: 0 0 0.5rem 0; color: var(--primary-color);">
                                        <?php echo htmlspecialchars($attr['name']); ?>
                                    </h4>
                                    <p style="margin: 0; color: var(--text-light); font-size: 0.9rem;">
                                        📍 <?php echo htmlspecialchars($attr['address']); ?>
                                    </p>
                                    <?php if ($attr['category']): ?>
                                        <p style="margin: 0.25rem 0 0 0; font-size: 0.85rem;">
                                            <span class="badge" style="background: var(--primary-color); color: white; padding: 0.25rem 0.5rem; border-radius: 4px;">
                                                <?php echo htmlspecialchars($attr['category']); ?>
                                            </span>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size: 0.85rem; color: var(--text-light);">
                                    ID: <?php echo $attr['id']; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>

</div>

<?php include '../includes/footer.php'; ?>
