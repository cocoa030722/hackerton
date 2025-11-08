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

// 인증 코드 발급 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'issue_code') {
    $attraction_id = intval($_POST['attraction_id']);
    $code_type = $_POST['code_type'] ?? 'text';
    
    // 해당 관광지에 대한 권한 확인
    if (!canManageAttraction($attraction_id)) {
        $error = '권한이 없는 관광지입니다.';
    } else {
        // 관광지 정보 가져오기
        $stmt = $conn->prepare("SELECT id, name FROM attractions WHERE id = ?");
        $stmt->bind_param("i", $attraction_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $error = '권한이 없는 관광지입니다.';
            $stmt->close();
        } else {
            $attraction_data = $result->fetch_assoc();
            $stmt->close(); // 여기서 닫음
        
        // 코드 타입에 따른 코드 생성
        if ($code_type === 'qr') {
            // QR 코드: 관광지별 고유 코드 (재사용 가능, 긴 유효기간)
            // 기존 QR 코드가 있는지 확인
            $check_stmt = $conn->prepare("SELECT id, verification_code, expires_at FROM attraction_verifications WHERE attraction_id = ? AND code_type = 'qr' AND is_used = FALSE AND expires_at > NOW()");
            $check_stmt->bind_param("i", $attraction_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                // 기존 QR 코드가 있으면 그것을 사용
                $existing = $check_result->fetch_assoc();
                $verification_code = $existing['verification_code'];
                $success = "기존 QR 코드를 사용합니다: <strong style='font-size: 1.5rem; color: #667eea;'>$verification_code</strong><br>유효기간: " . date('Y-m-d H:i', strtotime($existing['expires_at'])) . "<br><small style='color: #666;'>※ QR 코드는 재사용 가능하며, 관광객은 30일 이내 재인증 불가</small>";
            } else {
                // 새 QR 코드 생성 (관광지 ID + 타임스탬프 기반)
                $verification_code = 'QR-' . strtoupper(substr(md5($attraction_id . time()), 0, 10));
                
                // 유효기간: QR 코드는 기본 30일
                $expires_at = date('Y-m-d H:i:s', strtotime("+720 hours")); // 30일
                
                // QR 코드 저장
                $insert_stmt = $conn->prepare("INSERT INTO attraction_verifications (attraction_id, verification_code, code_type, expires_at, is_used, created_by) VALUES (?, ?, 'qr', ?, FALSE, ?)");
                $insert_stmt->bind_param("issi", $attraction_id, $verification_code, $expires_at, $user_id);
                
                if ($insert_stmt->execute()) {
                    $success = "QR 코드가 발급되었습니다: <strong style='font-size: 1.5rem; color: #667eea;'>$verification_code</strong><br>유효기간: 30일<br><small style='color: #666;'>※ QR 코드는 인쇄하여 안내소에 비치할 수 있습니다. 관광객은 30일 이내 재인증 불가</small>";
                } else {
                    $error = 'QR 코드 발급 중 오류가 발생했습니다.';
                }
                $insert_stmt->close();
            }
            $check_stmt->close();
        } else {
            // 문자열 코드: 일회성 코드 (한 번만 사용 가능)
            $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
            do {
                $verification_code = '';
                for ($i = 0; $i < 6; $i++) {
                    $verification_code .= $characters[rand(0, strlen($characters) - 1)];
                }
                
                // 중복 체크
                $check_stmt = $conn->prepare("SELECT id FROM attraction_verifications WHERE verification_code = ?");
                $check_stmt->bind_param("s", $verification_code);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $check_stmt->close();
            } while ($check_result->num_rows > 0);
            
            // 유효기간: 문자열 코드는 12시간 고정
            $expires_at = date('Y-m-d H:i:s', strtotime("+12 hours"));
            
            // 인증 코드 저장
            $insert_stmt = $conn->prepare("INSERT INTO attraction_verifications (attraction_id, verification_code, code_type, expires_at, is_used, created_by) VALUES (?, ?, 'text', ?, FALSE, ?)");
            $insert_stmt->bind_param("issi", $attraction_id, $verification_code, $expires_at, $user_id);
            
            if ($insert_stmt->execute()) {
                $success = "인증 코드가 발급되었습니다: <strong style='font-size: 1.5rem; color: #667eea;'>$verification_code</strong><br>유효기간: 12시간<br><small style='color: #666;'>※ 일회성 코드로, 한 번 사용하면 재사용할 수 없습니다.</small>";
            } else {
                $error = '인증 코드 발급 중 오류가 발생했습니다.';
            }
            $insert_stmt->close();
        }
        }
    }
}

