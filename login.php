<?php
require_once 'config/database.php';
require_once 'config/session.php';

// 이미 로그인된 경우 리다이렉트
if (isLoggedIn()) {
    header('Location: index.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = '아이디와 비밀번호를 입력해주세요.';
    } else {
        $conn = getDBConnection();
        
        $stmt = $conn->prepare("SELECT id, username, password, user_type, full_name, status FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            if (password_verify($password, $user['password'])) {
                if ($user['status'] === 'rejected') {
                    $error = '계정이 승인 거부되었습니다. 관리자에게 문의하세요.';
                } else {
                    // 세션 설정
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['user_type'] = $user['user_type'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['status'] = $user['status'];
                    
                    // 로그인 성공 시 무조건 index.php로 리다이렉트
                    header('Location: index.php');
                    exit();
                }
            } else {
                $error = '아이디 또는 비밀번호가 올바르지 않습니다.';
            }
        } else {
            $error = '아이디 또는 비밀번호가 올바르지 않습니다.';
        }
        
        $stmt->close();
        $conn->close();
    }
}

$page_title = '로그인 - 관광 코스 인증 시스템';
$base_url = '';
include 'includes/header.php';
?>

<div class="container" style="max-width: 500px;">
    <div class="card" style="margin-top: 2rem;">
        <div class="card-header text-center">
            <h2>🔐 로그인</h2>
            <p style="color: var(--text-light); margin-top: 0.5rem;">관광 코스 인증 시스템</p>
        </div>
        
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-error">⚠️ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="username" class="form-label">아이디</label>
                    <input type="text" id="username" name="username" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="password" class="form-label">비밀번호</label>
                    <input type="password" id="password" name="password" class="form-control" required>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">로그인</button>
            </form>
        </div>
        
        <div class="card-footer text-center">
            <p style="color: var(--text-light);">
                계정이 없으신가요? <a href="register.php" style="color: var(--primary-color); font-weight: 500;">회원가입</a>
            </p>
            <p style="color: var(--text-light); margin-top: 0.5rem;">
                <a href="index.php" style="color: var(--text-light);">← 메인으로 돌아가기</a>
            </p>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
