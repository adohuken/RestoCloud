<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/modules_helper.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Only for Mesero role. Others shouldn't be here, but just in case, verify.
if ($_SESSION['role_name'] !== 'mesero') {
    header('Location: inicio.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['device_type'])) {
    $device = $_POST['device_type'];
    if ($device === 'pc' || $device === 'mobile') {
        $_SESSION['device_type'] = $device;
        
        // Redirect to their default module (usually Mesas)
        $user_modules = getUserModules($pdo, $_SESSION['role_id'], true);
        if (!empty($user_modules)) {
            header('Location: ' . $user_modules[0]['file_path']);
        } else {
            header('Location: sin_acceso.php');
        }
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seleccionar Dispositivo - RestoCloud</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        :root {
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --bg: #0f172a;
            --surface: rgba(30, 41, 59, 0.7);
            --text-main: #f8fafc;
            --text-sec: #94a3b8;
            --glass-border: rgba(255, 255, 255, 0.1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            background: var(--bg);
            background-image: 
                radial-gradient(at 0% 0%, rgba(79, 70, 229, 0.15) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(14, 165, 233, 0.15) 0px, transparent 50%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-main);
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 900px;
            text-align: center;
            animation: fadeIn 0.8s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .header-title {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 15px;
            background: linear-gradient(to right, #fff, #94a3b8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -1px;
        }

        .header-subtitle {
            font-size: 1.1rem;
            color: var(--text-sec);
            margin-bottom: 50px;
            font-weight: 400;
        }

        .cards-wrapper {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-bottom: 40px;
        }

        .device-card {
            background: var(--surface);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            padding: 50px 30px;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .device-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(180deg, rgba(255,255,255,0.05) 0%, transparent 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .device-card:hover {
            transform: translateY(-10px);
            border-color: rgba(79, 70, 229, 0.5);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4), 
                        0 0 40px rgba(79, 70, 229, 0.2);
        }

        .device-card:hover::before {
            opacity: 1;
        }

        .icon-wrapper {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 25px;
            transition: transform 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .device-card:hover .icon-wrapper {
            transform: scale(1.1) rotate(5deg);
            background: rgba(79, 70, 229, 0.15);
            border-color: rgba(79, 70, 229, 0.3);
            color: var(--primary);
        }

        .icon-wrapper i {
            font-size: 48px;
            color: #cbd5e1;
            transition: color 0.3s ease;
        }

        .device-card:hover .icon-wrapper i {
            color: var(--primary);
        }

        .card-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 12px;
            color: #fff;
        }

        .card-desc {
            font-size: 0.95rem;
            color: var(--text-sec);
            line-height: 1.5;
        }

        /* Glowing dots effect */
        .glow-dot {
            position: absolute;
            width: 8px;
            height: 8px;
            background: var(--primary);
            border-radius: 50%;
            top: 20px;
            right: 20px;
            box-shadow: 0 0 15px var(--primary);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .device-card:hover .glow-dot {
            opacity: 1;
        }

        @media (max-width: 768px) {
            .cards-wrapper {
                grid-template-columns: 1fr;
            }
            .header-title {
                font-size: 2rem;
            }
            .device-card {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="header-title">¡Hola, <?= htmlspecialchars($_SESSION['name']) ?>!</h1>
        <p class="header-subtitle">Selecciona el dispositivo que estás usando para optimizar tu experiencia</p>

        <form id="deviceForm" method="POST" style="display: none;">
            <input type="hidden" name="device_type" id="deviceInput" value="">
        </form>

        <div class="cards-wrapper">
            <!-- PC Card -->
            <div class="device-card" onclick="selectDevice('pc')">
                <div class="glow-dot"></div>
                <div class="icon-wrapper">
                    <i class='bx bx-desktop'></i>
                </div>
                <h2 class="card-title">Monitor / PC</h2>
                <p class="card-desc">Carga la interfaz clásica con menú lateral, ideal para monitores y pantallas grandes.</p>
            </div>

            <!-- Mobile Card -->
            <div class="device-card" onclick="selectDevice('mobile')">
                <div class="glow-dot" style="background: #0ea5e9; box-shadow: 0 0 15px #0ea5e9;"></div>
                <div class="icon-wrapper">
                    <i class='bx bx-mobile-alt'></i>
                </div>
                <h2 class="card-title">Tablet / Celular</h2>
                <p class="card-desc">Carga la nueva experiencia tipo App, táctil y de respuesta rápida.</p>
            </div>
        </div>
    </div>

    <script>
        function selectDevice(type) {
            // Optional: Add some sound or haptic feedback here if desired
            document.getElementById('deviceInput').value = type;
            document.getElementById('deviceForm').submit();
        }
    </script>
</body>
</html>
