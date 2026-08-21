<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Mesas - RestoCloud Mobile</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="assets/css/mobile_mesero.css">
</head>
<body>

    <!-- Header -->
    <header class="app-header">
        <div class="app-title">Mesas</div>
        <div class="app-header-actions">
            <!-- Optional: User profile or logout -->
            <button class="icon-btn" onclick="window.location.href='salir.php'">
                <i class='bx bx-log-out'></i>
            </button>
        </div>
    </header>

    <!-- Main Content -->
    <main class="app-content">
        <?php if (!$active_register): ?>
            <div style="background: var(--app-warning); color: #fff; padding: 15px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; text-align: center;">
                ⚠️ Caja cerrada. No puedes tomar pedidos.
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error']) && $_GET['error'] === 'table_locked'): ?>
            <div style="background: var(--app-danger); color: white; padding: 15px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; text-align: center;">
                ⚠️ Acceso denegado: Esta mesa está siendo atendida por otro mesero.
            </div>
        <?php endif; ?>

        <h2 style="font-size: 1.1rem; color: var(--app-text-sec); margin-bottom: 15px;">Salón Principal</h2>
        
        <div class="table-grid mb-3">
            <?php foreach ($tables as $table):
                $status = $table['order_status'] ?? null;
                $cardClass = 'free';
                
                if ($table['order_id']) {
                    $cardClass = 'occupied'; // We can simplify status for mobile or add more colors later
                }
            ?>
            <?php 
                $is_locked_for_me = false;
                if ($table['order_id'] && $_SESSION['role_id'] == 2) {
                    if ($table['order_user_id'] != $_SESSION['user_id']) {
                        $is_locked_for_me = true;
                    }
                }
            ?>
                <!-- When clicking, go to venta.php unless locked -->
                <a <?= $is_locked_for_me ? 'href="javascript:void(0)" onclick="alert(\'Esta mesa está siendo atendida por otro mesero.\')"' : 'href="venta.php?table=' . $table['id'] . '"' ?> class="table-card <?= $cardClass ?>" <?= $is_locked_for_me ? 'style="opacity: 0.6;"' : '' ?>>
                    <div class="table-number">
                        <?= $is_locked_for_me ? "<i class='bx bx-lock'></i> " : "" ?>
                        <?= htmlspecialchars($table['name']) ?>
                    </div>
                    <div class="table-status">
                        <?php if ($table['order_id']): ?>
                            <?php 
                                $status_map = [
                                    'draft' => 'Tomando...',
                                    'pending' => 'En cocina',
                                    'preparing' => 'Preparando',
                                    'ready' => '¡Listo!',
                                    'picked_up' => 'Recogido',
                                    'delivered' => 'Servido'
                                ];
                                echo $status_map[$status] ?? 'Ocupada';
                            ?>
                            <div style="font-size: 0.75rem; margin-top: 5px; color: var(--app-text-sec); font-weight: 500;">
                                <i class='bx bxs-user-badge'></i> <?= htmlspecialchars($table['waiter_name'] ?? 'Mesero') ?>
                            </div>
                            <div style="font-size: 0.8rem; margin-top: 5px; color: var(--app-text-main); font-weight: 700;">
                                C$<?= number_format($table['order_total'], 0) ?>
                            </div>
                        <?php else: ?>
                            Libre
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </main>

    <!-- Bottom Navigation -->
    <nav class="bottom-nav">
        <a href="mesas.php" class="nav-item active">
            <i class='bx bxs-grid-alt'></i>
            <span>Mesas</span>
        </a>
        <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'cuentas')): ?>
        <a href="cuentas.php" class="nav-item">
            <i class='bx bx-receipt'></i>
            <span>Cuentas</span>
        </a>
        <?php endif; ?>
        <a href="seleccionar_dispositivo.php?change=1" class="nav-item" onclick="event.preventDefault(); document.getElementById('switchForm').submit();">
            <i class='bx bx-desktop'></i>
            <span>Ver PC</span>
        </a>
    </nav>
    <form id="switchForm" method="POST" action="seleccionar_dispositivo.php" style="display:none;">
        <input type="hidden" name="device_type" value="pc">
    </form>
</body>
</html>
