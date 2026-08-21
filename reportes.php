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
$stmt = $pdo->prepare('SELECT name FROM roles WHERE id = ?');
$stmt->execute([$_SESSION['role_id'] ?? 1]);
$user_role_name = $stmt->fetchColumn() ?: 'Administrador';

// Get current user name (fallback to DB if session missing)
$user_name = $_SESSION['name'] ?? null;
if (!$user_name && isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user_name = $stmt->fetchColumn();
}
$user_name = $user_name ?: 'Usuario';

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
    $low_stock_count = 0;
    foreach ($inventory as $item) {
        $total_inventory_value += $item['total_value'];
        $total_items += $item['stock'];
        if ($item['stock'] < 10) {
            $low_stock_count++;
        }
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

    // Fetch order history for waiters
    $sql_orders = '
        SELECT o.id, o.date_created, o.total, t.name as table_name, u.name as waiter_name
        FROM orders o
        JOIN users u ON o.user_id = u.id
        LEFT JOIN tables t ON o.table_id = t.id
        WHERE o.status = "completed" AND u.role_id = 2 AND DATE(o.date_created) BETWEEN ? AND ?
    ';
    $params_orders = [$start_date, $end_date];

    if ($waiter_id !== 'all' && is_numeric($waiter_id)) {
        $sql_orders .= ' AND u.id = ?';
        $params_orders[] = $waiter_id;
    }

    $sql_orders .= ' ORDER BY o.date_created DESC';

    $stmt = $pdo->prepare($sql_orders);
    $stmt->execute($params_orders);
    $waiter_orders_history = $stmt->fetchAll();

    // Calculate summary statistics
    $total_waiter_sales = 0;
    $total_waiter_orders = 0;
    $top_waiter_name = 'N/A';
    $top_waiter_sales = 0;
    
    foreach ($waiters_stats as $waiter) {
        $total_waiter_sales += $waiter['total_sales'];
        $total_waiter_orders += $waiter['total_orders'];
        if ($waiter['total_sales'] > $top_waiter_sales) {
            $top_waiter_sales = $waiter['total_sales'];
            $top_waiter_name = $waiter['name'];
        }
    }
    
    $waiter_avg_ticket = $total_waiter_orders > 0 ? $total_waiter_sales / $total_waiter_orders : 0;

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

} elseif ($report_type === 'cierres') {
    // Register closures logic
    $stmt = $pdo->prepare('
        SELECT cr.*, u.name as cashier_name
        FROM cash_register cr
        JOIN users u ON cr.user_id = u.id
        WHERE cr.type = "close" AND DATE(cr.date_created) BETWEEN ? AND ?
        ORDER BY cr.date_created DESC
    ');
    $stmt->execute([$start_date, $end_date]);
    $closures = $stmt->fetchAll();

    $total_expected = 0;
    $total_declared = 0;
    $total_difference = 0;
    foreach ($closures as $closure) {
        $total_expected += $closure['expected_amount'] ?? 0;
        $total_declared += $closure['amount'] ?? 0;
        $total_difference += $closure['difference'] ?? 0;
    }
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
                    <div class="fc-user-avatar"><?= strtoupper(substr($user_name, 0, 1)) ?></div>
                    <div class="fc-user-info">
                        <span class="name"><?= htmlspecialchars($user_name) ?></span>
                        <span class="role"><?= htmlspecialchars($user_role_name) ?></span>
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
            <a href="?type=cierres&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>"
                class="fc-tab <?= $report_type === 'cierres' ? 'active' : '' ?>">
                <i class='bx bx-archive-out'></i> <span>Arqueos</span>
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

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 25px; margin-bottom: 30px;">
                <div class="fc-card" style="margin: 0;">
                    <div class="fc-modal-header" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                        <h3><i class='bx bx-calendar'></i> Ventas por Fecha</h3>
                        <button onclick="exportTableToCSV('#sales-by-date-table', 'ventas_por_fecha.csv')" class="fc-btn fc-btn-outline no-print" style="padding: 6px 12px; font-size: 11px; height: auto;">
                            <i class='bx bx-export'></i> Excel
                        </button>
                    </div>
                    <div class="fc-table-responsive">
                        <table class="fc-table" id="sales-by-date-table">
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

                <!-- NEW: Productos Más Vendidos Card -->
                <div class="fc-card" style="margin: 0;">
                    <div class="fc-modal-header" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                        <h3><i class='bx bx-star'></i> Más Vendidos</h3>
                        <button onclick="exportTableToCSV('#top-products-table', 'productos_mas_vendidos.csv')" class="fc-btn fc-btn-outline no-print" style="padding: 6px 12px; font-size: 11px; height: auto;">
                            <i class='bx bx-export'></i> Excel
                        </button>
                    </div>
                    <div class="fc-table-responsive">
                        <table class="fc-table" id="top-products-table">
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th style="text-align: center;">Cant.</th>
                                    <th style="text-align: right;">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($top_products)): ?>
                                    <tr>
                                        <td colspan="3" style="text-align:center; padding: 20px; color: var(--fc-text-sec);">Sin datos</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($top_products as $prod): ?>
                                        <tr>
                                            <td><strong style="color: var(--fc-text-main); font-size: 13px;"><?= htmlspecialchars($prod['name']) ?></strong></td>
                                            <td style="text-align: center;"><span class="fc-badge fc-badge-outline"><?= $prod['quantity'] ?></span></td>
                                            <td style="text-align: right; font-weight: 700; color: #10b981; font-size: 13px;">C$<?= number_format($prod['total'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
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
                <div class="fc-modal-header" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                    <h3><i class='bx bx-list-ul'></i> Diario de Facturación Detallado</h3>
                    <button onclick="exportTableToCSV('#detailed-invoices-table', 'facturas_detalladas.csv')" class="fc-btn fc-btn-outline no-print" style="padding: 6px 12px; font-size: 11px; height: auto;">
                        <i class='bx bx-export'></i> Exportar Excel
                    </button>
                </div>
                <div class="fc-table-responsive">
                    <table class="fc-table" id="detailed-invoices-table">
                        <thead>
                            <tr>
                                <th># Folio</th>
                                <th>Fecha y Hora</th>
                                <th>Ubicación</th>
                                <th>Detalle de Productos</th>
                                <th>Método</th>
                                <th>Monto Total</th>
                                <th class="no-print no-export" style="text-align: center;">Acciones</th>
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

        <!-- REGISTER CLOSURES REPORT -->
        <?php if ($report_type === 'cierres'): ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px;">
                <div class="fc-card" style="margin:0; padding: 20px; background: rgba(255,255,255,0.03);">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div style="width: 50px; height: 50px; background: #3b82f6; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: white;">
                            <i class='bx bx-calculator'></i>
                        </div>
                        <div>
                            <span style="color: var(--fc-text-sec); font-size: 13px;">Total Esperado</span>
                            <div style="color: var(--fc-text-main); font-size: 20px; font-weight: 700;">C$<?= number_format($total_expected, 2) ?></div>
                        </div>
                    </div>
                </div>
                <div class="fc-card" style="margin:0; padding: 20px; background: rgba(255,255,255,0.03);">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div style="width: 50px; height: 50px; background: #10b981; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: white;">
                            <i class='bx bx-money'></i>
                        </div>
                        <div>
                            <span style="color: var(--fc-text-sec); font-size: 13px;">Total Declarado (Real)</span>
                            <div style="color: var(--fc-text-main); font-size: 20px; font-weight: 700;">C$<?= number_format($total_declared, 2) ?></div>
                        </div>
                    </div>
                </div>
                <div class="fc-card" style="margin:0; padding: 20px; background: <?= $total_difference < 0 ? 'rgba(239, 68, 68, 0.05)' : 'rgba(16, 185, 129, 0.05)' ?>; border-left: 4px solid <?= $total_difference < 0 ? '#ef4444' : '#10b981' ?>;">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div style="width: 50px; height: 50px; background: <?= $total_difference < 0 ? '#ef4444' : '#10b981' ?>; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: white;">
                            <i class='bx <?= $total_difference < 0 ? 'bx-trending-down' : 'bx-trending-up' ?>'></i>
                        </div>
                        <div>
                            <span style="color: var(--fc-text-sec); font-size: 13px;">Diferencia Total</span>
                            <div style="color: <?= $total_difference < 0 ? '#ef4444' : '#10b981' ?>; font-size: 20px; font-weight: 700;">C$<?= number_format($total_difference, 2) ?></div>
                        </div>
                    </div>
                </div>
                <div class="fc-card" style="margin:0; padding: 20px; background: rgba(255,255,255,0.03);">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div style="width: 50px; height: 50px; background: #8b5cf6; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: white;">
                            <i class='bx bx-archive'></i>
                        </div>
                        <div>
                            <span style="color: var(--fc-text-sec); font-size: 13px;">Arqueos Realizados</span>
                            <div style="color: var(--fc-text-main); font-size: 20px; font-weight: 700;"><?= count($closures) ?> <span style="font-size: 12px; font-weight: 400; color: var(--fc-text-sec);">cierres</span></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="fc-card">
                <div class="fc-modal-header" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                    <h3><i class='bx bx-archive-out'></i> Historial de Arqueos y Cierres</h3>
                    <button onclick="exportTableToCSV('#cierres-table', 'arqueos_caja.csv')" class="fc-btn fc-btn-outline no-print" style="padding: 6px 12px; font-size: 11px; height: auto;">
                        <i class='bx bx-export'></i> Exportar Excel
                    </button>
                </div>
                <div class="fc-table-responsive">
                    <table class="fc-table" id="cierres-table">
                        <thead>
                            <tr>
                                <th>Fecha Cierre</th>
                                <th>Cajero</th>
                                <th style="text-align: right;">Esperado en Caja</th>
                                <th style="text-align: right;">Declarado (Real)</th>
                                <th style="text-align: right;">Diferencia</th>
                                <th style="text-align: center;">Resultado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($closures)): ?>
                                <tr>
                                    <td colspan="6" style="text-align:center; padding: 40px; color: var(--fc-text-sec);">No se encontraron arqueos en el rango seleccionado</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($closures as $closure): 
                                    $diff = $closure['difference'] ?? 0;
                                    $statusClass = 'fc-badge-outline';
                                    $statusText = 'Cuadrado';
                                    if ($diff < 0) {
                                        $statusClass = 'fc-badge-primary'; // Red/Error badge style
                                        $statusText = 'Faltante';
                                    } elseif ($diff > 0) {
                                        $statusClass = 'fc-badge-outline'; // Amber or Green outline
                                        $statusText = 'Sobrante';
                                    }
                                ?>
                                    <tr>
                                        <td>
                                            <div style="font-size: 13px; color: var(--fc-text-main);"><?= date('d/m/Y', strtotime($closure['date_created'])) ?></div>
                                            <div style="font-size: 11px; color: var(--fc-text-sec);"><?= date('H:i', strtotime($closure['date_created'])) ?></div>
                                        </td>
                                        <td>
                                            <strong style="color: var(--fc-text-main);"><?= htmlspecialchars($closure['cashier_name']) ?></strong>
                                        </td>
                                        <td style="text-align: right; font-weight: 600;">C$<?= number_format($closure['expected_amount'], 2) ?></td>
                                        <td style="text-align: right; font-weight: 600; color: var(--fc-text-main);">C$<?= number_format($closure['amount'], 2) ?></td>
                                        <td style="text-align: right; font-weight: 700; color: <?= $diff < 0 ? '#ef4444' : ($diff > 0 ? '#f59e0b' : '#10b981') ?>;">
                                            C$<?= number_format($diff, 2) ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if ($diff < 0): ?>
                                                <span class="fc-badge" style="background-color: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.2);"><?= $statusText ?></span>
                                            <?php elseif ($diff > 0): ?>
                                                <span class="fc-badge" style="background-color: rgba(245, 158, 11, 0.1); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.2);"><?= $statusText ?></span>
                                            <?php else: ?>
                                                <span class="fc-badge" style="background-color: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.2);"><?= $statusText ?></span>
                                            <?php endif; ?>
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
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px;">
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
                <!-- NEW: Low Stock Summary Card -->
                <div class="fc-card" style="margin:0; padding: 20px; background: rgba(239, 68, 68, 0.05); border-left: 4px solid #ef4444;">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div style="width: 50px; height: 50px; background: #ef4444; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: white;">
                            <i class='bx bx-error-alt'></i>
                        </div>
                        <div>
                            <span style="color: var(--fc-text-sec); font-size: 13px;">Stock Bajo / Agotado</span>
                            <div style="color: #ef4444; font-size: 20px; font-weight: 700;"><?= $low_stock_count ?> <span style="font-size: 12px; font-weight: 400; color: var(--fc-text-sec);">alertas</span></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="fc-card">
                <div class="fc-modal-header" style="justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <h3><i class='bx bx-store'></i> Estado de Existencias</h3>
                    <div style="display: flex; gap: 10px; align-items: center;" class="no-print">
                        <button id="btn-filter-all" onclick="filterInventory('all')" class="fc-btn fc-btn-primary inventory-filter-btn" style="padding: 6px 12px; font-size: 11px; height: auto;">
                            Ver Todo
                        </button>
                        <button id="btn-filter-low" onclick="filterInventory('low')" class="fc-btn fc-btn-outline inventory-filter-btn" style="padding: 6px 12px; font-size: 11px; height: auto;">
                            Alertas (<?= $low_stock_count ?>)
                        </button>
                        <button onclick="exportTableToCSV('#inventory-table', 'reporte_inventario.csv')" class="fc-btn fc-btn-outline" style="padding: 6px 12px; font-size: 11px; height: auto;">
                            <i class='bx bx-export'></i> Exportar Excel
                        </button>
                        <button onclick="window.print()" class="fc-btn fc-btn-outline" style="padding: 6px 12px; font-size: 11px; height: auto;">
                            <i class='bx bx-printer'></i> PDF
                        </button>
                    </div>
                </div>
                <div class="fc-table-responsive">
                    <table class="fc-table" id="inventory-table">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Categoría</th>
                                <th style="text-align: right;">Costo Unit.</th>
                                <th style="text-align: center;">Disponibilidad</th>
                                <th style="text-align: right;">Valuación</th>
                                <th style="text-align: center;" class="no-export">Indicador</th>
                            </tr>
                        </thead>
                        <tbody id="inventory-table-body">
                            <?php foreach ($inventory as $item): 
                                $rowStatus = ($item['stock'] <= 0) ? 'empty' : (($item['stock'] < 10) ? 'low' : 'ok');
                            ?>
                                <tr data-status="<?= $rowStatus ?>">
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
                                    <td style="text-align: center;" class="no-export">
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
            <!-- NEW: Summary Cards for Waiters -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px;">
                <div class="fc-card" style="margin:0; padding: 20px; background: rgba(255,255,255,0.03);">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div style="width: 50px; height: 50px; background: var(--fc-primary); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: white;">
                            <i class='bx bx-money'></i>
                        </div>
                        <div>
                            <span style="color: var(--fc-text-sec); font-size: 13px;">Ventas Totales Equipo</span>
                            <div style="color: var(--fc-text-main); font-size: 20px; font-weight: 700;">C$<?= number_format($total_waiter_sales, 2) ?></div>
                        </div>
                    </div>
                </div>
                <div class="fc-card" style="margin:0; padding: 20px; background: rgba(255,255,255,0.03);">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div style="width: 50px; height: 50px; background: #3b82f6; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: white;">
                            <i class='bx bx-shopping-bag'></i>
                        </div>
                        <div>
                            <span style="color: var(--fc-text-sec); font-size: 13px;">Pedidos Atendidos</span>
                            <div style="color: var(--fc-text-main); font-size: 20px; font-weight: 700;"><?= $total_waiter_orders ?> <span style="font-size: 12px; font-weight: 400; color: var(--fc-text-sec);">comandas</span></div>
                        </div>
                    </div>
                </div>
                <div class="fc-card" style="margin:0; padding: 20px; background: rgba(255,255,255,0.03);">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div style="width: 50px; height: 50px; background: #10b981; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: white;">
                            <i class='bx bx-trending-up'></i>
                        </div>
                        <div>
                            <span style="color: var(--fc-text-sec); font-size: 13px;">Ticket Promedio Equipo</span>
                            <div style="color: var(--fc-text-main); font-size: 20px; font-weight: 700;">C$<?= number_format($waiter_avg_ticket, 2) ?></div>
                        </div>
                    </div>
                </div>
                <!-- Highlight Star Waiter card -->
                <div class="fc-card" style="margin:0; padding: 20px; background: linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, rgba(245, 158, 11, 0.05) 100%); border-left: 4px solid #f59e0b;">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div style="width: 50px; height: 50px; background: #f59e0b; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: white; box-shadow: 0 4px 8px rgba(245, 158, 11, 0.2);">
                            <i class='bx bxs-medal'></i>
                        </div>
                        <div>
                            <span style="color: var(--fc-text-sec); font-size: 13px;">Colaborador Estrella</span>
                            <div style="color: #f59e0b; font-size: 16px; font-weight: 800;"><?= htmlspecialchars($top_waiter_name) ?></div>
                            <div style="font-size: 11px; color: var(--fc-text-sec); font-weight: 600;">Ventas: C$<?= number_format($top_waiter_sales, 0) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="fc-card">
                <div class="fc-modal-header" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                    <h3><i class='bx bx-medal'></i> Desempeño del Equipo de Servicio</h3>
                    <button onclick="exportTableToCSV('#waiters-performance-table', 'desempeño_meseros.csv')" class="fc-btn fc-btn-outline no-print" style="padding: 6px 12px; font-size: 11px; height: auto;">
                        <i class='bx bx-export'></i> Exportar Excel
                    </button>
                </div>
                <div class="fc-table-responsive">
                    <table class="fc-table" id="waiters-performance-table">
                        <thead>
                            <tr>
                                <th>Colaborador</th>
                                <th style="text-align: center;">Pedidos Concluidos</th>
                                <th style="text-align: right;">Volumen de Venta</th>
                                <th style="text-align: right;">Ticket Promedio</th>
                                <th style="text-align: center;" class="no-export">Productividad</th>
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
                                        <td style="text-align: center;" class="no-export">
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

            <!-- HISTORIAL DE COMANDAS -->
            <div class="fc-card" style="margin-top: 25px;">
                <div class="fc-modal-header" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                    <h3><i class='bx bx-receipt'></i> Historial de Comandas</h3>
                    <button onclick="exportTableToCSV('#waiters-history-table', 'historial_comandas_meseros.csv')" class="fc-btn fc-btn-outline no-print" style="padding: 6px 12px; font-size: 11px; height: auto;">
                        <i class='bx bx-export'></i> Exportar Excel
                    </button>
                </div>
                <div class="fc-table-responsive">
                    <table class="fc-table" id="waiters-history-table">
                        <thead>
                            <tr>
                                <th># Comanda</th>
                                <th>Fecha y Hora</th>
                                <th>Ubicación</th>
                                <th>Mesero</th>
                                <th style="text-align: right;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($waiter_orders_history)): ?>
                                <tr>
                                    <td colspan="5" style="text-align:center; padding: 30px; color: var(--fc-text-sec);">No hay comandas registradas</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($waiter_orders_history as $order): ?>
                                    <tr>
                                        <td>
                                            <span class="fc-badge" style="background: rgba(255,255,255,0.05); color: var(--fc-text-main); font-family: monospace;">
                                                #<?= str_pad($order['id'], 6, '0', STR_PAD_LEFT) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="font-size: 13px; color: var(--fc-text-main);"><?= date('d/m/Y', strtotime($order['date_created'])) ?></div>
                                            <div style="font-size: 11px; color: var(--fc-text-sec);"><?= date('H:i', strtotime($order['date_created'])) ?></div>
                                        </td>
                                        <td>
                                            <span style="display: flex; align-items: center; gap: 5px; color: var(--fc-text-sec);">
                                                <i class='bx bx-table'></i> <?= htmlspecialchars($order['table_name'] ?? 'Barra/Delivery') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong style="color: var(--fc-text-main); font-size: 13px;"><?= htmlspecialchars($order['waiter_name']) ?></strong>
                                        </td>
                                        <td style="text-align: right; font-weight: 600; color: #10b981;">
                                            C$<?= number_format($order['total'], 2) ?>
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
                    <div class="fc-modal-header" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                        <h3><i class='bx bx-calendar'></i> Histórico Delivery</h3>
                        <button onclick="exportTableToCSV('#pedidosya-history-table', 'pedidosya_historico.csv')" class="fc-btn fc-btn-outline no-print" style="padding: 6px 12px; font-size: 11px; height: auto;">
                            <i class='bx bx-export'></i> Excel
                        </button>
                    </div>
                    <div class="fc-table-responsive">
                        <table class="fc-table" id="pedidosya-history-table">
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
                    <div class="fc-modal-header" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                        <h3><i class='bx bx-trending-up'></i> Top Delivery</h3>
                        <button onclick="exportTableToCSV('#pedidosya-top-table', 'pedidosya_top_productos.csv')" class="fc-btn fc-btn-outline no-print" style="padding: 6px 12px; font-size: 11px; height: auto;">
                            <i class='bx bx-export'></i> Excel
                        </button>
                    </div>
                    <div class="fc-table-responsive">
                        <table class="fc-table" id="pedidosya-top-table">
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
                <div class="fc-modal-header" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                    <h3><i class='bx bx-list-check'></i> Auditoría de Órdenes Delivery</h3>
                    <button onclick="exportTableToCSV('#pedidosya-audit-table', 'auditoria_pedidosya.csv')" class="fc-btn fc-btn-outline no-print" style="padding: 6px 12px; font-size: 11px; height: auto;">
                        <i class='bx bx-export'></i> Exportar Excel
                    </button>
                </div>
                <div class="fc-table-responsive">
                    <table class="fc-table" id="pedidosya-audit-table">
                        <thead>
                            <tr>
                                <th>ID Externo</th>
                                <th>Fecha/Hora</th>
                                <th>Información Cliente</th>
                                <th>Items</th>
                                <th>Recaudación</th>
                                <th class="no-print no-export">Ref.</th>
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
                                        <td class="no-print no-export">
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
            <div class="fc-modal-header" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                <h3><i class='bx bx-history'></i> Auditoría de Eliminaciones Técnicas</h3>
                <button onclick="exportTableToCSV('#deleted-invoices-table', 'auditoria_eliminaciones.csv')" class="fc-btn fc-btn-outline no-print" style="padding: 6px 12px; font-size: 11px; height: auto;">
                    <i class='bx bx-export'></i> Exportar Excel
                </button>
            </div>
            <div class="fc-table-responsive">
                <table class="fc-table" id="deleted-invoices-table">
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

<script>
//restocloud CSV exporter
function exportTableToCSV(tableSelector, filename) {
    const table = document.querySelector(tableSelector);
    if (!table) return;
    
    let csv = [];
    const rows = table.querySelectorAll("tr");
    
    for (let i = 0; i < rows.length; i++) {
        // Skip hidden rows (like filtered out inventory items)
        if (rows[i].style.display === 'none') continue;
        
        const row = [];
        const cols = rows[i].querySelectorAll("td, th");
        
        for (let j = 0; j < cols.length; j++) {
            // Skip action/icon columns marked with no-export
            if (cols[j].classList.contains('no-export')) continue;
            
            let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, " ").replace(/\s+/g, " ").trim();
            // Escape double quotes
            data = data.replace(/"/g, '""');
            row.push('"' + data + '"');
        }
        if (row.length > 0) {
            csv.push(row.join(","));
        }
    }
    
    // Add UTF-8 BOM to make Excel read Spanish accents/currency symbols correctly
    const csvString = "\uFEFF" + csv.join("\n");
    const blob = new Blob([csvString], { type: "text/csv;charset=utf-8;" });
    const link = document.createElement("a");
    const url = URL.createObjectURL(blob);
    link.setAttribute("href", url);
    link.setAttribute("download", filename);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// inventory filtering logic
function filterInventory(type) {
    const rows = document.querySelectorAll('#inventory-table-body tr');
    const buttons = document.querySelectorAll('.inventory-filter-btn');
    
    // Update button styling states
    buttons.forEach(btn => {
        btn.classList.remove('fc-btn-primary');
        btn.classList.add('fc-btn-outline');
    });
    
    const activeBtn = document.getElementById('btn-filter-' + type);
    if (activeBtn) {
        activeBtn.classList.remove('fc-btn-outline');
        activeBtn.classList.add('fc-btn-primary');
    }
    
    rows.forEach(row => {
        const status = row.getAttribute('data-status');
        if (type === 'all') {
            row.style.display = '';
        } else if (type === 'low') {
            if (status === 'low' || status === 'empty') {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        }
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>