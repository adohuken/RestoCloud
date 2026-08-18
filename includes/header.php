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

    <!-- Global Premium Redesign Styles (Injected to bypass cache) -->
    <style>
        /* Force the beautiful blue-lilac gradient background on the whole system */
        body, .dashboard-wrapper, .fc-main-content, .main-content {
            background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 50%, #f3e8ff 100%) !important;
            background-attachment: fixed !important;
        }

        /* Redesign all cards globally to be crisp white premium cards instead of milky glass */
        .glass-card, .fc-card, .dashboard-card, .kpi-card, .pos-order-item { 
            background: #ffffff !important; 
            border: 1px solid #e2e8f0 !important; 
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05), 0 4px 6px rgba(0, 0, 0, 0.02) !important; 
            border-radius: 20px !important; 
            transition: transform 0.3s ease, box-shadow 0.3s ease !important; 
        }
        
        /* Aggressive Text Contrast Fixes for all cards globally */
        .glass-card *, .fc-card *, .dashboard-card *, .kpi-card * {
            color: #000000 !important;
            text-shadow: none !important;
        }
        
        .glass-card .fc-text-sec, .fc-card .fc-text-sec, .kpi-card .fc-text-sec,
        .glass-card p[style*="fc-text-sec"], .fc-card p[style*="fc-text-sec"],
        .glass-card span[style*="fc-text-sec"], .fc-card span[style*="fc-text-sec"] {
            color: #1e293b !important;
            font-weight: 600 !important;
        }
        
        /* Modern Inputs globally */
        .fc-input, .form-control, input[type="text"], input[type="password"], input[type="number"], select { 
            background: #f8fafc !important; 
            border: 2px solid #cbd5e1 !important; 
            color: #000000 !important; 
            font-weight: 700 !important; 
            border-radius: 10px !important;
        }
        .fc-input:focus, .form-control:focus, input:focus, select:focus { 
            border-color: #4f46e5 !important; 
            background: #ffffff !important; 
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.15) !important; 
        }
        
        /* Tables globally inside cards */
        .glass-card table, .fc-card table { border-collapse: separate !important; border-spacing: 0 !important; }
        .glass-card th, .fc-card th { background: #f1f5f9 !important; color: #000000 !important; font-weight: 800 !important; border-bottom: 2px solid #cbd5e1 !important; padding: 12px !important; }
        .glass-card td, .fc-card td { border-bottom: 1px solid #e2e8f0 !important; font-weight: 600 !important; padding: 12px !important; }
        
        /* Sidebar redesign for high contrast against gradient */
        .sidebar { background: #ffffff !important; border-right: 1px solid #e2e8f0 !important; box-shadow: 2px 0 15px rgba(0,0,0,0.03) !important; }
        .sidebar .nav-link { color: #1e293b !important; font-weight: 600 !important; }
        .sidebar .nav-link:hover { background: #e0e7ff !important; color: #4f46e5 !important; }
        .sidebar .nav-link.active { background: #4f46e5 !important; color: #ffffff !important; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3) !important; }
        
        /* Push 'Cerrar Sesión' to the absolute bottom of the sidebar */
        .sidebar-menu { display: flex !important; flex-direction: column !important; flex: 1 !important; padding-bottom: 0 !important; }
        .sidebar-menu li:last-child { margin-top: auto !important; margin-bottom: 0 !important; padding-bottom: 20px !important; }
        .sidebar-menu .logout-link { color: #ef4444 !important; font-weight: 700 !important; border: 1px solid rgba(239, 68, 68, 0.2) !important; }
        .sidebar-menu .logout-link:hover { background: rgba(239, 68, 68, 0.08) !important; color: #dc2626 !important; border-color: rgba(239, 68, 68, 0.4) !important; transform: translateY(-2px) !important; }
    </style>

</head>
<body<?php if (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 2): ?> class="waiter-mobile" <?php endif; ?>>
