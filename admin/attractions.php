<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireUserType('admin');

$conn = getDBConnection();
$success = '';
$error = '';

// 관광지 추가 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $name = $_POST['name'] ?? '';
    $address = $_POST['address'] ?? '';
    $description = $_POST['description'] ?? '';
    $category = $_POST['category'] ?? '';
    $contact_phone = $_POST['contact_phone'] ?? '';
    $operating_hours = $_POST['operating_hours'] ?? '';
    $admission_fee = $_POST['admission_fee'] ?? '';
    $manager_id = !empty($_POST['manager_id']) ? intval($_POST['manager_id']) : null;
    
    if (empty($name)) {
        $error = '관광지명은 필수입니다.';
    } else {
        // 트랜잭션 시작
        $conn->begin_transaction();
        
        try {
            // 1. attractions 테이블에 추가
            $stmt = $conn->prepare("INSERT INTO attractions (name, address, description, category, contact_phone, operating_hours, admission_fee, manager_id, created_by, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
            $created_by = $_SESSION['user_id'];
            $stmt->bind_param("sssssssii", $name, $address, $description, $category, $contact_phone, $operating_hours, $admission_fee, $manager_id, $created_by);
            $stmt->execute();
            $attraction_id = $conn->insert_id;
            $stmt->close();
            
            // 2. manager_id가 지정되었으면 attraction_managers에도 추가
            if ($manager_id) {
                $stmt2 = $conn->prepare("INSERT INTO attraction_managers (attraction_id, user_id, role, added_by, status) VALUES (?, ?, 'primary', ?, 'active')");
                $stmt2->bind_param("iii", $attraction_id, $manager_id, $created_by);
                $stmt2->execute();
                $stmt2->close();
            }
            
            $conn->commit();
            $success = '관광지가 성공적으로 등록되었습니다.';
        } catch (Exception $e) {
            $conn->rollback();
            $error = '관광지 등록 중 오류가 발생했습니다: ' . $e->getMessage();
        }
    }
}

// 관광지 삭제 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $attraction_id = intval($_POST['attraction_id']);
    $stmt = $conn->prepare("DELETE FROM attractions WHERE id = ?");
    $stmt->bind_param("i", $attraction_id);
    
    if ($stmt->execute()) {
        $success = '관광지가 삭제되었습니다.';
    } else {
        $error = '관광지 삭제 중 오류가 발생했습니다.';
    }
    $stmt->close();
}

// 관광지 상태 변경
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
    $attraction_id = intval($_POST['attraction_id']);
    $new_status = $_POST['new_status'];
    $stmt = $conn->prepare("UPDATE attractions SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $attraction_id);
    
    if ($stmt->execute()) {
        $success = '관광지 상태가 변경되었습니다.';
    }
    $stmt->close();
}

// 전체 관광지 목록 조회
$attractions = [];
$result = $conn->query("SELECT a.*, u.full_name as manager_name 
                        FROM attractions a 
                        LEFT JOIN users u ON a.manager_id = u.id 
                        ORDER BY a.created_at DESC");
while ($row = $result->fetch_assoc()) {
    $attractions[] = $row;
}

// attraction_manager 권한을 가진 사용자 목록 조회
$managers = [];
$result = $conn->query("SELECT id, username, full_name, email 
                        FROM users 
                        WHERE user_type = 'attraction_manager' 
                        AND status = 'approved' 
                        ORDER BY full_name ASC");
while ($row = $result->fetch_assoc()) {
    $managers[] = $row;
}

$conn->close();

// 페이지 설정
$page_title = '관광지 관리';
$base_url = '..';
include '../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>📍 관광지 관리</h1>
        <p>코스에 추가할 수 있는 인가된 관광지 목록을 관리합니다.</p>
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
    
    <!-- 새 관광지 등록 -->
    <div class="card">
        <h2>새 관광지 등록</h2>
        
        <form method="POST" action="">
            <input type="hidden" name="action" value="add">
            
            <div class="form-grid">
                <div class="form-group">
                    <label for="name">관광지명 *</label>
                    <input type="text" id="name" name="name" required>
                </div>
                
                <div class="form-group">
                    <label for="category">카테고리</label>
                    <select id="category" name="category">
                        <option value="">선택</option>
                        <option value="문화재">문화재</option>
                        <option value="박물관">박물관</option>
                        <option value="자연">자연</option>
                        <option value="테마파크">테마파크</option>
                        <option value="랜드마크">랜드마크</option>
                        <option value="쇼핑">쇼핑</option>
                        <option value="기타">기타</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="manager_id">관리자 지정</label>
                    <select id="manager_id" name="manager_id">
                        <option value="">나중에 지정</option>
                        <?php foreach ($managers as $manager): ?>
                            <option value="<?php echo $manager['id']; ?>">
                                <?php echo htmlspecialchars($manager['full_name']); ?> 
                                (<?php echo htmlspecialchars($manager['username']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small>관광지를 관리할 관리자를 선택하세요. 나중에 변경할 수 있습니다.</small>
                </div>
            </div>
            
            <div class="form-group">
                <label for="address">주소</label>
                <input type="text" id="address" name="address">
            </div>
            
            <div class="form-group">
                <label for="description">설명</label>
                <textarea id="description" name="description" rows="4"></textarea>
            </div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label for="contact_phone">연락처</label>
                    <input type="text" id="contact_phone" name="contact_phone" placeholder="02-1234-5678">
                </div>
                
                <div class="form-group">
                    <label for="operating_hours">운영시간</label>
                    <input type="text" id="operating_hours" name="operating_hours" placeholder="09:00-18:00">
                </div>
                
                <div class="form-group">
                    <label for="admission_fee">입장료</label>
                    <input type="text" id="admission_fee" name="admission_fee" placeholder="성인 3,000원">
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">관광지 등록</button>
            </div>
        </form>
    </div>
    
    <!-- 관광지 목록 -->
    <div class="card">
        <h2>등록된 관광지 목록 (<?php echo count($attractions); ?>개)</h2>
        
        <?php if (empty($attractions)): ?>
            <div class="empty-state">
                <p>등록된 관광지가 없습니다.</p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>관광지명</th>
                        <th>카테고리</th>
                        <th>주소</th>
                        <th>담당자</th>
                        <th>상태</th>
                        <th>등록일</th>
                        <th>관리</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($attractions as $attraction): ?>
                        <tr>
                            <td><?php echo $attraction['id']; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($attraction['name']); ?></strong>
                                <?php if ($attraction['description']): ?>
                                    <br><small style="color: #666;"><?php echo htmlspecialchars(mb_substr($attraction['description'], 0, 50)); ?>...</small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($attraction['category'] ?? '-'); ?></td>
                            <td><small><?php echo htmlspecialchars($attraction['address'] ?? '-'); ?></small></td>
                            <td><?php echo htmlspecialchars($attraction['manager_name'] ?? '미지정'); ?></td>
                            <td>
                                <?php if ($attraction['status'] === 'active'): ?>
                                    <span class="badge badge-success">활성</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">비활성</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('Y-m-d', strtotime($attraction['created_at'])); ?></td>
                            <td>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('상태를 변경하시겠습니까?');">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="attraction_id" value="<?php echo $attraction['id']; ?>">
                                    <input type="hidden" name="new_status" value="<?php echo $attraction['status'] === 'active' ? 'inactive' : 'active'; ?>">
                                    <button type="submit" class="btn btn-sm btn-warning">
                                        <?php echo $attraction['status'] === 'active' ? '비활성화' : '활성화'; ?>
                                    </button>
                                </form>
                                
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