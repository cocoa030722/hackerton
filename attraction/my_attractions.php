<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireUserType('attraction_manager');

// 승인된 사용자만 접근 가능
if (!isApproved()) {
    header('Location: ../index.php');
    exit();
}

// 관광지 책임자는 등록된 관광지가 없으면 등록 페이지로 리다이렉트
requireAttractionRegistration();

$conn = getDBConnection();
$user_id = $_SESSION['user_id'];

// 관광지 관리인 정보 확인
$stmt = $conn->prepare("SELECT * FROM attraction_manager_info WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$manager_info = $stmt->get_result()->fetch_assoc();
$stmt->close();

// 인증되지 않은 경우 등록/인증 페이지로 리다이렉트
if (!$manager_info || empty($manager_info['attraction_name'])) {
    header('Location: register_attraction.php');
    exit();
}

$success = '';
$error = '';

// 관광지 등록/수정 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add' || $_POST['action'] === 'edit') {
        $name = $_POST['name'] ?? '';
        $address = $_POST['address'] ?? '';
        $description = $_POST['description'] ?? '';
        $category = $_POST['category'] ?? '';
        $contact_phone = $_POST['contact_phone'] ?? '';
        $operating_hours = $_POST['operating_hours'] ?? '';
        $admission_fee = $_POST['admission_fee'] ?? '';
        
        if (empty($name)) {
            $error = '관광지명은 필수입니다.';
        } else {
            if ($_POST['action'] === 'add') {
                // 새 관광지 등록
                $stmt = $conn->prepare("INSERT INTO attractions (name, address, description, category, contact_phone, operating_hours, admission_fee, manager_id, created_by, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
                $stmt->bind_param("sssssssii", $name, $address, $description, $category, $contact_phone, $operating_hours, $admission_fee, $user_id, $user_id);
                
                if ($stmt->execute()) {
                    $success = '관광지가 성공적으로 등록되었습니다.';
                } else {
                    $error = '관광지 등록 중 오류가 발생했습니다.';
                }
                $stmt->close();
            } else {
                // 기존 관광지 수정
                $attraction_id = intval($_POST['attraction_id']);
                $stmt = $conn->prepare("UPDATE attractions SET name = ?, address = ?, description = ?, category = ?, contact_phone = ?, operating_hours = ?, admission_fee = ? WHERE id = ? AND manager_id = ?");
                $stmt->bind_param("sssssssii", $name, $address, $description, $category, $contact_phone, $operating_hours, $admission_fee, $attraction_id, $user_id);
                
                if ($stmt->execute()) {
                    $success = '관광지 정보가 수정되었습니다.';
                } else {
                    $error = '관광지 수정 중 오류가 발생했습니다.';
                }
                $stmt->close();
            }
        }
    }
    
    // 관광지 삭제
    if ($_POST['action'] === 'delete') {
        $attraction_id = intval($_POST['attraction_id']);
        $stmt = $conn->prepare("DELETE FROM attractions WHERE id = ? AND manager_id = ?");
        $stmt->bind_param("ii", $attraction_id, $user_id);
        
        if ($stmt->execute()) {
            $success = '관광지가 삭제되었습니다.';
        } else {
            $error = '관광지 삭제 중 오류가 발생했습니다.';
        }
        $stmt->close();
    }
}

// 내 관광지 목록 조회
$my_attractions = [];
$stmt = $conn->prepare("SELECT * FROM attractions WHERE manager_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $my_attractions[] = $row;
}
$stmt->close();

// 수정할 관광지 정보 가져오기
$edit_attraction = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM attractions WHERE id = ? AND manager_id = ?");
    $stmt->bind_param("ii", $edit_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_attraction = $result->fetch_assoc();
    $stmt->close();
}

$conn->close();

