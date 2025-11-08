<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireUserType('tourist');

$conn = getDBConnection();
$user_id = $_SESSION['user_id'];

// 사용자 정보
$stmt = $conn->prepare("SELECT u.*, ti.* FROM users u 
                        LEFT JOIN tourist_info ti ON u.id = ti.user_id 
                        WHERE u.id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_info = $result->fetch_assoc();
$stmt->close();

// 완료한 코스 목록 (reward_claims 테이블에서 가져오기)
$stmt = $conn->prepare("SELECT rc.*, c.title as name, c.description, c.reward_points,
                        (SELECT COUNT(*) FROM course_attractions WHERE course_id = rc.course_id) as total_attractions,
                        rc.claimed_at as completed_at
                        FROM reward_claims rc
                        JOIN courses c ON rc.course_id = c.id
                        WHERE rc.tourist_id = ?
                        ORDER BY rc.approved_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$completed_courses = [];
while ($row = $result->fetch_assoc()) {
    $row['reward_status'] = $row['status'];
    $row['verified_count'] = $row['total_attractions']; // 완료된 코스는 모든 관광지 인증됨
    
    // 해당 코스의 관광지 목록 가져오기
    $course_id = $row['course_id'];
    $attr_stmt = $conn->prepare("SELECT a.name, a.address, ca.sequence_order 
                                   FROM course_attractions ca 
                                   JOIN attractions a ON ca.attraction_id = a.id 
                                   WHERE ca.course_id = ? 
                                   ORDER BY ca.sequence_order");
    $attr_stmt->bind_param("i", $course_id);
    $attr_stmt->execute();
    $attr_result = $attr_stmt->get_result();
    $attractions = [];
    while ($attr_row = $attr_result->fetch_assoc()) {
        $attractions[] = $attr_row;
    }
    $attr_stmt->close();
    $row['attractions'] = $attractions;
    
    $completed_courses[] = $row;
}
$stmt->close();

// 진행 중인 코스
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM tourist_courses WHERE tourist_id = ? AND status = 'in_progress'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$in_progress_count = $result->fetch_assoc()['count'];
$stmt->close();

// 통계
$total_completed = count($completed_courses);
$total_attractions = 0;
$total_rewards = 0;
foreach ($completed_courses as $course) {
    $total_attractions += $course['verified_count'];
    // 승인 완료(approved) 또는 지급 완료(paid) 상태만 합산
    if ($course['reward_status'] === 'approved' || $course['reward_status'] === 'paid') {
        $total_rewards += $course['total_reward'];
    }
}

$conn->close();

$page_title = '마이페이지';
$base_url = '..';
include '../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>👤 마이페이지</h1>
        <p><?php echo htmlspecialchars($user_info['full_name']); ?>님의 여행 기록</p>
    </div>
    
    <!-- 프로필 정보 -->
    <div class="card">
        <div class="card-body text-center">
            <div style="width: 100px; height: 100px; background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 3rem; margin: 0 auto 1rem; color: white;">
                👤
            </div>
            <h2 style="font-size: 1.8rem; margin-bottom: 0.5rem;"><?php echo htmlspecialchars($user_info['full_name']); ?></h2>
            <p style="color: var(--text-light); margin-bottom: 1.5rem;"><?php echo htmlspecialchars($user_info['email']); ?></p>
            
            <div class="grid grid-3">
                <div class="stat-card">
                    <div class="stat-card-value"><?php echo $total_completed; ?></div>
                    <div class="stat-card-label">완료한 코스</div>
                </div>
                <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <div class="stat-card-value"><?php echo $total_attractions; ?></div>
                    <div class="stat-card-label">방문한 관광지</div>
                </div>
                <div class="stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <div class="stat-card-value"><?php echo number_format($total_rewards); ?>P</div>
                    <div class="stat-card-label">획득한 포인트</div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="page-header">
        <h2>🏆 완료한 코스</h2>
        <p>진행 중인 코스: <?php echo $in_progress_count; ?>개</p>
    </div>
            
    <?php if (empty($completed_courses)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">🗺️</div>
            <p class="empty-state-text">아직 완료한 코스가 없습니다</p>
            <p>코스를 선택하고 관광지를 방문해보세요!</p>
            <a href="select_course.php" class="btn btn-primary mt-2">코스 둘러보기</a>
        </div>
    <?php else: ?>
        <div class="grid grid-2">
            <?php foreach ($completed_courses as $course): ?>
                <div class="card course-card" id="course-card-<?php echo $course['id']; ?>">
                    <div class="card-header flex-between">
                        <h3><?php echo htmlspecialchars($course['name']); ?></h3>
                        <span class="badge badge-success">✓ 완료</span>
                    </div>
                    
                    <div class="card-body">
                        <p style="color: var(--text-light); margin-bottom: 1rem;">
                            <?php echo htmlspecialchars($course['description']); ?>
                        </p>
                        <p style="color: var(--text-light); font-size: 0.9rem; margin-bottom: 1rem;">
                            완료일: <?php echo date('Y.m.d', strtotime($course['completed_at'])); ?>
                        </p>
                        
                        <!-- 관광지 목록 -->
                        <div style="background: var(--bg-light); padding: 1rem; border-radius: var(--border-radius); margin-bottom: 1rem;">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;">
                                <span style="font-weight: 600; color: var(--text-dark);">📍 방문 관광지</span>
                                <span style="font-weight: 600; color: var(--primary-color);">
                                    <?php echo $course['verified_count']; ?> / <?php echo $course['total_attractions']; ?>
                                </span>
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                                <?php foreach ($course['attractions'] as $attraction): ?>
                                    <div style="display: flex; align-items: start; padding: 0.5rem; background: white; border-radius: 6px;">
                                        <span style="color: var(--success-color); margin-right: 0.5rem; font-size: 1.1rem;">✓</span>
                                        <div style="flex: 1;">
                                            <div style="font-weight: 500; color: var(--text-dark); margin-bottom: 0.2rem;">
                                                <?php echo $attraction['sequence_order']; ?>. <?php echo htmlspecialchars($attraction['name']); ?>
                                            </div>
                                            <div style="font-size: 0.85rem; color: var(--text-light);">
                                                <?php echo htmlspecialchars($attraction['address']); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <?php if ($course['reward_status']): ?>
                            <div style="background: linear-gradient(135deg, var(--success-color), #38a169); color: white; padding: 1rem; border-radius: var(--border-radius); margin-bottom: 1rem; text-align: center;">
                                <div style="font-size: 1.8rem; font-weight: bold; margin-bottom: 0.5rem;">
                                    <?php echo number_format($course['total_reward']); ?>원
                                    <?php if ($course['reward_type'] === 'local_currency'): ?>🏝️<?php else: ?>💵<?php endif; ?>
                                </div>
                                <div style="font-size: 0.9rem; opacity: 0.9;">
                                    <?php
                                    $status_text = [
                                        'pending' => '⏳ 승인 대기 중',
                                        'approved' => '✅ 승인 완료',
                                        'rejected' => '❌ 승인 거부',
                                        'paid' => '🎉 지급 완료'
                                    ];
                                    echo $status_text[$course['reward_status']];
                                    ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <button class="btn btn-primary" style="width: 100%;" 
                                onclick="shareCompletedCourse('<?php echo $course['id']; ?>', '<?php echo htmlspecialchars($course['name'], ENT_QUOTES); ?>', <?php echo $course['verified_count']; ?>)">
                            📸 완료 인증 공유
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- html2canvas 라이브러리 -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    
    <!-- 공유 모달 -->
    <div class="share-modal" id="shareModal">
        <div class="share-content">
            <div class="share-header">
                <h3>🎉 완료 인증을 공유하세요!</h3>
                <button class="close-btn" onclick="closeShareModal()">✕</button>
            </div>
            
            <div id="shareImagePreview" style="margin: 1.5rem 0; text-align: center;">
                <img id="capturedImage" style="max-width: 100%; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);" />
            </div>
            
            <p style="color: #666; text-align: center; margin-bottom: 1.5rem;">
                이미지가 저장되었습니다! 💾<br>
                SNS에 공유하여 여행 경험을 자랑해보세요! 🚀
            </p>
            
            <div class="share-buttons">
                <button class="share-button share-kakao" onclick="shareKakao()">
                    💬 카카오톡
                </button>
                <button class="share-button share-facebook" onclick="shareFacebook()">
                    📘 페이스북
                </button>
                <button class="share-button share-twitter" onclick="shareTwitter()">
                    🐦 트위터
                </button>
                <button class="share-button share-instagram">
                    � 인스타그램
                </button>
            </div>
            
            <button class="btn btn-secondary" style="width: 100%; margin-top: 1rem;" onclick="closeShareModal()">닫기</button>
        </div>
    </div>
    
    <style>
        .share-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .share-modal.active {
            display: flex;
        }
        
        .share-content {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }
        
        .share-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .share-header h3 {
            margin: 0;
            font-size: 1.3rem;
        }
        
        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #999;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .close-btn:hover {
            color: #333;
        }
        
        .share-buttons {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }
        
        .share-button {
            padding: 0.75rem 1rem;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            color: white;
        }
        
        .share-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        
        .share-kakao {
            background: #FEE500;
            color: #000;
        }
        
        .share-facebook {
            background: #1877F2;
        }
        
        .share-twitter {
            background: #1DA1F2;
        }
        
        .share-instagram {
            background: linear-gradient(45deg, #F58529, #DD2A7B, #8134AF, #515BD4);
        }
        
        #shareImagePreview {
            position: relative;
        }
        
        #shareImagePreview::after {
            content: '✅ 이미지 저장 완료';
            position: absolute;
            top: 10px;
            right: 10px;
            background: var(--success-color);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
    </style>
    
    <script>
        let currentShareText = '';
        let currentImageData = '';
        
        async function shareCompletedCourse(courseId, courseName, attractionCount) {
            const cardElement = document.getElementById('course-card-' + courseId);
            
            if (!cardElement) {
                alert('카드를 찾을 수 없습니다.');
                return;
            }
            
            // 로딩 표시
            const originalButton = event.target;
            const originalText = originalButton.innerHTML;
            originalButton.innerHTML = '📸 캡처 중...';
            originalButton.disabled = true;
            
            try {
                // html2canvas를 사용하여 카드를 이미지로 변환
                const canvas = await html2canvas(cardElement, {
                    backgroundColor: '#ffffff',
                    scale: 2, // 고해상도
                    logging: false,
                    useCORS: true
                });
                
                // Canvas를 이미지로 변환
                const imageData = canvas.toDataURL('image/png');
                currentImageData = imageData;
                
                // 이미지 다운로드
                const link = document.createElement('a');
                link.download = `완료인증_${courseName}_${new Date().getTime()}.png`;
                link.href = imageData;
                link.click();
                
                // 공유 텍스트 설정
                currentShareText = `🎉 관광 스탬프 투어 완료!\n"${courseName}" 코스를 완료했어요!\n\n📍 ${attractionCount}개의 관광지를 모두 방문했습니다!\n\n#관광여행 #스탬프투어 #여행완료`;
                
                // 모달에 이미지 표시
                document.getElementById('capturedImage').src = imageData;
                
                // 모달 표시
                openShareModal();
                
            } catch (error) {
                console.error('이미지 캡처 실패:', error);
                alert('이미지 캡처에 실패했습니다. 다시 시도해주세요.');
            } finally {
                // 버튼 복구
                originalButton.innerHTML = originalText;
                originalButton.disabled = false;
            }
        }
        
        function openShareModal() {
            const modal = document.getElementById('shareModal');
            modal.classList.add('active');
        }
        
        function closeShareModal() {
            const modal = document.getElementById('shareModal');
            modal.classList.remove('active');
        }
        
        function shareKakao() {
            alert('💬 카카오톡 공유\n\n' + currentShareText + '\n\n※ 저장된 이미지를 카카오톡에서 직접 전송해주세요!');
        }
        
        function shareFacebook() {
            const text = encodeURIComponent(currentShareText);
            const url = encodeURIComponent(window.location.href);
            window.open(`https://www.facebook.com/sharer/sharer.php?u=${url}&quote=${text}`, '_blank');
            alert('📘 페이스북이 열립니다!\n\n저장된 이미지를 함께 업로드해주세요.');
        }
        
        function shareTwitter() {
            const text = encodeURIComponent(currentShareText);
            const url = encodeURIComponent(window.location.href);
            window.open(`https://twitter.com/intent/tweet?text=${text}&url=${url}`, '_blank');
            alert('🐦 트위터가 열립니다!\n\n저장된 이미지를 함께 업로드해주세요.');
        }
        
        // 모달 외부 클릭 시 닫기
        document.getElementById('shareModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeShareModal();
            }
        });
    </script>

<?php include '../includes/footer.php'; ?>
