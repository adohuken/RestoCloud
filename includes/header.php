<?php
if (!isset($page_title)) {
    $page_title = 'FoodCorp System';
    if (isset($pdo)) {
        try {
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
    <link rel="stylesheet" href="assets/css/style.css?v=1.4">
    <link rel="stylesheet" href="assets/css/foodcorp-theme.css?v=2.4">
    <?php if (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 2): ?>
        <link rel="stylesheet" href="css/waiter-mobile.css?v=1.0">
    <?php endif; ?>
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <?php if (isset($theme_effects_enabled) && $theme_effects_enabled === '1'): ?>
        <!-- GLOBAL GOTHIC THEME EFFECTS (Spiders & Webs) -->
        <style>
            /* Spiderwebs */
            .gothic-web {
                position: fixed;
                top: 0;
                width: 150px;
                height: 150px;
                pointer-events: none;
                z-index: 9999;
                opacity: 0.15;
                background-size: contain;
                background-repeat: no-repeat;
                background-image: url('data:image/svg+xml;utf8,<svg fill="%23ffffff" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg"><path d="M0 0v21h12.5c4.7 0 9.2 1.9 12.6 5.3l154.6 154.6-56.1 27.6C79 178.5 21 139 0 139v22.4c17.6 0 71.5 35.8 115.6 71.4l-57.7 75C28.5 284 10.3 268.4 0 268.4v22.8c12.2 0 35.1 19.8 69.8 45.1l-43 93.3C12.5 411.3 4 397.6 0 397.6V420c5.1 0 16 16.5 33.7 39.5l-20.4 44.2C5.9 501.1 2 497.6 0 497.6V512h17.1c3.1 0 7.4-4.8 15.6-13l213.6-213.6v96L145 482.7c-9.7 9.8-13 14.5-13 17.5V512h21v-12.5c0-4.7 1.9-9.2 5.3-12.6L246 399v113h20V399l87.7 87.8c3.4 3.4 8 5.3 12.6 5.3H388v-11.8c0-3.1-3.3-7.7-13-17.5L273.7 381.5v-96l213.6 213.6c8.2 8.2 12.5 13 15.6 13H512v-14.4c-2 0-5.9 3.5-13.3-13.9L478.4 459c17.6-23 28.5-39.5 33.6-39.5v-22.4c-4 0-12.5 13.7-26.8 32l-43-93.3c34.7-25.2 57.6-45.1 69.8-45.1v-22.8c-10.3 0-28.5 15.6-57.9 39.4l-57.7-75c44.1-35.6 98-71.4 115.6-71.4V139c-21 0-79 39.5-123.6 69.5l-56.1-27.6L487 26.2c3.4-3.4 8-5.3 12.6-5.3H512V0h-17.1c-3.1 0-7.4 4.8-15.6 13L265.7 226.6v-96.1L367.1 29.3c9.7-9.8 13-14.5 13-17.5V0h-21v12.5c0 4.7-1.9 9.2-5.3 12.6L266 113.1V0h-20v113L158.2 25.1C154.8 21.7 150.3 19.8 145.6 19.8H124v11.8c0 3.1 3.3 7.7 13 17.5l109.3 101.2v96.1L32.7 13C24.5 4.8 20.2 0 17.1 0H0z"/></svg>');
            }

            .gothic-web.left {
                left: 0;
            }

            .gothic-web.right {
                right: 0;
                transform: scaleX(-1);
            }

            /* Animated Spiders */
            .gothic-spider {
                position: fixed;
                top: -100px;
                /* Start hidden above screen */
                width: 2px;
                background-color: rgba(255, 255, 255, 0.2);
                /* The silk string */
                z-index: 9998;
                pointer-events: none;
            }

            .gothic-spider::after {
                content: '🕷️';
                position: absolute;
                bottom: -20px;
                left: -13px;
                font-size: 24px;
                filter: drop-shadow(0px 5px 2px rgba(0, 0, 0, 0.5));
            }

            /* Animations for different spiders */
            .spider-1 {
                left: 10%;
                height: 30vh;
                animation: dropSpider 15s infinite ease-in-out;
                animation-delay: 2s;
            }

            .spider-2 {
                left: 50%;
                height: 15vh;
                animation: dropSpider 22s infinite ease-in-out;
                animation-delay: 10s;
            }

            .spider-3 {
                right: 15%;
                height: 40vh;
                animation: dropSpider 18s infinite ease-in-out;
                animation-delay: 5s;
            }

            @keyframes dropSpider {
                0% {
                    transform: translateY(-100%);
                }

                10% {
                    transform: translateY(0);
                }

                /* Drop down */
                40% {
                    transform: translateY(0);
                }

                /* Hang there */
                42% {
                    transform: translateY(-5%);
                }

                /* Twitch */
                44% {
                    transform: translateY(0);
                }

                50% {
                    transform: translateY(-100%);
                }

                /* Climb back up */
                100% {
                    transform: translateY(-100%);
                }
            }
        </style>

        <!-- Webs DOM Elements -->
        <div class="gothic-web left"></div>
        <div class="gothic-web right"></div>
        <!-- Spiders DOM Elements -->
        <div class="gothic-spider spider-1"></div>
        <div class="gothic-spider spider-2"></div>
        <div class="gothic-spider spider-3"></div>
    <?php endif; ?>
</head>
<body<?php if (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 2): ?> class="waiter-mobile" <?php endif; ?>>