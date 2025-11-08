<?php
require_once '../config/database.php';
require_once '../config/session.php';

// 관광지 책임자만 접근 가능
requireUserType(['attraction_manager']);

// 승인된 사용자만 접근 가능
if (!isApproved()) {
    header('Location: ../index.php');
    exit();
}

// 관광지 책임자는 등록된 관광지가 없으면 등록 페이지로 리다이렉트
requireAttractionRegistration();

$conn = getDBConnection();
$user_id = $_SESSION['user_id'];

$manager_info = null;
$my_attraction_name = null;

// 관광지 관리인 정보 확인
$stmt = $conn->prepare("SELECT * FROM attraction_manager_info WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$manager_info = $stmt->get_result()->fetch_assoc();
$stmt->close();

// 인증되지 않은 경우 등록 페이지로 리다이렉트
if (!$manager_info || empty($manager_info['attraction_name'])) {
    header('Location: register_attraction.php');
    exit();
}

$my_attraction_name = $manager_info['attraction_name'];

$success = '';
$error = '';

// 내가 관리하는 관광지 목록 조회
$my_attractions = [];

// 책임자: manager_id로 조회 (1:1 관계)
$stmt = $conn->prepare("SELECT id, name FROM attractions WHERE manager_id = ? AND status = 'active' ORDER BY name");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $my_attractions[] = $row;
}
$stmt->close();

// QR 코드 발급/조회 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'issue_qr') {
    $attraction_id = intval($_POST['attraction_id']);
    
    // 해당 관광지에 대한 권한 확인
    if (!canManageAttraction($attraction_id)) {
        $error = '권한이 없는 관광지입니다.';
    } else {
        // 관광지 정보 가져오기
        $stmt = $conn->prepare("SELECT id, name FROM attractions WHERE id = ?");
        $stmt->bind_param("i", $attraction_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $attraction_data = $result->fetch_assoc();
        $stmt->close();
        
        // 기존 QR 코드가 있는지 확인
        $check_stmt = $conn->prepare("SELECT id, verification_code, expires_at FROM attraction_verifications WHERE attraction_id = ? AND code_type = 'qr' AND expires_at > NOW()");
        $check_stmt->bind_param("i", $attraction_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            // 기존 QR 코드가 있으면 그것을 사용
            $existing = $check_result->fetch_assoc();
            $verification_code = $existing['verification_code'];
            $success = "기존 QR 코드: <strong style='font-size: 1.5rem; color: #667eea;'>$verification_code</strong><br>유효기간: " . date('Y-m-d H:i', strtotime($existing['expires_at'])) . "<br><small style='color: #666;'>※ QR 코드는 인쇄하여 안내소에 비치하세요. 관광객은 30일 이내 재인증 불가합니다.</small>";
        } else {
            // 새 QR 코드 생성 (관광지 ID + 타임스탬프 기반)
            $verification_code = 'QR-' . strtoupper(substr(md5($attraction_id . time()), 0, 10));
            
            // 유효기간: QR 코드는 기본 30일
            $expires_at = date('Y-m-d H:i:s', strtotime("+720 hours")); // 30일
            
            // QR 코드 저장 (tourist_id는 NULL - 아직 사용되지 않음)
            $insert_stmt = $conn->prepare("INSERT INTO attraction_verifications (attraction_id, tourist_id, verification_code, code_type, expires_at, is_verified, issued_by) VALUES (?, NULL, ?, 'qr', ?, FALSE, ?)");
            $insert_stmt->bind_param("issi", $attraction_id, $verification_code, $expires_at, $user_id);
            
            if ($insert_stmt->execute()) {
                $success = "QR 코드가 발급되었습니다: <strong style='font-size: 1.5rem; color: #667eea;'>$verification_code</strong><br>유효기간: 30일<br><small style='color: #666;'>※ QR 코드는 인쇄하여 안내소에 비치하세요. 관광객은 30일 이내 재인증 불가합니다.</small>";
            } else {
                $error = 'QR 코드 발급 중 오류가 발생했습니다.';
            }
            $insert_stmt->close();
        }
        $check_stmt->close();
    }
}

// 내 관광지의 QR 코드 목록
$qr_codes = [];

if (isAttractionOwner()) {
    // 책임자: manager_id로 조회 (1:1 관계)
    $stmt = $conn->prepare("SELECT av.*, a.name as attraction_name 
                            FROM attraction_verifications av 
                            JOIN attractions a ON av.attraction_id = a.id 
                            WHERE a.manager_id = ? AND av.code_type = 'qr'
                            ORDER BY av.created_at DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $qr_codes[] = $row;
    }
    $stmt->close();
}

