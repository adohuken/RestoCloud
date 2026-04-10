<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/modules_helper.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Check module access (will redirect if not authorized)
checkModuleAccess($pdo, $_SESSION['role_id'], 'cashier');

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'open':
                $amount = $_POST['amount'];
                $stmt = $pdo->prepare('INSERT INTO cash_register (user_id, amount, type, status) VALUES (?, ?, "open", "active")');
                $stmt->execute([$_SESSION['user_id'], $amount]);
                header('Location: caja.php?success=opened');
                exit();

            case 'close':
                // Check for occupied tables
                $stmt = $pdo->query("SELECT COUNT(*) FROM tables WHERE status = 'occupied'");
                if ($stmt->fetchColumn() > 0) {
                    header('Location: caja.php?error=occupied');
                    exit();
                }

                $register_id = $_POST['register_id'];
                $amount = $_POST['amount'];
                $expected_amount = $_POST['expected_amount'] ?? $amount;
                $difference = $amount - $expected_amount;

                // Close the register
                $stmt = $pdo->prepare('UPDATE cash_register SET status = "closed" WHERE id = ?');
                $stmt->execute([$register_id]);

                // Create close record
                $stmt = $pdo->prepare('INSERT INTO cash_register (user_id, amount, expected_amount, difference, type, status) VALUES (?, ?, ?, ?, "close", "closed")');
                $stmt->execute([$_SESSION['user_id'], $amount, $expected_amount, $difference]);

                header('Location: caja.php?success=closed');
                exit();
        }
    }
}

