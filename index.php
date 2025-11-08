<?php
require_once 'config/database.php';
require_once 'config/session.php';

// 데이터베이스 초기화 (최초 실행시)
if (!file_exists('config/.initialized')) {
    initializeDatabase();
    file_put_contents('config/.initialized', date('Y-m-d H:i:s'));
}

// 세션 메시지 가져오기
$session_success = $_SESSION['success_message'] ?? '';
$session_error = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

$page_title = '관광 코스 인증 시스템';
$base_url = '';
include 'includes/header.php';
?>
    
    <div class="container">
        <div class="card" style="text-align: center; padding: 3rem;">
            <?php if ($session_success || isset($_GET['success'])): ?>
                <div class="alert alert-success">
                    ✅ <?php echo htmlspecialchars($session_success ?: $_GET['success']); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($session_error): ?>
                <div class="alert alert-error">
                    ⚠️ <?php echo htmlspecialchars($session_error); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-warning">
                    <?php
                    switch($_GET['error']) {
                        case 'unauthorized':
                            echo '⚠️ 접근 권한이 없습니다.';
                            break;
                        default:
                            echo '오류가 발생했습니다.';
                    }
                    ?>
                </div>
            <?php endif; ?>
            
            <div class="page-header">
                <h1>🎯 관광 코스 인증 시스템</h1>
                <p>다양한 관광 코스를 체험하고 인증받아 보상을 받으세요!</p>
            </div>
            
            <?php if (!isLoggedIn()): ?>
                <div style="margin-top: 2rem;">
                    <a href="login.php" class="btn btn-primary btn-lg">로그인</a>
                    <a href="register.php" class="btn btn-outline btn-lg">회원가입</a>
                </div>
            <?php elseif (!isApproved()): ?>
                <div class="alert alert-warning" style="margin-top: 2rem; text-align: left;">
                    <h3>⏳ 계정 승인 대기 중</h3>
                    <p>
                        관광지 책임자 계정은 관리자의 승인이 필요합니다.<br>
                        승인이 완료되면 모든 기능을 이용하실 수 있습니다.<br>
                        <br>
                        <strong>승인 절차:</strong><br>
                        1. 관리자가 귀하의 정보를 검토합니다<br>
                        2. 승인 완료 시 자동으로 기능이 활성화됩니다<br>
                        3. 승인까지 영업일 기준 1-2일 소요됩니다
                    </p>
                </div>
            <?php else: ?>
                <div style="margin-top: 2rem;">
                    <?php if ($_SESSION['user_type'] === 'admin'): ?>
                        <a href="admin/dashboard.php" class="btn btn-primary btn-lg">관리자 대시보드로 이동</a>
                    <?php elseif ($_SESSION['user_type'] === 'attraction_manager'): ?>
                        <a href="attraction/dashboard.php" class="btn btn-primary btn-lg">관광지 관리 시작</a>
                    <?php else: ?>
                        <a href="tourist/dashboard.php" class="btn btn-primary btn-lg">코스 선택하기</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <div class="grid grid-3 mt-4">
                <div class="card">
                    <h3 style="color: var(--primary-color);">👥 관광객</h3>
                    <p>다양한 관광 코스를 선택하고 인증하여 보상을 받으세요.</p>
                </div>
                <div class="card">
                    <h3 style="color: var(--primary-color);">🏛️ 관광지 책임자</h3>
                    <p>관광지를 등록하고 관리하며 방문객 인증 코드를 발급하세요.</p>
                </div>
                <div class="card">
                    <h3 style="color: var(--primary-color);">⚙️ 시스템 관리자</h3>
                    <p>관광지와 코스를 등록하고 전체 시스템을 관리하세요.</p>
                </div>
            </div>
        </div>
    </div>

<?php include 'includes/footer.php'; ?>
