<?php
/**
 * Dynamic Modular Sidebar - RestoCloud
 * Renders navigation based on user's role module permissions
 */

require_once __DIR__ . '/modules_helper.php';

// Fetch company settings
$company_name = 'RestoCloud System';
$company_logo = '';
$show_company_name = '1';

try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'settings'");
    if ($stmt->rowCount() > 0) {
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('company_name', 'company_logo', 'show_company_name')");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        if (!empty($settings['company_name'])) {
            $company_name = $settings['company_name'];
        }
        if (!empty($settings['company_logo'])) {
            $company_logo = $settings['company_logo'];
        }
        if (isset($settings['show_company_name'])) {
            $show_company_name = $settings['show_company_name'];
        }
    }
} catch (Exception $e) {
    // Silent fail, use defaults
}

// Current file detection
$current_page = basename($_SERVER['PHP_SELF']);

// Retrieve user's assigned sidebar modules
$user_role_id = $_SESSION['role_id'] ?? 0;
$user_modules = getUserModules($pdo, $user_role_id, true);

// Get kitchen workflow to potentially hide the kitchen screen
$kitchen_workflow = 'pantalla';
if (isset($settings)) {
    try {
        $stmt_wf = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'kitchen_workflow'");
        if ($wf = $stmt_wf->fetchColumn()) {
            $kitchen_workflow = $wf;
        }
    } catch (Exception $e) {}
}

if ($kitchen_workflow === 'comandera') {
    $user_modules = array_filter($user_modules, function($m) {
        $key = $m['module_key'] ?? $m['file_path'];
        return $key !== 'kitchen' && $key !== 'cocina.php';
    });
}

// Icon mapping helper
function getSidebarIcon($module)
{
    $icon = $module['icon'] ?? '';
    if (!empty($icon) && strpos($icon, 'bx') !== false) {
        return "<i class='bx " . htmlspecialchars($icon) . "'></i>";
    }

    $page = $module['file_path'] ?? '';
    switch ($page) {
        case 'inicio.php':
            return "<i class='bx bxs-dashboard'></i>";
        case 'productos.php':
            return "<i class='bx bx-package'></i>";
        case 'inventario_insumos.php':
            return "<i class='bx bx-layer'></i>";
        case 'mesas.php':
            return "<i class='bx bx-chair'></i>";
        case 'cocina.php':
            return "<i class='bx bx-restaurant'></i>";
        case 'caja.php':
            return "<i class='bx bx-dollar-circle'></i>";
        case 'cuentas.php':
            return "<i class='bx bx-receipt'></i>";
        case 'pedidosya.php':
            return "<i class='bx bx-cycling'></i>";
        case 'reportes.php':
            return "<i class='bx bx-bar-chart-alt-2'></i>";
        case 'usuarios.php':
            return "<i class='bx bx-user'></i>";
        case 'configuracion.php':
            return "<i class='bx bx-cog'></i>";
        default:
            return "<i class='bx bx-circle'></i>";
    }
}

// Check if a module corresponds to active page
function isModuleActive($module, $current_page)
{
    $target = $module['file_path'];
    if ($current_page === $target) {
        return true;
    }

    // Related page mappings
    if ($target === 'productos.php' && $current_page === 'categorias.php') return true;
    if ($target === 'pedidosya.php' && $current_page === 'factura_pedidosya.php') return true;
    if ($target === 'caja.php' && $current_page === 'panel_cajero.php') return true;
    if ($target === 'mesas.php' && in_array($current_page, ['venta.php', 'ver_pedido.php', 'procesar_pago_split.php'])) return true;
    if ($target === 'reportes.php' && in_array($current_page, ['export_report.php', 'imprimir_reporte.php'])) return true;
    if ($target === 'configuracion.php' && in_array($current_page, ['gestion_facturas.php', 'menu_init.php'])) return true;

    return false;
}
?>
<aside class="sidebar">
    <div class="sidebar-header">
        <?php if ($company_logo): ?>
            <div class="logo-container">
                <img src="<?= htmlspecialchars($company_logo) ?>?v=<?= time() ?>" alt="Logo" class="sidebar-logo">
                <?php if ($show_company_name == '1'): ?>
                    <h2 class="company-title"><?= htmlspecialchars($company_name) ?></h2>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <h2><i class='bx bx-dish' style="color: var(--fc-primary);"></i> <?= htmlspecialchars($company_name) ?></h2>
        <?php endif; ?>
    </div>

    <ul class="sidebar-menu">
        <?php foreach ($user_modules as $module): ?>
            <?php 
                $activeClass = '';
                if ($module['file_path'] === 'mesas.php') {
                    $tab = $_GET['tab'] ?? 'mesas';
                    if ($current_page === 'mesas.php' && $tab === 'mesas') $activeClass = 'active';
                    if (in_array($current_page, ['venta.php', 'ver_pedido.php', 'procesar_pago_split.php']) && !isset($_GET['libre'])) $activeClass = 'active';
                } elseif ($module['file_path'] === 'mesas.php?tab=barra') {
                    $tab = $_GET['tab'] ?? 'mesas';
                    if ($current_page === 'mesas.php' && $tab === 'barra') $activeClass = 'active';
                    if (in_array($current_page, ['venta.php', 'ver_pedido.php', 'procesar_pago_split.php']) && isset($_GET['libre'])) $activeClass = 'active';
                } else {
                    $activeClass = isModuleActive($module, $current_page) ? 'active' : ''; 
                }
            ?>
            <li>
                <a href="<?= htmlspecialchars($module['file_path']) ?>" class="<?= $activeClass ?>">
                    <?= getSidebarIcon($module) ?> <span><?= htmlspecialchars($module['name']) ?></span>
                </a>
            </li>
        <?php endforeach; ?>

        <?php if ($_SESSION['role_name'] === 'mesero'): ?>
        <li>
            <a href="javascript:void(0)" onclick="document.getElementById('switchToMobileForm').submit();">
                <i class='bx bx-mobile'></i> <span>Ver Móvil</span>
            </a>
            <form id="switchToMobileForm" method="POST" action="seleccionar_dispositivo.php" style="display:none;">
                <input type="hidden" name="device_type" value="mobile">
            </form>
        </li>
        <?php endif; ?>

        <li>
            <a href="salir.php" class="logout-link">
                <i class='bx bx-log-out'></i> <span>Cerrar Sesión</span>
            </a>
        </li>
    </ul>
</aside>