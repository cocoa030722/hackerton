<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireUserType('admin');

$conn = getDBConnection();
$success = '';
$error = '';

// 코스 수정 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_course') {
    $course_id = intval($_POST['course_id']);
    $title = $_POST['title'] ?? '';
    $description = $_POST['description'] ?? '';
    $difficulty = $_POST['difficulty'] ?? '보통';
    $estimated_duration = !empty($_POST['estimated_duration']) ? intval($_POST['estimated_duration']) : null;
    $reward_points = !empty($_POST['reward_points']) ? intval($_POST['reward_points']) : 0;
    $region = $_POST['region'] ?? '';
    $attraction_ids_json = $_POST['attraction_ids_json'] ?? '[]';
    $attraction_ids = json_decode($attraction_ids_json, true) ?: [];
    
    if (empty($title)) {
        $error = '코스명은 필수입니다.';
    } elseif (empty($attraction_ids)) {
        $error = '최소 1개 이상의 관광지를 추가해야 합니다.';
    } else {
        $conn->begin_transaction();
        
        try {
            // 코스 정보 업데이트 (보상 포인트 포함)
            $stmt = $conn->prepare("UPDATE courses SET title = ?, description = ?, region = ?, difficulty = ?, estimated_duration = ?, reward_points = ? WHERE id = ?");
            $stmt->bind_param("ssssiii", $title, $description, $region, $difficulty, $estimated_duration, $reward_points, $course_id);
            $stmt->execute();
            $stmt->close();
            
            // 기존 관광지 연결 삭제
            $stmt = $conn->prepare("DELETE FROM course_attractions WHERE course_id = ?");
            $stmt->bind_param("i", $course_id);
            $stmt->execute();
            $stmt->close();
            
            // 새로운 관광지 연결 추가
            $stmt = $conn->prepare("INSERT INTO course_attractions (course_id, attraction_id, sequence_order) VALUES (?, ?, ?)");
            $sequence = 1;
            foreach ($attraction_ids as $attraction_id) {
                $attraction_id = intval($attraction_id);
                $stmt->bind_param("iii", $course_id, $attraction_id, $sequence);
                $stmt->execute();
                $sequence++;
            }
            $stmt->close();
            
            $conn->commit();
            $success = '코스가 성공적으로 수정되었습니다.';
        } catch (Exception $e) {
            $conn->rollback();
            $error = '코스 수정 중 오류가 발생했습니다: ' . $e->getMessage();
        }
    }
}

// 코스 삭제 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_course') {
    $course_id = intval($_POST['course_id']);
    $stmt = $conn->prepare("DELETE FROM courses WHERE id = ?");
    $stmt->bind_param("i", $course_id);
    
    if ($stmt->execute()) {
        $success = '코스가 삭제되었습니다.';
    } else {
        $error = '코스 삭제 중 오류가 발생했습니다.';
    }
    $stmt->close();
}

// 활성 관광지 목록 조회
$attractions = [];
$result = $conn->query("SELECT id, name, category FROM attractions WHERE status = 'active' ORDER BY name");
while ($row = $result->fetch_assoc()) {
    $attractions[] = $row;
}

