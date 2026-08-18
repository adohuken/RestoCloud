<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/modules_helper.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Check module access (will redirect if not authorized)
checkModuleAccess($pdo, $_SESSION['role_id'], 'reports');

// Get report type and date range
$report_type = $_GET['type'] ?? 'sales';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Logic based on report type
if ($report_type === 'sales') {
    // Sales report logic (Existing)
    $stmt = $pdo->prepare('
        SELECT DATE(date_created) as date, COUNT(*) as orders, SUM(total) as total
        FROM orders
        WHERE DATE(date_created) BETWEEN ? AND ? AND status = "completed"
        GROUP BY DATE(date_created)
        ORDER BY date DESC
    ');
    $stmt->execute([$start_date, $end_date]);
    $sales_by_date = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT SUM(total) as total FROM orders WHERE DATE(date_created) BETWEEN ? AND ? AND status = "completed"');
    $stmt->execute([$start_date, $end_date]);
    $total_sales = $stmt->fetch()['total'] ?? 0;

    $stmt = $pdo->prepare('
        SELECT p.name, SUM(od.quantity) as quantity, SUM(od.quantity * od.price) as total
        FROM order_details od
        JOIN products p ON od.product_id = p.id
        JOIN orders o ON od.order_id = o.id
        WHERE DATE(o.date_created) BETWEEN ? AND ? AND o.status = "completed"
        GROUP BY p.id
        ORDER BY total DESC
        LIMIT 10
    ');
    $stmt->execute([$start_date, $end_date]);
    $top_products = $stmt->fetchAll();

    $stmt = $pdo->prepare('
        SELECT method, COUNT(*) as count, SUM(amount) as total
        FROM payments p
        JOIN orders o ON p.order_id = o.id
        WHERE DATE(p.date_created) BETWEEN ? AND ?
        GROUP BY method
    ');
    $stmt->execute([$start_date, $end_date]);
    $payment_methods = $stmt->fetchAll();

    // Get all invoices for detailed view
    $stmt = $pdo->prepare('
        SELECT i.*, t.name as table_name,
               (SELECT GROUP_CONCAT(CONCAT(od.quantity, "x ", p.name) SEPARATOR ", ")
                FROM order_details od
                JOIN products p ON od.product_id = p.id
                WHERE od.order_id = i.order_id) as items
        FROM invoices i
        LEFT JOIN orders o ON i.order_id = o.id
        LEFT JOIN tables t ON o.table_id = t.id
        WHERE DATE(i.date_created) BETWEEN ? AND ?
        ORDER BY i.date_created DESC
    ');
    $stmt->execute([$start_date, $end_date]);
    $all_invoices = $stmt->fetchAll();

} elseif ($report_type === 'inventory') {
    // Inventory report logic (New)
    $stmt = $pdo->query('
        SELECT p.*, c.name as category_name, (p.stock * p.price) as total_value
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.status = "active"
        ORDER BY p.stock ASC
    ');
    $inventory = $stmt->fetchAll();

    $total_inventory_value = 0;
    $total_items = 0;
    foreach ($inventory as $item) {
        $total_inventory_value += $item['total_value'];
        $total_items += $item['stock'];
    }

} elseif ($report_type === 'waiters') {
    // Waiters report logic (New)
    // Fetch all waiters for the dropdown
    $waiters_list = $pdo->query('SELECT id, name FROM users WHERE role_id = 2 ORDER BY name')->fetchAll();

    $waiter_id = $_GET['waiter_id'] ?? 'all';

    $sql = '
        SELECT u.name, 
               COUNT(o.id) as total_orders, 
               COALESCE(SUM(o.total), 0) as total_sales
        FROM users u
        LEFT JOIN orders o ON u.id = o.user_id 
            AND o.status = "completed" 
            AND DATE(o.date_created) BETWEEN ? AND ?
        WHERE u.role_id = 2
    ';

    $params = [$start_date, $end_date];

    if ($waiter_id !== 'all' && is_numeric($waiter_id)) {
        $sql .= ' AND u.id = ?';
        $params[] = $waiter_id;
    }

    $sql .= ' GROUP BY u.id ORDER BY total_sales DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $waiters_stats = $stmt->fetchAll();

} elseif ($report_type === 'pedidosya') {
    // PedidosYa report logic
    try {
        // Check if table exists
        $pdo->query("SELECT 1 FROM pedidosya_orders LIMIT 1");

        // Total sales
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(total), 0) as total FROM pedidosya_orders WHERE DATE(date_created) BETWEEN ? AND ?');
        $stmt->execute([$start_date, $end_date]);
        $pedidosya_total = $stmt->fetch()['total'];

        // Total orders count
        $stmt = $pdo->prepare('SELECT COUNT(*) as count FROM pedidosya_orders WHERE DATE(date_created) BETWEEN ? AND ?');
        $stmt->execute([$start_date, $end_date]);
        $pedidosya_count = $stmt->fetch()['count'];

        // Orders by date
        $stmt = $pdo->prepare('
            SELECT DATE(date_created) as date, COUNT(*) as orders, SUM(total) as total
            FROM pedidosya_orders
            WHERE DATE(date_created) BETWEEN ? AND ?
            GROUP BY DATE(date_created)
            ORDER BY date DESC
        ');
        $stmt->execute([$start_date, $end_date]);
        $pedidosya_by_date = $stmt->fetchAll();

        // All orders with details
        $stmt = $pdo->prepare('
            SELECT po.*, u.name as created_by_name,
                   (SELECT GROUP_CONCAT(CONCAT(pod.quantity, "x ", pod.product_name) SEPARATOR ", ")
                    FROM pedidosya_order_details pod WHERE pod.pedidosya_order_id = po.id) as items
            FROM pedidosya_orders po
            JOIN users u ON po.created_by = u.id
            WHERE DATE(po.date_created) BETWEEN ? AND ?
            ORDER BY po.date_created DESC
        ');
        $stmt->execute([$start_date, $end_date]);
        $pedidosya_orders = $stmt->fetchAll();

        // Top products from PedidosYa
        $stmt = $pdo->prepare('
            SELECT pod.product_name, SUM(pod.quantity) as quantity, SUM(pod.quantity * pod.price) as total
            FROM pedidosya_order_details pod
            JOIN pedidosya_orders po ON pod.pedidosya_order_id = po.id
            WHERE DATE(po.date_created) BETWEEN ? AND ?
            GROUP BY pod.product_id
            ORDER BY total DESC
            LIMIT 10
        ');
        $stmt->execute([$start_date, $end_date]);
        $pedidosya_top_products = $stmt->fetchAll();

        $pedidosya_table_exists = true;
    } catch (Exception $e) {
        $pedidosya_table_exists = false;
    }

} elseif ($report_type === 'deleted') {
    // Deleted Invoices Logic
    $stmt = $pdo->prepare('
        SELECT d.*, u.name as deleted_by_name
        FROM deleted_invoices_log d
        LEFT JOIN users u ON d.deleted_by = u.id
        WHERE DATE(d.deleted_at) BETWEEN ? AND ?
        ORDER BY d.deleted_at DESC
    ');
    $stmt->execute([$start_date, $end_date]);
    $deleted_invoices = $stmt->fetchAll();
}

?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="dashboard-wrapper">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <div class="fc-header" style="margin-bottom: 30px;">
            <div class="fc-header-left">
                <h1><i class='bx bx-line-chart'></i> Analítica y Reportes</h1>
                <p>Estadísticas detalladas y rendimiento del negocio</p>
            </div>
            <div class="fc-header-right no-print">
                <div class="fc-user-pill">
                    <div class="fc-user-avatar"><?= strtoupper(substr($_SESSION['name'] ?? 'U', 0, 1)) ?></div>
                    <div class="fc-user-info">
                        <span class="name"><?= htmlspecialchars($_SESSION['name'] ?? 'Usuario') ?></span>
                        <span class="role"><?= htmlspecialchars($_SESSION['role_name'] ?? 'Administrador') ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Print Header (Visible only on print) -->
        <div class="print-header">
            <div style="text-align: center; margin-bottom: 20px;">
                <h1 style="font-size: 24px; margin: 0;">🍕 Sistema Pizzería</h1>
                <p style="margin: 5px 0; font-size: 14px;">Reporte de <?= ucfirst($report_type) ?></p>
                <p style="margin: 5px 0; font-size: 12px; color: #666;">Generado el: <?= date('d/m/Y H:i') ?></p>
                <?php if ($report_type !== 'inventory'): ?>
                    <p style="margin: 5px 0; font-size: 12px;">Período: <?= date('d/m/Y', strtotime($start_date)) ?> -
                        <?= date('d/m/Y', strtotime($end_date)) ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Report Navigation Tabs -->
        <div class="fc-tabs no-print" style="margin-bottom: 25px; overflow-x: auto; padding-bottom: 10px;">
            <a href="?type=sales&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>"
                class="fc-tab <?= $report_type === 'sales' ? 'active' : '' ?>">
                <i class='bx bx-trending-up'></i> <span>Ventas</span>
            </a>
            <a href="?type=inventory" class="fc-tab <?= $report_type === 'inventory' ? 'active' : '' ?>">
                <i class='bx bx-package'></i> <span>Inventario</span>
            </a>
            <a href="?type=waiters&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>"
                class="fc-tab <?= $report_type === 'waiters' ? 'active' : '' ?>">
                <i class='bx bx-group'></i> <span>Meseros</span>
            </a>
            <a href="?type=pedidosya&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>"
                class="fc-tab <?= $report_type === 'pedidosya' ? 'active' : '' ?>">
                <i class='bx bxs-truck'></i> <span>PedidosYa</span>
            </a>
            <a href="?type=deleted&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" 
               class="fc-tab <?= $report_type === 'deleted' ? 'active' : '' ?>">
                <i class='bx bx-trash'></i> <span>Eliminaciones</span>
            </a>
        </div>

        <!-- Date Filter Card -->
        <?php if ($report_type !== 'inventory'): ?>
            <div class="fc-card no-print" style="margin-bottom: 30px;">
                <div class="fc-modal-header">
                    <h3><i class='bx bx-filter-alt'></i> Parámetros de Consulta</h3>
                </div>
                <div class="fc-modal-body">
                    <form method="GET" class="fc-form" style="display: flex; gap: 20px; align-items: flex-end; flex-wrap: wrap;">
                        <input type="hidden" name="type" value="<?= $report_type ?>">
                        
                        <div class="fc-form-group" style="flex: 1; min-width: 180px; margin-bottom: 0;">
                            <label class="fc-label">Desde</label>
                            <input type="date" name="start_date" class="fc-input" value="<?= $start_date ?>" style="height: 48px;">
                        </div>
                        
                        <div class="fc-form-group" style="flex: 1; min-width: 180px; margin-bottom: 0;">
                            <label class="fc-label">Hasta</label>
                            <input type="date" name="end_date" class="fc-input" value="<?= $end_date ?>" style="height: 48px;">
                        </div>

                        <?php if ($report_type === 'waiters' && isset($waiters_list)): ?>
                            <div class="fc-form-group" style="flex: 1; min-width: 180px; margin-bottom: 0;">
                                <label class="fc-label">Colaborador</label>
                                <select name="waiter_id" class="fc-input" style="height: 48px;">
                                    <option value="all">Todos los meseros</option>
                                    <?php foreach ($waiters_list as $waiter): ?>
                                        <option value="<?= $waiter['id'] ?>" <?= (isset($_GET['waiter_id']) && $_GET['waiter_id'] == $waiter['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($waiter['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div style="display: flex; gap: 10px;">
                            <button type="submit" class="fc-btn fc-btn-primary" style="height: 48px; padding: 0 25px;">
                                <i class='bx bx-search-alt'></i> <span>Filtrar</span>
                            </button>
                            <button type="button" onclick="window.print()" class="fc-btn fc-btn-outline" style="height: 48px; padding: 0 20px;">
                                <i class='bx bx-printer'></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- SALES REPORT -->
        <?php if ($report_type === 'sales'): ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 30px;">
                <div class="fc-card" style="margin: 0; padding: 25px; background: linear-gradient(135deg, rgba(225, 29, 72, 0.1) 0%, rgba(225, 29, 72, 0.05) 100%);">
                    <div style="display: flex; align-items: center; gap: 20px;">
                        <div style="width: 60px; height: 60px; background: var(--fc-primary); border-radius: 18px; display: flex; align-items: center; justify-content: center; font-size: 28px; color: white; box-shadow: 0 8px 16px rgba(225, 29, 72, 0.3);">
                            <i class='bx bx-money'></i>
                        </div>
                        <div>
                            <span style="color: var(--fc-text-sec); font-size: 14px; font-weight: 600;">Ingresos Totales</span>
                            <h2 style="color: var(--fc-text-main); font-size: 28px; margin: 4px 0 0 0;">C$<?= number_format($total_sales, 2) ?></h2>
                        </div>
                    </div>
                </div>

                <div class="fc-card" style="margin: 0; padding: 25px; background: linear-gradient(135deg, rgba(255,255,255,0.05) 0%, rgba(255,255,255,0.02) 100%);">
                    <div style="display: flex; align-items: center; gap: 20px;">
                        <div style="width: 60px; height: 60px; background: #6366f1; border-radius: 18px; display: flex; align-items: center; justify-content: center; font-size: 28px; color: white; box-shadow: 0 8px 16px rgba(99, 102, 241, 0.2);">
                            <i class='bx bxs-star'></i>
                        </div>
                        <div>
                            <span style="color: var(--fc-text-sec); font-size: 14px; font-weight: 600;">Rendimiento Productos</span>
                            <h2 style="color: var(--fc-text-main); font-size: 28px; margin: 4px 0 0 0;"><?= count($top_products) ?> <span style="font-size: 14px; font-weight: 400;">Destacados</span></h2>
                        </div>
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 25px; margin-bottom: 30px;">
                <div class="fc-card" style="margin: 0;">
                    <div class="fc-modal-header">
                        <h3><i class='bx bx-calendar'></i> Ventas por Fecha</h3>
                    </div>
                    <div class="fc-table-responsive">
                        <table class="fc-table">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th style="text-align: center;">Pedidos</th>
                                    <th style="text-align: right;">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sales_by_date as $sale): ?>
                                    <tr>
                                        <td><?= date('d/m/Y', strtotime($sale['date'])) ?></td>
                                        <td style="text-align: center;"><span class="fc-badge fc-badge-outline"><?= $sale['orders'] ?></span></td>
                                        <td style="text-align: right; font-weight: 700; color: var(--fc-text-main);">C$<?= number_format($sale['total'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="fc-card" style="margin: 0;">
                    <div class="fc-modal-header">
                        <h3><i class='bx bx-credit-card-front'></i> Métodos de Pago</h3>
                    </div>
                    <div class="fc-modal-body">
                        <?php
                        $pay_icons = ['cash' => ['bx-money', '#10b981', 'Efectivo'], 'card' => ['bx-credit-card', '#3b82f6', 'Tarjeta'], 'transfer' => ['bx-transfer-alt', '#8b5cf6', 'Transferencia']];
                        foreach ($payment_methods as $method):
                            $pi = $pay_icons[$method['method']] ?? ['bx-help-circle', 'var(--fc-text-sec)', $method['method']];
                        ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 15px; background: rgba(255,255,255,0.02); border-radius: 12px; margin-bottom: 10px; border: 1px solid var(--fc-border);">
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <i class='bx <?= $pi[0] ?>' style="font-size: 22px; color: <?= $pi[1] ?>;"></i>
                                    <div>
                                        <strong style="color: var(--fc-text-main);"><?= $pi[2] ?></strong>
                                        <div style="color: var(--fc-text-sec); font-size: 12px;"><?= $method['count'] ?> transacciones</div>
                                    </div>
                                </div>
                                <strong style="color: <?= $pi[1] ?>;">C$<?= number_format($method['total'], 2) ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Detailed Invoices -->
            <div class="fc-card" style="margin-top: 25px;">
                <div class="fc-modal-header">
                    <h3><i class='bx bx-list-ul'></i> Diario de Facturación Detallado</h3>
                </div>
                <div class="fc-table-responsive">
                    <table class="fc-table">
                        <thead>
                            <tr>
                                <th># Folio</th>
                                <th>Fecha y Hora</th>
                                <th>Ubicación</th>
                                <th>Detalle de Productos</th>
                                <th>Método</th>
                                <th>Monto Total</th>
                                <th class="no-print" style="text-align: center;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($all_invoices)): ?>
                                <tr>
                                    <td colspan="7" style="text-align:center; padding: 40px; color: var(--fc-text-sec);">No se encontraron registros en el rango seleccionado</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($all_invoices as $invoice): ?>
                                    <tr>
                                        <td>
                                            <span class="fc-badge fc-badge-primary" style="font-family: monospace; letter-spacing: 1px;">
                                                #<?= str_pad($invoice['id'], 6, '0', STR_PAD_LEFT) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="font-size: 13px; color: var(--fc-text-main);"><?= date('d/m/Y', strtotime($invoice['date_created'])) ?></div>
                                            <div style="font-size: 11px; color: var(--fc-text-sec);"><?= date('H:i', strtotime($invoice['date_created'])) ?></div>
                                        </td>
                                        <td>
                                            <span style="display: flex; align-items: center; gap: 5px;">
                                                <i class='bx bx-table'></i> <?= htmlspecialchars($invoice['table_name'] ?? 'Barra/N/A') ?>
                                            </span>
                                        </td>
                                        <td style="max-width: 300px;">
                                            <div style="font-size: 12px; color: var(--fc-text-sec); line-height: 1.4;">
                                                <?= htmlspecialchars($invoice['items'] ?? 'Sin detalle') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php
                                            $m_icons = ['cash' => 'bx-money', 'card' => 'bx-credit-card', 'transfer' => 'bx-transfer-alt', 'mixed' => 'bx-shuffle'];
                                            $m_names = ['cash' => 'Efectivo', 'card' => 'Tarjeta', 'transfer' => 'Transfer', 'mixed' => 'Mixto'];
                                            $icon = $m_icons[$invoice['payment_method']] ?? 'bx-help-circle';
                                            $name = $m_names[$invoice['payment_method']] ?? $invoice['payment_method'];
                                            ?>
                                            <span style="display: flex; align-items: center; gap: 6px; font-size: 13px;">
                                                <i class='bx <?= $icon ?>'></i> <?= $name ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong style="color: #10b981;">C$<?= number_format($invoice['total'], 2) ?></strong>
                                        </td>
                                        <td class="no-print" style="text-align: center;">
                                            <a href="factura.php?invoice_id=<?= $invoice['id'] ?>&from=reports" 
                                               class="fc-btn fc-btn-outline" style="padding: 6px 12px; font-size: 11px;">
                                                <i class='bx bx-file-find'></i> Detalle
                                            </a>
                                        </td>
                                    </tr>
                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- INVENTORY REPORT -->
        <?php if ($report_type === 'inventory'): ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 30px;">
                <div class="fc-card" style="margin:0; padding: 20px; background: rgba(255,255,255,0.03);">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div style="width: 50px; height: 50px; background: #f59e0b; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: white;">
                            <i class='bx bx-calculator'></i>
                        </div>
                        <div>
                            <span style="color: var(--fc-text-sec); font-size: 13px;">Valorización Total</span>
                            <div style="color: var(--fc-text-main); font-size: 20px; font-weight: 700;">C$<?= number_format($total_inventory_value, 2) ?></div>
                        </div>
                    </div>
                </div>
                <div class="fc-card" style="margin:0; padding: 20px; background: rgba(255,255,255,0.03);">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div style="width: 50px; height: 50px; background: #3b82f6; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: white;">
                            <i class='bx bx-layer'></i>
                        </div>
                        <div>
                            <span style="color: var(--fc-text-sec); font-size: 13px;">Unidades en Stock</span>
                            <div style="color: var(--fc-text-main); font-size: 20px; font-weight: 700;"><?= $total_items ?> <span style="font-size: 12px; font-weight: 400;">items</span></div>
                        </div>
                    </div>
                </div>
                <div class="fc-card" style="margin:0; padding: 20px; background: rgba(255,255,255,0.03);">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div style="width: 50px; height: 50px; background: #10b981; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: white;">
                            <i class='bx bx-purchase-tag-alt'></i>
                        </div>
                        <div>
                            <span style="color: var(--fc-text-sec); font-size: 13px;">SKUs Registrados</span>
                            <div style="color: var(--fc-text-main); font-size: 20px; font-weight: 700;"><?= count($inventory) ?> <span style="font-size: 12px; font-weight: 400;">referencias</span></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="fc-card">
                <div class="fc-modal-header" style="justify-content: space-between;">
                    <h3><i class='bx bx-store'></i> Estado de Existencias</h3>
                    <button onclick="window.print()" class="fc-btn fc-btn-outline" style="padding: 8px 15px; font-size: 12px;">
                       <i class='bx bx-printer'></i> Exportar PDF
                    </button>
                </div>
                <div class="fc-table-responsive">
                    <table class="fc-table">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Categoría</th>
                                <th style="text-align: right;">Costo Unit.</th>
                                <th style="text-align: center;">Disponibilidad</th>
                                <th style="text-align: right;">Valuación</th>
                                <th style="text-align: center;">Indicador</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inventory as $item): ?>
                                <tr>
                                    <td><strong style="color: var(--fc-text-main);"><?= htmlspecialchars($item['name']) ?></strong></td>
                                    <td><span class="fc-badge fc-badge-outline" style="font-size: 10px;"><?= htmlspecialchars($item['category_name'] ?? 'General') ?></span></td>
                                    <td style="text-align: right;">C$<?= number_format($item['price'], 2) ?></td>
                                    <td style="text-align: center;">
                                        <?php if ($item['stock'] <= 0): ?>
                                            <span class="fc-badge fc-badge-primary" style="background-color: #ef4444; border-color: #ef4444;">Agotado</span>
                                        <?php elseif ($item['stock'] < 10): ?>
                                            <span class="fc-badge" style="background-color: rgba(245, 158, 11, 0.2); color: #f59e0b; border: 1px solid #f59e0b;"><?= $item['stock'] ?> (Bajo)</span>
                                        <?php else: ?>
                                            <span class="fc-badge" style="background-color: rgba(16, 185, 129, 0.2); color: #10b981; border: 1px solid #10b981;"><?= $item['stock'] ?> (OK)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: right; font-weight: 600;">C$<?= number_format($item['total_value'], 2) ?></td>
                                    <td style="text-align: center;">
                                        <?php if ($item['stock'] <= 0): ?>
                                            <i class='bx bxs-error-circle' style="color: #ef4444; font-size: 18px;"></i>
                                        <?php elseif ($item['stock'] < 10): ?>
                                            <i class='bx bxs-info-circle' style="color: #f59e0b; font-size: 18px;"></i>
                                        <?php else: ?>
                                            <i class='bx bxs-check-circle' style="color: #10b981; font-size: 18px;"></i>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- WAITERS REPORT -->
        <?php if ($report_type === 'waiters'): ?>
            <div class="fc-card">
                <div class="fc-modal-header">
                    <h3><i class='bx bx-medal'></i> Desempeño del Equipo de Servicio</h3>
                </div>
                <div class="fc-table-responsive">
                    <table class="fc-table">
                        <thead>
                            <tr>
                                <th>Colaborador</th>
                                <th style="text-align: center;">Pedidos Concluidos</th>
                                <th style="text-align: right;">Volumen de Venta</th>
                                <th style="text-align: right;">Ticket Promedio</th>
                                <th style="text-align: center;">Productividad</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($waiters_stats)): ?>
                                <tr>
                                    <td colspan="5" style="text-align:center; padding: 40px; color: var(--fc-text-sec);">Sin métricas registradas en este periodo</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($waiters_stats as $waiter): ?>
                                    <?php $avg = $waiter['total_orders'] > 0 ? $waiter['total_sales'] / $waiter['total_orders'] : 0; ?>
                                    <tr>
                                        <td>
                                            <div style="display:flex; align-items:center; gap:12px;">
                                                <div class="fc-user-avatar" style="width:36px; height:36px; font-size:15px; border-radius: 10px;">
                                                    <?= strtoupper(substr($waiter['name'], 0, 1)) ?>
                                                </div>
                                                <div>
                                                    <div style="color: var(--fc-text-main); font-weight: 600;"><?= htmlspecialchars($waiter['name']) ?></div>
                                                    <div style="font-size: 11px; color: var(--fc-text-sec);">Fuerza de Ventas</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="text-align: center;">
                                            <span class="fc-badge fc-badge-outline"><?= $waiter['total_orders'] ?> pedidos</span>
                                        </td>
                                        <td style="text-align: right; color: var(--fc-text-main); font-weight: 700;">C$<?= number_format($waiter['total_sales'], 2) ?></td>
                                        <td style="text-align: right; color: #10b981; font-weight: 600;">C$<?= number_format($avg, 2) ?></td>
                                        <td style="text-align: center;">
                                            <div style="width: 100px; height: 8px; background: rgba(255,255,255,0.05); border-radius: 4px; margin: 0 auto; overflow: hidden;">
                                                <div style="width: <?= min(100, ($avg/500)*100) ?>%; height: 100%; background: var(--fc-primary);"></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>           </div>        <!-- PEDIDOSYA REPORT -->
        <?php if ($report_type === 'pedidosya'): ?>
            <?php if (!isset($pedidosya_table_exists) || !$pedidosya_table_exists): ?>
                <div class="fc-badge fc-badge-outline" style="width: 100%; justify-content: center; padding: 30px; border-style: dashed;">
                    <i class='bx bx-error-circle' style="font-size: 20px;"></i>
                    <span style="margin-left: 10px;">El módulo de PedidosYa no está configurado. <a href="setup_pedidosya.php" style="color: var(--fc-primary); text-decoration: underline;">Configurar enlace</a></span>
                </div>
            <?php else: ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 30px;">
                    <div class="fc-card" style="margin: 0; padding: 25px; border-left: 5px solid #ff4757; background: rgba(255, 71, 87, 0.05);">
                        <div style="display: flex; align-items: center; gap: 20px;">
                            <div style="width: 60px; height: 60px; background: #ff4757; border-radius: 18px; display: flex; align-items: center; justify-content: center; font-size: 28px; color: white;">
                                <i class='bx bxs-truck'></i>
                            </div>
                            <div>
                                <span style="color: var(--fc-text-sec); font-size: 14px;">Ventas Delivery</span>
                                <h2 style="color: var(--fc-text-main); font-size: 28px; margin: 4px 0 0 0;">C$<?= number_format($pedidosya_total, 2) ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="fc-card" style="margin: 0; padding: 25px;">
                        <div style="display: flex; align-items: center; gap: 20px;">
                            <div style="width: 60px; height: 60px; background: var(--fc-primary); border-radius: 18px; display: flex; align-items: center; justify-content: center; font-size: 28px; color: white;">
                                <i class='bx bx-shopping-bag'></i>
                            </div>
                            <div>
                                <span style="color: var(--fc-text-sec); font-size: 14px;">Total Órdenes</span>
                                <h2 style="color: var(--fc-text-main); font-size: 28px; margin: 4px 0 0 0;"><?= $pedidosya_count ?></h2>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 25px; margin-bottom: 30px;">
                    <div class="fc-card" style="margin: 0;">
                        <div class="fc-modal-header">
                            <h3><i class='bx bx-calendar'></i> Histórico Delivery</h3>
                        </div>
                        <div class="fc-table-responsive">
                            <table class="fc-table">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th style="text-align: center;">Pedidos</th>
                                        <th style="text-align: right;">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($pedidosya_by_date)): ?>
                                        <tr><td colspan="3" style="text-align:center; padding:30px; color: var(--fc-text-sec);">No hay registros</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($pedidosya_by_date as $day): ?>
                                            <tr>
                                                <td><?= date('d/m/Y', strtotime($day['date'])) ?></td>
                                                <td style="text-align: center;"><span class="fc-badge fc-badge-outline"><?= $day['orders'] ?></span></td>
                                                <td style="text-align: right; font-weight: 700; color: #ff4757;">C$<?= number_format($day['total'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="fc-card" style="margin: 0;">
                        <div class="fc-modal-header">
                            <h3><i class='bx bx-trending-up'></i> Top Delivery</h3>
                        </div>
                        <div class="fc-table-responsive">
                            <table class="fc-table">
                                <thead>
                                    <tr>
                                        <th>Producto</th>
                                        <th style="text-align: center;">Cant.</th>
                                        <th style="text-align: right;">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($pedidosya_top_products)): ?>
                                        <tr><td colspan="3" style="text-align:center; padding:30px; color: var(--fc-text-sec);">No hay registros</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($pedidosya_top_products as $prod): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($prod['product_name']) ?></td>
                                                <td style="text-align: center;"><?= $prod['quantity'] ?></td>
                                                <td style="text-align: right; font-weight: 600;">C$<?= number_format($prod['total'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="fc-card">
                    <div class="fc-modal-header">
                        <h3><i class='bx bx-list-check'></i> Auditoría de Órdenes Delivery</h3>
                    </div>
                    <div class="fc-table-responsive">
                        <table class="fc-table">
                            <thead>
                                <tr>
                                    <th>ID Externo</th>
                                    <th>Fecha/Hora</th>
                                    <th>Información Cliente</th>
                                    <th>Items</th>
                                    <th>Recaudación</th>
                                    <th class="no-print">Ref.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($pedidosya_orders)): ?>
                                    <tr><td colspan="6" style="text-align:center; padding:40px; color: var(--fc-text-sec);">Sin pedidos PedidosYa</td></tr>
                                <?php else: ?>
                                    <?php foreach ($pedidosya_orders as $order): ?>
                                        <tr>
                                            <td><span class="fc-badge" style="background: #ff4757; color: white; border: none; font-family: monospace;"><?= htmlspecialchars($order['external_order_id']) ?></span></td>
                                            <td>
                                                <div style="font-size: 13px; color: var(--fc-text-main);"><?= date('d/m/Y', strtotime($order['date_created'])) ?></div>
                                                <div style="font-size: 11px; color: var(--fc-text-sec);"><?= date('H:i', strtotime($order['date_created'])) ?></div>
                                            </td>
                                            <td>
                                                <?php if ($order['customer_name']): ?>
                                                    <div style="font-weight: 600; font-size: 13px;"><?= htmlspecialchars($order['customer_name']) ?></div>
                                                    <?php if ($order['customer_phone']): ?>
                                                        <div style="font-size: 11px; color: var(--fc-text-sec);"><i class='bx bx-phone'></i> <?= htmlspecialchars($order['customer_phone']) ?></div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span style="color: var(--fc-text-sec);">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="max-width: 250px; font-size: 12px; color: var(--fc-text-sec);"><?= htmlspecialchars($order['items'] ?? '-') ?></td>
                                            <td><strong style="color: #ff4757;">C$<?= number_format($order['total'], 2) ?></strong></td>
                                            <td class="no-print">
                                                <a href="factura_pedidosya.php?id=<?= $order['id'] ?>" class="fc-btn fc-btn-outline" style="padding: 5px 10px; font-size: 11px;">Ver</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- DELETED INVOICES REPORT -->
        <?php if ($report_type === 'deleted'): ?>
            <div class="fc-card">
                <div class="fc-modal-header">
                    <h3><i class='bx bx-history'></i> Auditoría de Eliminaciones Técnicas</h3>
                </div>
                <div class="fc-table-responsive">
                    <table class="fc-table">
                        <thead>
                            <tr>
                                <th>Factura/Pedido</th>
                                <th style="text-align: right;">Monto Anulado</th>
                                <th>Fecha Anulación</th>
                                <th>Responsable</th>
                                <th>Motivación del Ajuste</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($deleted_invoices)): ?>
                                <tr><td colspan="5" style="text-align:center; padding:40px; color:var(--fc-text-sec);">Registro de auditoría limpio</td></tr>
                            <?php else: ?>
                                <?php foreach ($deleted_invoices as $del): ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 700; color: var(--fc-text-main);">#<?= $del['original_invoice_id'] ?></div>
                                            <div style="font-size: 11px; color: var(--fc-text-sec);">Orden #<?= $del['order_id'] ?></div>
                                        </td>
                                        <td style="text-align: right;"><strong style="color: #ef4444;">C$<?= number_format($del['amount'], 2) ?></strong></td>
                                        <td>
                                            <div style="font-size: 13px;"><?= date('d/m/Y', strtotime($del['deleted_at'])) ?></div>
                                            <div style="font-size: 11px; color: var(--fc-text-sec);"><?= date('H:i', strtotime($del['deleted_at'])) ?></div>
                                        </td>
                                        <td>
                                            <div style="display:flex; align-items:center; gap:8px;">
                                                <div class="fc-user-avatar" style="width:28px; height:28px; font-size:12px; background: rgba(239, 68, 68, 0.1); color: #ef4444; border-radius: 8px;">
                                                    <?= strtoupper(substr($del['deleted_by_name'] ?? 'S', 0, 1)) ?>
                                                </div>
                                                <span style="font-size: 13px;"><?= htmlspecialchars($del['deleted_by_name'] ?? 'SISTEMA') ?></span>
                                            </div>
                                        </td>
                                        <td style="max-width: 250px; font-size: 12px;">
                                            <i class='bx bx-comment-error' style="color: #ef4444;"></i> <?= htmlspecialchars($del['reason'] ?: 'Sin motivo especificado') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    </main>
</div>

<style>
    @media print {
        @page { margin: 20mm; size: auto; }
        body { background: white !important; color: black !important; font-family: serif; }
        .sidebar, .no-print, .fc-tabs, .fc-header-right, .fc-btn { display: none !important; }
        .main-content { margin-left: 0 !important; padding: 0 !important; width: 100% !important; background: white !important; }
        .dashboard-wrapper { display: block !important; }
        .fc-card { box-shadow: none !important; border: 1px solid #ddd !important; background: white !important; }
        .fc-modal-header { border-bottom: 2px solid #000 !important; padding: 10px 0 !important; }
        .fc-modal-header h3 { color: black !important; font-size: 18px !important; }
        .fc-table th { background: #f0f0f0 !important; color: black !important; border: 1px solid #000 !important; }
        .fc-table td { color: black !important; border: 1px solid #ddd !important; }
        .fc-badge { border: 1px solid #000 !important; color: black !important; background: white !important; }
        .print-header { display: block !important; }
        .fc-card, .fc-table-responsive { page-break-inside: avoid; }
    }
    .print-header { display: none; }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>