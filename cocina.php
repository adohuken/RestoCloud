<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/modules_helper.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

checkModuleAccess($pdo, $_SESSION['role_id'], 'kitchen');

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['start_preparation'])) {
        $order_id = $_POST['order_id'];
        $pdo->prepare('UPDATE orders SET status = "preparing" WHERE id = ?')->execute([$order_id]);
        $pdo->prepare('UPDATE order_details SET item_status = "preparing" WHERE order_id = ? AND item_status = "pending"')->execute([$order_id]);
        header('Location: cocina.php');
        exit();
    }

    if (isset($_POST['complete_order'])) {
        $order_id = $_POST['order_id'];
        $pdo->prepare('UPDATE order_details SET item_status = "ready" WHERE order_id = ? AND item_status IN ("pending", "preparing")')->execute([$order_id]);
        $pdo->prepare('UPDATE orders SET status = "ready" WHERE id = ?')->execute([$order_id]);
        header('Location: cocina.php?success=ready');
        exit();
    }
}

// Fetch Active Orders
$sql = '
    SELECT o.id as order_id, o.status as order_status, o.date_created,
           t.name as table_name, u.name as waiter_name,
           od.id as detail_id, od.quantity, od.item_status, od.notes,
           p.name as product_name, p.icon as product_icon
    FROM orders o
    JOIN tables t ON o.table_id = t.id
    JOIN users u ON o.user_id = u.id
    JOIN order_details od ON o.id = od.order_id AND od.item_status IN ("pending", "preparing")
    JOIN products p ON od.product_id = p.id
    WHERE o.status IN ("pending", "preparing")
    ORDER BY FIELD(o.status, "preparing", "pending"), o.date_created ASC
';
$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pending_orders = [];
foreach ($rows as $row) {
    $oid = $row['order_id'];
    if (!isset($pending_orders[$oid])) {
        $pending_orders[$oid] = [
            'id' => $oid,
            'status' => $row['order_status'],
            'date_created' => $row['date_created'],
            'table_name' => $row['table_name'],
            'waiter_name' => $row['waiter_name'],
            'items' => []
        ];
    }
    $pending_orders[$oid]['items'][] = [
        'qty' => $row['quantity'],
        'name' => $row['product_name'],
        'icon' => $row['product_icon'],
        'notes' => $row['notes'],
        'status' => $row['item_status']
    ];
}

// Fetch History Orders (Today)
$sql_history = '
    SELECT o.id as order_id, o.status as order_status, o.date_created,
           t.name as table_name, u.name as waiter_name,
           od.quantity, p.name as product_name
    FROM orders o
    JOIN tables t ON o.table_id = t.id
    JOIN users u ON o.user_id = u.id
    JOIN order_details od ON o.id = od.order_id AND od.item_status IN ("ready", "served")
    JOIN products p ON od.product_id = p.id
    WHERE o.status IN ("ready", "picked_up", "delivered", "completed")
    AND DATE(o.date_created) = CURDATE()
    ORDER BY o.date_created DESC
';
$stmt_hist = $pdo->query($sql_history);
$rows_hist = $stmt_hist->fetchAll(PDO::FETCH_ASSOC);

$history_orders = [];
foreach ($rows_hist as $row) {
    $oid = $row['order_id'];
    if (!isset($history_orders[$oid])) {
        $history_orders[$oid] = [
            'id' => $oid,
            'table_name' => $row['table_name'],
            'waiter_name' => $row['waiter_name'],
            'time' => date('h:i A', strtotime($row['date_created'])),
            'items' => []
        ];
    }
    $history_orders[$oid]['items'][] = [
        'qty' => $row['quantity'],
        'name' => $row['product_name']
    ];
}

