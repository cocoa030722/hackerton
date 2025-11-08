<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireUserType('admin');

$conn = getDBConnection();
$success = '';
$error = '';

// 코스 추�? 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_course') {
    $name = $_POST['name'] ?? '';
    $description = $_POST['description'] ?? '';
    $difficulty = $_POST['difficulty'] ?? 'normal';
    $estimated_time = !empty($_POST['estimated_time']) ? intval($_POST['estimated_time']) : null;
    $reward_points = !empty($_POST['reward_points']) ? intval($_POST['reward_points']) : 0;
    $attraction_ids_json = $_POST['attraction_ids_json'] ?? '[]';
    $attraction_ids = json_decode($attraction_ids_json, true) ?: [];
    
    if (empty($name)) {
        $error = '코스명�? ?�수?�니??';
    } elseif (empty($attraction_ids)) {
        $error = '최소 1�??�상??관광�?�?추�??�야 ?�니??';
    } else {
        $conn->begin_transaction();
        
        try {
            // 코스 ?�록
            $stmt = $conn->prepare("INSERT INTO courses (name, description, difficulty, estimated_time, reward_points, created_by, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
            $created_by = $_SESSION['user_id'];
            $stmt->bind_param("sssiii", $name, $description, $difficulty, $estimated_time, $reward_points, $created_by);
            $stmt->execute();
            $course_id = $stmt->insert_id;
            $stmt->close();
            
            // 관광�? ?�결
            $stmt = $conn->prepare("INSERT INTO course_attractions (course_id, attraction_id, is_required) VALUES (?, ?, TRUE)");
            foreach ($attraction_ids as $attraction_id) {
                $attraction_id = intval($attraction_id);
                $stmt->bind_param("ii", $course_id, $attraction_id);
                $stmt->execute();
            }
            $stmt->close();
            
            $conn->commit();
            $success = '코스가 ?�공?�으�??�록?�었?�니??';
        } catch (Exception $e) {
            $conn->rollback();
            $error = '코스 ?�록 �??�류가 발생?�습?�다: ' . $e->getMessage();
        }
    }
}

// 코스 ??�� 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_course') {
    $course_id = intval($_POST['course_id']);
    $stmt = $conn->prepare("DELETE FROM courses WHERE id = ?");
    $stmt->bind_param("i", $course_id);
    
    if ($stmt->execute()) {
        $success = '코스가 ??��?�었?�니??';
    } else {
        $error = '코스 ??�� �??�류가 발생?�습?�다.';
    }
    $stmt->close();
}

// ?�성 관광�? 목록 조회
$attractions = [];
$result = $conn->query("SELECT id, name, category FROM attractions WHERE status = 'active' ORDER BY name");
while ($row = $result->fetch_assoc()) {
    $attractions[] = $row;
}

