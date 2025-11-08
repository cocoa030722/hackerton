<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireUserType('tourist');

$conn = getDBConnection();
$user_id = $_SESSION['user_id'];

// 사용 가능한 모든 코스 조회
$available_courses = [];
$stmt = $conn->prepare("SELECT c.*, 
                        (SELECT COUNT(*) FROM course_attractions WHERE course_id = c.id) as attraction_count,
                        (SELECT GROUP_CONCAT(a.name SEPARATOR ', ') 
                         FROM course_attractions ca 
                         JOIN attractions a ON ca.attraction_id = a.id 
                         WHERE ca.course_id = c.id) as attraction_names,
                        (SELECT tc.status 
                         FROM tourist_courses tc 
                         WHERE tc.tourist_id = ? AND tc.course_id = c.id 
                         AND (tc.status != 'expired' 
                              AND (tc.status != 'completed' 
                                   OR tc.completed_at > DATE_SUB(NOW(), INTERVAL 30 DAY)))
                         ORDER BY tc.id DESC LIMIT 1) as enrollment_status
                        FROM courses c 
                        WHERE c.status = 'active' 
                        ORDER BY c.created_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $available_courses[] = $row;
}
$stmt->close();

$conn->close();

$page_title = '코스 선택';
$base_url = '..';
include '../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>🗺️ 관광 코스 선택</h1>
        <p>원하는 관광 코스를 선택하여 시작하세요!</p>
    </div>
    
    <?php if (empty($available_courses)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">🏖️</div>
            <div class="empty-state-text">등록된 코스가 없습니다</div>
            <p>관리자가 새로운 코스를 등록할 때까지 기다려주세요.</p>
        </div>
    <?php else: ?>
        <div class="grid grid-2">
            <?php foreach ($available_courses as $course): ?>
                <div class="card">
                    <div class="card-header">
                        <h2><?php echo htmlspecialchars($course['title']); ?></h2>
                        <?php if ($course['enrollment_status'] === 'in_progress'): ?>
                            <span class="badge badge-success">✓ 참여 중</span>
                        <?php elseif ($course['enrollment_status'] === 'completed'): ?>
                            <span class="badge badge-primary">✓ 완료</span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="card-body">
                        <p style="color: var(--text-light); margin-bottom: 1rem;"><?php echo htmlspecialchars($course['description']); ?></p>
                        
                        <div class="flex flex-gap" style="flex-wrap: wrap; margin-bottom: 1rem;">
                            <span style="color: var(--text-light);">난이도:</span>
                            <?php
                            $difficulty_badges = [
                                'easy' => '<span class="badge badge-success">쉬움</span>',
                                'normal' => '<span class="badge badge-warning">보통</span>',
                                'hard' => '<span class="badge badge-danger">어려움</span>'
                            ];
                            echo $difficulty_badges[$course['difficulty']] ?? '';
                            ?>
                            <?php if (isset($course['estimated_duration']) && $course['estimated_duration']): ?>
                                <span style="color: var(--text-light);">⏱️ <?php echo $course['estimated_duration']; ?>분</span>
                            <?php endif; ?>
                            <span style="color: var(--text-light);">📍 <?php echo $course['attraction_count']; ?>개 관광지</span>
                        </div>
                        
                        <div style="background: var(--bg-light); padding: 1rem; border-radius: var(--border-radius); margin-bottom: 1rem;">
                            <h4 style="color: var(--text-dark); margin-bottom: 0.5rem; font-size: 0.9rem;">포함된 관광지:</h4>
                            <div class="flex flex-gap" style="flex-wrap: wrap;">
                                <?php 
                                $attractions = explode(', ', $course['attraction_names']);
                                foreach ($attractions as $attr): 
                                ?>
                                    <span class="badge badge-info"><?php echo htmlspecialchars($attr); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <?php if (false): // reward_points 컬럼이 제거되어 숨김 처리 ?>
                        <div style="background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white; padding: 1rem; border-radius: var(--border-radius); margin-bottom: 1rem;">
                            <h4 style="font-size: 0.9rem; margin-bottom: 0.3rem; opacity: 0.9;">🎁 완료 시 보상</h4>
                            <div style="font-size: 1.5rem; font-weight: bold;">포인트 시스템 준비 중</div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="card-footer">
                        <?php if ($course['enrollment_status'] === 'completed'): ?>
                            <a href="my_courses.php" class="btn btn-success" style="width: 100%;">✓ 완료</a>
                        <?php elseif ($course['enrollment_status'] === 'in_progress'): ?>
                            <a href="my_courses.php" class="btn btn-primary" style="width: 100%;">참여 중 →</a>
                        <?php else: ?>
                            <form method="POST" action="enroll_course.php">
                                <input type="hidden" name="course_id" value="<?php echo $course['id']; ?>">
                                <button type="submit" class="btn btn-primary" style="width: 100%;">이 코스 시작하기</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>
