<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireUserType('tourist');

$conn = getDBConnection();
$user_id = $_SESSION['user_id'];

// tourist_course_id가 전달되었는지 확인
if (!isset($_GET['tc_id'])) {
    header('Location: my_courses.php');
    exit();
}

$tourist_course_id = intval($_GET['tc_id']);

// 코스 정보 및 완료 여부 확인
$stmt = $conn->prepare("SELECT tc.*, c.name as course_name, c.reward_points,
                        (SELECT COUNT(*) FROM course_attractions WHERE course_id = tc.course_id) as total_attractions,
                        (SELECT COUNT(DISTINCT attraction_id) FROM attraction_verifications 
                         WHERE tourist_course_id = tc.id AND is_used = TRUE) as verified_count
                        FROM tourist_courses tc
                        JOIN courses c ON tc.course_id = c.id
                        WHERE tc.id = ? AND tc.tourist_id = ?");
$stmt->bind_param("ii", $tourist_course_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error'] = '유효하지 않은 요청입니다.';
    $stmt->close();
    $conn->close();
    header('Location: my_courses.php');
    exit();
}

$course = $result->fetch_assoc();
$stmt->close();

// 코스가 완료되지 않았으면 리다이렉트
if ($course['status'] !== 'completed') {
    $_SESSION['error'] = '아직 완료되지 않은 코스입니다.';
    $conn->close();
    header('Location: my_courses.php');
    exit();
}

// 이미 보상을 신청했는지 확인
$stmt = $conn->prepare("SELECT * FROM reward_claims WHERE tourist_course_id = ?");
$stmt->bind_param("i", $tourist_course_id);
$stmt->execute();
$result = $stmt->get_result();
$existing_claim = $result->fetch_assoc();
$stmt->close();

$success = '';
$error = '';

// 보상 신청 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$existing_claim) {
    $reward_type = $_POST['reward_type'] ?? 'cash';
    $base_points = $course['reward_points'];
    
    // 지역화폐 선택 시 10% 추가
    if ($reward_type === 'local_currency') {
        $bonus_rate = 10.00;
        $total_reward = $base_points * 1.10;
    } else {
        $bonus_rate = 0.00;
        $total_reward = $base_points;
    }
    
    // 보상 신청 등록 (더미 로직: 즉시 승인 완료 처리)
    // status='approved', approved_at=NOW()로 설정하여 신청과 동시에 승인 완료
    $stmt = $conn->prepare("INSERT INTO reward_claims 
                            (tourist_course_id, tourist_id, course_id, reward_points, reward_type, bonus_rate, total_reward, status, approved_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'approved', NOW())");
    $stmt->bind_param("iiiisdi", $tourist_course_id, $user_id, $course['course_id'], 
                      $base_points, $reward_type, $bonus_rate, $total_reward);
    
    if ($stmt->execute()) {
        $claim_id = $stmt->insert_id;
        $stmt->close();
        
        // tourist_courses 업데이트
        $stmt = $conn->prepare("UPDATE tourist_courses SET reward_claimed = TRUE, reward_claim_id = ? WHERE id = ?");
        $stmt->bind_param("ii", $claim_id, $tourist_course_id);
        $stmt->execute();
        $stmt->close();
        
        // 완료된 코스 DB에서 삭제 (보상 신청 정보는 reward_claims에 보존됨)
        $stmt = $conn->prepare("DELETE FROM tourist_courses WHERE id = ?");
        $stmt->bind_param("i", $tourist_course_id);
        $stmt->execute();
        $stmt->close();
        
        $conn->close();
        
        // 마이페이지로 리다이렉트
        $_SESSION['success'] = '보상 신청이 완료되었습니다! 승인이 완료되어 곧 지급됩니다.';
        header('Location: my_page.php');
        exit();
    } else {
        $error = '보상 신청 중 오류가 발생했습니다.';
        $stmt->close();
    }
}

$conn->close();

$page_title = '보상 신청';
$base_url = '..';
include '../includes/header.php';
?>

    <div class="container" style="max-width: 600px; padding: 2rem;">
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>
        
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <div class="emoji">🎁</div>
                <h1>보상 신청</h1>
            </div>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div class="course-info">
                <h2><?php echo htmlspecialchars($course['course_name']); ?></h2>
                <p>✓ 완료일: <?php echo date('Y년 m월 d일', strtotime($course['completed_at'])); ?></p>
                <p>✓ 인증 관광지: <?php echo $course['verified_count']; ?>개 / <?php echo $course['total_attractions']; ?>개</p>
            </div>
            
            <?php if (!$existing_claim): ?>
                <div class="reward-info">
                    <h3>기본 보상 포인트</h3>
                    <div class="reward-amount"><?php echo number_format($course['reward_points']); ?>원</div>
                    <p style="text-align: center; color: #666;">코스 완료 보상</p>
                </div>
                
                <form method="POST" id="rewardForm">
                    <div class="reward-options">
                        <h3 style="margin-bottom: 1rem; color: #333;">보상 수령 방법 선택</h3>
                        
                        <div class="option-card" onclick="selectOption('cash')">
                            <input type="radio" name="reward_type" value="cash" id="cash" checked>
                            <label for="cash">
                                <div class="option-title">💵 현금</div>
                                <div class="option-description">계좌로 보상금을 받습니다</div>
                                <div class="option-amount" id="cash-amount">
                                    <?php echo number_format($course['reward_points']); ?>원
                                </div>
                            </label>
                        </div>
                        
                        <div class="option-card" onclick="selectOption('local_currency')">
                            <input type="radio" name="reward_type" value="local_currency" id="local_currency">
                            <label for="local_currency">
                                <div class="option-title">🏝️ 지역화폐</div>
                                <div class="option-description">지역화폐로 받으면 10% 추가 지급!</div>
                                <div class="option-bonus">+ 10% 보너스</div>
                                <div class="option-amount" id="local-amount">
                                    <?php echo number_format($course['reward_points'] * 1.1); ?>원
                                </div>
                            </label>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">보상 신청하기</button>
                </form>
                
                <div class="alert alert-warning" style="margin-top: 1.5rem;">
                    <strong>📌 안내사항</strong><br>
                    • 보상 신청 시 자동으로 승인됩니다<br>
                    • 승인 완료 후 3-5 영업일 이내 지급됩니다<br>
                    • 신청 내역은 마이페이지에서 확인할 수 있습니다
                </div>
                
            <?php else: ?>
                <div class="reward-info">
                    <h3>신청한 보상</h3>
                    <div class="reward-amount"><?php echo number_format($existing_claim['total_reward']); ?>원</div>
                    <p style="text-align: center; color: #666;">
                        <?php echo $existing_claim['reward_type'] === 'local_currency' ? '🏝️ 지역화폐 (10% 보너스 포함)' : '💵 현금'; ?>
                    </p>
                    
                    <?php
                    $status_text = [
                        'pending' => '승인 대기 중',
                        'approved' => '승인 완료',
                        'rejected' => '승인 거부',
                        'paid' => '지급 완료'
                    ];
                    $status_class = [
                        'pending' => 'status-pending',
                        'approved' => 'status-approved',
                        'rejected' => 'status-error',
                        'paid' => 'status-paid'
                    ];
                    ?>
                    
                    <div style="text-align: center;">
                        <span class="status-badge <?php echo $status_class[$existing_claim['status']]; ?>">
                            <?php echo $status_text[$existing_claim['status']]; ?>
                        </span>
                    </div>
                </div>
                
                <div class="claim-details">
                    <p><strong>신청 번호:</strong> #<?php echo str_pad($existing_claim['id'], 8, '0', STR_PAD_LEFT); ?></p>
                    <p><strong>신청일:</strong> <?php echo date('Y년 m월 d일 H:i', strtotime($existing_claim['claimed_at'])); ?></p>
                    <p><strong>기본 포인트:</strong> <?php echo number_format($existing_claim['reward_points']); ?>원</p>
                    <?php if ($existing_claim['bonus_rate'] > 0): ?>
                        <p><strong>보너스:</strong> +<?php echo $existing_claim['bonus_rate']; ?>% (<?php echo number_format($existing_claim['reward_points'] * $existing_claim['bonus_rate'] / 100); ?>원)</p>
                    <?php endif; ?>
                    <p><strong>최종 금액:</strong> <?php echo number_format($existing_claim['total_reward']); ?>원</p>
                    
                    <?php if ($existing_claim['status'] === 'approved' || $existing_claim['status'] === 'paid'): ?>
                        <p><strong>승인일:</strong> <?php echo date('Y년 m월 d일 H:i', strtotime($existing_claim['approved_at'])); ?></p>
                    <?php endif; ?>
                    
                    <?php if ($existing_claim['notes']): ?>
                        <p><strong>비고:</strong> <?php echo htmlspecialchars($existing_claim['notes']); ?></p>
                    <?php endif; ?>
                </div>
                
                <div class="alert alert-warning" style="margin-top: 1.5rem;">
                    <?php if ($existing_claim['status'] === 'pending'): ?>
                        <strong>⏳ 승인 대기 중</strong><br>
                        관리자가 보상 신청을 검토 중입니다. 잠시만 기다려주세요.
                    <?php elseif ($existing_claim['status'] === 'approved'): ?>
                        <strong>✅ 승인 완료</strong><br>
                        보상이 승인되었습니다! 3-5 영업일 이내 지급됩니다.
                    <?php elseif ($existing_claim['status'] === 'paid'): ?>
                        <strong>🎉 지급 완료</strong><br>
                        보상이 성공적으로 지급되었습니다. 감사합니다!
                    <?php else: ?>
                        <strong>❌ 승인 거부</strong><br>
                        보상 신청이 거부되었습니다. 자세한 내용은 관리자에게 문의하세요.
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <a href="my_courses.php" class="btn btn-secondary">내 코스로 돌아가기</a>
        </div>
    </div>
    
    <script>
        function selectOption(type) {
            // 모든 option-card에서 selected 클래스 제거
            document.querySelectorAll('.option-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            // 선택한 옵션에 selected 클래스 추가
            event.currentTarget.classList.add('selected');
            
            // 라디오 버튼 체크
            document.getElementById(type).checked = true;
        }
        
        // 페이지 로드 시 첫 번째 옵션 선택
        document.addEventListener('DOMContentLoaded', function() {
            const firstOption = document.querySelector('.option-card');
            if (firstOption) {
                firstOption.classList.add('selected');
            }
        });
    </script>

<?php include '../includes/footer.php'; ?>