// 코스 목록 조회 (관광�? ?�보 ?�함)
$courses = [];
$result = $conn->query("SELECT c.*, u.full_name as creator_name,
                        (SELECT COUNT(*) FROM course_attractions WHERE course_id = c.id) as attraction_count,
                        (SELECT COUNT(*) FROM tourist_courses WHERE course_id = c.id) as tourist_count
                        FROM courses c
                        LEFT JOIN users u ON c.created_by = u.id
                        ORDER BY c.created_at DESC");
while ($row = $result->fetch_assoc()) {
    $course_id = $row['id'];
    
    // 코스???�함??관광�? 조회
    $attraction_list = [];
    $stmt = $conn->prepare("SELECT a.name FROM course_attractions ca 
                           JOIN attractions a ON ca.attraction_id = a.id 
                           WHERE ca.course_id = ?");
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $result2 = $stmt->get_result();
    while ($row2 = $result2->fetch_assoc()) {
        $attraction_list[] = $row2['name'];
    }
    $stmt->close();
    
    $row['attraction_list'] = $attraction_list;
    $courses[] = $row;
}

$conn->close();

$page_title = 'Course Management';
$base_url = '..';
include '../includes/header.php';
?>

<style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
        }
        
        .navbar {
            background: white;
            padding: 1rem 2rem;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .navbar-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            font-size: 1.3rem;
            font-weight: bold;
            color: #667eea;
        }
        
        .nav-links a {
            margin-left: 1.5rem;
            text-decoration: none;
            color: #333;
            font-weight: 500;
        }
        
        .container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        .page-header {
            margin-bottom: 2rem;
        }
        
        .page-header h1 {
            color: #333;
            margin-bottom: 0.5rem;
        }
        
        .card {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .card h2 {
            color: #333;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #667eea;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 500;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            font-size: 1rem;
        }
        
        textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .checkbox-group {
            max-height: 300px;
            overflow-y: auto;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            padding: 1rem;
        }
        
        .checkbox-item {
            margin-bottom: 0.5rem;
        }
        
        .checkbox-item input[type="checkbox"] {
            width: auto;
            margin-right: 0.5rem;
        }
        
        .btn {
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
            transition: opacity 0.3s;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-danger {
            background: #f44336;
            color: white;
        }
        
        .btn:hover {
            opacity: 0.8;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        
        .badge {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .badge-easy {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-normal {
            background: #fff3cd;
            color: #856404;
        }
        
        .badge-hard {
            background: #f8d7da;
            color: #721c24;
        }
        
        .badge-active {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #999;
        }
        
        .attraction-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }
        
        .attraction-tag {
            background: #e3f2fd;
            color: #1976d2;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.85rem;
        }
        
        /* 모달 ?��???*/
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 10px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            animation: slideDown 0.3s;
        }
        
        @keyframes slideDown {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            margin: 0;
            color: #333;
        }
        
        .close {
            color: #aaa;
            font-size: 2rem;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
        }
        
        .close:hover {
            color: #000;
        }
        
        .modal-body {
            padding: 1.5rem;
            max-height: 500px;
            overflow-y: auto;
        }
        
        .search-box {
            margin-bottom: 1.5rem;
        }
        
        .search-box input {
            width: 100%;
            padding: 0.8rem 1rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        .search-box input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .attraction-list {
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
        }
        
        .attraction-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .attraction-item:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }
        
        .attraction-info {
            flex: 1;
        }
        
        .btn-add, .btn-remove {
            padding: 0.5rem 1.2rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .btn-add {
            background: #007bff;
            color: white;
        }
        
        .btn-add:hover:not(:disabled) {
            background: #0056b3;
        }
        
        .btn-add:disabled {
            background: #6c757d;
            cursor: not-allowed;
            opacity: 0.6;
        }
        
        .btn-remove {
            background: #dc3545;
            color: white;
        }
        
        .btn-remove:hover {
            background: #c82333;
        }
        
        .selected-list {
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
        }
        
        .selected-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            background: #e3f2fd;
            border-radius: 8px;
            border-left: 4px solid #1976d2;
        }
        
        .selected-item span {
            font-weight: 500;
            color: #333;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            opacity: 0.8;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="navbar-content">
            <div class="logo">?���?코스 관�?/div>
            <div class="nav-links">
                <a href="dashboard.php">?�?�보??/a>
                <a href="attractions.php">관광�? 관�?/a>
                <a href="courses.php">코스 관�?/a>
                <a href="../index.php">메인?�로</a>
                <a href="../logout.php">로그?�웃</a>
            </div>
        </div>
    </nav>
    
    <div class="container">
        <div class="page-header">
            <h1>코스 관�?/h1>
            <p>?�록??관광�??�의 집합?�로 코스�?만들??관리합?�다.</p>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success">??<?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error">?�️ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="card">
            <h2>??코스 ?�록</h2>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_course">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="name">코스�?*</label>
                        <input type="text" id="name" name="name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="difficulty">?�이??/label>
                        <select id="difficulty" name="difficulty">
                            <option value="easy">?��?</option>
                            <option value="normal" selected>보통</option>
                            <option value="hard">?�려?�</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="description">코스 ?�명</label>
                    <textarea id="description" name="description"></textarea>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="estimated_time">?�상 ?�요?�간 (�?</label>
                        <input type="number" id="estimated_time" name="estimated_time" min="0">
                    </div>
                    
                    <div class="form-group">
                        <label for="reward_points">보상 ?�인??/label>
                        <input type="number" id="reward_points" name="reward_points" min="0" value="0">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>?�함??관광�? *</label>
                    <div style="display: flex; gap: 1rem; margin-bottom: 1rem;">
                        <button type="button" class="btn btn-primary" onclick="openAttractionModal()">
                            ??관광�? 추�?
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="openManageModal()">
                            ?�� 관광�? 관�?(<span id="selected-count">0</span>)
                        </button>
                    </div>
                    <div id="selected-attractions-preview" style="min-height: 60px; padding: 1rem; background: #f8f9fa; border-radius: 5px; border: 2px dashed #ccc;">
                        <small style="color: #999;">관광�? 추�? 버튼???�러 관광�?�??�택?�세??</small>
                    </div>
                    <input type="hidden" name="attraction_ids_json" id="attraction_ids_json" value="[]">
                </div>
                
                <button type="submit" class="btn btn-primary">코스 ?�록</button>
            </form>
        </div>
        
        <div class="card">
            <h2>?�록??코스 목록 (<?php echo count($courses); ?>�?</h2>
            
            <?php if (empty($courses)): ?>
                <div class="empty-state">
                    <p>?�록??코스가 ?�습?�다.</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>코스�?/th>
                            <th>?�이??/th>
                            <th>?�함 관광�?</th>
                            <th>보상</th>
                            <th>참여??/th>
                            <th>?�태</th>
                            <th>관�?/th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($courses as $course): ?>
                            <tr>
                                <td><?php echo $course['id']; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($course['name']); ?></strong>
                                    <?php if ($course['description']): ?>
                                        <br><small style="color: #666;"><?php echo htmlspecialchars(mb_substr($course['description'], 0, 50)); ?>...</small>
                                    <?php endif; ?>
                                    <?php if ($course['estimated_time']): ?>
                                        <br><small style="color: #666;">?�️ <?php echo $course['estimated_time']; ?>�?/small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $difficulty_badges = [
                                        'easy' => '<span class="badge badge-easy">?��?</span>',
                                        'normal' => '<span class="badge badge-normal">보통</span>',
                                        'hard' => '<span class="badge badge-hard">?�려?�</span>'
                                    ];
                                    echo $difficulty_badges[$course['difficulty']] ?? '';
                                    ?>
                                </td>
                                <td>
                                    <small><?php echo $course['attraction_count']; ?>�?관광�?</small>
                                    <div class="attraction-tags">
                                        <?php foreach ($course['attraction_list'] as $attr_name): ?>
                                            <span class="attraction-tag"><?php echo htmlspecialchars($attr_name); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td>
                                    <small><?php echo number_format($course['reward_points']); ?>P</small>
                                </td>
                                <td><?php echo $course['tourist_count']; ?>�?/td>
                                <td>
                                    <?php if ($course['status'] === 'active'): ?>
                                        <span class="badge badge-active">?�성</span>
                                    <?php else: ?>
                                        <span class="badge badge-inactive">비활??/span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('?�말 ??��?�시겠습?�까?');">
                                        <input type="hidden" name="action" value="delete_course">
                                        <input type="hidden" name="course_id" value="<?php echo $course['id']; ?>">
                                        <button type="submit" class="btn btn-danger" style="padding: 0.4rem 0.8rem; font-size: 0.9rem;">??��</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- 관광�? 추�? 모달 -->
    <div id="attractionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>관광�? 추�?</h3>
                <span class="close" onclick="closeAttractionModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="search-box">
                    <input type="text" id="attraction-search" placeholder="?�� 관광�? ?�름 ?�는 카테고리�?검??.." onkeyup="filterAttractions()">
                </div>
                <div id="attraction-list" class="attraction-list">
                    <?php foreach ($attractions as $attraction): ?>
                        <div class="attraction-item" data-id="<?php echo $attraction['id']; ?>" data-name="<?php echo htmlspecialchars($attraction['name']); ?>" data-category="<?php echo htmlspecialchars($attraction['category'] ?? ''); ?>">
                            <div class="attraction-info">
                                <strong><?php echo htmlspecialchars($attraction['name']); ?></strong>
                                <?php if ($attraction['category']): ?>
                                    <small style="color: #666; margin-left: 0.5rem;"><?php echo htmlspecialchars($attraction['category']); ?></small>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn-add" onclick="addAttraction(<?php echo $attraction['id']; ?>, '<?php echo htmlspecialchars($attraction['name'], ENT_QUOTES); ?>')">추�?</button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 관광�? 관�?모달 -->
    <div id="manageModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>?�택??관광�? 관�?/h3>
                <span class="close" onclick="closeManageModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div id="selected-attractions-list" class="selected-list">
                    <p style="color: #999; text-align: center; padding: 2rem;">?�택??관광�?가 ?�습?�다.</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // ?�택??관광�? ?�?�용 배열
        let selectedAttractions = [];

        // 관광�? 추�? 모달 ?�기
        function openAttractionModal() {
            document.getElementById('attractionModal').style.display = 'block';
            updateAttractionList();
        }

        // 관광�? 추�? 모달 ?�기
        function closeAttractionModal() {
            document.getElementById('attractionModal').style.display = 'none';
            document.getElementById('attraction-search').value = '';
            filterAttractions();
        }

        // 관광�? 관�?모달 ?�기
        function openManageModal() {
            document.getElementById('manageModal').style.display = 'block';
            updateSelectedList();
        }

        // 관광�? 관�?모달 ?�기
        function closeManageModal() {
            document.getElementById('manageModal').style.display = 'none';
        }

        // 모달 ?��? ?�릭 ???�기
        window.onclick = function(event) {
            const attractionModal = document.getElementById('attractionModal');
            const manageModal = document.getElementById('manageModal');
            if (event.target === attractionModal) {
                closeAttractionModal();
            } else if (event.target === manageModal) {
                closeManageModal();
            }
        }

        // 관광�? 검???�터�?
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

        // 관광�? 추�?
        function addAttraction(id, name) {
            if (!selectedAttractions.some(attr => attr.id === id)) {
                selectedAttractions.push({ id, name });
                updateUI();
                updateAttractionList();
            }
        }

        // 관광�? ?�거
        function removeAttraction(id) {
            selectedAttractions = selectedAttractions.filter(attr => attr.id !== id);
            updateUI();
            updateSelectedList();
        }

        // UI ?�데?�트 (카운?? ?�리�? hidden input)
        function updateUI() {
            // 카운???�데?�트
            document.getElementById('selected-count').textContent = selectedAttractions.length;
            
            // ?�리�??�데?�트
            const preview = document.getElementById('selected-attractions-preview');
            if (selectedAttractions.length === 0) {
                preview.innerHTML = '<small style="color: #999;">관광�? 추�? 버튼???�러 관광�?�??�택?�세??</small>';
            } else {
                preview.innerHTML = selectedAttractions.map(attr => 
                    `<span class="attraction-tag">${attr.name}</span>`
                ).join('');
            }
            
            // Hidden input ?�데?�트
            const attractionIds = selectedAttractions.map(attr => attr.id);
            document.getElementById('attraction_ids_json').value = JSON.stringify(attractionIds);
        }

        // 추�? 모달??관광�? 리스???�데?�트 (?��? ?�택????�� 비활?�화)
        function updateAttractionList() {
            const items = document.querySelectorAll('.attraction-item');
            items.forEach(item => {
                const id = parseInt(item.dataset.id);
                const button = item.querySelector('.btn-add');
                
                if (selectedAttractions.some(attr => attr.id === id)) {
                    button.disabled = true;
                    button.textContent = '추�???;
                    button.style.background = '#6c757d';
                } else {
                    button.disabled = false;
                    button.textContent = '추�?';
                    button.style.background = '#007bff';
                }
            });
        }

        // 관�?모달???�택??관광�? 리스???�데?�트
        function updateSelectedList() {
            const listContainer = document.getElementById('selected-attractions-list');
            
            if (selectedAttractions.length === 0) {
                listContainer.innerHTML = '<p style="color: #999; text-align: center; padding: 2rem;">?�택??관광�?가 ?�습?�다.</p>';
            } else {
                listContainer.innerHTML = selectedAttractions.map(attr => `
                    <div class="selected-item">
                        <span>${attr.name}</span>
                        <button type="button" class="btn-remove" onclick="removeAttraction(${attr.id})">??��</button>
                    </div>
                `).join('');
            }
        }
    </script>

<?php include '../includes/footer.php'; ?>
