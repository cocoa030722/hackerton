<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireUserType('tourist');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['course_id'])) {
    header('Location: select_course.php');
    exit();
}

$conn = getDBConnection();
$user_id = $_SESSION['user_id'];
$course_id = intval($_POST['course_id']);

// 코스가 존재하고 활성 상태인지 확인
$stmt = $conn->prepare("SELECT id, name FROM courses WHERE id = ? AND status = 'active'");
$stmt->bind_param("i", $course_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error'] = '유효하지 않은 코스입니다.';
    $stmt->close();
    $conn->close();
    header('Location: select_course.php');
    exit();
}

$course = $result->fetch_assoc();
$stmt->close();

// 이미 등록되어 있는지 확인
$stmt = $conn->prepare("SELECT id, status FROM tourist_courses WHERE tourist_id = ? AND course_id = ? AND status != 'expired'");
$stmt->bind_param("ii", $user_id, $course_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $_SESSION['error'] = '이미 참여 중인 코스입니다.';
    $stmt->close();
    $conn->close();
    header('Location: my_courses.php');
    exit();
}
$stmt->close();

// QR 코드 쿨타임이 남은 관광지 확인 (30일 이내 인증한 관광지)
$stmt = $conn->prepare("SELECT DISTINCT a.name 
                        FROM course_attractions ca
                        JOIN attractions a ON ca.attraction_id = a.id
                        WHERE ca.course_id = ?
                        AND EXISTS (
                            SELECT 1 FROM attraction_verifications av
                            WHERE av.tourist_id = ?
                            AND av.attraction_id = a.id
                            AND av.code_type = 'qr'
                            AND av.used_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                            AND av.is_used = TRUE
                        )");
$stmt->bind_param("ii", $course_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
$cooldown_attractions = [];
while ($row = $result->fetch_assoc()) {
    $cooldown_attractions[] = $row['name'];
}
$stmt->close();

// 코스 등록 (진행도 0%로 초기화)
$stmt = $conn->prepare("INSERT INTO tourist_courses (tourist_id, course_id, status, started_at, progress_percentage) VALUES (?, ?, 'in_progress', NOW(), 0)");
$stmt->bind_param("ii", $user_id, $course_id);

if ($stmt->execute()) {
    $success_msg = "'{$course['name']}' 코스에 참여하셨습니다! 관광지를 방문하여 인증을 시작하세요.";
    
    // 쿨타임 관광지가 있으면 경고 메시지 추가
    if (!empty($cooldown_attractions)) {
        $attraction_list = implode(', ', $cooldown_attractions);
        $success_msg .= "<br><br><strong>⚠️ 부정인증 방지 안내</strong><br>";
        $success_msg .= "다음의 관광지는 QR코드를 통해 인증할 수 없습니다:<br>";
        $success_msg .= "<span style='color: #f44336;'>" . htmlspecialchars($attraction_list) . "</span><br>";
        $success_msg .= "이 관광지를 인증할 때에는 <strong>문자열 코드</strong>를 사용하여야 합니다.";
    }
    
    $_SESSION['success'] = $success_msg;
    header('Location: my_courses.php');
} else {
    $_SESSION['error'] = '코스 등록 중 오류가 발생했습니다.';
    header('Location: select_course.php');
}

$stmt->close();
$conn->close();
exit();
?>
