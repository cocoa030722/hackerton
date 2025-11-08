<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireUserType('admin');

$conn = getDBConnection();
$success = '';
$error = '';

// 코스 추가 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_course') {
    $title = $_POST['title'] ?? '';
    $description = $_POST['description'] ?? '';
    $difficulty = $_POST['difficulty'] ?? '보통';
    $estimated_duration = !empty($_POST['estimated_duration']) ? intval($_POST['estimated_duration']) : null;
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
            // 코스 등록
            $stmt = $conn->prepare("INSERT INTO courses (title, description, region, difficulty, estimated_duration, created_by, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
            $created_by = $_SESSION['user_id'];
            $stmt->bind_param("ssssii", $title, $description, $region, $difficulty, $estimated_duration, $created_by);
            $stmt->execute();
            $course_id = $stmt->insert_id;
            $stmt->close();
            
            // 관광지 연결
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
            $success = '코스가 성공적으로 등록되었습니다.';
            
            // 등록 후 폼 초기화를 위해 GET 요청으로 리다이렉트
            header('Location: courses_register.php?success=' . urlencode($success));
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $error = '코스 등록 중 오류가 발생했습니다: ' . $e->getMessage();
        }
    }
}

// 활성 관광지 목록 조회
$attractions = [];
$result = $conn->query("SELECT id, name, category FROM attractions WHERE status = 'active' ORDER BY name");
while ($row = $result->fetch_assoc()) {
    $attractions[] = $row;
}

$conn->close();

// GET으로 전달된 성공 메시지
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}

// 페이지 설정
$page_title = '코스 등록';
$base_url = '..';
include '../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>➕ 새 코스 등록</h1>
        <p>등록된 관광지들을 선택하여 새로운 관광 코스를 만듭니다.</p>
        <div style="margin-top: 1rem;">
            <a href="courses_list.php" class="btn btn-secondary">📋 코스 목록으로</a>
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
    
    <!-- 새 코스 등록 폼 -->
    <div class="card">
        <h2>코스 정보 입력</h2>
        
        <form method="POST" action="">
            <input type="hidden" name="action" value="add_course">
                
            <div class="form-grid">
                <div class="form-group">
                    <label for="title">코스명 *</label>
                    <input type="text" id="title" name="title" required placeholder="예: 서울 역사 탐방 코스">
                </div>
                
                <div class="form-group">
                    <label for="difficulty">난이도</label>
                    <select id="difficulty" name="difficulty">
                        <option value="쉬움">쉬움</option>
                        <option value="보통" selected>보통</option>
                        <option value="어려움">어려움</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label for="description">코스 설명</label>
                <textarea id="description" name="description" rows="4" placeholder="이 코스에 대한 설명을 입력하세요"></textarea>
            </div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label for="region">지역</label>
                    <input type="text" id="region" name="region" placeholder="예: 서울">
                </div>
                
                <div class="form-group">
                    <label for="estimated_duration">예상 소요시간 (분)</label>
                    <input type="number" id="estimated_duration" name="estimated_duration" min="0" placeholder="예: 180">
                </div>
            </div>
            
            <div class="form-group">
                <label>포함할 관광지 *</label>
                <div style="display: flex; gap: 1rem; margin-bottom: 1rem;">
                    <button type="button" class="btn btn-primary" onclick="openAttractionModal()">
                        ➕ 관광지 추가
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="openManageModal()">
                        📋 관광지 관리 (<span id="selected-count">0</span>)
                    </button>
                </div>
                <div id="selected-attractions-preview" style="min-height: 60px; padding: 1rem; background: #f8f9fa; border-radius: 5px; border: 2px dashed #ccc;">
                    <small style="color: #999;">관광지 추가 버튼을 눌러 관광지를 선택하세요.</small>
                </div>
                <input type="hidden" name="attraction_ids_json" id="attraction_ids_json" value="[]">
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-lg">✅ 코스 등록</button>
                <a href="courses_list.php" class="btn btn-secondary">취소</a>
            </div>
        </form>
    </div>
</div>

