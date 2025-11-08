<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireUserType('tourist');

$conn = getDBConnection();
$user_id = $_SESSION['user_id'];

// 관광객 정보 가져오기
$stmt = $conn->prepare("SELECT * FROM tourist_info WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$tourist_info = $result->fetch_assoc();
$stmt->close();

// 진행 중인 코스 정보 가져오기
$stmt = $conn->prepare("SELECT c.title as name 
                        FROM tourist_courses tc
                        JOIN courses c ON tc.course_id = c.id
                        WHERE tc.tourist_id = ? AND tc.status = 'in_progress'
                        ORDER BY tc.started_at DESC
                        LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$current_course = $result->fetch_assoc();
$stmt->close();

$conn->close();

$page_title = '관광객 대시보드';
$base_url = '..';
include '../includes/header.php';
include '../includes/helpers.php';
?>

<div class="container">
    <div class="page-header">
        <h1>관광객 대시보드</h1>
        <p><?php echo htmlspecialchars($_SESSION['full_name']); ?>님, 즐거운 여행 되세요! 🌟</p>
    </div>
    
    <?php if ($current_course): ?>
        <div class="alert alert-info">
            <strong>📍 진행 중인 코스</strong><br>
            <?php echo htmlspecialchars($current_course['name']); ?> 코스를 진행 중입니다!
        </div>
    <?php else: ?>
        <div class="alert alert-warning">
            진행 중인 코스가 없습니다. 새로운 코스를 선택해보세요!
        </div>
    <?php endif; ?>
    
    <div class="grid grid-2">
        <div class="card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
            <div class="card-header" style="border-color: rgba(255,255,255,0.3);">
                <h2 style="color: white;">🗺️ 코스 선택</h2>
            </div>
            <div class="card-body">
                <p style="opacity: 0.95;">다양한 관광 코스를 선택하고 여행을 시작하세요.</p>
            </div>
            <div class="card-footer" style="border-color: rgba(255,255,255,0.3);">
                <a href="select_course.php" class="btn btn-secondary" style="background: white; color: var(--primary-color);">코스 선택하기</a>
            </div>
        </div>
        
        <div class="card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;">
            <div class="card-header" style="border-color: rgba(255,255,255,0.3);">
                <h2 style="color: white;">📋 내 코스</h2>
            </div>
            <div class="card-body">
                <p style="opacity: 0.95;">진행 중인 코스를 확인하고 관광지를 인증하세요.</p>
            </div>
            <div class="card-footer" style="border-color: rgba(255,255,255,0.3);">
                <a href="my_courses.php" class="btn btn-secondary" style="background: white; color: #f5576c;">내 코스 보기</a>
            </div>
        </div>
        
        <div class="card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white;">
            <div class="card-header" style="border-color: rgba(255,255,255,0.3);">
                <h2 style="color: white;">✓ 관광지 인증</h2>
            </div>
            <div class="card-body">
                <p style="opacity: 0.95;">QR 코드 또는 문자열 코드로 관광지를 인증하세요.</p>
            </div>
            <div class="card-footer" style="border-color: rgba(255,255,255,0.3);">
                <a href="verify_attraction.php" class="btn btn-secondary" style="background: white; color: #00f2fe;">인증하기</a>
            </div>
        </div>
        
        <div class="card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: white;">
            <div class="card-header" style="border-color: rgba(255,255,255,0.3);">
                <h2 style="color: white;">🎁 마이페이지</h2>
            </div>
            <div class="card-body">
                <p style="opacity: 0.95;">완료한 코스와 획득한 보상을 확인하세요.</p>
            </div>
            <div class="card-footer" style="border-color: rgba(255,255,255,0.3);">
                <a href="my_page.php" class="btn btn-secondary" style="background: white; color: #38f9d7;">마이페이지</a>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
