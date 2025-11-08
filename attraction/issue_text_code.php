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

// 대량 코드 발급 처리 (10만 명 처리 가능)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'issue_bulk') {
    $attraction_id = intval($_POST['attraction_id']);
    $quantity = intval($_POST['quantity']);
    
    // 수량 제한 (1회 최대 1000개)
    if ($quantity < 1 || $quantity > 1000) {
        $error = '발급 수량은 1~1000개 사이여야 합니다.';
    } elseif (!canManageAttraction($attraction_id)) {
        $error = '권한이 없는 관광지입니다.';
    } else {
        // 관광지 정보 가져오기
        $stmt = $conn->prepare("SELECT id, name FROM attractions WHERE id = ?");
        $stmt->bind_param("i", $attraction_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $attraction_data = $result->fetch_assoc();
        $stmt->close();
            
            // 트랜잭션 시작
            $conn->begin_transaction();
            
            try {
                $expires_at = date('Y-m-d H:i:s', strtotime("+12 hours"));
                $generated_codes = [];
                
                // Prepared statement 재사용으로 성능 최적화 (tourist_id는 NULL)
                $insert_stmt = $conn->prepare("INSERT INTO attraction_verifications (attraction_id, tourist_id, verification_code, code_type, expires_at, is_verified, issued_by) VALUES (?, NULL, ?, 'text', ?, FALSE, ?)");
                
                $successful = 0;
                for ($i = 0; $i < $quantity; $i++) {
                    // 고성능 코드 생성 알고리즘
                    // 8자리: 숫자(4) + 대문자(4) = 36^8 = 약 2.8조 조합
                    $code = generateUniqueCode($conn);
                    
                    if ($code) {
                        $insert_stmt->bind_param("issi", $attraction_id, $code, $expires_at, $user_id);
                        if ($insert_stmt->execute()) {
                            $generated_codes[] = $code;
                            $successful++;
                        }
                    }
                }
                
                $insert_stmt->close();
                $conn->commit();
                
                // CSV 파일 생성
                $csv_filename = 'codes_' . $attraction_id . '_' . date('YmdHis') . '.csv';
                $csv_path = '../temp/' . $csv_filename;
                
                // temp 디렉토리 생성
                if (!file_exists('../temp')) {
                    mkdir('../temp', 0755, true);
                }
                
                $fp = fopen($csv_path, 'w');
                fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
                fputcsv($fp, ['번호', '인증코드', '관광지', '유효기간']);
                
                foreach ($generated_codes as $idx => $code) {
                    fputcsv($fp, [$idx + 1, $code, $attraction_data['name'], date('Y-m-d H:i', strtotime($expires_at))]);
                }
                fclose($fp);
                
                $success = "<strong>{$successful}개</strong>의 인증 코드가 발급되었습니다.<br>
                           <a href='../temp/{$csv_filename}' class='btn btn-primary' download style='margin-top: 1rem;'>📥 CSV 다운로드</a>";
                
            } catch (Exception $e) {
                $conn->rollback();
                $error = '코드 발급 중 오류가 발생했습니다: ' . $e->getMessage();
            }
    }
}

// 무효 코드 삭제 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_invalid') {
    // 무효 코드: 만료되었거나 이미 사용된 코드
    $stmt = $conn->prepare("DELETE av FROM attraction_verifications av
                            JOIN attractions a ON av.attraction_id = a.id
                            WHERE a.manager_id = ?
                            AND av.code_type = 'text'
                            AND (av.is_verified = TRUE OR av.expires_at < NOW())");
    $stmt->bind_param("i", $user_id);
    
    if ($stmt->execute()) {
        $deleted = $stmt->affected_rows;
        $success = "<strong>{$deleted}개</strong>의 무효 코드가 삭제되었습니다.";
    } else {
        $error = '코드 삭제 중 오류가 발생했습니다.';
    }
    $stmt->close();
}

// 모든 코드 삭제 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_all') {
    $stmt = $conn->prepare("DELETE av FROM attraction_verifications av
                            JOIN attractions a ON av.attraction_id = a.id
                            WHERE a.manager_id = ? AND av.code_type = 'text'");
    $stmt->bind_param("i", $user_id);
    
    if ($stmt->execute()) {
        $deleted = $stmt->affected_rows;
        $success = "<strong>{$deleted}개</strong>의 모든 코드가 삭제되었습니다.";
    } else {
        $error = '코드 삭제 중 오류가 발생했습니다.';
    }
    $stmt->close();
}

