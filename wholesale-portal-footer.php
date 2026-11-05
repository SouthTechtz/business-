    </div>
</main>
<footer class="ws-footer">
    <div class="ws-container ws-footer-inner">
        <span>&copy; <?php echo date('Y'); ?> <?php echo e($ws_company); ?> &middot; Wholesale portal</span>
        <span>Prices shown are catalogue prices. Our team confirms wholesale pricing, stock and delivery.</span>
    </div>
</footer>
<div class="app-toast-stack" id="appToastStack" data-feed="wholesale-notifications-feed.php" aria-live="polite" aria-atomic="false"></div>
<script src="assets/js/notification-toasts.js?v=<?php echo file_exists('assets/js/notification-toasts.js') ? filemtime('assets/js/notification-toasts.js') : time(); ?>"></script>
</body>
</html>