// 최근 발급한 코드 목록 (최근 20개)
$recent_codes = [];
$stmt = $conn->prepare("SELECT av.*, a.name as attraction_name 
                        FROM attraction_verifications av 
                        JOIN attractions a ON av.attraction_id = a.id 
                        WHERE a.manager_id = ? 
                        ORDER BY av.created_at DESC 
                        LIMIT 30");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recent_codes[] = $row;
}
$stmt->close();

$conn->close();

// 페이지 설정
$page_title = '인증 코드 발급';
$base_url = '..';
include '../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>🎫 인증 코드 발급</h1>
        <p>QR 코드 또는 문자열 코드를 발급하여 관광객 인증을 관리합니다.</p>
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
    
    <!-- 코드 발급 폼 -->
    <div class="card">
        <h2>새 코드 발급</h2>
        
        <?php if (empty($my_attractions)): ?>
            <div class="empty-state">
                <p>등록된 관광지가 없습니다.</p>
                <p><a href="my_attractions.php" class="btn btn-primary" style="margin-top: 1rem;">관광지 등록하기</a></p>
            </div>
        <?php else: ?>
            <div style="background: #e3f2fd; padding: 1rem; border-radius: 5px; margin-bottom: 1.5rem; border-left: 4px solid #2196f3;">
                <strong>💡 인증 코드 사용 방법</strong>
                <ul style="margin-top: 0.5rem; padding-left: 1.5rem;">
                    <li><strong>QR 코드</strong>: 인쇄하여 관광지 안내소나 입구에 비치합니다. 관광객이 스마트폰으로 QR을 찍어 즉시 인증합니다.</li>
                    <li><strong>문자열 코드</strong>: 방문객에게 직접 발급합니다. 관광객이 나중에 웹/앱에서 입력하여 인증합니다.</li>
                    <li>QR 코드는 재사용 가능하지만, 관광객은 30일 이내 같은 관광지 재인증 불가합니다.</li>
                    <li>문자열 코드는 일회성으로, 한 번 사용하면 재사용할 수 없습니다.</li>
                    <li>유효기간이 지나면 코드는 자동으로 만료됩니다.</li>
                </ul>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="issue_code">
                
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
                
                <div class="form-group">
                    <label for="code_type">코드 타입 *</label>
                    <select name="code_type" id="code_type" required>
                        <option value="text">문자열 코드 (일회성, 12시간 유효)</option>
                        <option value="qr">QR 코드 (재사용 가능, 30일 유효)</option>
                    </select>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">🎫 코드 발급하기</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
    
    <!-- 최근 발급 코드 -->
    <div class="card">
        <h2>최근 발급된 코드</h2>
        <?php if (empty($recent_codes)): ?>
            <div class="empty-state">
                <p>아직 발급된 코드가 없습니다.</p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>관광지</th>
                        <th>코드 타입</th>
                        <th>인증 코드</th>
                        <th>발급 일시</th>
                        <th>유효기간</th>
                        <th>상태</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_codes as $code): ?>
                        <?php $is_expired = strtotime($code['expires_at']) < time(); ?>
                        <tr>
                            <td><?php echo htmlspecialchars($code['attraction_name']); ?></td>
                            <td>
                                <?php if ($code['code_type'] === 'qr'): ?>
                                    <span class="badge badge-info">📱 QR</span>
                                <?php else: ?>
                                    <span class="badge badge-primary">🔤 문자열</span>
                                <?php endif; ?>
                            </td>
                            <td><code style="background: #f8f9fa; padding: 0.3rem 0.6rem; border-radius: 3px; font-size: 1.1rem; font-weight: bold; color: var(--primary-color);"><?php echo htmlspecialchars($code['verification_code']); ?></code></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($code['created_at'])); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($code['expires_at'])); ?></td>
                            <td>
                                <?php if ($code['is_used']): ?>
                                    <span class="badge badge-success">✓ 사용됨</span>
                                <?php elseif ($is_expired): ?>
                                    <span class="badge badge-danger">⏰ 만료됨</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">⏳ 미사용</span>
                                    <?php if ($code['code_type'] === 'qr'): ?>
                                        <br><button onclick="showQRCode('<?php echo htmlspecialchars($code['verification_code']); ?>', '<?php echo htmlspecialchars($code['attraction_name']); ?>')" class="btn btn-sm btn-primary" style="margin-top: 0.5rem;">QR 보기</button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- QR 코드 모달 -->