<!-- 관광지 추가 모달 -->
<div id="attractionModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>관광지 추가</h2>
            <span class="close" onclick="closeAttractionModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <input type="text" id="attraction-search" placeholder="🔍 관광지 이름 또는 카테고리로 검색..." onkeyup="filterAttractions()" style="margin-bottom: 1rem;">
            </div>
            <div id="attraction-list" style="max-height: 400px; overflow-y: auto;">
                <?php foreach ($attractions as $attraction): ?>
                    <div class="attraction-item" data-id="<?php echo $attraction['id']; ?>" data-name="<?php echo htmlspecialchars($attraction['name']); ?>" data-category="<?php echo htmlspecialchars($attraction['category'] ?? ''); ?>" style="display: flex; justify-content: space-between; align-items: center; padding: 0.8rem; border-bottom: 1px solid #eee;">
                        <div>
                            <strong><?php echo htmlspecialchars($attraction['name']); ?></strong>
                            <?php if ($attraction['category']): ?>
                                <small style="color: #666; margin-left: 0.5rem;"><?php echo htmlspecialchars($attraction['category']); ?></small>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="btn btn-sm btn-primary btn-add" onclick="addAttraction(<?php echo $attraction['id']; ?>, '<?php echo htmlspecialchars($attraction['name'], ENT_QUOTES); ?>')">추가</button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- 관광지 관리 모달 -->
<div id="manageModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>선택된 관광지 관리</h2>
            <span class="close" onclick="closeManageModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div id="selected-attractions-list" style="max-height: 400px; overflow-y: auto;">
                <p style="color: #999; text-align: center; padding: 2rem;">선택된 관광지가 없습니다.</p>
            </div>
        </div>
    </div>
</div>

<script>
// 선택된 관광지 저장용 배열
let selectedAttractions = [];

// 관광지 추가 모달 열기
function openAttractionModal() {
    document.getElementById('attractionModal').style.display = 'block';
    updateAttractionList();
}

// 관광지 추가 모달 닫기
function closeAttractionModal() {
    document.getElementById('attractionModal').style.display = 'none';
    document.getElementById('attraction-search').value = '';
    filterAttractions();
}

// 관광지 관리 모달 열기
function openManageModal() {
    document.getElementById('manageModal').style.display = 'block';
    updateSelectedList();
}

// 관광지 관리 모달 닫기
function closeManageModal() {
    document.getElementById('manageModal').style.display = 'none';
}

// 모달 외부 클릭 시 닫기
window.onclick = function(event) {
    const attractionModal = document.getElementById('attractionModal');
    const manageModal = document.getElementById('manageModal');
    
    if (event.target === attractionModal) {
        closeAttractionModal();
    } else if (event.target === manageModal) {
        closeManageModal();
    }
}

// 관광지 검색 필터링
function filterAttractions() {
    const searchTerm = document.getElementById('attraction-search').value.toLowerCase();
    const items = document.querySelectorAll('.attraction-item');
    
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

// 관광지 추가
function addAttraction(id, name) {
    if (!selectedAttractions.some(attr => attr.id === id)) {
        selectedAttractions.push({ id, name });
        updateUI();
        updateAttractionList();
    }
}

// 관광지 제거
function removeAttraction(id) {
    selectedAttractions = selectedAttractions.filter(attr => attr.id !== id);
    updateUI();
    updateSelectedList();
    updateAttractionList();
}

// UI 업데이트 (카운트, 프리뷰, hidden input)
function updateUI() {
    // 카운트 업데이트
    document.getElementById('selected-count').textContent = selectedAttractions.length;
    
    // 프리뷰 업데이트
    const preview = document.getElementById('selected-attractions-preview');
    if (selectedAttractions.length === 0) {
        preview.innerHTML = '<small style="color: #999;">관광지 추가 버튼을 눌러 관광지를 선택하세요.</small>';
    } else {
        preview.innerHTML = selectedAttractions.map(attr => 
            `<span class="badge badge-info" style="margin: 0.2rem; display: inline-block;">${attr.name}</span>`
        ).join('');
    }
    
    // Hidden input 업데이트
    const attractionIds = selectedAttractions.map(attr => attr.id);
    document.getElementById('attraction_ids_json').value = JSON.stringify(attractionIds);
}

// 추가 모달의 관광지 리스트 업데이트 (이미 선택된 항목 비활성화)
function updateAttractionList() {
    const items = document.querySelectorAll('.attraction-item');
    items.forEach(item => {
        const id = parseInt(item.dataset.id);
        const button = item.querySelector('.btn-add');
        
        if (selectedAttractions.some(attr => attr.id === id)) {
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

// 관리 모달의 선택된 관광지 리스트 업데이트
function updateSelectedList() {
    const listContainer = document.getElementById('selected-attractions-list');
    
    if (selectedAttractions.length === 0) {
        listContainer.innerHTML = '<p style="color: #999; text-align: center; padding: 2rem;">선택된 관광지가 없습니다.</p>';
    } else {
        listContainer.innerHTML = selectedAttractions.map(attr => `
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.8rem; border-bottom: 1px solid #eee;">
                <span>${attr.name}</span>
                <button type="button" class="btn btn-sm btn-danger" onclick="removeAttraction(${attr.id})">삭제</button>
            </div>
        `).join('');
    }
}
</script>

<?php include '../includes/footer.php'; ?>
