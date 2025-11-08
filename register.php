<?php
require_once 'config/database.php';
require_once 'config/session.php';

// 이미 로그인된 경우 리다이렉트
if (isLoggedIn()) {
    header('Location: index.php');
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $full_name = $_POST['full_name'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $user_type = $_POST['user_type'] ?? 'tourist';
    
    // 유효성 검사
    if (empty($username) || empty($email) || empty($password) || empty($full_name)) {
        $error = '필수 항목을 모두 입력해주세요.';
    } elseif ($password !== $confirm_password) {
        $error = '비밀번호가 일치하지 않습니다.';
    } elseif (strlen($password) < 8) {
        $error = '비밀번호는 8자 이상이어야 합니다.';
    } elseif ($user_type === 'admin') {
        $error = '관리자 계정은 직접 생성할 수 없습니다. 시스템 관리자에게 문의하세요.';
    } else {
        $conn = getDBConnection();
        
        // 에러가 없으면 계속 진행
        if (empty($error)) {
            // 중복 체크
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->bind_param("ss", $username, $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $error = '이미 사용 중인 아이디 또는 이메일입니다.';
                $stmt->close();
            } else {
                $stmt->close();
                // 비밀번호 해시화
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // 사용자 타입에 따른 승인 상태
            // 관광객: 자동 승인
            // 관광지 책임자: 관리자 승인 필요 (pending)
            $status = 'approved';
            if ($user_type === 'attraction_manager') {
                $status = 'pending';  // 관리자 승인 대기
            }
            
            // 사용자 등록
            $stmt = $conn->prepare("INSERT INTO users (username, email, password, user_type, full_name, phone, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssss", $username, $email, $hashed_password, $user_type, $full_name, $phone, $status);
            
            if ($stmt->execute()) {
                $user_id = $stmt->insert_id;
                
                // 사용자 타입별 추가 정보 처리
                if ($user_type === 'tourist') {
                    // 관광객 정보 저장
                    $national_id = $_POST['national_id'] ?? '';
                    $birth_date = $_POST['birth_date'] ?? null;
                    
                    $stmt = $conn->prepare("INSERT INTO tourist_info (user_id, national_id, birth_date, is_verified) VALUES (?, ?, ?, TRUE)");
                    $stmt->bind_param("iss", $user_id, $national_id, $birth_date);
                    $stmt->execute();
                    $stmt->close();
                    
                    $success = '회원가입이 완료되었습니다. 로그인해주세요.';
                    
                } elseif ($user_type === 'attraction_manager') {
                    // 관광지 책임자 정보 저장
                    $attraction_name = $_POST['attraction_name'] ?? '';
                    $business_registration_number = $_POST['business_registration_number'] ?? '';
                    
                    $stmt = $conn->prepare("INSERT INTO attraction_manager_info (user_id, attraction_name, business_registration_number, verification_status) VALUES (?, ?, ?, 'pending')");
                    $stmt->bind_param("iss", $user_id, $attraction_name, $business_registration_number);
                    $stmt->execute();
                    $stmt->close();

                    $success = '회원가입이 완료되었습니다!<br>관리자 승인 후 로그인하여 관광지를 등록하고 관리할 수 있습니다.<br>승인이 완료되면 이메일로 안내드립니다.';
                }
            } else {
                $error = '회원가입 중 오류가 발생했습니다.';
                $stmt->close();
            }
            }
        }
        
        if (isset($conn)) {
            $conn->close();
        }
    }
}

$page_title = '회원가입 - 관광 코스 인증 시스템';
$base_url = '';
include 'includes/header.php';
?>

<div class="container" style="max-width: 700px;">
    <div class="card" style="margin-top: 2rem;">
        <div class="card-header text-center">
            <h2>✍️ 회원가입</h2>
            <p style="color: var(--text-light); margin-top: 0.5rem;">관광 코스 인증 시스템</p>
        </div>
        
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-error">⚠️ <?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    ✅ <?php echo $success; ?>
                    <div style="margin-top: 1rem;">
                        <a href="login.php" class="btn btn-primary">로그인하기</a>
                    </div>
                </div>
            <?php else: ?>
            
            <div class="alert alert-info mb-3">
                <strong>📋 계정 유형 안내</strong>
                <ul style="margin: 0.5rem 0 0 1.5rem;">
                    <li><strong>관광객:</strong> 즉시 가입 가능</li>
                    <li><strong>관광지 책임자:</strong> 관리자 승인 필요 (승인 후 관광지 등록 및 관리 가능)</li>
                </ul>
            </div>
            
            <form method="POST" action="" id="registerForm">
                <div class="form-group">
                    <label class="form-label">계정 유형 선택 <span style="color: var(--danger-color);">*</span></label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <label style="cursor: pointer;">
                            <input type="radio" id="tourist" name="user_type" value="tourist" checked style="margin-right: 0.5rem;">
                            <strong>👥 관광객</strong><br>
                            <small style="color: var(--text-light);">일반 이용자</small>
                        </label>
                        <label style="cursor: pointer;">
                            <input type="radio" id="attraction_manager" name="user_type" value="attraction_manager" style="margin-right: 0.5rem;">
                            <strong>🏛️ 관광지 책임자</strong><br>
                            <small style="color: var(--text-light);">관광지 운영 담당자</small>
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="username" class="form-label">아이디 <span style="color: var(--danger-color);">*</span></label>
                    <input type="text" id="username" name="username" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="email" class="form-label">이메일 <span style="color: var(--danger-color);">*</span></label>
                    <input type="email" id="email" name="email" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="password" class="form-label">비밀번호 <span style="color: var(--danger-color);">*</span></label>
                    <input type="password" id="password" name="password" class="form-control" required minlength="8">
                    <small style="color: var(--text-light);">8자 이상 입력해주세요</small>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password" class="form-label">비밀번호 확인 <span style="color: var(--danger-color);">*</span></label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="full_name" class="form-label">이름 <span style="color: var(--danger-color);">*</span></label>
                    <input type="text" id="full_name" name="full_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="phone" class="form-label">전화번호</label>
                    <input type="tel" id="phone" name="phone" class="form-control" placeholder="010-1234-5678">
                </div>
                
                <!-- 관광객 추가 정보 -->
                <div id="touristFields" style="display: block;">
                    <h3 style="margin-bottom: 1rem; color: var(--primary-color); font-size: 1.25rem;">관광객 추가 정보</h3>
                    <div class="form-group">
                        <label for="national_id" class="form-label">주민등록번호 (앞 6자리)</label>
                        <input type="text" id="national_id" name="national_id" class="form-control" placeholder="예: 900101" maxlength="6">
                        <small style="color: var(--text-light);">부정 수급 방지를 위한 간단한 인증</small>
                    </div>
                    <div class="form-group">
                        <label for="birth_date" class="form-label">생년월일</label>
                        <input type="date" id="birth_date" name="birth_date" class="form-control">
                    </div>
                </div>
                
                <!-- 관광지 책임자 추가 정보 -->
                <div id="managerFields" style="display: none;">
                    <h3 style="margin-bottom: 1rem; color: var(--primary-color); font-size: 1.25rem;">관광지 책임자 추가 정보</h3>
                    <div class="alert alert-info">
                        <strong>🏛️ 관광지 책임자 가입 안내</strong>
                        <p style="margin: 0.5rem 0 0 0;">
                            • 관광지명과 사업자등록번호를 입력하세요.<br>
                            • 가입 후 관리자 승인을 받으면 관광지를 등록하고 관리할 수 있습니다.<br>
                            • 하나의 계정은 하나의 관광지만 담당할 수 있습니다.
                        </p>
                    </div>
                    
                    <div class="form-group">
                        <label for="attraction_name" class="form-label">관광지명</label>
                        <input type="text" id="attraction_name" name="attraction_name" class="form-control" 
                               placeholder="예: 경복궁">
                        <small style="color: var(--text-light);">
                            담당하실 관광지의 이름을 입력하세요.
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label for="business_registration_number" class="form-label">사업자등록번호</label>
                        <input type="text" id="business_registration_number" name="business_registration_number" class="form-control" 
                               placeholder="예: 123-45-67890">
                        <small style="color: var(--text-light);">
                            관광지의 사업자등록번호를 입력하세요.
                        </small>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">가입하기</button>
            </form>
            
            <?php endif; ?>
        </div>
        
        <div class="card-footer text-center">
            <p style="color: var(--text-light);">
                이미 계정이 있으신가요? <a href="login.php" style="color: var(--primary-color); font-weight: 500;">로그인</a>
            </p>
            <p style="color: var(--text-light); margin-top: 0.5rem;">
                <a href="index.php" style="color: var(--text-light);">← 메인으로 돌아가기</a>
            </p>
        </div>
    </div>
</div>

<script>
    // 계정 유형에 따른 추가 필드 표시/숨김
    const touristRadio = document.getElementById('tourist');
    const managerRadio = document.getElementById('attraction_manager');
    const touristFields = document.getElementById('touristFields');
    const managerFields = document.getElementById('managerFields');
    
    touristRadio.addEventListener('change', function() {
        if (this.checked) {
            touristFields.style.display = 'block';
            managerFields.style.display = 'none';
        }
    });
    
    managerRadio.addEventListener('change', function() {
        if (this.checked) {
            touristFields.style.display = 'none';
            managerFields.style.display = 'block';
        }
    });
</script>

<?php include 'includes/footer.php'; ?>
