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
// 단, 무한 루프 방지를 위해 현재 페이지가 register_attraction.php가 아닐 때만
$current_page = basename($_SERVER['PHP_SELF']);
if ($current_page !== 'register_attraction.php') {
    requireAttractionRegistration();
}

$conn = getDBConnection();
$user_id = $_SESSION['user_id'];

$success = '';
$error = '';

// 직원이 관광지 선택 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'select_attraction') {
    $_SESSION['selected_attraction_id'] = intval($_POST['attraction_id']);
    header('Location: dashboard.php');
    exit();
}

// 관광지 정보 가져오기
$attraction_info = null;
$my_attraction = null;
$my_attractions = []; // 책임자의 모든 관광지 목록
$needs_verification = false;

// 책임자인 경우
if (isAttractionOwner()) {
    $stmt = $conn->prepare("SELECT * FROM attraction_manager_info WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $attraction_info = $result->fetch_assoc();
    $stmt->close();

    // 인증되지 않은 경우 등록/인증 페이지로 리다이렉트
    if (!$attraction_info) {
        $needs_verification = true;
    } else {
        // 새로운 구조: attraction_managers에서 모든 관광지 조회
        $stmt = $conn->prepare("SELECT a.* FROM attractions a 
                                JOIN attraction_managers am ON a.id = am.attraction_id 
                                WHERE am.user_id = ? AND am.status = 'active' 
                                ORDER BY CASE am.role WHEN 'primary' THEN 1 WHEN 'co-manager' THEN 2 ELSE 3 END, a.created_at DESC");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $my_attractions[] = $row;
        }
        $stmt->close();
        
        // 하위 호환성: 기존 구조에서도 조회 (중복 제거)
        if (empty($my_attractions)) {
            $stmt = $conn->prepare("SELECT * FROM attractions WHERE manager_id = ? ORDER BY created_at DESC");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $my_attractions[] = $row;
            }
            $stmt->close();
        }
        
        // 관광지가 없으면 인증 필요
        if (empty($my_attractions)) {
            $needs_verification = true;
        } else {
            // 첫 번째 관광지를 기본 선택 (상세보기용)
            $my_attraction = $my_attractions[0];
            $attraction_name = $my_attraction['name'];
        }
    }
}

if (!$needs_verification && $my_attraction) {
    // 책임자: 모든 관광지 기준 통계
    // 내가 관리하는 관광지 개수
    $attraction_count = count($my_attractions);

    // 내 모든 관광지들의 ID 목록
    $my_attraction_ids = array_column($my_attractions, 'id');
    $ids_placeholder = implode(',', array_fill(0, count($my_attraction_ids), '?'));
    
    // 내 모든 관광지들의 총 인증 수
    if (!empty($my_attraction_ids)) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM attraction_verifications WHERE attraction_id IN ($ids_placeholder)");
        $types = str_repeat('i', count($my_attraction_ids));
        $stmt->bind_param($types, ...$my_attraction_ids);
        $stmt->execute();
        $result = $stmt->get_result();
        $total_verifications = $result->fetch_assoc()['count'];
        $stmt->close();

        // 오늘 발급한 인증 코드 수 (모든 관광지)
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM attraction_verifications 
                                WHERE attraction_id IN ($ids_placeholder) AND DATE(created_at) = CURDATE()");
        $stmt->bind_param($types, ...$my_attraction_ids);
        $stmt->execute();
        $result = $stmt->get_result();
        $today_verifications = $result->fetch_assoc()['count'];
        $stmt->close();

        // 최근 인증 목록 (최근 5개, 모든 관광지)
        $recent_verifications = [];
        $stmt = $conn->prepare("SELECT av.*, a.name as attraction_name, u.full_name as tourist_name 
                                FROM attraction_verifications av 
                                JOIN attractions a ON av.attraction_id = a.id 
                                LEFT JOIN users u ON av.tourist_id = u.id 
                                WHERE av.attraction_id IN ($ids_placeholder) 
                                ORDER BY av.created_at DESC 
                                LIMIT 5");
        $stmt->bind_param($types, ...$my_attraction_ids);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $recent_verifications[] = $row;
        }
        $stmt->close();
    } else {
        $total_verifications = 0;
        $today_verifications = 0;
        $recent_verifications = [];
    }
}

$conn->close();

