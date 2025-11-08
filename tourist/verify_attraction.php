<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireUserType('tourist');

// tourist_course_id가 전달되었는지 확인
if (!isset($_GET['tc_id'])) {
    header('Location: my_courses.php');
    exit();
}

$conn = getDBConnection();
$user_id = $_SESSION['user_id'];
$tourist_course_id = intval($_GET['tc_id']);

// tourist_course가 현재 사용자의 것인지 확인
$stmt = $conn->prepare("SELECT tc.*, c.name as course_name, c.description
                        FROM tourist_courses tc
                        JOIN courses c ON tc.course_id = c.id
                        WHERE tc.id = ? AND tc.tourist_id = ? AND tc.status = 'in_progress'");
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

$tourist_course = $result->fetch_assoc();
$stmt->close();

// 코스에 포함된 관광지 목록 가져오기 (QR 쿨타임 정보 포함)
$stmt = $conn->prepare("SELECT a.*, 
                        EXISTS(SELECT 1 FROM attraction_verifications av 
                               WHERE av.tourist_course_id = ? 
                               AND av.attraction_id = a.id 
                               AND av.is_used = TRUE) as is_verified,
                        EXISTS(SELECT 1 FROM attraction_verifications av
                               WHERE av.tourist_id = ?
                               AND av.attraction_id = a.id
                               AND av.code_type = 'qr'
                               AND av.used_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                               AND av.is_used = TRUE) as has_qr_cooldown
                        FROM course_attractions ca
                        JOIN attractions a ON ca.attraction_id = a.id
                        WHERE ca.course_id = ?
                        ORDER BY a.name");
$stmt->bind_param("iii", $tourist_course_id, $user_id, $tourist_course['course_id']);
$stmt->execute();
$result = $stmt->get_result();
$attractions = [];
$cooldown_attractions = [];
while ($row = $result->fetch_assoc()) {
    $attractions[] = $row;
    if ($row['has_qr_cooldown'] && !$row['is_verified']) {
        $cooldown_attractions[] = $row['name'];
    }
}
$stmt->close();

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// 인증 코드 제출 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'verify_code') {
        $code = strtoupper(trim($_POST['verification_code']));
        
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
            $stmt->bind_param("ii", $tourist_course['course_id'], $verification['attraction_id']);
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
                        $stmt->bind_param("i", $tourist_course['course_id']);
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
                            $conn->close();
                            header("Location: claim_reward.php?tc_id=" . $tourist_course_id);
                            exit();
                        } else {
                            // 진행도만 업데이트
                            $stmt = $conn->prepare("UPDATE tourist_courses SET progress_percentage = ? WHERE id = ?");
                            $stmt->bind_param("di", $progress, $tourist_course_id);
                            $stmt->execute();
                            $stmt->close();
                        }
                        
                        // 관광지 목록 새로고침
                        $stmt = $conn->prepare("SELECT a.*, 
                                                EXISTS(SELECT 1 FROM attraction_verifications av 
                                                       WHERE av.tourist_course_id = ? 
                                                       AND av.attraction_id = a.id 
                                                       AND av.is_used = TRUE) as is_verified
                                                FROM course_attractions ca
                                                JOIN attractions a ON ca.attraction_id = a.id
                                                WHERE ca.course_id = ?
                                                ORDER BY a.name");
                        $stmt->bind_param("ii", $tourist_course_id, $tourist_course['course_id']);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $attractions = [];
                        while ($row = $result->fetch_assoc()) {
                            $attractions[] = $row;
                        }
                        $stmt->close();
                    }
                }
            }
        }
    }
}

$conn->close();

$page_title = '관광지 인증';
$base_url = '..';
include '../includes/header.php';
?>

    <div class="container">
        <div class="page-header">
            <h1>🎫 관광지 인증</h1>
            <p class="breadcrumb">
                <a href="my_courses.php">내 코스</a> &gt; <?php echo htmlspecialchars($tourist_course['course_name']); ?>
            </p>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>
            
