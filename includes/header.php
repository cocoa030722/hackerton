<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? '관광 코스 인증 시스템'; ?></title>
    <link rel="stylesheet" href="<?php echo ($base_url ?? '') ? $base_url . '/' : ''; ?>assets/css/common.css">
    <?php if (isset($extra_css)): ?>
        <?php foreach ($extra_css as $css): ?>
            <link rel="stylesheet" href="<?php echo $css; ?>">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body>
    <nav class="navbar">
        <div class="navbar-content">
            <a href="<?php echo ($base_url ?? '') ? $base_url . '/' : ''; ?>index.php" class="logo">🎯 관광 코스 인증</a>
            
            <div class="nav-links">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <?php if ($_SESSION['user_type'] === 'admin'): ?>
                        <a href="<?php echo ($base_url ?? '') ? $base_url . '/' : ''; ?>admin/dashboard.php">대시보드</a>
                        <a href="<?php echo ($base_url ?? '') ? $base_url . '/' : ''; ?>admin/attractions.php">관광지 관리</a>
                        <a href="<?php echo ($base_url ?? '') ? $base_url . '/' : ''; ?>admin/courses_list.php">코스 목록</a>
                        <a href="<?php echo ($base_url ?? '') ? $base_url . '/' : ''; ?>admin/courses_register.php">코스 등록</a>
                    <?php elseif ($_SESSION['user_type'] === 'tourist'): ?>
                        <a href="<?php echo ($base_url ?? '') ? $base_url . '/' : ''; ?>tourist/dashboard.php">대시보드</a>
                        <a href="<?php echo ($base_url ?? '') ? $base_url . '/' : ''; ?>tourist/select_course.php">코스 선택</a>
                        <a href="<?php echo ($base_url ?? '') ? $base_url . '/' : ''; ?>tourist/my_courses.php">내 코스</a>
                        <a href="<?php echo ($base_url ?? '') ? $base_url . '/' : ''; ?>tourist/my_page.php">마이페이지</a>
                    <?php elseif ($_SESSION['user_type'] === 'attraction_manager'): ?>
                        <a href="<?php echo ($base_url ?? '') ? $base_url . '/' : ''; ?>attraction/dashboard.php">대시보드</a>
                        <a href="<?php echo ($base_url ?? '') ? $base_url . '/' : ''; ?>attraction/my_attractions.php">내 관광지</a>
                        <a href="<?php echo ($base_url ?? '') ? $base_url . '/' : ''; ?>attraction/issue_qr.php">QR 코드</a>
                        <a href="<?php echo ($base_url ?? '') ? $base_url . '/' : ''; ?>attraction/issue_text_code.php">문자열 코드</a>
                    <?php endif; ?>
                    
                    <div class="user-info">
                        <span><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?></span>
                        <span class="user-badge">
                            <?php 
                                echo $_SESSION['user_type'] === 'admin' ? '관리자' : 
                                     ($_SESSION['user_type'] === 'tourist' ? '관광객' : '관광지 책임자');
                            ?>
                        </span>
                        <a href="<?php echo ($base_url ?? '') ? $base_url . '/' : ''; ?>logout.php" class="btn btn-sm btn-secondary">로그아웃</a>
                    </div>
                <?php else: ?>
                    <a href="<?php echo ($base_url ?? '') ? $base_url . '/' : ''; ?>login.php" class="btn btn-sm btn-outline">로그인</a>
                    <a href="<?php echo ($base_url ?? '') ? $base_url . '/' : ''; ?>register.php" class="btn btn-sm btn-primary">회원가입</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>
