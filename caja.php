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
                    <div class="fc-user-avatar"><?= strtoupper(substr($_SESSION['name'] ?? 'U', 0, 1)) ?></div>
                    <div class="fc-user-info">
                        <span class="name"><?= htmlspecialchars($_SESSION['name'] ?? 'Usuario') ?></span>
                        <span class="role"><?= htmlspecialchars($user_role_name) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div style="display: flex; flex-direction: column; gap: 30px; margin-bottom: 30px;">
            <?php if ($active_register): ?>
                <!-- Active Register Panel -->
                <div class="fc-card" style="margin: 0; border: none; background: #ffffff; box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.05); border-radius: 16px; overflow: hidden;">
                    <div style="padding: 20px 30px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; background: #fff;">
                        <h3 style="margin: 0; font-size: 18px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                            <i class='bx bx-lock-open-alt' style="color: #6366f1; background: rgba(99, 102, 241, 0.1); padding: 6px; border-radius: 8px;"></i> 
                            Turno en Curso
                        </h3>
                        <span style="font-size: 12px; font-weight: 600; color: #64748b; background: #f8fafc; padding: 4px 10px; border-radius: 15px;">
                            <i class='bx bx-time-five' style="margin-right: 4px;"></i> Iniciado: <?= date('h:i A', strtotime($active_register['date_created'])) ?>
                        </span>
                    </div>

                    <div class="fc-modal-body" style="padding: 25px;">
                        <div style="display: grid; grid-template-columns: 1fr 1.5fr; gap: 30px; align-items: start;">
                            
                            <!-- Columna Izquierda: Resumen -->
                            <div class="resumen-turno">
                                <h4 style="margin-bottom: 15px; font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 1px;">Resumen Financiero</h4>
                                <?php $is_admin = ($_SESSION['role_id'] == 1 || $_SESSION['role_id'] == 5); ?>
                                
                                <?php if($is_admin): ?>
                                    <div style="margin-bottom: 20px;">
                                        <span style="color: #94a3b8; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px;">Saldo Estimado Actual</span>
                                        <div style="color: #0f172a; font-size: 32px; font-weight: 900; margin-top: 2px; letter-spacing: -1px;">C$<?= number_format($active_register['amount'] + $today_sales, 2) ?></div>
                                    </div>

                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid #f1f5f9; padding-bottom: 20px;">
                                        <div style="padding: 12px; background: #f8fafc; border-radius: 12px; border: 1px solid #f1f5f9;">
                                            <span style="display: block; color: #64748b; font-size: 11px; font-weight: 600;">Fondo Inicial</span>
                                            <span style="color: #334155; font-weight: 800; font-size: 15px; margin-top: 4px; display: block;">C$<?= number_format($active_register['amount'], 2) ?></span>
                                        </div>
                                        <div style="padding: 12px; background: rgba(16, 185, 129, 0.05); border-radius: 12px; border: 1px solid rgba(16, 185, 129, 0.1);">
                                            <span style="display: block; color: #10b981; font-size: 11px; font-weight: 600;">Ventas (Hoy)</span>
                                            <span style="color: #10b981; font-weight: 800; font-size: 15px; margin-top: 4px; display: block;">+C$<?= number_format($today_sales, 2) ?></span>
                                        </div>
                                    </div>

                                    <div style="display: flex; flex-direction: column; gap: 8px;">
                                        <span style="font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px;">Desglose de Ingresos</span>
                                        <?php
                                        $m_data = [
                                            ['icon' => 'bx-money', 'label' => 'Efectivo Físico', 'val' => $payment_breakdown['cash'], 'color' => '#10b981', 'bg' => 'rgba(16, 185, 129, 0.1)'],
                                            ['icon' => 'bx-credit-card', 'label' => 'Tarjetas (POS)', 'val' => $payment_breakdown['card'], 'color' => '#3b82f6', 'bg' => 'rgba(59, 130, 246, 0.1)'],
                                            ['icon' => 'bx-transfer-alt', 'label' => 'Transferencias', 'val' => $payment_breakdown['transfer'], 'color' => '#8b5cf6', 'bg' => 'rgba(139, 92, 246, 0.1)']
                                        ];
                                        foreach($m_data as $m): ?>
                                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 0;">
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <div style="width: 28px; height: 28px; border-radius: 8px; background: <?= $m['bg'] ?>; color: <?= $m['color'] ?>; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                                                        <i class='bx <?= $m['icon'] ?>'></i>
                                                    </div>
                                                    <span style="font-size: 13px; font-weight: 600; color: #475569;"><?= $m['label'] ?></span>
                                                </div>
                                                <strong style="color: #0f172a; font-size: 14px; font-weight: 700;">C$<?= number_format($m['val'], 2) ?></strong>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div style="text-align: center; padding: 40px 15px;">
                                        <div style="font-size: 36px; margin-bottom: 15px; color: #cbd5e1;"><i class='bx bx-low-vision'></i></div>
                                        <h4 style="color: #334155; margin-bottom: 8px; font-size: 16px;">Arqueo Ciego Activado</h4>
                                        <p style="color: #64748b; font-size: 13px; line-height: 1.5;">El balance del sistema está oculto por motivos de seguridad.</p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Columna Derecha: Formulario de Arqueo -->
                            <div class="formulario-arqueo" style="background: #f8fafc; padding: 25px; border-radius: 16px; border: 1px solid #e2e8f0;">

                        <form method="POST" id="closeRegisterForm">
                            <input type="hidden" name="action" value="close">
                            <input type="hidden" name="register_id" value="<?= $active_register['id'] ?>">
                            <input type="hidden" id="sysExpectedCash" name="expected_amount" value="<?= $active_register['amount'] + $payment_breakdown['cash'] ?>">
                            <input type="hidden" id="sysExpectedCard" value="<?= $payment_breakdown['card'] ?>">
                            <input type="hidden" id="sysExpectedTransfer" value="<?= $payment_breakdown['transfer'] ?>">

                                <style>
                                    /* Hide native number spinners for a cleaner look */
                                    input[type=number].denom-input::-webkit-inner-spin-button, 
                                    input[type=number].denom-input::-webkit-outer-spin-button,
                                    input[type=number].electronic-input::-webkit-inner-spin-button, 
                                    input[type=number].electronic-input::-webkit-outer-spin-button { 
                                        -webkit-appearance: none; 
                                        margin: 0; 
                                    }
                                    input[type=number].denom-input, input[type=number].electronic-input {
                                        -moz-appearance: textfield; 
                                    }
                                </style>

                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                                    <i class='bx bx-money-withdraw' style="font-size: 16px; color: #10b981;"></i>
                                    <h4 style="font-size: 12px; font-weight: 700; color: #475569; margin: 0; text-transform: uppercase; letter-spacing: 1px;">Billetes</h4>
                                </div>
                                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(90px, 1fr)); gap: 10px; margin-bottom: 25px;">
                                    <?php
                                    $billetes = [1000, 500, 200, 100, 50, 20, 10];
                                    foreach($billetes as $b): ?>
                                    <div style="background: #fff; border-radius: 10px; padding: 10px 8px; display: flex; flex-direction: column; align-items: center; gap: 8px; border: 1px solid #e2e8f0; transition: all 0.2s;" onfocusin="this.style.borderColor='#10b981'; this.style.boxShadow='0 0 0 2px rgba(16,185,129,0.1)'" onfocusout="this.style.borderColor='#e2e8f0'; this.style.boxShadow='none'">
                                        <span style="font-size: 11px; font-weight: 800; color: #64748b;">C$ <?= $b ?></span>
                                        <input type="number" min="0" class="denom-input" data-val="<?= $b ?>" style="width: 100%; text-align: center; font-size: 16px; font-weight: 700; background: #f8fafc; border: 1px solid #f1f5f9; border-radius: 6px; padding: 6px 0; outline: none; color: #0f172a; transition: background 0.2s;" onfocus="this.style.background='#fff'" onblur="this.style.background='#f8fafc'" placeholder="0">
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                                    <i class='bx bx-coin-stack' style="font-size: 16px; color: #f59e0b;"></i>
                                    <h4 style="font-size: 12px; font-weight: 700; color: #475569; margin: 0; text-transform: uppercase; letter-spacing: 1px;">Monedas</h4>
                                </div>
                                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(90px, 1fr)); gap: 10px; margin-bottom: 25px;">
                                    <?php
                                    $monedas = [5, 1, 0.50, 0.25];
                                    foreach($monedas as $m): ?>
                                    <div style="background: #fff; border-radius: 10px; padding: 10px 8px; display: flex; flex-direction: column; align-items: center; gap: 8px; border: 1px solid #e2e8f0; transition: all 0.2s;" onfocusin="this.style.borderColor='#f59e0b'; this.style.boxShadow='0 0 0 2px rgba(245,158,11,0.1)'" onfocusout="this.style.borderColor='#e2e8f0'; this.style.boxShadow='none'">
                                        <span style="font-size: 11px; font-weight: 800; color: #64748b;">C$ <?= number_format($m, 2) ?></span>
                                        <input type="number" min="0" class="denom-input" data-val="<?= $m ?>" style="width: 100%; text-align: center; font-size: 16px; font-weight: 700; background: #f8fafc; border: 1px solid #f1f5f9; border-radius: 6px; padding: 6px 0; outline: none; color: #0f172a; transition: background 0.2s;" onfocus="this.style.background='#fff'" onblur="this.style.background='#f8fafc'" placeholder="0">
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                                    <i class='bx bx-credit-card-front' style="font-size: 16px; color: #3b82f6;"></i>
                                    <h4 style="font-size: 12px; font-weight: 700; color: #475569; margin: 0; text-transform: uppercase; letter-spacing: 1px;">Arqueo Electrónico</h4>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                    <div style="background: #fff; border-radius: 12px; padding: 12px; border: 1px solid #e2e8f0; transition: all 0.2s;" onfocusin="this.style.borderColor='#3b82f6'; this.style.boxShadow='0 0 0 2px rgba(59,130,246,0.1)'" onfocusout="this.style.borderColor='#e2e8f0'; this.style.boxShadow='none'">
                                        <label style="display: block; font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 8px;">Lote POS (Tarjeta)</label>
                                        <div style="position: relative;">
                                            <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-weight: 800; font-size: 14px; pointer-events: none;">C$</span>
                                            <input type="number" step="0.01" min="0" id="declCardAmount" class="electronic-input" style="width: 100%; padding: 8px 12px 8px 40px; font-size: 18px; font-weight: 800; background: #f8fafc; border: 1px solid #f1f5f9; border-radius: 8px; outline: none; color: #0f172a; transition: background 0.2s;" onfocus="this.style.background='#fff'" onblur="this.style.background='#f8fafc'" placeholder="0.00">
                                        </div>
                                    </div>
                                    <div style="background: #fff; border-radius: 12px; padding: 12px; border: 1px solid #e2e8f0; transition: all 0.2s;" onfocusin="this.style.borderColor='#8b5cf6'; this.style.boxShadow='0 0 0 2px rgba(139,92,246,0.1)'" onfocusout="this.style.borderColor='#e2e8f0'; this.style.boxShadow='none'">
                                        <label style="display: block; font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 8px;">Transferencias (Banco)</label>
                                        <div style="position: relative;">
                                            <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-weight: 800; font-size: 14px; pointer-events: none;">C$</span>
                                            <input type="number" step="0.01" min="0" id="declTransferAmount" class="electronic-input" style="width: 100%; padding: 8px 12px 8px 40px; font-size: 18px; font-weight: 800; background: #f8fafc; border: 1px solid #f1f5f9; border-radius: 8px; outline: none; color: #0f172a; transition: background 0.2s;" onfocus="this.style.background='#fff'" onblur="this.style.background='#f8fafc'" placeholder="0.00">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div style="margin-top: 25px; padding-top: 20px; border-top: 1px dashed #cbd5e1;">
                                <label style="display: block; font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px; text-align: center;">Total Físico Calculado</label>
                                <div style="position: relative; max-width: 250px; margin: 0 auto;">
                                    <span style="position: absolute; left: 15px; top: 10px; color: #94a3b8; font-weight: 900; font-size: 18px;">C$</span>
                                    <input type="text" id="calcTotalAmountDisplay" style="width: 100%; padding: 10px 15px 10px 50px; font-size: 24px; font-weight: 900; color: #0f172a; text-align: center; background: #fff; border: 2px solid #e2e8f0; border-radius: 12px; outline: none; pointer-events: none;" value="0.00" readonly>
                                    <input type="hidden" name="amount" id="calcTotalAmount" value="0.00">
                                </div>
                                <div id="arqueoStatus" style="font-size: 14px; font-weight: 700; margin-top: 20px; padding: 15px; border-radius: 12px; display: none; text-align: center;"></div>
                            </div>

                            <button type="button" id="btnCerrarCaja" style="width: 100%; margin-top: 25px; background: #0f172a; color: white; border: none; padding: 18px; border-radius: 16px; font-size: 16px; font-weight: 700; display: flex; justify-content: center; align-items: center; gap: 10px; cursor: not-allowed; opacity: 0.3; transition: all 0.3s ease;" disabled onclick="confirmCloseRegister()">
                                <i class='bx bx-lock-alt' style="font-size: 20px;"></i> Cerrar Caja Definitivamente
                            </button>
                        </form>
                            </div> <!-- Cierre formulario-arqueo -->
                        </div> <!-- Cierre del grid principal 2 columnas -->
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
    document.addEventListener('DOMContentLoaded', function() {
        const denomInputs = document.querySelectorAll('.denom-input');
        const elecInputs = document.querySelectorAll('.electronic-input');
        
        const calcTotalHidden = document.getElementById('calcTotalAmount');
        const calcTotalDisplay = document.getElementById('calcTotalAmountDisplay');
        
        const expectedCashInput = document.getElementById('sysExpectedCash');
        const expectedCardInput = document.getElementById('sysExpectedCard');
        const expectedTransferInput = document.getElementById('sysExpectedTransfer');
        
        const declCardInput = document.getElementById('declCardAmount');
        const declTransferInput = document.getElementById('declTransferAmount');
        
        const btnCerrar = document.getElementById('btnCerrarCaja');
        const statusDiv = document.getElementById('arqueoStatus');
        
        if (denomInputs.length > 0 && expectedCashInput) {
            const expectedCash = parseFloat(expectedCashInput.value) || 0;
            const expectedCard = parseFloat(expectedCardInput.value) || 0;
            const expectedTransfer = parseFloat(expectedTransferInput.value) || 0;
            
            // Add listeners to all inputs
            denomInputs.forEach(input => input.addEventListener('input', validateArqueo));
            elecInputs.forEach(input => input.addEventListener('input', validateArqueo));
            
            function validateArqueo() {
                // 1. Calculate Physical Cash
                let totalCash = 0;
                denomInputs.forEach(input => {
                    const count = parseInt(input.value) || 0;
                    const val = parseFloat(input.getAttribute('data-val'));
                    totalCash += count * val;
                });
                
                const formattedTotal = totalCash.toFixed(2);
                if(calcTotalHidden) calcTotalHidden.value = formattedTotal;
                if(calcTotalDisplay) calcTotalDisplay.value = formattedTotal;
                
                // 2. Get Electronic Declarations
                const declaredCard = parseFloat(declCardInput.value) || 0;
                const declaredTransfer = parseFloat(declTransferInput.value) || 0;
                
                // 3. Calculate Differences
                const diffCash = totalCash - expectedCash;
                const diffCard = declaredCard - expectedCard;
                const diffTransfer = declaredTransfer - expectedTransfer;
                
                statusDiv.style.display = 'block';
                
                // If everything matches perfectly
                if (Math.abs(diffCash) < 0.01 && Math.abs(diffCard) < 0.01 && Math.abs(diffTransfer) < 0.01) {
                    statusDiv.innerHTML = '<i class="bx bx-check-circle"></i> Arqueo Cuadrado Perfecto (Efectivo y Electrónico)';
                    statusDiv.style.backgroundColor = 'rgba(16, 185, 129, 0.1)';
                    statusDiv.style.color = '#10b981';
                    
                    btnCerrar.disabled = false;
                    btnCerrar.style.opacity = '1';
                    btnCerrar.style.cursor = 'pointer';
                } else {
                    // Build error messages
                    let errors = [];
                    if (Math.abs(diffCash) >= 0.01) {
                        errors.push(`Efectivo: ${diffCash > 0 ? '+' : ''}${diffCash.toFixed(2)}`);
                    }
                    if (Math.abs(diffCard) >= 0.01) {
                        errors.push(`Tarjeta: ${diffCard > 0 ? '+' : ''}${diffCard.toFixed(2)}`);
                    }
                    if (Math.abs(diffTransfer) >= 0.01) {
                        errors.push(`Transfer: ${diffTransfer > 0 ? '+' : ''}${diffTransfer.toFixed(2)}`);
                    }
                    
                    statusDiv.innerHTML = `<i class="bx bx-error"></i> Descuadre en: ${errors.join(' | ')}`;
                    statusDiv.style.backgroundColor = 'rgba(239, 68, 68, 0.1)';
                    statusDiv.style.color = '#ef4444';
                    
                    btnCerrar.disabled = true;
                    btnCerrar.style.opacity = '0.5';
                    btnCerrar.style.cursor = 'not-allowed';
                }
            }
            
            // Initial call
            validateArqueo();
        }
    });

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