$user_role_name = 'Cocinero';
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="dashboard-wrapper">
    <?php if ($_SESSION['role_id'] != 4) include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content" style="<?= $_SESSION['role_id'] == 4 ? 'margin-left: 0;' : '' ?>">
        <div class="page-header">
            <div>
                <h1>👨‍🍳 KDS - Cocina</h1>
                <p>Monitor de preparación de pedidos</p>
            </div>
            
            <div class="kds-tabs">
                <button class="kds-tab active" data-target="pending" onclick="switchTab('pending')">Pendientes (<?= count($pending_orders) ?>)</button>
                <button class="kds-tab" data-target="history" onclick="switchTab('history')">Historial (<?= count($history_orders) ?>)</button>
            </div>

            <div style="display: flex; align-items: center; gap: 20px;">
                <div id="clock" style="font-size: 1.5em; font-weight: 700; color: var(--text-primary);">
                    <?= date('H:i') ?>
                </div>
            </div>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert-success">
                <i class="bx bx-check-circle"></i> Acción completada con éxito
            </div>
        <?php endif; ?>
        
        <!-- PENDING TAB -->
        <div id="tab-pending" class="tab-content active">
             <?php if (empty($pending_orders)): ?>
                <div class="empty-state">
                    <div style="font-size: 4em; margin-bottom: 20px;">✅</div>
                    <h2>Todo al día</h2>
                    <p>No hay pedidos pendientes en cocina.</p>
                </div>
            <?php else: ?>
                <div class="kitchen-grid">
                    <?php foreach ($pending_orders as $order): ?>
                        <?php
                        $isPreparing = ($order['status'] === 'preparing');
                        $startTime = strtotime($order['date_created']);
                        $elapsedMins = floor((time() - $startTime) / 60);
                        $isUrgent = $elapsedMins >= 15;
                        $isWarning = $elapsedMins >= 10 && $elapsedMins < 15;
                        ?>
                        <div class="kds-ticket <?= $isPreparing ? 'status-preparing' : 'status-pending' ?> <?= $isWarning ? 'is-warning' : '' ?> <?= $isUrgent ? 'is-urgent' : '' ?>">
                            <div class="ticket-header">
                                <div class="ticket-meta">
                                    <span class="order-id">#<?= $order['id'] ?></span>
                                    <span class="table-name"><?= htmlspecialchars($order['table_name']) ?></span>
                                    <span class="waiter-name">🤵 <?= htmlspecialchars($order['waiter_name']) ?></span>
                                    <span class="order-entry-time">🕒 <?= date('h:i A', strtotime($order['date_created'])) ?></span>
                                    <?php if($isPreparing): ?>
                                        <span class="status-badge badge-prep">Cocinando</span>
                                    <?php else: ?>
                                        <span class="status-badge badge-pend">Pendiente</span>
                                    <?php endif; ?>
                                </div>
                                <div class="urgent-container">
                                    <div class="timer-badge" data-time="<?= $startTime * 1000 ?>">
                                        ⏱ <span class="min-val"><?= $elapsedMins ?></span> min
                                    </div>
                                    <?php if ($isUrgent): ?>
                                        <div class="urgent-badge">🔥 TARDE</div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="ticket-body">
                                <ul class="item-list">
                                    <?php foreach ($order['items'] as $item): ?>
                                        <li>
                                            <div class="item-qty"><?= $item['qty'] ?></div>
                                            <div class="item-details">
                                                <div class="item-title">
                                                    <span class="item-icon"><?= $item['icon'] ?: '🍽️' ?></span>
                                                    <span class="item-name"><?= htmlspecialchars($item['name']) ?></span>
                                                    <?php if($item['status'] === 'pending' && $isPreparing): ?>
                                                        <span class="new-item-badge">NUEVO</span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if(!empty($item['notes'])): ?>
                                                    <div class="item-notes">⚠️ <?= htmlspecialchars($item['notes']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>

                            <div class="ticket-footer">
                                <form method="POST">
                                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                    <?php if ($isPreparing): ?>
                                        <input type="hidden" name="complete_order" value="1">
                                        <button type="submit" class="kds-btn btn-finish">✅ LISTO</button>
                                    <?php else: ?>
                                        <input type="hidden" name="start_preparation" value="1">
                                        <button type="submit" class="kds-btn btn-start">👨‍🍳 PREPARAR TODO</button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- HISTORY TAB -->
        <div id="tab-history" class="tab-content" style="display:none;">
            <?php if (empty($history_orders)): ?>
                <div class="empty-state">
                    <div style="font-size: 4em; margin-bottom: 20px;">📜</div>
                    <h2>Sin Historial</h2>
                    <p>No hay pedidos completados hoy.</p>
                </div>
            <?php else: ?>
                <div class="kitchen-grid">
                    <?php foreach ($history_orders as $order): ?>
                        <div class="kds-ticket status-ready">
                            <div class="ticket-header">
                                <div class="ticket-meta">
                                    <span class="order-id">#<?= $order['id'] ?></span>
                                    <span class="table-name"><?= htmlspecialchars($order['table_name']) ?></span>
                                    <span class="waiter-name">✅ Completado a las <?= $order['time'] ?></span>
                                </div>
                            </div>
                            <div class="ticket-body">
                                <ul class="item-list">
                                    <?php foreach ($order['items'] as $item): ?>
                                        <li>
                                            <div class="item-qty history-qty"><?= $item['qty'] ?></div>
                                            <div class="item-details">
                                                <span class="item-name history-name"><?= htmlspecialchars($item['name']) ?></span>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<style>
    /* Dark Premium Theme CSS */
    :root {
        --kds-bg: #0f172a; /* Slate 900 */
        --ticket-bg: #1e293b; /* Slate 800 */
        --ticket-border: rgba(255, 255, 255, 0.05);
        --text-primary: #f8fafc;
        --text-secondary: #94a3b8;
        --color-pending: #f59e0b; /* Amber */
        --color-prep: #3b82f6; /* Blue */
        --color-ready: #10b981; /* Emerald */
    }

    body {
        background-color: var(--kds-bg);
        color: var(--text-primary);
    }

    .main-content {
        padding: 30px;
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
    }

    .page-header h1 {
        margin: 0;
        font-size: 1.8rem;
        font-weight: 800;
        letter-spacing: -0.5px;
        color: var(--text-primary);
    }

    .page-header p {
        margin: 5px 0 0 0;
        color: var(--text-secondary);
        font-size: 0.95rem;
    }

    /* Tabs UI */
    .kds-tabs {
        display: flex;
        gap: 5px;
        background: rgba(255, 255, 255, 0.05);
        padding: 5px;
        border-radius: 12px;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .kds-tab {
        border: none;
        background: transparent;
        padding: 10px 20px;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        color: var(--text-secondary);
        transition: all 0.3s ease;
        font-size: 0.95rem;
    }

    .kds-tab:hover {
        color: white;
        background: rgba(255, 255, 255, 0.1);
    }

    .kds-tab.active {
        background: var(--fc-primary, #e11d48);
        color: white;
        box-shadow: 0 4px 10px rgba(225, 29, 72, 0.3);
    }

    /* Grid */
    .kitchen-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 20px;
        padding-bottom: 40px;
    }

    /* Tickets */
    .kds-ticket {
        background: var(--ticket-bg);
        border-radius: 16px;
        border: 1px solid var(--ticket-border);
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
        overflow: hidden;
        display: flex;
        flex-direction: column;
        border-top: 4px solid var(--ticket-border);
        transition: all 0.3s ease;
        animation: fadeIn 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(15px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .kds-ticket:hover {
        transform: translateY(-4px);
        box-shadow: 0 20px 30px -10px rgba(0, 0, 0, 0.4);
    }

    /* Top Border Colors */
    .status-pending { border-top-color: var(--color-pending); }
    .status-preparing { border-top-color: var(--color-prep); }
    .status-ready { border-top-color: var(--color-ready); }

    /* Header inside Ticket */
    .ticket-header {
        background: rgba(255, 255, 255, 0.02);
        padding: 15px 20px;
        border-bottom: 1px solid var(--ticket-border);
        display: flex;
        justify-content: space-between;
    }

    .ticket-meta {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .order-id {
        font-size: 1.4em;
        font-weight: 900;
        color: white;
        letter-spacing: 0.5px;
    }

    .table-name {
        font-size: 1em;
        font-weight: 600;
        color: var(--text-primary);
    }

    .waiter-name, .order-entry-time {
        font-size: 0.85em;
        color: var(--text-secondary);
    }

    /* Badges */
    .status-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 0.75em;
        font-weight: 800;
        text-transform: uppercase;
        margin-top: 5px;
        width: fit-content;
    }

    .badge-pend { background: rgba(245, 158, 11, 0.2); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
    .badge-prep { background: rgba(59, 130, 246, 0.2); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); }

    .new-item-badge {
        background: var(--fc-primary, #e11d48);
        color: white;
        font-size: 0.6em;
        padding: 2px 6px;
        border-radius: 4px;
        margin-left: 8px;
        font-weight: bold;
    }

    /* Timers & Alerts */
    .urgent-container {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 8px;
    }

    .timer-badge {
        background: rgba(255, 255, 255, 0.1);
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.9em;
        font-weight: 600;
        border: 1px solid rgba(255,255,255,0.05);
        color: white;
    }

    .is-warning .timer-badge {
        background: rgba(245, 158, 11, 0.2);
        color: #fbbf24;
        border-color: rgba(245, 158, 11, 0.5);
    }

    .is-urgent .timer-badge {
        background: rgba(239, 68, 68, 0.2);
        color: #f87171;
        border-color: rgba(239, 68, 68, 0.5);
        animation: pulseUrgent 1.5s infinite;
    }

    @keyframes pulseUrgent {
        0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
        70% { box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); }
        100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
    }

    .urgent-badge {
        background: #ef4444;
        color: white;
        font-size: 0.7em;
        font-weight: 800;
        padding: 4px 8px;
        border-radius: 6px;
        box-shadow: 0 2px 8px rgba(239, 68, 68, 0.5);
    }

    /* Items */
    .ticket-body {
        padding: 15px 20px;
        flex-grow: 1;
    }

    .item-list {
        list-style: none;
        padding: 0;
        margin: 0;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .item-list li {
        display: flex;
        gap: 12px;
        align-items: flex-start;
        padding-bottom: 12px;
        border-bottom: 1px dashed rgba(255, 255, 255, 0.05);
    }

    .item-list li:last-child {
        border-bottom: none;
        padding-bottom: 0;
    }

    .item-qty {
        background: rgba(255, 255, 255, 0.1);
        color: white;
        min-width: 32px;
        height: 32px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        font-size: 0.9em;
        flex-shrink: 0;
    }

    .item-details {
        display: flex;
        flex-direction: column;
        gap: 4px;
        width: 100%;
    }

    .item-title {
        display: flex;
        align-items: center;
    }

    .item-icon {
        margin-right: 8px;
        font-size: 1.1em;
    }

    .item-name {
        font-size: 1.05em;
        font-weight: 600;
        color: var(--text-primary);
        line-height: 1.3;
    }

    .item-notes {
        background: rgba(245, 158, 11, 0.1);
        border: 1px solid rgba(245, 158, 11, 0.2);
        color: #fbbf24;
        padding: 6px 10px;
        border-radius: 6px;
        font-size: 0.85em;
        font-weight: 600;
        margin-top: 4px;
    }

    /* History Specifics */
    .history-qty {
        background: rgba(16, 185, 129, 0.15);
        color: #34d399;
    }
    .history-name {
        color: var(--text-secondary);
    }

    /* Action Buttons */
    .ticket-footer {
        padding: 15px 20px;
        background: rgba(255, 255, 255, 0.02);
        border-top: 1px solid var(--ticket-border);
    }

    .kds-btn {
        width: 100%;
        padding: 12px;
        border-radius: 10px;
        font-weight: 800;
        font-size: 1em;
        cursor: pointer;
        border: none;
        transition: all 0.2s;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .btn-start {
        background: var(--color-prep);
        color: white;
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
    }

    .btn-start:hover {
        background: #2563eb;
        transform: translateY(-2px);
    }

    .btn-finish {
        background: var(--color-ready);
        color: white;
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    }

    .btn-finish:hover {
        background: #059669;
        transform: translateY(-2px);
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 80px 20px;
        background: var(--ticket-bg);
        border-radius: 16px;
        border: 1px dashed rgba(255, 255, 255, 0.1);
        max-width: 600px;
        margin: 40px auto;
        opacity: 0.8;
    }

    .empty-state h2 {
        margin: 0 0 10px 0;
        color: white;
        font-weight: 800;
    }

    .empty-state p {
        color: var(--text-secondary);
        margin: 0;
    }

    .alert-success {
        background: rgba(16, 185, 129, 0.1);
        border: 1px solid rgba(16, 185, 129, 0.2);
        color: #34d399;
        padding: 15px 20px;
        border-radius: 12px;
        margin-bottom: 25px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 10px;
    }
</style>

<script>
    // Tab Switcher Logic - Robusto y sin dependencias de evento global
    function switchTab(tabId) {
        // UI
        document.querySelectorAll('.kds-tab').forEach(t => t.classList.remove('active'));
        const btn = document.querySelector(`.kds-tab[data-target="${tabId}"]`);
        if (btn) btn.classList.add('active');

        // Content
        document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');
        const targetContainer = document.getElementById('tab-' + tabId);
        if (targetContainer) targetContainer.style.display = 'block';

        // Session
        sessionStorage.setItem('kds_active_tab', tabId);
    }

    // Init
    document.addEventListener('DOMContentLoaded', () => {
        const savedTab = sessionStorage.getItem('kds_active_tab');
        if (savedTab) {
            switchTab(savedTab);
        }
    });

    // Clock and SLA Tracker
    setInterval(() => {
        const now = new Date();
        document.getElementById('clock').textContent = now.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
        
        document.querySelectorAll('.timer-badge').forEach(badge => {
            const startTime = parseInt(badge.getAttribute('data-time'));
            if (startTime) {
                const elapsedMins = Math.floor((now.getTime() - startTime) / 60000);
                badge.querySelector('.min-val').textContent = elapsedMins;
                
                const ticket = badge.closest('.kds-ticket');
                if (elapsedMins >= 15 && !ticket.classList.contains('is-urgent')) {
                    ticket.classList.remove('is-warning');
                    ticket.classList.add('is-urgent');
                    let urgentBadge = ticket.querySelector('.urgent-badge');
                    if (!urgentBadge) {
                        const container = badge.closest('.urgent-container');
                        container.insertAdjacentHTML('beforeend', '<div class="urgent-badge">🔥 TARDE</div>');
                    }
                } else if (elapsedMins >= 10 && elapsedMins < 15 && !ticket.classList.contains('is-urgent') && !ticket.classList.contains('is-warning')) {
                    ticket.classList.add('is-warning');
                }
            }
        });
    }, 1000);

    // Auto-refresh KDS every 15 seconds to fetch new incoming orders
    setInterval(() => {
        location.reload();
    }, 15000);
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>