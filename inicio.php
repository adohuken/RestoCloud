<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/modules_helper.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Check module access
checkModuleAccess($pdo, $_SESSION['role_id'], 'dashboard');

// Redirect based on role if needed
if ($_SESSION['role_id'] == 4) {
    header('Location: cocina.php');
    exit();
} elseif ($_SESSION['role_id'] == 2) {
    header('Location: mesas.php');
    exit();
} elseif ($_SESSION['role_id'] == 3) {
    header('Location: panel_cajero.php');
    exit();
}

// --- DATA FETCHING ---

// Get active register
$stmt = $pdo->query('SELECT * FROM cash_register WHERE status = "active" ORDER BY id DESC LIMIT 1');
$active_register = $stmt->fetch();

$total_sales = 0;
$total_orders = 0;
$top_products = [];
$category_sales = [];

if ($active_register) {
    // Sales & Orders
    $stmt = $pdo->prepare('
        SELECT SUM(p.amount) as total_sales, COUNT(DISTINCT o.id) as total_orders 
        FROM payments p
        JOIN orders o ON p.order_id = o.id
        WHERE p.date_created >= ?
    ');
    $stmt->execute([$active_register['date_created']]);
    $sales_data = $stmt->fetch();
    $total_sales = $sales_data['total_sales'] ?? 0;
    $total_orders = $sales_data['total_orders'] ?? 0;

    // Top Products
    $stmt = $pdo->prepare('
        SELECT p.name, SUM(od.quantity * od.price) as total
        FROM order_details od
        JOIN products p ON od.product_id = p.id
        JOIN orders o ON od.order_id = o.id
        JOIN payments pay ON o.id = pay.order_id
        WHERE pay.date_created >= ?
        GROUP BY p.id
        ORDER BY total DESC
        LIMIT 5
    ');
    $stmt->execute([$active_register['date_created']]);
    $top_products = $stmt->fetchAll();

    // Category Sales
    $stmt = $pdo->prepare('
        SELECT c.name, SUM(od.quantity * od.price) as total
        FROM order_details od
        JOIN products p ON od.product_id = p.id
        JOIN categories c ON p.category_id = c.id
        JOIN orders o ON od.order_id = o.id
        JOIN payments pay ON o.id = pay.order_id
        WHERE pay.date_created >= ?
        GROUP BY c.id
        ORDER BY total DESC
    ');
    $stmt->execute([$active_register['date_created']]);
    $category_sales = $stmt->fetchAll();
}

// Pending Orders & Tables
$stmt = $pdo->query('SELECT COUNT(*) as pending FROM orders WHERE status = "pending"');
$pending_orders = $stmt->fetch()['pending'] ?? 0;

$stmt = $pdo->query('SELECT COUNT(*) as active FROM tables WHERE status = "occupied"');
$active_tables = $stmt->fetch()['active'] ?? 0;
$stmt = $pdo->query('SELECT COUNT(*) as total FROM tables');
$total_tables = $stmt->fetch()['total'] ?? 0;

// Recent Activity
$recent_orders = $pdo->query('
    SELECT o.id, o.status, o.date_created, t.name as table_name,
           COALESCE(SUM(od.quantity * od.price), 0) as total
    FROM orders o
    LEFT JOIN tables t ON o.table_id = t.id
    LEFT JOIN order_details od ON o.id = od.order_id
    GROUP BY o.id
    ORDER BY o.date_created DESC
    LIMIT 6
')->fetchAll();

// User Data
$stmt = $pdo->prepare('SELECT name FROM roles WHERE id = ?');
$stmt->execute([$_SESSION['role_id']]);
$user_role_name = $stmt->fetchColumn() ?: 'Usuario';

$pageTitle = "Dashboard";
include __DIR__ . '/includes/header.php';
?>

<div class="dashboard-wrapper">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <style>
            @keyframes fadeSlideUp {
                from { opacity: 0; transform: translateY(25px); }
                to { opacity: 1; transform: translateY(0); }
            }
            @keyframes pulseGlow {
                0%, 100% { box-shadow: 0 0 8px rgba(16, 185, 129, 0.3); }
                50% { box-shadow: 0 0 20px rgba(16, 185, 129, 0.6); }
            }
            @keyframes shimmer {
                0% { background-position: -200% 0; }
                100% { background-position: 200% 0; }
            }
            @keyframes countUp { from { opacity: 0; transform: scale(0.5); } to { opacity: 1; transform: scale(1); } }

            .dash-animate { animation: fadeSlideUp 0.6s ease-out forwards; opacity: 0; }
            .dash-animate:nth-child(1) { animation-delay: 0.05s; }
            .dash-animate:nth-child(2) { animation-delay: 0.12s; }
            .dash-animate:nth-child(3) { animation-delay: 0.19s; }
            .dash-animate:nth-child(4) { animation-delay: 0.26s; }

            .dash-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 35px; flex-wrap: wrap; gap: 20px; }
            .dash-title {
                margin: 0; font-size: 2.8em; font-weight: 900; letter-spacing: -1.5px;
                background: linear-gradient(135deg, #fff 0%, var(--fc-primary) 50%, #fb7185 100%);
                background-size: 200% auto;
                -webkit-background-clip: text; -webkit-text-fill-color: transparent;
                animation: shimmer 4s linear infinite;
            }
            .dash-subtitle { color: var(--fc-text-sec); font-size: 1.05em; margin-top: 6px; display: flex; align-items: center; gap: 8px; }
            .dash-subtitle .live-dot {
                width: 8px; height: 8px; background: #10b981; border-radius: 50%;
                display: inline-block; animation: pulseGlow 2s infinite;
            }

            /* KPI Grid */
            .kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
            .kpi-card {
                position: relative; overflow: hidden; padding: 28px; border-radius: 20px;
                background: var(--fc-card-bg); border: 1px solid var(--fc-border); transition: all 0.3s ease;
            }
            .kpi-card:hover { transform: translateY(-5px); border-color: rgba(225, 29, 72, 0.3); box-shadow: 0 12px 40px rgba(0,0,0,0.2); }
            .kpi-card::before {
                content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; border-radius: 20px 20px 0 0;
            }
            .kpi-card:nth-child(1)::before { background: linear-gradient(90deg, #e11d48, #fb7185); }
            .kpi-card:nth-child(2)::before { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
            .kpi-card:nth-child(3)::before { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
            .kpi-card:nth-child(4)::before { background: linear-gradient(90deg, #10b981, #34d399); }
            .kpi-card::after {
                content: ''; position: absolute; right: -25px; bottom: -25px;
                width: 110px; height: 110px; border-radius: 50%; opacity: 0.04;
            }
            .kpi-card:nth-child(1)::after { background: #e11d48; }
            .kpi-card:nth-child(2)::after { background: #3b82f6; }
            .kpi-card:nth-child(3)::after { background: #f59e0b; }
            .kpi-card:nth-child(4)::after { background: #10b981; }

            .kpi-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; }
            .kpi-icon { width: 52px; height: 52px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: white; }
            .kpi-label { color: var(--fc-text-sec); font-size: 0.78em; font-weight: 700; text-transform: uppercase; letter-spacing: 1.8px; margin-bottom: 8px; }
            .kpi-value { font-size: 2.2em; font-weight: 900; color: var(--fc-text-main); line-height: 1; animation: countUp 0.5s ease-out; }
            .kpi-value small { font-size: 0.38em; opacity: 0.4; font-weight: 600; }

            /* Charts */
            .charts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-bottom: 30px; }
            .chart-card {
                background: var(--fc-card-bg); border: 1px solid var(--fc-border);
                border-radius: 20px; padding: 25px;
                animation: fadeSlideUp 0.6s ease-out forwards; opacity: 0;
            }
            .chart-card:nth-child(1) { animation-delay: 0.35s; }
            .chart-card:nth-child(2) { animation-delay: 0.45s; }
            .chart-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
            .chart-header h3 { margin: 0; font-size: 1.1em; font-weight: 700; display: flex; align-items: center; gap: 10px; }
            .chart-header h3 i { color: var(--fc-primary); font-size: 1.2em; }

            /* Recent Activity */
            .activity-section { animation: fadeSlideUp 0.6s ease-out forwards; opacity: 0; animation-delay: 0.55s; margin-bottom: 30px; }
            .activity-header { display: flex; align-items: center; gap: 10px; margin-bottom: 18px; }
            .activity-header h3 { margin: 0; font-weight: 700; font-size: 1.1em; }
            .activity-header i { color: var(--fc-primary); }
            .activity-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; }
            .activity-item {
                display: flex; align-items: center; justify-content: space-between;
                padding: 16px 20px; border-radius: 16px;
                background: var(--fc-card-bg); border: 1px solid var(--fc-border);
                transition: all 0.2s ease;
            }
            .activity-item:hover { border-color: rgba(225, 29, 72, 0.2); }
            .activity-left { display: flex; align-items: center; gap: 14px; }
            .activity-icon { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 16px; }
            .activity-name { font-weight: 700; font-size: 0.95em; color: var(--fc-text-main); }
            .activity-meta { font-size: 0.78em; color: var(--fc-text-sec); margin-top: 2px; }
            .activity-amount { font-weight: 800; font-size: 1em; }

            /* Quick Actions */
            .quick-actions {
                display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 15px;
                animation: fadeSlideUp 0.6s ease-out forwards; opacity: 0; animation-delay: 0.65s;
            }
            .qa-btn {
                display: flex; flex-direction: column; align-items: center; gap: 12px;
                padding: 25px 15px; border-radius: 18px;
                background: rgba(255,255,255,0.02); border: 1px solid var(--fc-border);
                cursor: pointer; transition: all 0.3s ease; text-decoration: none; color: var(--fc-text-sec);
            }
            .qa-btn:hover { background: rgba(225,29,72,0.08); border-color: rgba(225,29,72,0.3); color: var(--fc-primary); transform: translateY(-4px); box-shadow: 0 8px 25px rgba(225,29,72,0.15); }
            .qa-btn i { font-size: 28px; transition: transform 0.3s; }
            .qa-btn:hover i { transform: scale(1.15); }
            .qa-btn span { font-size: 0.82em; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }

            .empty-chart { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 60px 20px; color: var(--fc-text-sec); text-align: center; }
            .empty-chart i { font-size: 52px; opacity: 0.1; margin-bottom: 15px; }
            .empty-chart p { font-size: 0.9em; opacity: 0.6; }

            .register-status { display: flex; align-items: center; gap: 8px; padding: 8px 18px; border-radius: 30px; font-size: 0.82em; font-weight: 700; letter-spacing: 0.5px; }
            .register-status.open { background: rgba(16,185,129,0.1); color: #10b981; border: 1px solid rgba(16,185,129,0.2); }
            .register-status.closed { background: rgba(239,68,68,0.1); color: #ef4444; border: 1px solid rgba(239,68,68,0.2); }

            @media (max-width: 1200px) { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }
            @media (max-width: 768px) { .kpi-grid { grid-template-columns: 1fr; } .charts-grid { grid-template-columns: 1fr; } .dash-title { font-size: 1.8em; } .dash-header { flex-direction: column; align-items: flex-start; } }
        </style>

        <!-- HEADER -->
        <div class="dash-header">
            <div>
                <h1 class="dash-title">Dashboard</h1>
                <p class="dash-subtitle">
                    <span class="live-dot"></span>
                    <i class='bx bx-calendar-star'></i> <?= date('l, d M Y') ?> — Resumen en Tiempo Real
                </p>
            </div>
            <div style="display: flex; align-items: center; gap: 15px;">
                <?php if ($active_register): ?>
                    <div class="register-status open"><i class='bx bx-check-circle'></i> Caja Abierta</div>
                <?php else: ?>
                    <div class="register-status closed"><i class='bx bx-x-circle'></i> Caja Cerrada</div>
                <?php endif; ?>
                <div class="fc-user-pill">
                    <div class="fc-user-avatar"><?= strtoupper(substr($_SESSION['name'], 0, 1)) ?></div>
                    <div class="fc-user-info">
                        <span class="name"><?= htmlspecialchars($_SESSION['name']) ?></span>
                        <span class="role"><?= htmlspecialchars($user_role_name) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- KPIS -->
        <div class="kpi-grid">
            <div class="kpi-card dash-animate">
                <div class="kpi-top">
                    <div class="kpi-icon" style="background: linear-gradient(135deg, #e11d48, #be123c);"><i class='bx bx-dollar-circle'></i></div>
                </div>
                <div class="kpi-label">Ventas del Turno</div>
                <div class="kpi-value">C$<?= number_format($total_sales, 2) ?></div>
            </div>
            <div class="kpi-card dash-animate">
                <div class="kpi-top">
                    <div class="kpi-icon" style="background: linear-gradient(135deg, #3b82f6, #2563eb);"><i class='bx bx-receipt'></i></div>
                </div>
                <div class="kpi-label">Pedidos Completados</div>
                <div class="kpi-value"><?= $total_orders ?></div>
            </div>
            <div class="kpi-card dash-animate">
                <div class="kpi-top">
                    <div class="kpi-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);"><i class='bx bx-time-five'></i></div>
                </div>
                <div class="kpi-label">En Espera</div>
                <div class="kpi-value" style="color: <?= $pending_orders > 0 ? '#f59e0b' : 'var(--fc-text-main)' ?>;"><?= $pending_orders ?></div>
            </div>
            <div class="kpi-card dash-animate">
                <div class="kpi-top">
                    <div class="kpi-icon" style="background: linear-gradient(135deg, #10b981, #059669);"><i class='bx bx-chair'></i></div>
                </div>
                <div class="kpi-label">Ocupación del Salón</div>
                <div class="kpi-value"><?= $active_tables ?> <small>/ <?= $total_tables ?> mesas</small></div>
            </div>
        </div>

        <!-- CHARTS -->
        <div class="charts-grid">
            <div class="chart-card">
                <div class="chart-header"><h3><i class='bx bx-pie-chart-alt-2'></i> Ventas por Categoría</h3></div>
                <?php if (!empty($category_sales)): ?>
                    <div style="height: 320px; display: flex; align-items: center; justify-content: center;"><canvas id="catChart"></canvas></div>
                <?php else: ?>
                    <div class="empty-chart"><i class='bx bx-pie-chart-alt-2'></i><p>Aún no hay ventas registradas en este turno</p></div>
                <?php endif; ?>
            </div>
            <div class="chart-card">
                <div class="chart-header"><h3><i class='bx bx-bar-chart-alt-2'></i> Top 5 Productos</h3></div>
                <?php if (!empty($top_products)): ?>
                    <div style="height: 320px; display: flex; align-items: center; justify-content: center;"><canvas id="prodChart"></canvas></div>
                <?php else: ?>
                    <div class="empty-chart"><i class='bx bx-bar-chart-alt-2'></i><p>Aún no hay ventas registradas en este turno</p></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- RECENT ACTIVITY -->
        <?php if (!empty($recent_orders)): ?>
        <div class="activity-section">
            <div class="activity-header">
                <i class='bx bx-pulse' style="font-size: 1.3em;"></i>
                <h3>Actividad Reciente</h3>
            </div>
            <div class="activity-list">
                <?php foreach ($recent_orders as $order):
                    $status_colors = ['pending' => '#f59e0b', 'completed' => '#10b981', 'cancelled' => '#ef4444', 'preparing' => '#3b82f6'];
                    $status_labels = ['pending' => 'Pendiente', 'completed' => 'Completado', 'cancelled' => 'Cancelado', 'preparing' => 'Preparando'];
                    $status_icons = ['pending' => 'bx-time-five', 'completed' => 'bx-check-circle', 'cancelled' => 'bx-x-circle', 'preparing' => 'bx-loader-alt'];
                    $sc = $status_colors[$order['status']] ?? '#94a3b8';
                    $sl = $status_labels[$order['status']] ?? $order['status'];
                    $si = $status_icons[$order['status']] ?? 'bx-receipt';
                ?>
                <div class="activity-item">
                    <div class="activity-left">
                        <div class="activity-icon" style="background: <?= $sc ?>15; color: <?= $sc ?>;">
                            <i class='bx <?= $si ?>'></i>
                        </div>
                        <div>
                            <div class="activity-name">Pedido #<?= str_pad($order['id'], 4, '0', STR_PAD_LEFT) ?></div>
                            <div class="activity-meta">
                                <i class='bx bx-table'></i> <?= htmlspecialchars($order['table_name'] ?? 'Para llevar') ?> · <?= date('H:i', strtotime($order['date_created'])) ?>
                            </div>
                        </div>
                    </div>
                    <div class="activity-amount" style="color: <?= $sc ?>;">C$<?= number_format($order['total'], 0) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- QUICK ACTIONS -->
        <div class="quick-actions">
            <a href="mesas.php" class="qa-btn"><i class='bx bx-grid-alt'></i><span>Nuevo Pedido</span></a>
            <a href="productos.php" class="qa-btn"><i class='bx bx-restaurant'></i><span>Menú</span></a>
            <a href="caja.php" class="qa-btn"><i class='bx bx-wallet'></i><span>Caja</span></a>
            <a href="reportes.php" class="qa-btn"><i class='bx bx-line-chart'></i><span>Reportes</span></a>
            <a href="inventario_insumos.php" class="qa-btn"><i class='bx bx-package'></i><span>Insumos</span></a>
            <a href="configuracion.php" class="qa-btn"><i class='bx bx-cog'></i><span>Config</span></a>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    Chart.defaults.color = '#94a3b8';
    Chart.defaults.font.family = "'Outfit', 'Inter', sans-serif";
    const roseColors = ['#e11d48', '#f43f5e', '#fb7185', '#3b82f6', '#8b5cf6', '#f59e0b', '#10b981'];

    <?php if (!empty($category_sales)): ?>
    new Chart(document.getElementById('catChart'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode(array_column($category_sales, 'name')) ?>,
            datasets: [{ data: <?= json_encode(array_column($category_sales, 'total')) ?>, backgroundColor: roseColors, borderWidth: 0, hoverOffset: 10 }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '72%',
            plugins: {
                legend: { position: 'right', labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 8, padding: 18, font: { size: 12, weight: '600' } } },
                tooltip: { backgroundColor: '#0f172a', titleColor: '#f8fafc', bodyColor: '#94a3b8', borderColor: '#1e293b', borderWidth: 1, padding: 14, cornerRadius: 12,
                    callbacks: { label: ctx => ` ${ctx.label}: C$${new Intl.NumberFormat('es-NI').format(ctx.parsed)}` }
                }
            }
        }
    });
    <?php endif; ?>

    <?php if (!empty($top_products)): ?>
    const ctx = document.getElementById('prodChart').getContext('2d');
    const gradient = ctx.createLinearGradient(0, 0, 0, 350);
    gradient.addColorStop(0, 'rgba(225, 29, 72, 0.9)');
    gradient.addColorStop(1, 'rgba(225, 29, 72, 0.1)');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($top_products, 'name')) ?>,
            datasets: [{ label: 'Ventas', data: <?= json_encode(array_column($top_products, 'total')) ?>, backgroundColor: gradient, borderRadius: 10, barThickness: 36, hoverBackgroundColor: '#fb7185' }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false },
                tooltip: { backgroundColor: '#0f172a', titleColor: '#f8fafc', bodyColor: '#94a3b8', borderColor: '#1e293b', borderWidth: 1, padding: 14, cornerRadius: 12,
                    callbacks: { label: ctx => ` C$${new Intl.NumberFormat('es-NI').format(ctx.parsed.y)}` }
                }
            },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.04)', drawBorder: false }, ticks: { font: { size: 11 } } },
                x: { grid: { display: false }, ticks: { font: { size: 11, weight: '600' } } }
            }
        }
    });
    <?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>