<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Mesas - RestoCloud Mobile</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="assets/css/mobile_mesero.css?v=<?= time() ?>">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

    <!-- Header -->
    <header class="app-header">
        <div class="app-title">Mesas</div>
        <div class="app-header-actions">
            <button class="icon-btn" onclick="window.location.href='salir.php'">
                <i class='bx bx-log-out'></i>
            </button>
        </div>
    </header>

    <!-- Main Content -->
    <main class="app-content">
        <?php if (!$active_register): ?>
            <div style="background: var(--app-warning-light); color: var(--app-warning); padding: 15px; border-radius: 16px; margin-bottom: 25px; font-weight: 700; text-align: center; border: 1px solid rgba(245, 158, 11, 0.2);">
                <i class='bx bx-lock' style="font-size: 1.2rem; vertical-align: middle;"></i> Caja Cerrada
            </div>
        <?php endif; ?>

        <div class="table-grid mb-3">
            <?php 
            $all_tables = $tables;
            if (hasModuleAccess($pdo, $_SESSION['role_id'], 'ver_asientos_barra')) {
                $all_tables = array_merge($all_tables, $barra_seats ?? []);
            }
            foreach ($all_tables as $table):
                $status = $table['order_status'] ?? null;
                $cardClass = 'free';
                
                if ($table['order_id']) {
                    $cardClass = 'occupied';
                }
            ?>
            <?php 
                $is_locked_for_me = false;
                $is_caja_cerrada = !$active_register;
                if ($table['order_id'] && $_SESSION['role_id'] == 2) {
                    if ($table['order_user_id'] != $_SESSION['user_id']) {
                        $is_locked_for_me = true;
                    }
                }
                
                // Determine styling and actions
                $card_style = '';
                $click_action = '';
                
                if ($is_caja_cerrada) {
                    $card_style = 'style="opacity: 0.6; filter: grayscale(1); cursor: not-allowed;"';
                    $click_action = 'href="javascript:void(0)" onclick="Swal.fire({html: \'<div style=&quot;margin-bottom:10px&quot;><i class=\\\'bx bx-lock-alt\\\' style=\\\'font-size: 54px; color: #ef4444; opacity: 0.9;\\\'></i></div><div style=&quot;font-size: 1.05rem; font-weight: 700; color: #0f172a; line-height: 1.4;&quot;>Operación bloqueada.<br>Debe abrir la caja para tomar pedidos.</div>\', confirmButtonColor: \'#ef4444\', confirmButtonText: \'Entendido\', customClass: { popup: \'premium-swal compact-swal\' }})"';
                } elseif ($is_locked_for_me) {
                    $card_style = 'style="opacity: 0.6; filter: grayscale(1);"';
                    $click_action = 'href="javascript:void(0)" onclick="Swal.fire({html: \'<div style=&quot;margin-bottom:10px&quot;><i class=\\\'bx bx-lock\\\' style=\\\'font-size: 54px; color: #f59e0b; opacity: 0.9;\\\'></i></div><div style=&quot;font-size: 1.05rem; font-weight: 700; color: #0f172a; line-height: 1.4;&quot;>Esta mesa está siendo atendida por otro mesero.</div>\', confirmButtonColor: \'#6366f1\', confirmButtonText: \'Entendido\', customClass: { popup: \'premium-swal compact-swal\' }})"';
                } else {
                    $click_action = 'href="venta.php?table=' . $table['id'] . '"';
                }
            ?>
                <div class="table-card <?= $cardClass ?>" <?= $card_style ?>>
                    <!-- Top clickable area to go to venta.php (unless locked/closed) -->
                    <a <?= $click_action ?> style="text-decoration: none; color: inherit; display: block;">
                        <div class="table-icon-wrapper">
                            <i class='bx <?= $is_caja_cerrada ? 'bx-lock-alt' : ($is_locked_for_me ? 'bx-lock' : 'bx-chair') ?>'></i>
                        </div>
                        <div class="table-number">
                            <?= htmlspecialchars($table['name']) ?>
                        </div>
                        <div class="table-status">
                            <?php if ($is_caja_cerrada): ?>
                                <div class="badge badge-locked mb-1" style="background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.2);">Bloqueada</div>
                            <?php elseif ($table['order_id']): ?>
                                <div class="badge badge-occupied mb-1">
                                    <?php 
                                        $status_map = [
                                            'draft' => 'Tomando...',
                                            'pending' => 'Cocina',
                                            'preparing' => 'Preparando',
                                            'ready' => '¡Listo!',
                                            'picked_up' => 'Recogido',
                                            'delivered' => 'Servido'
                                        ];
                                        echo $status_map[$status] ?? 'Ocupada';
                                    ?>
                                </div>
                                <div style="font-size: 0.75rem; margin-top: 5px; color: var(--app-text-sec); font-weight: 600;">
                                    <i class='bx bxs-user-badge'></i> <?= htmlspecialchars($table['waiter_name'] ?? 'Mesero') ?>
                                </div>
                                <div style="font-size: 0.85rem; margin-top: 5px; color: var(--app-text-main); font-weight: 800;">
                                    C$<?= number_format($table['order_total'], 0) ?>
                                </div>
                            <?php else: ?>
                                <div class="badge badge-free">Libre</div>
                            <?php endif; ?>
                        </div>
                    </a>

                    <!-- Action Buttons at the bottom of the card -->
                    <?php if (!$is_locked_for_me && $table['order_id']): ?>
                        <div style="margin-top: 15px;">
                            <?php if ($status === 'ready'): ?>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="action" value="pickup_order">
                                    <input type="hidden" name="order_id" value="<?= $table['order_id'] ?>">
                                    <button type="submit" style="width: 100%; padding: 10px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border: none; border-radius: 12px; font-weight: 700; font-size: 0.9rem; display: flex; align-items: center; justify-content: center; gap: 5px; box-shadow: 0 4px 10px rgba(16, 185, 129, 0.3);">
                                        <i class='bx bx-check-double' style="font-size: 1.2rem;"></i> SERVIR
                                    </button>
                                </form>
                            <?php elseif ($status === 'picked_up'): ?>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="action" value="deliver_order">
                                    <input type="hidden" name="order_id" value="<?= $table['order_id'] ?>">
                                    <button type="submit" style="width: 100%; padding: 10px; background: var(--app-gradient); color: white; border: none; border-radius: 12px; font-weight: 700; font-size: 0.9rem; display: flex; align-items: center; justify-content: center; gap: 5px; box-shadow: 0 4px 10px rgba(99, 102, 241, 0.3);">
                                        <i class='bx bx-check' style="font-size: 1.2rem;"></i> FINALIZAR
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
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
    <script src="assets/js/auto_refresh.js?v=<?= time() ?>"></script>
</body>
</html>