$conn->close();

// 페이지 설정
$page_title = 'QR 코드 발급';
$base_url = '..';
include '../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>📱 QR 코드 관리</h1>
        <p>관광지 안내소에 비치할 수 있는 QR 코드를 발급합니다.</p>
    </div>
    
    <!-- 알림 메시지 -->
    <?php if ($success): ?>
        <div class="alert alert-success">
            <?php echo $success; ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error">
            ⚠️ <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <!-- QR 코드 발급 폼 -->
    <div class="card">
        <h2>QR 코드 발급</h2>
        
        <?php if (empty($my_attractions)): ?>
            <div class="empty-state">
                <p>등록된 관광지가 없습니다.</p>
                <p><a href="my_attractions.php" class="btn btn-primary">관광지 등록하기</a></p>
            </div>
        <?php else: ?>
            <div class="info-box">
                <strong>📱 QR 코드 사용 방법</strong>
                <ul>
                    <li>관광지당 하나의 QR 코드가 발급됩니다.</li>
                    <li>QR 코드를 인쇄하여 관광지 안내소나 입구에 비치하세요.</li>
                    <li>관광객이 스마트폰으로 QR을 스캔하여 즉시 인증합니다.</li>
                    <li>QR 코드는 30일간 유효하며, 만료되면 자동으로 갱신할 수 있습니다.</li>
                    <li>부정 방지: 관광객은 30일 이내 같은 관광지를 재인증할 수 없습니다.</li>
                </ul>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="issue_qr">
                
                <div class="form-group">
                    <label for="attraction_id">관광지 선택 *</label>
                    <select name="attraction_id" id="attraction_id" required>
                        <option value="">선택하세요</option>
                        <?php foreach ($my_attractions as $attraction): ?>
                            <option value="<?php echo $attraction['id']; ?>">
                                <?php echo htmlspecialchars($attraction['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">📱 QR 코드 발급/조회</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
    
    <!-- 발급된 QR 코드 목록 -->
    <?php if (!empty($qr_codes)): ?>
    <div class="card">
        <h2>발급된 QR 코드</h2>
        <div class="grid grid-3">
            <?php foreach ($qr_codes as $qr): ?>
                <?php $is_expired = strtotime($qr['expires_at']) < time(); ?>
                <div class="card qr-card">
                    <h3><?php echo htmlspecialchars($qr['attraction_name']); ?></h3>
                    <code class="qr-code-text"><?php echo htmlspecialchars($qr['verification_code']); ?></code>
                    <p class="qr-expiry">
                        유효기간: <?php echo date('Y-m-d', strtotime($qr['expires_at'])); ?>
                    </p>
                    <?php if ($is_expired): ?>
                        <span class="badge badge-danger">⏰ 만료됨</span>
                    <?php else: ?>
                        <button onclick="showQRCode('<?php echo htmlspecialchars($qr['verification_code']); ?>', '<?php echo htmlspecialchars($qr['attraction_name']); ?>')" class="btn btn-primary btn-qr">QR 보기 및 인쇄</button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- QR 코드 모달 -->
<div id="qrModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="qr-attraction-name"></h2>
            <span class="close" onclick="closeQRModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div id="qrcode"></div>
            <p id="qr-code-text" class="qr-code-display"></p>
            <p class="qr-description">관광객이 이 QR 코드를 스캔하여 인증합니다.</p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-success" onclick="window.print()">🖨️ 인쇄하기</button>
            <button class="btn btn-secondary" onclick="closeQRModal()">닫기</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
let qrCodeInstance = null;

function showQRCode(code, attractionName) {
    const qrcodeDiv = document.getElementById('qrcode');
    qrcodeDiv.innerHTML = '';
    
    qrCodeInstance = new QRCode(qrcodeDiv, {
        text: code,
        width: 256,
        height: 256,
        colorDark: "#667eea",
        colorLight: "#ffffff",
        correctLevel: QRCode.CorrectLevel.H
    });
    
    document.getElementById('qr-attraction-name').textContent = attractionName;
    document.getElementById('qr-code-text').textContent = '코드: ' + code;
    document.getElementById('qrModal').style.display = 'block';
}

function closeQRModal() {
    document.getElementById('qrModal').style.display = 'none';
}

window.onclick = function(event) {
    const modal = document.getElementById('qrModal');
    if (event.target === modal) {
        closeQRModal();
    }
}
</script>

<?php include '../includes/footer.php'; ?>