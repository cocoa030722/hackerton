    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> 관광 코스 인증 시스템. All rights reserved.</p>
    </footer>
    
    <?php if (isset($extra_js)): ?>
        <?php foreach ($extra_js as $js): ?>
            <script src="<?php echo $js; ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
