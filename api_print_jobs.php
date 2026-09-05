<?php
/**
 * API de Trabajos de Impresión (Simple)
 * GET  → Devuelve tickets pendientes
 * POST → Marca un ticket como impreso
 * 
 * Autenticación: token simple en la URL
 */
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Simple token auth
$token = $_GET['token'] ?? $_POST['token'] ?? '';

// Get stored token
$stored_token = '';
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'printer_token'");
    $stmt->execute();
    $stored_token = $stmt->fetchColumn();
} catch (Exception $e) {}

// If no token exists yet, create one automatically
if (empty($stored_token)) {
    $stored_token = substr(md5(uniqid(rand(), true)), 0, 16);
    try {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('printer_token', ?) 
                       ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$stored_token, $stored_token]);
    } catch (Exception $e) {}
}

// Special endpoint: get the token (requires session)
if (isset($_GET['get_token'])) {
    session_start();
    if (isset($_SESSION['user_id'])) {
        echo json_encode(['token' => $stored_token]);
    } else {
        echo json_encode(['error' => 'Inicia sesión primero']);
    }
    exit();
}

// Validate token
if ($token !== $stored_token) {
    http_response_code(403);
    echo json_encode(['error' => 'Token inválido']);
    exit();
}

// Ensure print_jobs table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS print_jobs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT,
        table_name VARCHAR(255),
        waiter_name VARCHAR(255),
        items_json TEXT,
        status ENUM('pending', 'printed') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}

// GET: Return pending jobs
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare("SELECT * FROM print_jobs WHERE status = 'pending' ORDER BY created_at ASC");
    $stmt->execute();
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['jobs' => $jobs]);
    exit();
}

// POST: Mark job as printed
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['job_id'] ?? 0;
    if ($id) {
        $stmt = $pdo->prepare("UPDATE print_jobs SET status = 'printed' WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Falta job_id']);
    }
    exit();
}
