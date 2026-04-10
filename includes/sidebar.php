<?php
/**
 * Dynamic Sidebar - Renders menu based on role module assignments
 */

// Include modules helper
require_once __DIR__ . '/modules_helper.php';

// Fetch company settings
$company_name = 'Sistema Pizzería';
$company_logo = '';

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
        $show_company_name = $settings['show_company_name'] ?? '1';
    }
} catch (Exception $e) {
    // Silent fail, use defaults
}

// Get current page for active class
$current_page = basename($_SERVER['PHP_SELF']);

// Get user's modules (dynamic or fallback)
$user_modules = [];
if (modulesTableExists($pdo)) {
    $user_modules = getUserModules($pdo, $_SESSION['role_id']);
}

// Fallback: If no modules found (table empty or not migrated), use legacy logic
$use_legacy = empty($user_modules);

// Helper for icons (Legacy Mode) -> Now using Boxicons
function getIcon($page)
{
    switch ($page) {
        case 'inicio.php':
            return "<i class='bx bxs-dashboard'></i>";
        case 'productos.php':
            return "<i class='bx bx-package'></i>";
        case 'inventario_insumos.php':
            return "<i class='bx bx-layer'></i>";
        case 'gestion_recetas.php':
            return "<i class='bx bx-food-menu'></i>";
        case 'mesas.php':
            return "<i class='bx bx-chair'></i>";
        case 'venta.php':
            // Redirect POS icon to Mesas (Pedido Libre tab) since it's embedded now
            return "<i class='bx bx-cart'></i>";
        case 'cocina.php':
            return "<i class='bx bx-restaurant'></i>";
        case 'caja.php':
            return "<i class='bx bx-dollar-circle'></i>";
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
        <?php if ($use_legacy): ?>
            <!-- Legacy mode: hardcoded role checks (fallback) -->
            <?php if ($_SESSION['role_id'] == 1 || $_SESSION['role_id'] == 5): // Admin & SuperAdmin ?>
                <li><a href="inicio.php"
                        class="<?= $current_page == 'inicio.php' ? 'active' : '' ?>"><?= getIcon('inicio.php') ?> Dashboard</a>
                </li>
                <li><a href="productos.php"
                        class="<?= $current_page == 'productos.php' ? 'active' : '' ?>"><?= getIcon('productos.php') ?>
                        Menú</a>
                </li>
                <li><a href="inventario_insumos.php"
                        class="<?= $current_page == 'inventario_insumos.php' ? 'active' : '' ?>"><?= getIcon('inventario_insumos.php') ?>
                        Insumos</a></li>
                <li><a href="gestion_recetas.php"
                        class="<?= $current_page == 'gestion_recetas.php' ? 'active' : '' ?>"><?= getIcon('gestion_recetas.php') ?>
                        Recetas/Costos</a></li>
            <?php endif; ?>

            <?php if ($_SESSION['role_id'] == 1 || $_SESSION['role_id'] == 5 || $_SESSION['role_id'] == 2): // Admin, SuperAdmin & Waiter ?>
                <li><a href="mesas.php" class="<?= $current_page == 'mesas.php' ? 'active' : '' ?>"><?= getIcon('mesas.php') ?>
                        Mesas</a></li>
            <?php endif; ?>

            <?php if ($_SESSION['role_id'] == 1 || $_SESSION['role_id'] == 5): // Admin & SuperAdmin ?>
                <!-- <li><a href="mesas.php?tab=libre"
                        class="<?= $current_page == 'venta.php' ? 'active' : '' ?>"><?= getIcon('venta.php') ?>
                        POS</a></li> -->
                <li><a href="cocina.php"
                        class="<?= $current_page == 'cocina.php' ? 'active' : '' ?>"><?= getIcon('cocina.php') ?> Cocina</a>
                </li>
                <li><a href="caja.php" class="<?= $current_page == 'caja.php' ? 'active' : '' ?>"><?= getIcon('caja.php') ?>
                        Caja</a></li>
                <li><a href="cuentas.php" class="<?= $current_page == 'cuentas.php' ? 'active' : '' ?>"><i
                            class='bx bx-receipt'></i>
                        Cuentas</a></li>
                <li><a href="pedidosya.php"
                        class="<?= $current_page == 'pedidosya.php' || $current_page == 'factura_pedidosya.php' ? 'active' : '' ?>"><?= getIcon('pedidosya.php') ?>
                        PedidosYa</a></li>
                <li><a href="reportes.php"
                        class="<?= $current_page == 'reportes.php' ? 'active' : '' ?>"><?= getIcon('reportes.php') ?>
                        Reportes</a></li>
                <li><a href="usuarios.php"
                        class="<?= $current_page == 'usuarios.php' ? 'active' : '' ?>"><?= getIcon('usuarios.php') ?>
                        Usuarios</a></li>
                <li><a href="configuracion.php"
                        class="<?= $current_page == 'configuracion.php' ? 'active' : '' ?>"><?= getIcon('configuracion.php') ?>
                        Configuración</a></li>
            <?php endif; ?>
        <?php else: ?>
            <!-- Dynamic mode: render from database -->
            <?php foreach ($user_modules as $module): ?>
                <?php
                if (
                    strpos($module['module_key'], 'config_') === 0 ||
                    in_array($module['module_key'], ['inventory_edit', 'inventory_delete', 'pedido_libre', 'pedidosya'])
                ) {
                    continue;
                }

                $is_active = ($current_page == $module['file_path']);
                if ($module['module_key'] == 'pedidosya' && $current_page == 'factura_pedidosya.php') {
                    $is_active = true;
                }

                // Ensure icons are boxicons if possible, simplified for now assuming database has emojis or we override
                // Ideally, we'd map DB emojis to classes, but for now let's rely on the hardcoded list above if the DB stores emojis.
                // Or better, let's use the helper if the DB icon is just an emoji
                $icon = $module['icon'];
                // Check if icon is emoji-like (rudimentary check), if so, try to map to class
                // For this iteration, I'll trust the legacy map if it matches a known file, otherwise fallback
                $mappedIcon = getIcon($module['file_path']);
                // If mapped icon is circle (default), use database icon if available
                if (strpos($mappedIcon, 'bx-circle') !== false && !empty($module['icon'])) {
                    $mappedIcon = $module['icon']; // Fallback to DB icon
                }
                ?>
                <li>
                    <a href="<?= htmlspecialchars($module['file_path']) ?>" class="<?= $is_active ? 'active' : '' ?>">
                        <?= $mappedIcon ?>         <?= htmlspecialchars($module['name']) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        <?php endif; ?>

        <li><a href="salir.php" class="logout-link"><i class='bx bx-log-out'></i> Cerrar Sesión</a></li>
    </ul>
</aside>