<div id="qrModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.7);">
    <div class="modal-content" style="background-color: white; margin: 5% auto; padding: 2rem; border-radius: 10px; width: 90%; max-width: 500px; text-align: center;">
        <div class="modal-header">
            <h2 id="qr-attraction-name"></h2>
            <span class="close" onclick="closeQRModal()" style="float: right; cursor: pointer; font-size: 2rem; line-height: 1;">&times;</span>
        </div>
        <div class="modal-body">
            <div id="qrcode" style="display: inline-block; padding: 1rem; background: white; border: 2px solid var(--primary-color); border-radius: 10px; margin: 1rem 0;"></div>
            <p id="qr-code-text" style="font-size: 1.2rem; font-weight: bold; color: var(--primary-color); margin: 1rem 0;"></p>
            <p style="color: #666; font-size: 0.9rem;">관광객이 이 QR 코드를 스캔하여 인증합니다.</p>
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

<style>
@media print {
    body * {
        visibility: hidden;
    }
    #qrModal * {
        visibility: visible;
    }
    #qrModal {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        background: white;
    }
    .modal-footer {
        display: none;
    }
}
</style>

<?php include '../includes/footer.php'; ?>

    </style>
</head>
<body>
    <nav class="navbar">
        <div class="navbar-content">
            <div class="logo">🎫 인증 코드 발급</div>
            <div class="nav-links">
                <a href="dashboard.php">대시보드</a>
                <a href="my_attractions.php">내 관광지</a>
                <a href="issue_code.php">인증 코드 발급</a>
                <a href="../index.php">메인으로</a>
                <a href="../logout.php">로그아웃</a>
            </div>
        </div>
    </nav>
    
    <div class="container">
        <div class="page-header">
            <h1>인증 코드 발급</h1>
            <p>방문객에게 제공할 관광지 인증 코드를 발급합니다.</p>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="card">
            <h2>새 코드 발급</h2>
            
            <?php if (empty($my_attractions)): ?>
                <div class="empty-state">
                    <p>등록된 관광지가 없습니다.</p>
                    <p><a href="my_attractions.php" class="btn btn-primary" style="margin-top: 1rem;">관광지 등록하기</a></p>
                </div>
            <?php else: ?>
                <div class="info-box">
                    <h3>💡 인증 코드 사용 방법</h3>
                    <ul>
                        <li><strong>QR 코드</strong>: 인쇄하여 관광지 안내소나 입구에 비치합니다. 관광객이 스마트폰으로 QR을 찍어 즉시 인증합니다.</li>
                        <li><strong>문자열 코드</strong>: 방문객에게 직접 발급합니다. 관광객이 나중에 웹/앱에서 입력하여 인증합니다.</li>
                        <li>QR 코드는 재사용 가능하지만, 관광객은 30일 이내 같은 관광지 재인증 불가합니다.</li>
                        <li>문자열 코드는 일회성으로, 한 번 사용하면 재사용할 수 없습니다.</li>
                        <li>유효기간이 지나면 코드는 자동으로 만료됩니다.</li>
                    </ul>
                </div>
                
                <form method="POST">
                    <input type="hidden" name="action" value="issue_code">
                    
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
                    
                    <div class="form-group">
                        <label for="code_type">코드 타입 *</label>
                        <select name="code_type" id="code_type" required>
                            <option value="text">문자열 코드 (일회성, 12시간 유효)</option>
                            <option value="qr">QR 코드 (재사용 가능, 30일 유효)</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">🎫 코드 발급하기</button>
                </form>
                
                <script>
                    // 페이지 로드 시 초기화는 필요 없음 (유효기간 필드 제거됨)
                </script>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h2>최근 발급된 코드</h2>
            <?php if (empty($recent_codes)): ?>
                <div class="empty-state">
                    <p>아직 발급된 코드가 없습니다.</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>관광지</th>
                            <th>코드 타입</th>
                            <th>인증 코드</th>
                            <th>발급 일시</th>
                            <th>유효기간</th>
                            <th>상태</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_codes as $code): ?>
                            <?php
                            $is_expired = strtotime($code['expires_at']) < time();
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($code['attraction_name']); ?></td>
                                <td>
                                    <?php if ($code['code_type'] === 'qr'): ?>
                                        <span class="badge badge-qr">📱 QR</span>
                                    <?php else: ?>
                                        <span class="badge badge-text">🔤 문자열</span>
                                    <?php endif; ?>
                                </td>
                                <td><code><?php echo htmlspecialchars($code['verification_code']); ?></code></td>
                                <td><?php echo date('Y-m-d H:i', strtotime($code['created_at'])); ?></td>
                                <td><?php echo date('Y-m-d H:i', strtotime($code['expires_at'])); ?></td>
                                <td>
                                    <?php if ($code['is_used']): ?>
                                        <span class="badge badge-used">✓ 사용됨</span>
                                    <?php elseif ($is_expired): ?>
                                        <span class="badge badge-expired">⏰ 만료됨</span>
                                    <?php else: ?>
                                        <span class="badge badge-unused">⏳ 미사용</span>
                                        <?php if ($code['code_type'] === 'qr'): ?>
                                            <br><button onclick="showQRCode('<?php echo htmlspecialchars($code['verification_code']); ?>', '<?php echo htmlspecialchars($code['attraction_name']); ?>')" class="btn btn-primary" style="padding: 0.4rem 0.8rem; font-size: 0.85rem; margin-top: 0.5rem;">QR 보기</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- QR 코드 모달 -->
    <div id="qrModal" class="qr-modal">
        <div class="qr-modal-content">
            <span class="close-qr" onclick="closeQRModal()">&times;</span>
            <h3 id="qr-attraction-name"></h3>
            <div id="qrcode"></div>
            <p id="qr-code-text" style="font-size: 1.2rem; font-weight: bold; color: #7b1fa2; margin: 1rem 0;"></p>
            <p style="color: #666; font-size: 0.9rem;">관광객이 이 QR 코드를 스캔하여 인증합니다.</p>
            <button class="print-btn" onclick="window.print()">🖨️ 인쇄하기</button>
            <button class="btn btn-primary" onclick="closeQRModal()">닫기</button>
        </div>
    </div>
    
    <script>
        let qrCodeInstance = null;
        
        function showQRCode(code, attractionName) {
            // 기존 QR 코드 제거
            const qrcodeDiv = document.getElementById('qrcode');
            qrcodeDiv.innerHTML = '';
            
            // QR 코드 생성
            qrCodeInstance = new QRCode(qrcodeDiv, {
                text: code,
                width: 256,
                height: 256,
                colorDark: "#7b1fa2",
                colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.H
            });
            
            // 정보 표시
            document.getElementById('qr-attraction-name').textContent = attractionName;
            document.getElementById('qr-code-text').textContent = '코드: ' + code;
            
            // 모달 표시
            document.getElementById('qrModal').style.display = 'block';
        }
        
        function closeQRModal() {
            document.getElementById('qrModal').style.display = 'none';
        }
        
        // 모달 외부 클릭 시 닫기
        window.onclick = function(event) {
            const modal = document.getElementById('qrModal');
            if (event.target === modal) {
                closeQRModal();
            }
        }
    </script>

<?php include '../includes/footer.php'; ?>