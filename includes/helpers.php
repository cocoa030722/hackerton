<?php
/**
 * 페이지 헤더 출력 헬퍼 함수
 */
function renderPageHeader($title, $description = '') {
    echo '<div class="page-header">';
    echo '<h1>' . htmlspecialchars($title) . '</h1>';
    if ($description) {
        echo '<p>' . htmlspecialchars($description) . '</p>';
    }
    echo '</div>';
}

/**
 * 통계 카드 출력 헬퍼 함수
 */
function renderStatCard($icon, $value, $label, $color = '') {
    $style = $color ? "background: linear-gradient(135deg, $color, " . adjustColor($color, -20) . ");" : '';
    echo '<div class="stat-card" style="' . $style . '">';
    echo '<div class="stat-card-icon">' . $icon . '</div>';
    echo '<div class="stat-card-value">' . htmlspecialchars($value) . '</div>';
    echo '<div class="stat-card-label">' . htmlspecialchars($label) . '</div>';
    echo '</div>';
}

/**
 * 알림 출력 헬퍼 함수
 */
function renderAlert($message, $type = 'info') {
    echo '<div class="alert alert-' . $type . '">';
    echo htmlspecialchars($message);
    echo '</div>';
}

/**
 * 색상 조정 함수
 */
function adjustColor($color, $percent) {
    $color = str_replace('#', '', $color);
    $r = hexdec(substr($color, 0, 2));
    $g = hexdec(substr($color, 2, 2));
    $b = hexdec(substr($color, 4, 2));
    
    $r = max(0, min(255, $r + $percent));
    $g = max(0, min(255, $g + $percent));
    $b = max(0, min(255, $b + $percent));
    
    return '#' . str_pad(dechex($r), 2, '0', STR_PAD_LEFT) 
              . str_pad(dechex($g), 2, '0', STR_PAD_LEFT)
              . str_pad(dechex($b), 2, '0', STR_PAD_LEFT);
}

/**
 * 배지 출력 헬퍼 함수
 */
function renderBadge($text, $type = 'primary') {
    echo '<span class="badge badge-' . $type . '">' . htmlspecialchars($text) . '</span>';
}

/**
 * 상태 배지 출력
 */
function renderStatusBadge($status) {
    $badges = [
        'active' => ['성공', 'success'],
        'completed' => ['완료', 'success'],
        'in_progress' => ['진행중', 'info'],
        'pending' => ['대기', 'warning'],
        'inactive' => ['비활성', 'secondary'],
        'rejected' => ['거부', 'danger']
    ];
    
    $badge = $badges[$status] ?? ['알수없음', 'secondary'];
    renderBadge($badge[0], $badge[1]);
}
?>
