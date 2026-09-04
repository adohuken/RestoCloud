<?php
require_once __DIR__ . '/config/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Unauthorized');
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

// Evitar que el script se detenga por timeout, ya que SSE debe mantenerse vivo
set_time_limit(0);

// Cerrar sesión para que otras llamadas AJAX no se bloqueen esperando el lock de sesión
session_write_close();

while (true) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM print_jobs WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1");
        $stmt->execute();
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($job) {
            // Marcar como impreso para no reenviarlo
            $update = $pdo->prepare("UPDATE print_jobs SET status = 'printed' WHERE id = ?");
            $update->execute([$job['id']]);

            // Enviar datos por SSE
            $data = json_encode($job);
            echo "data: {$data}\n\n";
            
            // Forzar el envío del buffer al navegador
            if (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
        }
    } catch (PDOException $e) {
        // En caso de error, podríamos mandar un evento o simplemente silenciar
    }

    // Esperar 2 segundos antes de volver a consultar la BD
    sleep(2);
    
    // Enviar un comentario (heartbeat) para mantener la conexión viva en el proxy/servidor
    echo ": heartbeat\n\n";
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();

    // Romper el ciclo si el cliente se desconectó
    if (connection_aborted()) {
        break;
    }
}
