<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireUserType('admin');

$conn = getDBConnection();
$success = '';
$error = '';

// 승인/거부 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $user_id = intval($_POST['user_id']);
    $action = $_POST['action'];
    
    if ($action === 'approve') {
        // 사용자 승인
        $stmt = $conn->prepare("UPDATE users SET status = 'approved' WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
        
        $success = '관광지 책임자가 승인되었습니다.';
    } elseif ($action === 'reject') {
        // 사용자 거부
        $stmt = $conn->prepare("UPDATE users SET status = 'rejected' WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
        
        $success = '관광지 책임자가 거부되었습니다.';
    }
}

// 통계 데이터
$stats = [
    'total_users' => 0,
    'pending_approvals' => 0,
    'total_managers' => 0,
    'total_tourists' => 0,
    'total_attractions' => 0,
    'active_courses' => 0
];

// 전체 사용자 수
$result = $conn->query("SELECT COUNT(*) as count FROM users");
$stats['total_users'] = $result->fetch_assoc()['count'];

// 승인 대기 중인 관광지 책임자
$result = $conn->query("SELECT COUNT(*) as count FROM users WHERE user_type = 'attraction_manager' AND status = 'pending'");
$stats['pending_approvals'] = $result->fetch_assoc()['count'];

// 승인된 관광지 책임자 수
$result = $conn->query("SELECT COUNT(*) as count FROM users WHERE user_type = 'attraction_manager' AND status = 'approved'");
$stats['total_managers'] = $result->fetch_assoc()['count'];

// 관광객 수
$result = $conn->query("SELECT COUNT(*) as count FROM users WHERE user_type = 'tourist'");
$stats['total_tourists'] = $result->fetch_assoc()['count'];

// 등록된 관광지 수
$result = $conn->query("SELECT COUNT(*) as count FROM attractions WHERE status = 'active'");
$stats['total_attractions'] = $result->fetch_assoc()['count'];

// 활성 코스 수
$result = $conn->query("SELECT COUNT(*) as count FROM courses WHERE status = 'active'");
$stats['active_courses'] = $result->fetch_assoc()['count'];

// 승인 대기 중인 관광지 책임자 목록
$pending_users = [];
$result = $conn->query("SELECT u.*, ami.attraction_name, ami.business_registration_number
    FROM users u
    LEFT JOIN attraction_manager_info ami ON u.id = ami.user_id
    WHERE u.user_type = 'attraction_manager' AND u.status = 'pending'
    ORDER BY u.created_at DESC");

while ($row = $result->fetch_assoc()) {
    $pending_users[] = $row;
}

// 최근 승인/거부된 사용자 목록
$recent_processed = [];
$result = $conn->query("SELECT u.*, ami.attraction_name, ami.business_registration_number
    FROM users u
    LEFT JOIN attraction_manager_info ami ON u.id = ami.user_id
    WHERE u.user_type = 'attraction_manager' AND u.status IN ('approved', 'rejected')
    ORDER BY u.updated_at DESC
    LIMIT 10");

while ($row = $result->fetch_assoc()) {
    $recent_processed[] = $row;
}

$conn->close();

$page_title = '관리자 대시보드';
$base_url = '..';
include '../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>📊 관리자 대시보드</h1>
        <p>관광지 책임자 승인 관리</p>
    </div>
    
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
    
    <!-- 통계 카드 -->
    <div class="grid grid-3" style="margin-bottom: 2rem;">
        <div class="card text-center">
            <h3 style="color: var(--warning-color); margin: 0;">⏳ <?php echo $stats['pending_approvals']; ?>건</h3>
            <p style="color: var(--text-light); margin: 0.5rem 0 0 0;">승인 대기</p>
        </div>
        <div class="card text-center">
            <h3 style="color: var(--success-color); margin: 0;">✅ <?php echo $stats['total_managers']; ?>명</h3>
            <p style="color: var(--text-light); margin: 0.5rem 0 0 0;">승인된 책임자</p>
        </div>
        <div class="card text-center">
            <h3 style="color: var(--primary-color); margin: 0;">🏛️ <?php echo $stats['total_attractions']; ?>개</h3>
            <p style="color: var(--text-light); margin: 0.5rem 0 0 0;">등록된 관광지</p>
        </div>
    </div>
    
    <!-- 승인 대기 목록 -->
    <div class="card">
        <h2>⏳ 승인 대기 목록</h2>
        
        <?php if (empty($pending_users)): ?>
            <div class="empty-state">
                <p>✨ 승인 대기 중인 관광지 책임자가 없습니다.</p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>이름</th>
                        <th>아이디</th>
                        <th>이메일</th>
                        <th>연락처</th>
                        <th>가입일</th>
                        <th>작업</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending_users as $user): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($user['full_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td><?php echo htmlspecialchars($user['phone']); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($user['created_at'])); ?></td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <button type="submit" name="action" value="approve" class="btn btn-sm btn-success" 
                                            onclick="return confirm('<?php echo htmlspecialchars($user['full_name']); ?>님을 승인하시겠습니까?');">
                                        ✓ 승인
                                    </button>
                                    <button type="submit" name="action" value="reject" class="btn btn-sm btn-danger"
                                            onclick="return confirm('<?php echo htmlspecialchars($user['full_name']); ?>님을 거부하시겠습니까?');">
                                        ✗ 거부
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <!-- 최근 처리 내역 -->
    <?php if (!empty($recent_processed)): ?>
    <div class="card" style="margin-top: 2rem;">
        <h2>📋 최근 처리 내역</h2>
        <table>
            <thead>
                <tr>
                    <th>이름</th>
                    <th>아이디</th>
                    <th>이메일</th>
                    <th>상태</th>
                    <th>처리일</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_processed as $user): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                        <td>
                            <?php if ($user['status'] === 'approved'): ?>
                                <span class="badge badge-success">✅ 승인</span>
                            <?php else: ?>
                                <span class="badge badge-danger">❌ 거부</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo date('Y-m-d H:i', strtotime($user['updated_at'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- 빠른 링크 -->
    <div class="grid grid-3" style="margin-top: 2rem;">
        <a href="attractions.php" class="card text-center" style="text-decoration: none; color: inherit;">
            <h3>🏛️</h3>
            <p>관광지 관리</p>
        </a>
        <a href="courses_list.php" class="card text-center" style="text-decoration: none; color: inherit;">
            <h3>🎯</h3>
            <p>코스 관리</p>
        </a>
        <a href="../index.php" class="card text-center" style="text-decoration: none; color: inherit;">
            <h3>🏠</h3>
            <p>메인으로</p>
        </a>
    </div>
</div>

<style>
.badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.85rem;
    font-weight: 500;
}

.badge-success {
    background-color: var(--success-color);
    color: white;
}

.badge-danger {
    background-color: var(--danger-color);
    color: white;
}

.empty-state {
    text-align: center;
    padding: 3rem 1rem;
    color: var(--text-light);
}

.empty-state p {
    font-size: 1.1rem;
}
</style>

<?php include '../includes/footer.php'; ?>