$page_title = '관광지 관리자 대시보드';
$base_url = '..';
include '../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>🏛️ 관광지 관리자 대시보드</h1>
        <p><?php echo htmlspecialchars($_SESSION['full_name']); ?>님, 환영합니다!</p>
    </div>
    
    <!-- 알림 메시지 -->
    <?php if ($success): ?>
        <div class="alert alert-success">
            ✅ <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error">
            ⚠️ <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($needs_verification): ?>
        <!-- 인증 필요 안내 -->
        <div class="card">
            <div class="card-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                <h2 style="margin: 0; color: white;">⚠️ 관광지 등록/인증이 필요합니다</h2>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-error" style="margin-bottom: 2rem;">
                        ⚠️ <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <div style="text-align: center; padding: 2rem;">
                    <div style="font-size: 4rem; margin-bottom: 1rem;">🏛️</div>
                    <h3 style="margin-bottom: 1rem;">관광지 관리 기능을 이용하려면</h3>
                    
                    <p style="color: var(--text-light); margin-bottom: 2rem;">
                        새로운 관광지를 등록해야 합니다.<br>
                        관리자 승인 후 관광지를 등록하고 관리할 수 있습니다.
                    </p>
                
                    <div style="display: grid; grid-template-columns: 1fr; gap: 1.5rem; max-width: 500px; margin: 0 auto 2rem;">
                        <div style="padding: 1.5rem; border: 2px solid var(--primary-color); border-radius: 12px; background: rgba(102, 126, 234, 0.05);">
                            <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">🆕</div>
                            <h4 style="margin-bottom: 0.5rem;">신규 관광지 등록</h4>
                            <p style="color: var(--text-light); font-size: 0.9rem; margin-bottom: 1rem;">
                                새로운 관광지를 시스템에 등록하고 관리를 시작합니다.
                            </p>
                        </div>
                    </div>
                    
                    <a href="register_attraction.php" class="btn btn-primary" style="font-size: 1.1rem; padding: 1rem 2rem;">
                        관광지 등록 페이지로 이동 →
                    </a>
                </div>
            </div>
        </div>
        
        <div class="card" style="margin-top: 2rem;">
            <div class="card-header">
                <h3 style="margin: 0;">📋 등록 절차 안내</h3>
            </div>
            <div class="card-body">
                <div style="padding: 1rem; background: var(--bg-color); border-left: 4px solid var(--primary-color); border-radius: 4px;">
                    <h4 style="margin: 0 0 0.5rem 0; color: var(--primary-color);">신규 관광지 등록 절차</h4>
                    <ul style="margin: 0.5rem 0 0 1.5rem; color: var(--text-light);">
                        <li>관광지명, 주소, 설명 등 기본 정보 입력</li>
                        <li>사업자등록번호와 연락처 입력 (선택사항)</li>
                        <li>등록 즉시 관광지 관리 기능 이용 가능</li>
                    </ul>
                </div>
            </div>
        </div>
    <?php else: ?>
    
    <div class="card">
        <div class="card-header">
            <h2 style="margin: 0;">내 관광지 정보</h2>
        </div>
        <div class="card-body">
            <!-- 관광지 목록 표시 -->
            <?php if (count($my_attractions) > 1): ?>
                <div class="alert alert-info" style="margin-bottom: 1rem;">
                    <strong>ℹ️ 안내:</strong> 총 <strong><?php echo count($my_attractions); ?>개</strong>의 관광지를 관리하고 있습니다. 관광지를 클릭하면 상세정보를 확인할 수 있습니다.
                </div>
                <div style="display: grid; gap: 1rem;">
                    <?php foreach ($my_attractions as $attraction): ?>
                            <div class="attraction-card" onclick="showAttractionDetail(<?php echo $attraction['id']; ?>)" style="padding: 1.5rem; border: 2px solid var(--border-color); border-radius: 8px; cursor: pointer; transition: all 0.3s;">
                                <div style="margin-bottom: 1rem;">
                                    <h3 style="margin: 0 0 0.5rem 0; color: var(--primary-color);">
                                        <?php echo htmlspecialchars($attraction['name']); ?>
                                    </h3>
                                    <span class="badge badge-secondary"><?php echo htmlspecialchars($attraction['category'] ?? '미분류'); ?></span>
                                </div>
                                <div style="color: var(--text-light); font-size: 0.9rem;">
                                    <div style="margin-bottom: 0.3rem;">
                                        📍 <?php echo htmlspecialchars($attraction['address']); ?>
                                    </div>
                                    <?php if ($attraction['contact_phone']): ?>
                                        <div style="margin-bottom: 0.3rem;">
                                            📞 <?php echo htmlspecialchars($attraction['contact_phone']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($attraction['operating_hours']): ?>
                                        <div>
                                            🕒 <?php echo htmlspecialchars($attraction['operating_hours']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <!-- 단일 관광지인 경우 기존 UI -->
                    <div class="grid grid-2">
                        <div>
                            <label style="display: block; color: var(--text-light); margin-bottom: 0.5rem;">관광지명</label>
                            <div style="font-size: 1.1rem; font-weight: 500;"><?php echo htmlspecialchars($my_attractions[0]['name']); ?></div>
                        </div>
                        <div>
                            <label style="display: block; color: var(--text-light); margin-bottom: 0.5rem;">카테고리</label>
                            <div style="font-size: 1.1rem; font-weight: 500;"><?php echo htmlspecialchars($my_attractions[0]['category'] ?? '-'); ?></div>
                        </div>
                        <div>
                            <label style="display: block; color: var(--text-light); margin-bottom: 0.5rem;">주소</label>
                            <div style="font-size: 1.1rem; font-weight: 500;"><?php echo htmlspecialchars($my_attractions[0]['address'] ?? '-'); ?></div>
                        </div>
                        <div>
                            <label style="display: block; color: var(--text-light); margin-bottom: 0.5rem;">연락처</label>
                            <div style="font-size: 1.1rem; font-weight: 500;"><?php echo htmlspecialchars($my_attractions[0]['contact_phone'] ?? '-'); ?></div>
                        </div>
                        <div>
                            <label style="display: block; color: var(--text-light); margin-bottom: 0.5rem;">운영시간</label>
                            <div style="font-size: 1.1rem; font-weight: 500;"><?php echo htmlspecialchars($my_attractions[0]['operating_hours'] ?? '-'); ?></div>
                        </div>
                        <div>
                            <label style="display: block; color: var(--text-light); margin-bottom: 0.5rem;">입장료</label>
                            <div style="font-size: 1.1rem; font-weight: 500;"><?php echo htmlspecialchars($my_attractions[0]['admission_fee'] ?? '-'); ?></div>
                        </div>
                    </div>
                <?php endif; ?>
        </div>
    </div>
    
    <!-- 최근 인증 현황 -->
    <div class="card">
        <div class="card-header">
            <h2>최근 인증 현황</h2>
        </div>
        <div class="card-body">
            <?php if (empty($recent_verifications)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📊</div>
                    <p class="empty-state-text">아직 인증 기록이 없습니다.</p>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>관광지</th>
                            <th>인증 코드</th>
                            <th>인증 일시</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_verifications as $verification): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($verification['attraction_name']); ?></td>
                                <td><code><?php echo htmlspecialchars($verification['verification_code']); ?></code></td>
                                <td><?php echo date('Y-m-d H:i', strtotime($verification['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>
</div>

<!-- 관광지 정보 수정 모달 (책임자만) -->
<?php if (!$needs_verification && isAttractionOwner() && !empty($my_attractions)): ?>
<!-- 관광지 상세정보 모달 -->
<div id="detailModal" class="modal">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h2 id="detail_attraction_name">관광지 상세정보</h2>
            <span class="close" onclick="closeDetailModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div id="detail_content" class="grid grid-2">
                <!-- JavaScript로 동적 로드 -->
            </div>
            <div class="form-actions" style="margin-top: 1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeDetailModal()">닫기</button>
            </div>
        </div>
    </div>
</div>

<style>
.attraction-card:hover {
    border-color: var(--primary-color) !important;
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.15);
    transform: translateY(-2px);
}
</style>

<script>
// 관광지 데이터 (PHP에서 전달)
const attractionsData = <?php echo json_encode($my_attractions); ?>;
let currentAttractionId = null;

// 관광지 상세정보 표시
function showAttractionDetail(attractionId) {
    const attraction = attractionsData.find(a => a.id == attractionId);
    if (!attraction) {
        alert('관광지 정보를 찾을 수 없습니다.');
        return;
    }
    
    currentAttractionId = attractionId;
    
    // 제목 설정
    document.getElementById('detail_attraction_name').textContent = attraction.name;
    
    // 상세정보 내용
    const detailContent = document.getElementById('detail_content');
    detailContent.innerHTML = `
        <div>
            <label style="display: block; color: var(--text-light); margin-bottom: 0.5rem;">관광지명</label>
            <div style="font-size: 1.1rem; font-weight: 500;">${attraction.name}</div>
        </div>
        <div>
            <label style="display: block; color: var(--text-light); margin-bottom: 0.5rem;">카테고리</label>
            <div style="font-size: 1.1rem; font-weight: 500;">${attraction.category || '-'}</div>
        </div>
        <div style="grid-column: 1 / -1;">
            <label style="display: block; color: var(--text-light); margin-bottom: 0.5rem;">주소</label>
            <div style="font-size: 1.1rem; font-weight: 500;">${attraction.address || '-'}</div>
        </div>
        <div>
            <label style="display: block; color: var(--text-light); margin-bottom: 0.5rem;">연락처</label>
            <div style="font-size: 1.1rem; font-weight: 500;">${attraction.contact_phone || '-'}</div>
        </div>
        <div>
            <label style="display: block; color: var(--text-light); margin-bottom: 0.5rem;">입장료</label>
            <div style="font-size: 1.1rem; font-weight: 500;">${attraction.admission_fee || '-'}</div>
        </div>
        <div style="grid-column: 1 / -1;">
            <label style="display: block; color: var(--text-light); margin-bottom: 0.5rem;">운영시간</label>
            <div style="font-size: 1.1rem; font-weight: 500;">${attraction.operating_hours || '-'}</div>
        </div>
        ${attraction.description ? `
        <div style="grid-column: 1 / -1;">
            <label style="display: block; color: var(--text-light); margin-bottom: 0.5rem;">설명</label>
            <div style="font-size: 1rem; line-height: 1.6;">${attraction.description}</div>
        </div>
        ` : ''}
    `;
    
    document.getElementById('detailModal').style.display = 'block';
}

// 상세정보 모달 닫기
function closeDetailModal() {
    document.getElementById('detailModal').style.display = 'none';
    currentAttractionId = null;
}

// 모달 외부 클릭 시 닫기
window.onclick = function(event) {
    const detailModal = document.getElementById('detailModal');
    
    if (event.target === detailModal) {
        closeDetailModal();
    }
}
</script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
