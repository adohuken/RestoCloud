<?php
require_once __DIR__ . '/config/db.php';

echo "<h2>🔧 Limpieza de Estado de Mesas</h2>";
echo "<style>body{font-family:sans-serif;padding:20px;} .success{color:green;} .info{color:blue;}</style>";

try {
    // 1. Find tables that are occupied but have no pending orders
    $stmt = $pdo->query("
        SELECT t.id, t.name, t.status,
               (SELECT COUNT(*) FROM orders WHERE table_id = t.id AND status = 'pending') as pending_orders
        FROM tables t
        WHERE t.status = 'occupied'
    ");
    $occupied_tables = $stmt->fetchAll();
    
    echo "<h3>Mesas Ocupadas:</h3>";
    if (empty($occupied_tables)) {
        echo "<p class='info'>✅ No hay mesas marcadas como ocupadas</p>";
    } else {
        echo "<ul>";
        foreach ($occupied_tables as $table) {
            echo "<li><strong>{$table['name']}</strong> - ";
            if ($table['pending_orders'] == 0) {
                echo "<span style='color:orange'>⚠️ Sin pedidos pendientes (debería estar disponible)</span>";
            } else {
                echo "<span class='info'>Tiene {$table['pending_orders']} pedido(s) pendiente(s)</span>";
            }
            echo "</li>";
        }
        echo "</ul>";
    }
    
    // 2. Free tables that have no pending orders
    $stmt = $pdo->exec("
        UPDATE tables t
        SET status = 'available'
        WHERE status = 'occupied'
        AND NOT EXISTS (
            SELECT 1 FROM orders o 
            WHERE o.table_id = t.id 
            AND o.status = 'pending'
        )
    ");
    
    echo "<h3 class='success'>✅ Limpieza Completada</h3>";
    echo "<p>Se liberaron <strong>$stmt</strong> mesa(s) que no tenían pedidos pendientes.</p>";
    
    // 3. Show current status
    $stmt = $pdo->query("
        SELECT 
            COUNT(CASE WHEN status = 'available' THEN 1 END) as available,
            COUNT(CASE WHEN status = 'occupied' THEN 1 END) as occupied,
            COUNT(CASE WHEN status = 'reserved' THEN 1 END) as reserved
        FROM tables
    ");
    $status = $stmt->fetch();
    
    echo "<h3>Estado Actual de Mesas:</h3>";
    echo "<ul>";
    echo "<li>✅ Disponibles: <strong>{$status['available']}</strong></li>";
    echo "<li>🔴 Ocupadas: <strong>{$status['occupied']}</strong></li>";
    echo "<li>📅 Reservadas: <strong>{$status['reserved']}</strong></li>";
    echo "</ul>";
    
    echo "<hr>";
    echo "<h3>Opciones Adicionales:</h3>";
    echo "<form method='POST' onsubmit='return confirm(\"¿Está seguro de liberar TODAS las mesas?\")'>";
    echo "<button type='submit' name='reset_all' style='padding:10px 20px; background:#dc2626; color:white; border:none; border-radius:5px; cursor:pointer;'>🔄 Liberar TODAS las Mesas</button>";
    echo "</form>";
    
    // Handle reset all
    if (isset($_POST['reset_all'])) {
        $pdo->exec("UPDATE tables SET status = 'available'");
        echo "<p class='success'><strong>✅ Todas las mesas han sido liberadas</strong></p>";
        echo "<script>setTimeout(function(){ location.reload(); }, 1000);</script>";
    }
    
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Error: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='dashboard.php'>← Volver al Dashboard</a></p>";
?>