// 페이지 설정
$page_title = '내 관광지';
$base_url = '..';
include '../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>📍 내 관광지 관리</h1>
        <p><?php echo htmlspecialchars($_SESSION['full_name']); ?>님이 관리하는 관광지 정보입니다.</p>
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
    
    <!-- 관광지 등록/수정 폼 -->
    <div class="card">
        <h2><?php echo $edit_attraction ? '관광지 정보 수정' : '새 관광지 등록'; ?></h2>
        
        <div style="background: #e3f2fd; padding: 1rem; border-radius: 5px; margin-bottom: 1.5rem; border-left: 4px solid #2196f3;">
            <strong>📝 안내</strong>
            <p style="margin-top: 0.5rem;">관리하시는 관광지 정보를 등록해주세요. 등록된 관광지는 관리자의 승인 후 코스에 포함될 수 있습니다.</p>
        </div>
        
        <form method="POST" action="my_attractions.php">
                <input type="hidden" name="action" value="<?php echo $edit_attraction ? 'edit' : 'add'; ?>">
                <?php if ($edit_attraction): ?>
                    <input type="hidden" name="attraction_id" value="<?php echo $edit_attraction['id']; ?>">
                <?php endif; ?>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="name">관광지명 *</label>
                        <input type="text" id="name" name="name" required 
                               value="<?php echo $edit_attraction ? htmlspecialchars($edit_attraction['name']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="category">카테고리</label>
                        <select id="category" name="category">
                            <option value="">선택</option>
                            <option value="문화재" <?php echo ($edit_attraction && $edit_attraction['category'] === '문화재') ? 'selected' : ''; ?>>문화재</option>
                            <option value="박물관" <?php echo ($edit_attraction && $edit_attraction['category'] === '박물관') ? 'selected' : ''; ?>>박물관</option>
                            <option value="자연" <?php echo ($edit_attraction && $edit_attraction['category'] === '자연') ? 'selected' : ''; ?>>자연</option>
                            <option value="테마파크" <?php echo ($edit_attraction && $edit_attraction['category'] === '테마파크') ? 'selected' : ''; ?>>테마파크</option>
                            <option value="랜드마크" <?php echo ($edit_attraction && $edit_attraction['category'] === '랜드마크') ? 'selected' : ''; ?>>랜드마크</option>
                            <option value="쇼핑" <?php echo ($edit_attraction && $edit_attraction['category'] === '쇼핑') ? 'selected' : ''; ?>>쇼핑</option>
                            <option value="기타" <?php echo ($edit_attraction && $edit_attraction['category'] === '기타') ? 'selected' : ''; ?>>기타</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="address">주소</label>
                    <input type="text" id="address" name="address" 
                           value="<?php echo $edit_attraction ? htmlspecialchars($edit_attraction['address']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label for="description">설명</label>
                    <textarea id="description" name="description"><?php echo $edit_attraction ? htmlspecialchars($edit_attraction['description']) : ''; ?></textarea>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="contact_phone">연락처</label>
                        <input type="text" id="contact_phone" name="contact_phone" 
                               value="<?php echo $edit_attraction ? htmlspecialchars($edit_attraction['contact_phone']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="operating_hours">운영시간</label>
                        <input type="text" id="operating_hours" name="operating_hours" placeholder="예: 09:00-18:00"
                               value="<?php echo $edit_attraction ? htmlspecialchars($edit_attraction['operating_hours']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="admission_fee">입장료</label>
                        <input type="text" id="admission_fee" name="admission_fee" placeholder="예: 성인 3,000원"
                               value="<?php echo $edit_attraction ? htmlspecialchars($edit_attraction['admission_fee']) : ''; ?>">
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <?php echo $edit_attraction ? '수정하기' : '등록하기'; ?>
                    </button>
                    <?php if ($edit_attraction): ?>
                        <a href="my_attractions.php" class="btn btn-secondary">취소</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        
        <!-- 관광지 목록 -->
        <div class="card">
            <h2>내가 등록한 관광지 목록 (<?php echo count($my_attractions); ?>개)</h2>
            
            <?php if (empty($my_attractions)): ?>
                <div class="empty-state">
                    <p>아직 등록된 관광지가 없습니다.</p>
                    <p>위 양식을 통해 관광지를 등록해주세요.</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>관광지명</th>
                            <th>카테고리</th>
                            <th>주소</th>
                            <th>상태</th>
                            <th>등록일</th>
                            <th>관리</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($my_attractions as $attraction): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($attraction['name']); ?></strong>
                                    <?php if ($attraction['description']): ?>
                                        <br><small style="color: #666;"><?php echo htmlspecialchars(mb_substr($attraction['description'], 0, 50)); ?>...</small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($attraction['category'] ?? '-'); ?></td>
                                <td><small><?php echo htmlspecialchars($attraction['address'] ?? '-'); ?></small></td>
                                <td>
                                    <?php if ($attraction['status'] === 'active'): ?>
                                        <span class="badge badge-success">활성</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">비활성</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('Y-m-d', strtotime($attraction['created_at'])); ?></td>
                                <td>
                                    <a href="my_attractions.php?edit=<?php echo $attraction['id']; ?>" class="btn btn-sm btn-warning">수정</a>
                                    
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('정말 삭제하시겠습니까?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="attraction_id" value="<?php echo $attraction['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">삭제</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

<?php include '../includes/footer.php'; ?>