<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireUserType('tourist');

$conn = getDBConnection();
$user_id = $_SESSION['user_id'];
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// 코스 중단 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_course') {
    $tourist_course_id = intval($_POST['tourist_course_id']);
    
    // tourist_course가 현재 사용자의 것인지 확인
    $stmt = $conn->prepare("SELECT tc.id, c.name FROM tourist_courses tc 
                            JOIN courses c ON tc.course_id = c.id
                            WHERE tc.id = ? AND tc.tourist_id = ? AND tc.status = 'in_progress'");
    $stmt->bind_param("ii", $tourist_course_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $course_info = $result->fetch_assoc();
        $stmt->close();
        
        // 코스 상태를 expired로 변경
        $stmt = $conn->prepare("UPDATE tourist_courses SET status = 'expired' WHERE id = ?");
        $stmt->bind_param("i", $tourist_course_id);
        
        if ($stmt->execute()) {
            $success = "'{$course_info['name']}' 코스를 중단했습니다.";
        } else {
            $error = '코스 중단 중 오류가 발생했습니다.';
        }
        $stmt->close();
    } else {
        $error = '유효하지 않은 요청입니다.';
        if ($result) $stmt->close();
    }
}

// 인증 코드 제출 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_code') {
    $code = strtoupper(trim($_POST['verification_code']));
    $tourist_course_id = intval($_POST['tourist_course_id']);
    
    // tourist_course가 현재 사용자의 것인지 확인
    $stmt = $conn->prepare("SELECT tc.id, tc.course_id FROM tourist_courses tc WHERE tc.id = ? AND tc.tourist_id = ? AND tc.status = 'in_progress'");
    $stmt->bind_param("ii", $tourist_course_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $error = '유효하지 않은 요청입니다.';
    } else {
        $tc = $result->fetch_assoc();
        $stmt->close();
        
        // 인증 코드 확인
        $stmt = $conn->prepare("SELECT av.*, a.id as attraction_id, a.name as attraction_name 
                                FROM attraction_verifications av
                                JOIN attractions a ON av.attraction_id = a.id
                                WHERE av.verification_code = ? 
                                AND av.expires_at > NOW()
                                LIMIT 1");
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $error = '유효하지 않거나 만료된 코드입니다.';
        } else {
            $verification = $result->fetch_assoc();
            $stmt->close();
            
            // 해당 관광지가 코스에 포함되어 있는지 확인
            $stmt = $conn->prepare("SELECT id FROM course_attractions WHERE course_id = ? AND attraction_id = ?");
            $stmt->bind_param("ii", $tc['course_id'], $verification['attraction_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                $error = '이 관광지는 현재 코스에 포함되어 있지 않습니다.';
            } else {
                $stmt->close();
                
                // 이미 인증했는지 확인
                $stmt = $conn->prepare("SELECT id FROM attraction_verifications 
                                        WHERE tourist_course_id = ? AND attraction_id = ?");
                $stmt->bind_param("ii", $tourist_course_id, $verification['attraction_id']);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $error = '이미 인증한 관광지입니다.';
                } else {
                    $stmt->close();
                    
                    // QR 코드인 경우 30일 이내 재인증 확인
                    if ($verification['code_type'] === 'qr') {
                        $stmt = $conn->prepare("SELECT id FROM attraction_verifications 
                                                WHERE tourist_id = ? 
                                                AND attraction_id = ? 
                                                AND used_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
                        $stmt->bind_param("ii", $user_id, $verification['attraction_id']);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        
                        if ($result->num_rows > 0) {
                            $error = '이 관광지는 30일 이내에 이미 인증하셨습니다.';
                            $stmt->close();
                        } else {
                            $stmt->close();
                            // QR 코드 사용 처리
                            $stmt = $conn->prepare("UPDATE attraction_verifications 
                                                    SET is_used = TRUE, used_at = NOW(), tourist_id = ?, tourist_course_id = ? 
                                                    WHERE id = ?");
                            $stmt->bind_param("iii", $user_id, $tourist_course_id, $verification['id']);
                            $stmt->execute();
                            $stmt->close();
                            
                            $success = "'{$verification['attraction_name']}' 인증이 완료되었습니다! 🎉";
                        }
                    } else {
                        // 문자열 코드인 경우
                        if ($verification['is_used']) {
                            $error = '이미 사용된 코드입니다.';
                        } else {
                            // 문자열 코드 사용 처리
                            $stmt = $conn->prepare("UPDATE attraction_verifications 
                                                    SET is_used = TRUE, used_at = NOW(), tourist_id = ?, tourist_course_id = ? 
                                                    WHERE id = ?");
                            $stmt->bind_param("iii", $user_id, $tourist_course_id, $verification['id']);
                            $stmt->execute();
                            $stmt->close();
                            
                            $success = "'{$verification['attraction_name']}' 인증이 완료되었습니다! 🎉";
                        }
                    }
                    
                    // 코스 완료 확인 및 진행도 업데이트
                    if ($success) {
                        $stmt = $conn->prepare("SELECT COUNT(*) as total_attractions FROM course_attractions WHERE course_id = ?");
                        $stmt->bind_param("i", $tc['course_id']);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $total = $result->fetch_assoc()['total_attractions'];
                        $stmt->close();
                        
                        $stmt = $conn->prepare("SELECT COUNT(DISTINCT attraction_id) as verified_count 
                                                FROM attraction_verifications 
                                                WHERE tourist_course_id = ? AND is_used = TRUE");
                        $stmt->bind_param("i", $tourist_course_id);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $verified = $result->fetch_assoc()['verified_count'];
                        $stmt->close();
                        
                        // 진행도 계산 및 업데이트
                        $progress = $total > 0 ? round(($verified / $total) * 100, 2) : 0;
                        
                        if ($verified >= $total) {
                            // 코스 완료
                            $stmt = $conn->prepare("UPDATE tourist_courses SET status = 'completed', completed_at = NOW(), progress_percentage = 100 WHERE id = ?");
                            $stmt->bind_param("i", $tourist_course_id);
                            $stmt->execute();
                            $stmt->close();
                            
                            // 보상 신청 페이지로 리다이렉트
                            $_SESSION['success'] = '🎊 코스를 완료하셨습니다! 보상을 받으실 수 있습니다.';
                            header("Location: claim_reward.php?tc_id=" . $tourist_course_id);
                            exit();
                        } else {
                            // 진행도만 업데이트
                            $stmt = $conn->prepare("UPDATE tourist_courses SET progress_percentage = ? WHERE id = ?");
                            $stmt->bind_param("di", $progress, $tourist_course_id);
                            $stmt->execute();
                            $stmt->close();
                        }
                    }
                }
            }
        }
    }
}

// 진행 중인 코스 목록만 표시 (완료된 코스는 제외)
$my_courses = [];
$stmt = $conn->prepare("SELECT tc.*, c.title as name, c.description, c.difficulty, c.reward_points,
                        (SELECT COUNT(*) FROM course_attractions WHERE course_id = c.id) as total_attractions
                        FROM tourist_courses tc
                        JOIN courses c ON tc.course_id = c.id
                        WHERE tc.tourist_id = ? 
                        AND tc.status = 'in_progress'
                        ORDER BY tc.started_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    // 코스의 관광지 목록 가져오기
    $course_id = $row['course_id'];
    $attraction_stmt = $conn->prepare("SELECT ca.attraction_id, a.name,
                                       (SELECT COUNT(*) FROM attraction_verifications av 
                                        WHERE av.tourist_id = ? 
                                        AND av.attraction_id = ca.attraction_id 
                                        AND av.is_verified = TRUE 
                                        AND av.verified_at >= ?) as is_verified
                                       FROM course_attractions ca
                                       JOIN attractions a ON ca.attraction_id = a.id
                                       WHERE ca.course_id = ?
                                       ORDER BY ca.id");
    $attraction_stmt->bind_param("isi", $user_id, $row['started_at'], $course_id);
    $attraction_stmt->execute();
    $attraction_result = $attraction_stmt->get_result();
    
    $verified_count = 0;
    while ($attr = $attraction_result->fetch_assoc()) {
        if ($attr['is_verified'] > 0) {
            $verified_count++;
        }
    }
    $attraction_stmt->close();
    
    $row['verified_count'] = $verified_count;
    $my_courses[] = $row;
}
$stmt->close();

$conn->close();

$page_title = '내 코스';
$base_url = '..';
include '../includes/header.php';
?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo $error; ?></div>
    <?php endif; ?>
        
    <div class="container">
        <div class="page-header">
            <h1>내 코스</h1>
            <p style="color: var(--text-light);">진행 중인 코스를 확인하고 관광지를 인증하세요.</p>
        
        <?php if (empty($my_courses)): ?>
            <div class="empty-state">
                <div style="font-size: 3rem; margin-bottom: 1rem;">📭</div>
                <h2>참여 중인 코스가 없습니다</h2>
                <p><a href="select_course.php" class="btn btn-primary" style="margin-top: 1rem;">코스 선택하기</a></p>
            </div>
        <?php else: ?>
            <div style="display: grid; gap: 2rem;">
                <?php foreach ($my_courses as $course): ?>
                    <?php
                    $progress_percent = $course['total_attractions'] > 0 
                        ? ($course['verified_count'] / $course['total_attractions'] * 100) 
                        : 0;
                    $is_completed = $course['status'] === 'completed';
                    
                    // 코스의 관광지 목록 가져오기
                    $conn2 = getDBConnection();
                    $stmt2 = $conn2->prepare("SELECT a.id, a.name,
                                              EXISTS(SELECT 1 FROM attraction_verifications av 
                                                     WHERE av.tourist_course_id = ? 
                                                     AND av.attraction_id = a.id 
                                                     AND av.is_used = TRUE) as is_verified
                                              FROM course_attractions ca
                                              JOIN attractions a ON ca.attraction_id = a.id
                                              WHERE ca.course_id = ?");
                    $stmt2->bind_param("ii", $course['id'], $course['course_id']);
                    $stmt2->execute();
                    $result2 = $stmt2->get_result();
                    $attractions = [];
                    while ($row2 = $result2->fetch_assoc()) {
                        $attractions[] = $row2;
                    }
                    $stmt2->close();
                    $conn2->close();
                    ?>
                    
                    <div class="card" style="<?php echo $is_completed ? 'border-left: 5px solid var(--success-color);' : 'border-left: 5px solid var(--primary-color);'; ?>">
                        <div class="card-header flex-between">
                            <h2><?php echo htmlspecialchars($course['name']); ?></h2>
                            <span class="badge <?php echo $is_completed ? 'badge-success' : 'badge-info'; ?>">
                                <?php echo $is_completed ? '✓ 완료' : '진행 중'; ?>
                            </span>
                        </div>
                        
                        <div class="card-body">
                            <p style="color: var(--text-light); margin-bottom: 1.5rem;"><?php echo htmlspecialchars($course['description']); ?></p>
                            
                            <div style="margin-bottom: 1.5rem;">
                                <div style="background: var(--bg-light); height: 30px; border-radius: 15px; overflow: hidden; margin-bottom: 0.5rem;">
                                    <div style="height: 100%; background: <?php echo $is_completed ? 'linear-gradient(90deg, var(--success-color), #38a169)' : 'linear-gradient(90deg, var(--primary-color), #667eea)'; ?>; 
                                                width: <?php echo $progress_percent; ?>%; display: flex; align-items: center; justify-content: center; 
                                                color: white; font-weight: bold; transition: width 0.3s;">
                                        <?php echo $course['verified_count']; ?> / <?php echo $course['total_attractions']; ?>
                                    </div>
                                </div>
                                <p style="text-align: center; color: var(--text-light); font-size: 0.9rem;">
                                    <?php echo round($progress_percent); ?>% 완료
                                </p>
                            </div>
                            
                            <details style="margin-bottom: 1.5rem;">
                                <summary style="cursor: pointer; padding: 0.8rem; background: var(--bg-light); border-radius: var(--border-radius); 
                                                font-weight: 600; color: var(--primary-color);">
                                    📍 포함된 관광지 목록 (<?php echo count($attractions); ?>개)
                                </summary>
                                <div style="margin-top: 1rem;">
                                    <?php foreach ($attractions as $attr): ?>
                                        <div style="padding: 1rem; margin: 0.5rem 0; background: <?php echo $attr['is_verified'] ? '#f1f8f4' : 'white'; ?>; 
                                                    border-radius: var(--border-radius); border-left: 3px solid <?php echo $attr['is_verified'] ? 'var(--success-color)' : 'var(--bg-light)'; ?>;">
                                            <strong><?php echo htmlspecialchars($attr['name']); ?></strong>
                                            <?php if ($attr['is_verified']): ?>
                                                <span style="color: var(--success-color); float: right;">✓ 인증 완료</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                            
                            <?php if (!$is_completed): ?>
                                <div style="background: var(--bg-light); padding: 1.5rem; border-radius: var(--border-radius);">
                                    <h3 style="margin-bottom: 1rem;">🎫 관광지 인증</h3>
                                    <p style="color: var(--text-light); margin-bottom: 1rem;">QR 코드 스캔 또는 문자열 코드로 관광지를 인증하세요.</p>
                                    <a href="verify_attraction.php?tc_id=<?php echo $course['id']; ?>" 
                                       class="btn btn-primary mb-2" 
                                       style="width: 100%; text-align: center; display: block;">
                                        📷 관광지 인증하기
                                    </a>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="verify_code">
                                        <input type="hidden" name="tourist_course_id" value="<?php echo $course['id']; ?>">
                                        
                                        <div class="form-group">
                                            <label for="code_<?php echo $course['id']; ?>">또는 코드 직접 입력</label>
                                            <input type="text" 
                                                   id="code_<?php echo $course['id']; ?>" 
                                                   name="verification_code" 
                                                   class="form-control"
                                                   placeholder="코드를 입력하세요 (예: ABC123)"
                                                   required
                                                   autocomplete="off"
                                                   style="text-transform: uppercase;">
                                        </div>
                                        
                                        <button type="submit" class="btn btn-primary mb-2" style="width: 100%;">빠른 인증</button>
                                    </form>
                                    
                                    <!-- 코스 중단 버튼 -->
                                    <form method="POST" onsubmit="return confirm('정말로 이 코스를 중단하시겠습니까? 진행 상황은 저장되지 않습니다.');">
                                        <input type="hidden" name="action" value="cancel_course">
                                        <input type="hidden" name="tourist_course_id" value="<?php echo $course['id']; ?>">
                                        <button type="submit" class="btn btn-danger" style="width: 100%;">
                                            ❌ 코스 중단
                                        </button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <div style="background: linear-gradient(135deg, var(--success-color), #38a169); 
                                            padding: 1.5rem; border-radius: var(--border-radius); text-align: center; color: white;">
                                    <h3 style="margin-bottom: 0.5rem;">🎉 코스 완료!</h3>
                                    <p>완료일: <?php echo date('Y년 m월 d일', strtotime($course['completed_at'])); ?></p>
                                    <p style="font-size: 1.2rem; font-weight: bold; margin-top: 0.5rem;">
                                        보상: <?php echo number_format($course['reward_points']); ?> 포인트
                                    </p>
                                    <?php if (!$course['reward_claimed']): ?>
                                        <a href="claim_reward.php?tc_id=<?php echo $course['id']; ?>" 
                                           class="btn" style="margin-top: 1rem; background: white; color: var(--success-color); font-weight: bold;">
                                            🎁 보상 받기
                                        </a>
                                    <?php else: ?>
                                        <p style="margin-top: 1rem; font-weight: bold;">
                                            ✅ 보상 신청 완료
                                        </p>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

<?php include '../includes/footer.php'; ?>