</head>
<body>
    <nav class="navbar">
        <div class="navbar-content">
            <div class="logo">🏝️ 관광 스탬프</div>
            <div class="nav-links">
                <a href="dashboard.php">대시보드</a>
                <a href="select_course.php">코스 선택</a>
                <a href="my_courses.php">내 코스</a>
                <a href="my_page.php">마이페이지</a>
                <a href="../logout.php">로그아웃</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="page-header">
            <h1>관광지 인증</h1>
            <div class="breadcrumb">
                <a href="my_courses.php">내 코스</a> &gt; <?php echo htmlspecialchars($tourist_course['course_name']); ?> &gt; 관광지 인증
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if (!empty($cooldown_attractions)): ?>
            <div class="alert alert-error" style="background: #fff3cd; color: #856404; border-color: #ffc107;">
                <strong>⚠️ 부정인증 방지 안내</strong><br>
                다음의 관광지는 QR코드를 통해 인증할 수 없습니다:<br>
                <strong style="color: #f44336;"><?php echo implode(', ', $cooldown_attractions); ?></strong><br>
                이 관광지를 인증할 때에는 <strong>문자열 코드</strong>를 사용하여야 합니다.
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>인증 방법 선택</h2>
            
            <div class="instructions">
                <h4>📍 인증 안내</h4>
                <ul>
                    <li>QR 코드: 관광지에 비치된 QR 코드를 스캔하여 인증</li>
                    <li>문자열 코드: 관광지 직원에게 받은 코드를 입력하여 인증</li>
                    <li>각 관광지는 한 번만 인증 가능합니다</li>
                </ul>
            </div>
            
            <div class="verification-methods">
                <div class="method-card" id="qr-method" onclick="selectMethod('qr')">
                    <h3>📷 QR 코드 스캔</h3>
                    <p>카메라로 관광지의 QR 코드를 스캔합니다</p>
                </div>
                <div class="method-card" id="text-method" onclick="selectMethod('text')">
                    <h3>🔤 문자열 코드 입력</h3>
                    <p>관광지에서 받은 코드를 직접 입력합니다</p>
                </div>
            </div>

            <!-- QR 코드 스캔 영역 -->
            <div class="verification-area" id="qr-area">
                <div class="camera-permission" id="camera-permission">
                    <h3>📷 카메라 권한 필요</h3>
                    <p>QR 코드를 스캔하려면 카메라 접근 권한이 필요합니다.</p>
                    <p>아래 버튼을 클릭하여 카메라를 활성화해주세요.</p>
                    <button class="btn btn-primary" id="start-camera-btn" onclick="startCamera()">카메라 시작</button>
                </div>
                <div id="qr-reader" style="display: none;"></div>
                <div class="qr-status" id="qr-status"></div>
            </div>

            <!-- 문자열 코드 입력 영역 -->
            <div class="verification-area" id="text-area">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="verify_code">
                    <div class="form-group">
                        <label for="verification_code">인증 코드</label>
                        <input type="text" id="verification_code" name="verification_code" 
                               placeholder="예: ABC123DEF" required maxlength="20">
                    </div>
                    <button type="submit" class="btn btn-primary">인증하기</button>
                </form>
            </div>
        </div>

        <!-- 관광지 목록 -->
        <div class="card">
            <h2>코스 관광지 목록</h2>
            <div class="attractions-list">
                <?php if (empty($attractions)): ?>
                    <p style="text-align: center; color: #999; padding: 2rem;">
                        이 코스에 포함된 관광지가 없습니다.
                    </p>
                <?php else: ?>
                    <?php foreach ($attractions as $attraction): ?>
                        <div class="attraction-item <?php echo $attraction['is_verified'] ? 'verified' : ''; ?> <?php echo $attraction['has_qr_cooldown'] && !$attraction['is_verified'] ? 'cooldown' : ''; ?>">
                            <div class="icon">
                                <?php echo $attraction['is_verified'] ? '✅' : '📍'; ?>
                            </div>
                            <div class="info">
                                <h4>
                                    <?php echo htmlspecialchars($attraction['name']); ?>
                                    <?php if ($attraction['has_qr_cooldown'] && !$attraction['is_verified']): ?>
                                        <span style="color: #f44336; font-size: 0.85rem; font-weight: normal;"> (QR 불가)</span>
                                    <?php endif; ?>
                                </h4>
                                <p><?php echo htmlspecialchars($attraction['location'] ?? ''); ?></p>
                            </div>
                            <div class="status">
                                <?php echo $attraction['is_verified'] ? '인증 완료' : '미인증'; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div style="margin-top: 2rem; text-align: center;">
            <a href="my_courses.php" class="btn btn-secondary">내 코스로 돌아가기</a>
        </div>
    </div>

    <!-- html5-qrcode 라이브러리 -->
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    
    <script>
        let html5QrCode = null;
        let cameraStarted = false;

        function selectMethod(method) {
            // 모든 method-card에서 active 클래스 제거
            document.querySelectorAll('.method-card').forEach(card => {
                card.classList.remove('active');
            });
            
            // 모든 verification-area 숨기기
            document.querySelectorAll('.verification-area').forEach(area => {
                area.classList.remove('active');
            });
            
            // 선택한 method 활성화
            if (method === 'qr') {
                document.getElementById('qr-method').classList.add('active');
                document.getElementById('qr-area').classList.add('active');
            } else {
                document.getElementById('text-method').classList.add('active');
                document.getElementById('text-area').classList.add('active');
                // QR 카메라가 실행 중이면 중지
                if (cameraStarted && html5QrCode) {
                    stopCamera();
                }
            }
        }

        async function startCamera() {
            const cameraPermission = document.getElementById('camera-permission');
            const qrReader = document.getElementById('qr-reader');
            const qrStatus = document.getElementById('qr-status');
            
            try {
                // 카메라 권한 요청
                const stream = await navigator.mediaDevices.getUserMedia({ video: true });
                stream.getTracks().forEach(track => track.stop()); // 테스트 스트림 중지
                
                // 권한이 허용되면 QR 리더 시작
                cameraPermission.style.display = 'none';
                qrReader.style.display = 'block';
                qrStatus.textContent = '📷 QR 코드를 카메라에 비춰주세요...';
                qrStatus.className = 'qr-status scanning';
                
                html5QrCode = new Html5Qrcode("qr-reader");
                
                html5QrCode.start(
                    { facingMode: "environment" }, // 후면 카메라 사용
                    {
                        fps: 10,
                        qrbox: { width: 250, height: 250 }
                    },
                    (decodedText, decodedResult) => {
                        // QR 코드 스캔 성공
                        qrStatus.textContent = '✅ QR 코드 인식 완료! 인증 중...';
                        qrStatus.className = 'qr-status';
                        
                        // 카메라 중지
                        stopCamera();
                        
                        // 인증 코드 제출
                        submitVerificationCode(decodedText);
                    },
                    (errorMessage) => {
                        // QR 코드 스캔 실패 (계속 스캔 시도)
                        // console.log(errorMessage);
                    }
                ).then(() => {
                    cameraStarted = true;
                }).catch((err) => {
                    qrStatus.textContent = '❌ 카메라 시작 실패: ' + err;
                    qrStatus.className = 'qr-status error';
                    cameraPermission.style.display = 'block';
                    qrReader.style.display = 'none';
                });
                
            } catch (err) {
                // 카메라 권한 거부
                qrStatus.textContent = '❌ 카메라 권한이 거부되었습니다. 브라우저 설정에서 카메라 권한을 허용해주세요.';
                qrStatus.className = 'qr-status error';
            }
        }

        function stopCamera() {
            if (html5QrCode && cameraStarted) {
                html5QrCode.stop().then(() => {
                    cameraStarted = false;
                    document.getElementById('qr-reader').style.display = 'none';
                    document.getElementById('camera-permission').style.display = 'block';
                }).catch((err) => {
                    console.error('카메라 중지 오류:', err);
                });
            }
        }

        function submitVerificationCode(code) {
            // 폼 생성 및 제출
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'verify_code';
            
            const codeInput = document.createElement('input');
            codeInput.type = 'hidden';
            codeInput.name = 'verification_code';
            codeInput.value = code.toUpperCase();
            
            form.appendChild(actionInput);
            form.appendChild(codeInput);
            document.body.appendChild(form);
            form.submit();
        }

        // 페이지 이탈 시 카메라 중지
        window.addEventListener('beforeunload', () => {
            if (cameraStarted) {
                stopCamera();
            }
        });
    </script>

<?php include '../includes/footer.php'; ?>
