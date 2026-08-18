<?php
if (!isset($page_title)) {
    $page_title = 'RestoCloud System';
    if (isset($pdo)) {
        try {
            // Fix session data if missing
            if (isset($_SESSION['user_id'])) {
                if (empty($_SESSION['name'])) {
                    $stmt = $pdo->prepare("SELECT name, role_id FROM users WHERE id = ?");
                    $stmt->execute([$_SESSION['user_id']]);
                    if ($u = $stmt->fetch()) {
                        $_SESSION['name'] = $u['name'];
                        $_SESSION['role_id'] = $u['role_id'];
                    }
                }
                if (empty($_SESSION['role_name']) && !empty($_SESSION['role_id'])) {
                    $stmt = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
                    $stmt->execute([$_SESSION['role_id']]);
                    $_SESSION['role_name'] = $stmt->fetchColumn() ?: 'Administrador';
                }
            }

            // Check if settings table exists
            $stmt = $pdo->query("SHOW TABLES LIKE 'settings'");
            if ($stmt->rowCount() > 0) {
                $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('company_name', 'theme_effects_enabled')");
                $stmt->execute();
                $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                $name = $settings['company_name'] ?? '';
                if ($name) {
                    $page_title = $name;
                }
                $theme_effects_enabled = $settings['theme_effects_enabled'] ?? '0';
            }
        } catch (Exception $e) {
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0f172a">
    <title><?= htmlspecialchars($page_title) ?></title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="favicon.png">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Core Styles -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <link rel="stylesheet" href="assets/css/restocloud-theme.css?v=<?= filemtime(__DIR__ . '/../assets/css/restocloud-theme.css') ?>">
    <?php if (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 2): ?>
        <link rel="stylesheet" href="css/waiter-mobile.css?v=1.0">
    <?php endif; ?>
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>


</head>
<body<?php if (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 2): ?> class="waiter-mobile" <?php endif; ?>>