// 단일 코드 발급 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'issue_single') {
    $attraction_id = intval($_POST['attraction_id']);
    
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
        
        $verification_code = generateUniqueCode($conn);
        
        if ($verification_code) {
            $expires_at = date('Y-m-d H:i:s', strtotime("+12 hours"));
            
            $insert_stmt = $conn->prepare("INSERT INTO attraction_verifications (attraction_id, tourist_id, verification_code, code_type, expires_at, is_verified, issued_by) VALUES (?, NULL, ?, 'text', ?, FALSE, ?)");
            $insert_stmt->bind_param("issi", $attraction_id, $verification_code, $expires_at, $user_id);
            
            if ($insert_stmt->execute()) {
                $success = "인증 코드가 발급되었습니다: <strong style='font-size: 1.8rem; color: #667eea; display: block; margin: 1rem 0;'>$verification_code</strong><small style='color: #666;'>유효기간: 12시간 | 일회성 코드</small>";
            } else {
                $error = '인증 코드 발급 중 오류가 발생했습니다.';
            }
            $insert_stmt->close();
        } else {
            $error = '고유 코드 생성에 실패했습니다. 다시 시도해주세요.';
        }
    }
}

// 고성능 고유 코드 생성 함수
function generateUniqueCode($conn) {
    // 8자리 코드: 충돌 가능성 최소화
    // 형식: XXXX-XXXX (대문자+숫자 조합)
    $max_attempts = 10;
    
    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        // 타임스탬프 기반 엔트로피 추가
        $timestamp_part = base_convert(substr(time(), -4), 10, 36);
        $random_part = '';
        
        $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        for ($i = 0; $i < 6; $i++) {
            $random_part .= $characters[random_int(0, 35)];
        }
        
        $code = strtoupper($timestamp_part . $random_part);
        
        // 중복 체크 (인덱스 사용으로 빠른 조회)
        $check_stmt = $conn->prepare("SELECT id FROM attraction_verifications WHERE verification_code = ? LIMIT 1");
        $check_stmt->bind_param("s", $code);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $exists = $check_result->num_rows > 0;
        $check_stmt->close();
        
        if (!$exists) {
            return $code;
        }
    }
    
    return false; // 실패
}

// 최근 발급 통계
$stats = [];

