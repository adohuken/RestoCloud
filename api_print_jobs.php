<?php
/**
 * API de Trabajos de Impresión
 * GET  → Devuelve los tickets pendientes (JSON)
 * POST → Marca un ticket como impreso
 */
require_once __DIR__ . '/config/db.php';
session_start();

// Allow API key auth for the Python script
$api_key = $_GET['key'] ?? $_POST['key'] ?? '';
$valid_key = false;

try {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'printer_api_key'");
    $stmt->execute();
    $stored_key = $stmt->fetchColumn();
    if ($stored_key && $api_key === $stored_key) {
        $valid_key = true;
    }
} catch (Exception $e) {}

// Auth: either session or API key
if (!isset($_SESSION['user_id']) && !$valid_key) {
    http_response_code(403);
    echo json_encode(['error' => 'No autorizado']);
    exit();
}

header('Content-Type: application/json');

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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Return pending print jobs
    $stmt = $pdo->prepare("SELECT * FROM print_jobs WHERE status = 'pending' ORDER BY created_at ASC");
    $stmt->execute();
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['jobs' => $jobs]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'mark_printed') {
        $id = $_POST['job_id'] ?? 0;
        $stmt = $pdo->prepare("UPDATE print_jobs SET status = 'printed' WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        exit();
    }

    if ($action === 'generate_key') {
        // Generate a new API key for the printer client
        $new_key = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('printer_api_key', ?) 
                               ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$new_key, $new_key]);
        echo json_encode(['success' => true, 'key' => $new_key]);
        exit();
    }
}

echo json_encode(['error' => 'Acción no válida']);