// Get active register
if (isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin'] == 1) {
    // Super Admin sees ANY active register
    $stmt = $pdo->query('SELECT cr.*, u.name as user_name FROM cash_register cr JOIN users u ON cr.user_id = u.id WHERE cr.status = "active" ORDER BY cr.id DESC LIMIT 1');
    $active_register = $stmt->fetch();
} else {
    // Regular users see only THEIR active register
    $stmt = $pdo->prepare('SELECT cr.*, u.name as user_name FROM cash_register cr JOIN users u ON cr.user_id = u.id WHERE cr.user_id = ? AND cr.status = "active" ORDER BY cr.id DESC LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $active_register = $stmt->fetch();
}

// Get today's sales data
$today_sales = 0;
$sales_by_table = [];
$payment_breakdown = ['cash' => 0, 'card' => 0, 'transfer' => 0];

if ($active_register) {
    // Total sales (from the moment the register was opened)
    $stmt = $pdo->prepare('
        SELECT SUM(p.amount) as total 
        FROM payments p
        WHERE p.date_created >= ?
    ');
    $stmt->execute([$active_register['date_created']]);
    $result = $stmt->fetch();
    $today_sales = $result['total'] ?? 0;

    // Sales by table
    $stmt = $pdo->prepare('
        SELECT t.name as table_name, 
               COUNT(DISTINCT o.id) as order_count,
               SUM(p.amount) as table_total
        FROM payments p
        JOIN orders o ON p.order_id = o.id
        JOIN tables t ON o.table_id = t.id
        WHERE p.date_created >= ?
        GROUP BY t.id
        ORDER BY table_total DESC
    ');
    $stmt->execute([$active_register['date_created']]);
    $sales_by_table = $stmt->fetchAll();

    // Payment method breakdown
    $stmt = $pdo->prepare('
        SELECT method, SUM(amount) as total
        FROM payments
        WHERE date_created >= ?
        GROUP BY method
    ');
    $stmt->execute([$active_register['date_created']]);
    $payments = $stmt->fetchAll();

    foreach ($payments as $payment) {
        $payment_breakdown[$payment['method']] = $payment['total'];
    }
}

// Get register history
$stmt = $pdo->prepare('SELECT cr.*, u.name as user_name FROM cash_register cr JOIN users u ON cr.user_id = u.id ORDER BY cr.date_created DESC LIMIT 20');
$stmt->execute();
$register_history = $stmt->fetchAll();

// Get user's role name
$stmt = $pdo->prepare('SELECT name FROM roles WHERE id = ?');
$stmt->execute([$_SESSION['role_id']]);
$user_role_name = $stmt->fetchColumn() ?: 'Usuario';
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="dashboard-wrapper">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <div class="fc-header" style="margin-bottom: 30px;">
            <div class="fc-header-left">
                <h1><i class='bx bx-calculator'></i> Control de Caja</h1>
                <p>Supervisión financiera y arqueos de turno</p>
            </div>
            <div class="fc-header-right no-print">
                <div class="fc-user-pill">
                    <div class="fc-user-avatar"><?= strtoupper(substr($_SESSION['name'], 0, 1)) ?></div>
                    <div class="fc-user-info">
                        <span class="name"><?= htmlspecialchars($_SESSION['name']) ?></span>
                        <span class="role"><?= htmlspecialchars($user_role_name) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 30px; margin-bottom: 30px;">
            <?php if ($active_register): ?>
                <!-- Active Register Panel -->
                <div class="fc-card" style="margin: 0; border: 1px solid var(--fc-primary); background: rgba(225, 29, 72, 0.03);">
                    <div class="fc-modal-header" style="background: var(--fc-primary); color: white;">
                        <h3><i class='bx bx-lock-open-alt'></i> Turno en Curso</h3>
                        <span style="font-size: 13px; opacity: 0.9;">Iniciado: <?= date('h:i A', strtotime($active_register['date_created'])) ?></span>
                    </div>

                    <div class="fc-modal-body">
                        <?php $is_admin = ($_SESSION['role_id'] == 1 || $_SESSION['role_id'] == 5); ?>
                        
                        <?php if($is_admin): ?>
                            <div style="text-align: center; margin-bottom: 30px; padding: 25px; background: rgba(255,255,255,0.02); border-radius: 15px; border: 1px solid var(--fc-border);">
                                <span style="color: var(--fc-text-sec); font-size: 14px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px;">Saldo Estimado Actual</span>
                                <div style="color: var(--fc-text-main); font-size: 36px; font-weight: 800; margin-top: 10px;">C$<?= number_format($active_register['amount'] + $today_sales, 2) ?></div>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 25px;">
                                <div style="padding: 15px; background: rgba(255,255,255,0.03); border-radius: 12px; border: 1px solid var(--fc-border); text-align: center;">
                                    <span style="display: block; color: var(--fc-text-sec); font-size: 12px;">Fondo Initial</span>
                                    <span style="color: var(--fc-text-main); font-weight: 700;">C$<?= number_format($active_register['amount'], 2) ?></span>
                                </div>
                                <div style="padding: 15px; background: rgba(16, 185, 129, 0.05); border-radius: 12px; border: 1px solid rgba(16, 185, 129, 0.2); text-align: center;">
                                    <span style="display: block; color: var(--fc-text-sec); font-size: 12px;">Ventas (Hoy)</span>
                                    <span style="color: #10b981; font-weight: 700;">+C$<?= number_format($today_sales, 2) ?></span>
                                </div>
                            </div>

                            <div style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 25px;">
                                <?php
                                $m_data = [
                                    ['icon' => 'bx-money', 'label' => 'Efectivo', 'val' => $payment_breakdown['cash'], 'color' => '#10b981'],
                                    ['icon' => 'bx-credit-card', 'label' => 'Tarjeta', 'val' => $payment_breakdown['card'], 'color' => '#3b82f6'],
                                    ['icon' => 'bx-transfer-alt', 'label' => 'Transfer', 'val' => $payment_breakdown['transfer'], 'color' => '#8b5cf6']
                                ];
                                foreach($m_data as $m): ?>
                                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 15px; background: rgba(255,255,255,0.02); border-radius: 10px;">
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <i class='bx <?= $m['icon'] ?>' style="font-size: 20px; color: <?= $m['color'] ?>;"></i>
                                            <span style="font-size: 14px; color: var(--fc-text-sec);"><?= $m['label'] ?></span>
                                        </div>
                                        <strong style="color: var(--fc-text-main);">C$<?= number_format($m['val'], 2) ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div style="text-align: center; padding: 40px 20px; background: rgba(255,255,255,0.02); border-radius: 15px; border: 1px solid var(--fc-border); margin-bottom: 25px;">
                                <div style="font-size: 40px; margin-bottom: 15px;"><i class='bx bx-user-circle'></i></div>
                                <h4 style="color: var(--fc-text-main); margin-bottom: 10px;">Arqueo Ciego Activado</h4>
                                <p style="color: var(--fc-text-sec); font-size: 13px; line-height: 1.5;">Como medida de seguridad, el balance del sistema está oculto. Por favor, cuente el efectivo físico real antes de proceder al cierre.</p>
                            </div>
                        <?php endif; ?>

                        <form method="POST" class="fc-form" id="closeRegisterForm" style="margin-top: 20px;">
                            <input type="hidden" name="action" value="close">
                            <input type="hidden" name="register_id" value="<?= $active_register['id'] ?>">
                            <input type="hidden" name="expected_amount" value="<?= $active_register['amount'] + $today_sales ?>">

                            <div class="fc-form-group">
                                <label class="fc-label">Monto Físico en Caja</label>
                                <div style="position: relative;">
                                    <span style="position: absolute; left: 15px; top: 13px; color: var(--fc-text-sec); font-weight: 700;">C$</span>
                                    <input type="number" step="0.01" name="amount" class="fc-input" style="padding-left: 45px; height: 52px; font-size: 18px;" placeholder="0.00" required>
                                </div>
                                <small style="color: #ef4444; font-size: 11px; display: block; margin-top: 8px;">* Ingrese el total contado en efectivo para finalizar el turno.</small>
                            </div>

                            <button type="button" class="fc-btn fc-btn-primary fc-w100" style="height: 52px; font-weight: 700;" onclick="confirmCloseRegister()">
                                <i class='bx bx-lock-alt'></i> Cerrar Caja y Finalizar Turno
                            </button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <!-- Open Register Panel -->
                <div class="fc-card" style="margin: 0;">
                    <div class="fc-modal-header" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white;">
                        <h3><i class='bx bx-store-alt'></i> Apertura de Terminal</h3>
                        <span style="font-size: 13px; opacity: 0.9;">Inicia un nuevo ciclo de ventas</span>
                    </div>
                    <div class="fc-modal-body" style="padding: 40px 30px;">
                        <form method="POST" class="fc-form">
                            <input type="hidden" name="action" value="open">
                            
                            <div class="fc-form-group">
                                <label class="fc-label">Fondo de Apertura (Caja Chica)</label>
                                <div style="position: relative;">
                                    <span style="position: absolute; left: 15px; top: 18px; color: var(--fc-text-sec); font-weight: 700; font-size: 20px;">C$</span>
                                    <input type="number" step="0.01" name="amount" class="fc-input" style="padding-left: 55px; height: 64px; font-size: 24px; font-weight: 800;" placeholder="0.00" required autofocus>
                                </div>
                                <p style="color: var(--fc-text-sec); font-size: 12px; margin-top: 15px;">Monto total de efectivo disponible para cambio al inicio del turno.</p>
                            </div>

                            <button type="submit" class="fc-btn fc-btn-primary fc-w100" style="height: 60px; font-size: 18px; font-weight: 800; background: #10b981; border-color: #10b981;">
                                <i class='bx bx-rocket'></i> Iniciar Turno de Trabajo
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- History Section -->
            <div class="fc-card" style="margin: 0;">
                <div class="fc-modal-header">
                    <h3><i class='bx bx-history'></i> Auditoría de Arqueos</h3>
                </div>
                <div class="fc-table-responsive">
                    <table class="fc-table">
                        <thead>
                            <tr>
                                <th>Fecha y Hora</th>
                                <th>Usuario</th>
                                <th style="text-align: center;">Evento</th>
                                <th style="text-align: right;">Monto</th>
                                <th style="text-align: right;">Diferencia</th>
                                <th style="text-align: center;">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($register_history as $record): ?>
                                <tr>
                                    <td>
                                        <div style="font-size: 13px; color: var(--fc-text-main);"><?= date('d/m/Y', strtotime($record['date_created'])) ?></div>
                                        <div style="font-size: 11px; color: var(--fc-text-sec);"><?= date('H:i', strtotime($record['date_created'])) ?></div>
                                    </td>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            <div class="fc-user-avatar" style="width:28px; height:28px; font-size:12px; border-radius: 8px;">
                                                <?= strtoupper(substr($record['user_name'], 0, 1)) ?>
                                            </div>
                                            <span style="font-size: 13px;"><?= htmlspecialchars($record['user_name']) ?></span>
                                        </div>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="fc-badge <?= $record['type'] === 'open' ? 'fc-badge-outline' : 'fc-badge-primary' ?>" style="font-size: 10px;">
                                            <?= $record['type'] === 'open' ? 'APERTURA' : 'CIERRE' ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right; font-weight: 700; color: var(--fc-text-main);">
                                        C$<?= number_format($record['amount'], 2) ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <?php if($record['type'] === 'close' && isset($record['difference'])): ?>
                                            <?php if($record['difference'] < 0): ?>
                                                <span style="color: #ef4444; font-weight: 700;"><i class='bx bx-down-arrow-alt'></i> C$<?= number_format(abs($record['difference']), 2) ?></span>
                                            <?php elseif($record['difference'] > 0): ?>
                                                <span style="color: #10b981; font-weight: 700;"><i class='bx bx-up-arrow-alt'></i> C$<?= number_format($record['difference'], 2) ?></span>
                                            <?php else: ?>
                                                <span style="color: #10b981; font-size: 11px;">Cuadrado <i class='bx bx-check-double'></i></span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color: var(--fc-text-sec);">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <i class='bx bxs-circle' style="font-size: 8px; color: <?= $record['status'] === 'active' ? '#10b981' : 'var(--fc-text-sec)' ?>;"></i>
                                        <span style="font-size: 11px; text-transform: uppercase; color: var(--fc-text-sec);"> <?= $record['status'] === 'active' ? 'Activa' : 'Cerrada' ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
    function confirmCloseRegister() {
        Swal.fire({
            title: '¿Confirmar Arqueo de Caja?',
            text: "Esta acción dará por finalizado su turno de trabajo y no podrá ser revertida.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e11d48',
            cancelButtonColor: 'rgba(255,255,255,0.1)',
            confirmButtonText: 'Sí, Cerrar Caja',
            cancelButtonText: 'Cancelar',
            background: '#0f172a',
            color: '#f8fafc'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('closeRegisterForm').submit();
            }
        })
    }

    // Success/Error Alerts from PHP
    <?php if (isset($_GET['success'])): ?>
        Swal.fire({
            icon: 'success',
            title: '¡Operación Exitosa!',
            text: '<?= $_GET['success'] === 'opened' ? "La caja ha sido abierta correctamente." : "Cierre de caja registrado exitosamente." ?>',
            background: '#0f172a',
            color: '#f8fafc',
            timer: 2500
        });
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        Swal.fire({
            icon: 'error',
            title: 'Error de Bloqueo',
            text: 'No es posible cerrar la caja mientras existan mesas ocupadas.',
            background: '#0f172a',
            color: '#f8fafc'
        });
    <?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>