if (isAttractionOwner()) {
    $stmt = $conn->prepare("SELECT 
                            COUNT(*) as total,
                            COUNT(CASE WHEN is_verified = TRUE THEN 1 END) as used,
                            COUNT(CASE WHEN is_verified = FALSE AND expires_at > NOW() THEN 1 END) as active,
                            COUNT(CASE WHEN is_verified = TRUE OR expires_at < NOW() THEN 1 END) as invalid
                            FROM attraction_verifications av
                            JOIN attractions a ON av.attraction_id = a.id
                            WHERE a.manager_id = ? AND av.code_type = 'text'");
    $stmt->bind_param("i", $user_id);
}

$stmt->execute();
$result = $stmt->get_result();
$stats = $result->fetch_assoc();
$stmt->close();

// 최근 발급 코드 (최근 20개)
$recent_codes = [];

$stmt = $conn->prepare("SELECT av.*, a.name as attraction_name 
                        FROM attraction_verifications av 
                        JOIN attractions a ON av.attraction_id = a.id 
                        WHERE a.manager_id = ? AND av.code_type = 'text'
                        ORDER BY av.created_at DESC 
                        LIMIT 20");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recent_codes[] = $row;
}
$stmt->close();

$conn->close();

// 페이지 설정
$page_title = '문자열 코드 발급';
$base_url = '..';
include '../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>🔤 문자열 코드 발급</h1>
        <p>일회성 인증 코드를 발급하고 관리합니다 (최대 10만 명 처리 가능).</p>
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
        <h2>코드 발급</h2>
        
        <!-- 발급 통계 -->
        <?php if ($stats && $stats['total'] > 0): ?>
        <div class="stats-grid" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.5rem; padding: 1rem; background: #f8f9fa; border-radius: 8px;">
            <div class="stat-item" style="text-align: center;">
                <div style="font-size: 2rem; font-weight: bold; color: #667eea;"><?php echo number_format($stats['total']); ?></div>
                <div style="font-size: 0.9rem; color: #666;">총 발급</div>
            </div>
            <div class="stat-item" style="text-align: center;">
                <div style="font-size: 2rem; font-weight: bold; color: #48bb78;"><?php echo number_format($stats['active']); ?></div>
                <div style="font-size: 0.9rem; color: #666;">활성 코드</div>
            </div>
            <div class="stat-item" style="text-align: center;">
                <div style="font-size: 2rem; font-weight: bold; color: #4299e1;"><?php echo number_format($stats['used']); ?></div>
                <div style="font-size: 0.9rem; color: #666;">사용됨</div>
            </div>
            <div class="stat-item" style="text-align: center;">
                <div style="font-size: 2rem; font-weight: bold; color: #f56565;"><?php echo number_format($stats['invalid']); ?></div>
                <div style="font-size: 0.9rem; color: #666;">무효 코드</div>
            </div>
        </div>
        <?php endif; ?>
            
            <?php if (empty($my_attractions)): ?>
                <div class="empty-state">
                    <p>등록된 관광지가 없습니다.</p>
                    <p><a href="my_attractions.php" class="btn btn-primary" style="margin-top: 1rem;">관광지 등록하기</a></p>
                </div>
            <?php else: ?>
                <div class="info-box">
                    <h4>💡 문자열 코드 시스템 특징</h4>
                    <ul>
                        <li><strong>대량 발급</strong>: 한 번에 최대 1,000개 코드 발급 가능 (10만 명 처리 가능한 시스템 설계)</li>
                        <li><strong>일회성</strong>: 각 코드는 한 번만 사용 가능</li>
                        <li><strong>유효기간</strong>: 12시간 고정 (관광지 방문 후 숙소에서 입력 가능)</li>
                        <li><strong>고성능</strong>: 8자리 코드 (약 2.8조 조합), 중복 방지 알고리즘</li>
                        <li><strong>CSV 다운로드</strong>: 발급된 코드를 CSV 파일로 받아 관리 가능</li>
                    </ul>
                </div>
                
                <div class="form-grid">
                    <!-- 단일 발급 -->
                    <div class="form-section">
                        <h3>🎫 단일 코드 발급</h3>
                        <p style="color: #666; margin-bottom: 1rem; font-size: 0.9rem;">방문객에게 즉시 제공할 코드를 발급합니다.</p>
                        
                        <form method="POST">
                            <input type="hidden" name="action" value="issue_single">
                            
                            <div class="form-group">
                                <label for="attraction_id_single">관광지 *</label>
                                <select name="attraction_id" id="attraction_id_single" required>
                                    <option value="">선택하세요</option>
                                    <?php foreach ($my_attractions as $attraction): ?>
                                        <option value="<?php echo $attraction['id']; ?>">
                                            <?php echo htmlspecialchars($attraction['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <button type="submit" class="btn btn-primary" style="width: 100%;">발급하기</button>
                        </form>
                    </div>
                    
                    <!-- 대량 발급 -->
                    <div class="form-section">
                        <h3>📦 대량 코드 발급</h3>
                        <p style="color: #666; margin-bottom: 1rem; font-size: 0.9rem;">여러 개의 코드를 한 번에 발급하고 CSV로 다운로드합니다.</p>
                        
                        <form method="POST">
                            <input type="hidden" name="action" value="issue_bulk">
                            
                            <div class="form-group">
                                <label for="attraction_id_bulk">관광지 *</label>
                                <select name="attraction_id" id="attraction_id_bulk" required>
                                    <option value="">선택하세요</option>
                                    <?php foreach ($my_attractions as $attraction): ?>
                                        <option value="<?php echo $attraction['id']; ?>">
                                            <?php echo htmlspecialchars($attraction['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="quantity">발급 수량 (1~1000) *</label>
                                <input type="number" name="quantity" id="quantity" min="1" max="1000" value="100" required>
                            </div>
                            
                            <button type="submit" class="btn btn-success" style="width: 100%;">대량 발급 및 다운로드</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- 최근 발급 코드 -->
        <?php if (!empty($recent_codes)): ?>
        <div class="card">
            <h2>최근 발급 코드 (최근 20개)</h2>
            
            <!-- 코드 관리 버튼 -->
            <div class="management-buttons">
                <?php if ($stats['invalid'] > 0): ?>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('⚠️ 사용되었거나 만료된 <?php echo number_format($stats['invalid']); ?>개의 무효 코드를 삭제하시겠습니까?');">
                        <input type="hidden" name="action" value="delete_invalid">
                        <button type="submit" class="btn btn-warning">
                            🗑️ 무효 코드 삭제 (<?php echo number_format($stats['invalid']); ?>개)
                        </button>
                    </form>
                <?php endif; ?>
                
                <?php if ($stats['total'] > 0): ?>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('⚠️ 경고: 모든 코드(<?php echo number_format($stats['total']); ?>개)를 삭제하시겠습니까?\n\n이 작업은 되돌릴 수 없습니다!');">
                        <input type="hidden" name="action" value="delete_all">
                        <button type="submit" class="btn btn-danger">
                            🗑️ 모든 코드 삭제 (<?php echo number_format($stats['total']); ?>개)
                        </button>
                    </form>
                <?php endif; ?>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>관광지</th>
                        <th>코드</th>
                        <th>발급 시간</th>
                        <th>만료 시간</th>
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
                            <td><code><?php echo htmlspecialchars($code['verification_code']); ?></code></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($code['created_at'])); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($code['expires_at'])); ?></td>
                            <td>
                                <?php if ($code['is_verified']): ?>
                                    <span class="badge badge-secondary">✓ 사용됨</span>
                                <?php elseif ($is_expired): ?>
                                    <span class="badge badge-warning">⏰ 만료</span>
                                <?php else: ?>
                                    <span class="badge badge-success">⏳ 활성</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

<?php include '../includes/footer.php'; ?>