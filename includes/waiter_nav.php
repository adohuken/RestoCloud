<?php
// Bottom navigation bar for waiter mobile interface
// Only shown when user is a waiter (role_id = 2)
if (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 2):
    $current_page = basename($_SERVER['PHP_SELF']);
?>
<nav class="waiter-bottom-nav">
    <a href="mesas.php" class="<?= $current_page == 'mesas.php' ? 'active' : '' ?>">
        <span class="icon">🪑</span>
        Mesas
    </a>
    <a href="salir.php" class="logout-link">
        <span class="icon">🚪</span>
        Salir
    </a>
</nav>
<?php endif; ?>
