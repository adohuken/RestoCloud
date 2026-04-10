<?php
require_once __DIR__ . '/config/db.php';
session_start();

// If not logged in, redirect to login
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Get user's role name
$stmt = $pdo->prepare('SELECT name FROM roles WHERE id = ?');
$stmt->execute([$_SESSION['role_id']]);
$user_role_name = $stmt->fetchColumn() ?: 'Usuario';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sin Acceso - Sistema Pizzería</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
        }
        
        .container {
            text-align: center;
            padding: 40px;
            max-width: 500px;
        }
        
        .icon {
            font-size: 80px;
            margin-bottom: 20px;
        }
        
        h1 {
            font-size: 28px;
            margin-bottom: 15px;
            color: #fff;
        }
        
        p {
            font-size: 16px;
            color: rgba(255,255,255,0.7);
            margin-bottom: 10px;
            line-height: 1.6;
        }
        
        .role-badge {
            display: inline-block;
            background: rgba(139, 92, 246, 0.3);
            color: #a78bfa;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            margin: 15px 0;
            border: 1px solid rgba(139, 92, 246, 0.5);
        }
        
        .actions {
            margin-top: 30px;
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 25px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(139, 92, 246, 0.3);
        }
        
        .btn-secondary {
            background: rgba(255,255,255,0.1);
            color: white;
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .btn-secondary:hover {
            background: rgba(255,255,255,0.2);
        }
        
        .note {
            margin-top: 40px;
            padding: 20px;
            background: rgba(251, 191, 36, 0.1);
            border: 1px solid rgba(251, 191, 36, 0.3);
            border-radius: 10px;
            color: #fbbf24;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🔒</div>
        <h1>Sin Módulos Asignados</h1>
        <p>Hola, <strong><?= htmlspecialchars($_SESSION['name']) ?></strong></p>
        <div class="role-badge">Rol: <?= htmlspecialchars($user_role_name) ?></div>
        <p>Tu cuenta no tiene módulos asignados todavía. Un administrador debe asignarte acceso a los módulos del sistema.</p>
        
        <div class="actions">
            <a href="salir.php" class="btn btn-primary">🚪 Cerrar Sesión</a>
        </div>
    </div>
</body>
</html>