// 코스 목록 조회 (관광지 정보 포함)
$courses = [];
$result = $conn->query("SELECT c.*, u.full_name as creator_name,
                        (SELECT COUNT(*) FROM course_attractions WHERE course_id = c.id) as attraction_count,
                        (SELECT COUNT(*) FROM tourist_courses WHERE course_id = c.id) as tourist_count
                        FROM courses c
                        LEFT JOIN users u ON c.created_by = u.id
                        ORDER BY c.created_at DESC");
while ($row = $result->fetch_assoc()) {
    $course_id = $row['id'];
    
    // 코스에 포함된 관광지 조회
    $attraction_list = [];
    $attraction_ids_list = [];
    $stmt = $conn->prepare("SELECT a.id, a.name FROM course_attractions ca 
                           JOIN attractions a ON ca.attraction_id = a.id 
                           WHERE ca.course_id = ? ORDER BY ca.sequence_order");
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $result2 = $stmt->get_result();
    while ($row2 = $result2->fetch_assoc()) {
        $attraction_list[] = $row2['name'];
        $attraction_ids_list[] = $row2['id'];
    }
    $stmt->close();
    
    $row['attraction_list'] = $attraction_list;
    $row['attraction_ids'] = $attraction_ids_list;
    $courses[] = $row;
}

$conn->close();

// 페이지 설정
$page_title = '코스 목록';
$base_url = '..';
include '../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>📋 코스 목록</h1>
        <p>등록된 관광 코스를 관리합니다.</p>
        <div style="margin-top: 1rem;">
            <a href="courses_register.php" class="btn btn-primary">➕ 새 코스 등록</a>
        </div>
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
    
    <!-- 코스 목록 -->
    <div class="card">
        <h2>등록된 코스 (<?php echo count($courses); ?>개)</h2>
        
        <?php if (empty($courses)): ?>
            <div class="empty-state">
                <p>등록된 코스가 없습니다.</p>
                <a href="courses_register.php" class="btn btn-primary">첫 코스 등록하기</a>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>코스명</th>
                        <th>난이도</th>
                        <th>포함 관광지</th>
                        <th>보상 포인트</th>
                        <th>지역</th>
                        <th>참여자</th>
                        <th>상태</th>
                        <th>관리</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($courses as $course): ?>
                        <tr>
                            <td><?php echo $course['id']; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($course['title']); ?></strong>
                                <?php if ($course['description']): ?>
                                    <br><small style="color: #666;"><?php echo htmlspecialchars(mb_substr($course['description'], 0, 50)); ?>...</small>
                                <?php endif; ?>
                                <?php if ($course['estimated_duration']): ?>
                                    <br><small style="color: #666;">⏱️ <?php echo $course['estimated_duration']; ?>분</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $difficulty_badges = [
                                    '쉬움' => '<span class="badge badge-success">쉬움</span>',
                                    '보통' => '<span class="badge badge-info">보통</span>',
                                    '어려움' => '<span class="badge badge-danger">어려움</span>'
                                ];
                                echo $difficulty_badges[$course['difficulty']] ?? '<span class="badge badge-info">' . htmlspecialchars($course['difficulty']) . '</span>';
                                ?>
                            </td>
                            <td>
                                <small><?php echo $course['attraction_count']; ?>개 관광지</small>
                                <div style="margin-top: 0.5rem;">
                                    <?php foreach ($course['attraction_list'] as $attr_name): ?>
                                        <span class="badge badge-secondary" style="margin: 0.2rem;"><?php echo htmlspecialchars($attr_name); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td>
                                <strong style="color: var(--primary-color);">
                                    <?php echo number_format($course['reward_points'] ?? 0); ?>원
                                </strong>
                                <br><small style="color: #999;">지역화폐: <?php echo number_format(($course['reward_points'] ?? 0) * 1.1); ?>원</small>
                            </td>
                            <td>
                                <small><?php echo htmlspecialchars($course['region'] ?? '-'); ?></small>
                            </td>
                            <td><?php echo $course['tourist_count']; ?>명</td>
                            <td>
                                <?php if ($course['status'] === 'active'): ?>
                                    <span class="badge badge-success">활성</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">비활성</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-primary" onclick="openEditModal(<?php echo $course['id']; ?>)">수정</button>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('정말 삭제하시겠습니까?\n이 코스에 등록된 관광객 정보도 모두 삭제됩니다.');">
                                    <input type="hidden" name="action" value="delete_course">
                                    <input type="hidden" name="course_id" value="<?php echo $course['id']; ?>">
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

<!-- 코스 수정 모달 -->
<div id="editModal" class="modal">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h2>코스 수정</h2>
            <span class="close" onclick="closeEditModal()">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="" id="editForm">
                <input type="hidden" name="action" value="update_course">
                <input type="hidden" name="course_id" id="edit_course_id">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="edit_title">코스명 *</label>
                        <input type="text" id="edit_title" name="title" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_difficulty">난이도</label>
                        <select id="edit_difficulty" name="difficulty">
                            <option value="쉬움">쉬움</option>
                            <option value="보통">보통</option>
                            <option value="어려움">어려움</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="edit_description">코스 설명</label>
                    <textarea id="edit_description" name="description" rows="4"></textarea>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="edit_region">지역</label>
                        <input type="text" id="edit_region" name="region" placeholder="예: 서울">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_estimated_duration">예상 소요시간 (분)</label>
                        <input type="number" id="edit_estimated_duration" name="estimated_duration" min="0">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="edit_reward_points">보상 포인트 (원) *</label>
                    <input type="number" id="edit_reward_points" name="reward_points" min="0" value="0" required>
                    <small style="color: #666; display: block; margin-top: 0.5rem;">
                        💡 코스 완료 시 관광객에게 지급될 보상 포인트입니다. (지역화폐 선택 시 10% 추가)
                    </small>
                </div>
                
                <div class="form-group">
                    <label>포함할 관광지 *</label>
                    <div style="display: flex; gap: 1rem; margin-bottom: 1rem;">
                        <button type="button" class="btn btn-primary" onclick="openEditAttractionModal()">
                            ➕ 관광지 추가
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="openEditManageModal()">
                            📋 관광지 관리 (<span id="edit-selected-count">0</span>)
                        </button>
                    </div>
                    <div id="edit-selected-attractions-preview" style="min-height: 60px; padding: 1rem; background: #f8f9fa; border-radius: 5px; border: 2px dashed #ccc;">
                        <small style="color: #999;">관광지 추가 버튼을 눌러 관광지를 선택하세요.</small>
                    </div>
                    <input type="hidden" name="attraction_ids_json" id="edit_attraction_ids_json" value="[]">
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">취소</button>
                    <button type="submit" class="btn btn-primary">수정 완료</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 수정용 관광지 추가 모달 -->
<div id="editAttractionModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>관광지 추가</h2>
            <span class="close" onclick="closeEditAttractionModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <input type="text" id="edit-attraction-search" placeholder="🔍 관광지 이름 또는 카테고리로 검색..." onkeyup="filterEditAttractions()" style="margin-bottom: 1rem;">
            </div>
            <div id="edit-attraction-list" style="max-height: 400px; overflow-y: auto;">
                <?php foreach ($attractions as $attraction): ?>
                    <div class="edit-attraction-item" data-id="<?php echo $attraction['id']; ?>" data-name="<?php echo htmlspecialchars($attraction['name']); ?>" data-category="<?php echo htmlspecialchars($attraction['category'] ?? ''); ?>" style="display: flex; justify-content: space-between; align-items: center; padding: 0.8rem; border-bottom: 1px solid #eee;">
                        <div>
                            <strong><?php echo htmlspecialchars($attraction['name']); ?></strong>
                            <?php if ($attraction['category']): ?>
                                <small style="color: #666; margin-left: 0.5rem;"><?php echo htmlspecialchars($attraction['category']); ?></small>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="btn btn-sm btn-primary btn-edit-add" onclick="addEditAttraction(<?php echo $attraction['id']; ?>, '<?php echo htmlspecialchars($attraction['name'], ENT_QUOTES); ?>')">추가</button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- 수정용 관광지 관리 모달 -->
<div id="editManageModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>선택된 관광지 관리</h2>
            <span class="close" onclick="closeEditManageModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div id="edit-selected-attractions-list" style="max-height: 400px; overflow-y: auto;">
                <p style="color: #999; text-align: center; padding: 2rem;">선택된 관광지가 없습니다.</p>
            </div>
        </div>
    </div>
</div>

<script>
// 코스 데이터 (PHP에서 전달)
const coursesData = <?php echo json_encode($courses); ?>;

// 선택된 관광지 저장용 배열
let editSelectedAttractions = [];

// 코스 수정 모달 열기
function openEditModal(courseId) {
    const course = coursesData.find(c => c.id == courseId);
    if (!course) {
        alert('코스 정보를 찾을 수 없습니다.');
        return;
    }
    
    // 폼 필드 채우기
    document.getElementById('edit_course_id').value = course.id;
    document.getElementById('edit_title').value = course.title;
    document.getElementById('edit_description').value = course.description || '';
    document.getElementById('edit_difficulty').value = course.difficulty;
    document.getElementById('edit_region').value = course.region || '';
    document.getElementById('edit_estimated_duration').value = course.estimated_duration || '';
    document.getElementById('edit_reward_points').value = course.reward_points || 0;
    
    // 관광지 데이터 로드
    editSelectedAttractions = [];
    if (course.attraction_ids && course.attraction_list) {
        for (let i = 0; i < course.attraction_ids.length; i++) {
            editSelectedAttractions.push({
                id: parseInt(course.attraction_ids[i]),
                name: course.attraction_list[i]
            });
        }
    }
    
    updateEditUI();
    document.getElementById('editModal').style.display = 'block';
}

// 코스 수정 모달 닫기
function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
    editSelectedAttractions = [];
}

// 수정용 관광지 추가 모달 열기
function openEditAttractionModal() {
    document.getElementById('editAttractionModal').style.display = 'block';
    updateEditAttractionList();
}

// 수정용 관광지 추가 모달 닫기
function closeEditAttractionModal() {
    document.getElementById('editAttractionModal').style.display = 'none';
    document.getElementById('edit-attraction-search').value = '';
    filterEditAttractions();
}

// 수정용 관광지 관리 모달 열기
function openEditManageModal() {
    document.getElementById('editManageModal').style.display = 'block';
    updateEditSelectedList();
}

// 수정용 관광지 관리 모달 닫기
function closeEditManageModal() {
    document.getElementById('editManageModal').style.display = 'none';
}

// 모달 외부 클릭 시 닫기
window.onclick = function(event) {
    const editModal = document.getElementById('editModal');
    const editAttractionModal = document.getElementById('editAttractionModal');
    const editManageModal = document.getElementById('editManageModal');
    
    if (event.target === editModal) {
        closeEditModal();
    } else if (event.target === editAttractionModal) {
        closeEditAttractionModal();
    } else if (event.target === editManageModal) {
        closeEditManageModal();
    }
}

// 수정용 관광지 검색 필터링
function filterEditAttractions() {
    const searchTerm = document.getElementById('edit-attraction-search').value.toLowerCase();
    const items = document.querySelectorAll('.edit-attraction-item');
    
    items.forEach(item => {
        const name = item.dataset.name.toLowerCase();
        const category = item.dataset.category.toLowerCase();
        
        if (name.includes(searchTerm) || category.includes(searchTerm)) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
}

// 수정용 관광지 추가
function addEditAttraction(id, name) {
    if (!editSelectedAttractions.some(attr => attr.id === id)) {
        editSelectedAttractions.push({ id, name });
        updateEditUI();
        updateEditAttractionList();
    }
}

// 수정용 관광지 제거
function removeEditAttraction(id) {
    editSelectedAttractions = editSelectedAttractions.filter(attr => attr.id !== id);
    updateEditUI();
    updateEditSelectedList();
    updateEditAttractionList();
}

// 수정용 UI 업데이트
function updateEditUI() {
    // 카운트 업데이트
    document.getElementById('edit-selected-count').textContent = editSelectedAttractions.length;
    
    // 프리뷰 업데이트
    const preview = document.getElementById('edit-selected-attractions-preview');
    if (editSelectedAttractions.length === 0) {
        preview.innerHTML = '<small style="color: #999;">관광지 추가 버튼을 눌러 관광지를 선택하세요.</small>';
    } else {
        preview.innerHTML = editSelectedAttractions.map(attr => 
            `<span class="badge badge-info" style="margin: 0.2rem; display: inline-block;">${attr.name}</span>`
        ).join('');
    }
    
    // Hidden input 업데이트
    const attractionIds = editSelectedAttractions.map(attr => attr.id);
    document.getElementById('edit_attraction_ids_json').value = JSON.stringify(attractionIds);
}

// 수정용 관광지 리스트 업데이트
function updateEditAttractionList() {
    const items = document.querySelectorAll('.edit-attraction-item');
    items.forEach(item => {
        const id = parseInt(item.dataset.id);
        const button = item.querySelector('.btn-edit-add');
        
        if (editSelectedAttractions.some(attr => attr.id === id)) {
            button.disabled = true;
            button.textContent = '추가됨';
            button.classList.remove('btn-primary');
            button.classList.add('btn-secondary');
        } else {
            button.disabled = false;
            button.textContent = '추가';
            button.classList.remove('btn-secondary');
            button.classList.add('btn-primary');
        }
    });
}

// 수정용 선택된 관광지 리스트 업데이트
function updateEditSelectedList() {
    const listContainer = document.getElementById('edit-selected-attractions-list');
    
    if (editSelectedAttractions.length === 0) {
        listContainer.innerHTML = '<p style="color: #999; text-align: center; padding: 2rem;">선택된 관광지가 없습니다.</p>';
    } else {
        listContainer.innerHTML = editSelectedAttractions.map(attr => `
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.8rem; border-bottom: 1px solid #eee;">
                <span>${attr.name}</span>
                <button type="button" class="btn btn-sm btn-danger" onclick="removeEditAttraction(${attr.id})">삭제</button>
            </div>
        `).join('');
    }
}
</script>

<?php include '../includes/footer.php'; ?>
