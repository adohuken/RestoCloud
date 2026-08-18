<!-- Sistema & Datos Tab -->
<?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_backup') || hasModuleAccess($pdo, $_SESSION['role_id'], 'config_restore') || hasModuleAccess($pdo, $_SESSION['role_id'], 'config_menu_init') || hasModuleAccess($pdo, $_SESSION['role_id'], 'config_reset') || !modulesTableExists($pdo)): ?>
<div id="sistema" class="tab-content">
    <div style="margin-bottom: 35px; display: flex; justify-content: space-between; align-items: flex-end;">
        <div>
            <h2 style="font-size: 26px; font-weight: 700; color: var(--fc-text-main); margin-bottom: 8px;">Sistema & Datos</h2>
            <p style="color: var(--fc-text-sec); font-size: 15px;">Administración de bases de datos, inicialización y mantenimiento.</p>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 30px; padding-bottom: 40px;">
        
        <!-- Backup Section -->
        <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_backup') || !modulesTableExists($pdo)): ?>
        <div class="glass-card">
            <h4>
                <div class="p-icon p-icon-1"><i class='bx bx-cloud-download'></i></div>
                Respaldo de Base de Datos
            </h4>
            <p style="color: var(--fc-text-sec); margin-bottom: 25px; line-height: 1.6;">Descarga una copia completa y segura de la base de datos para auditorías o migraciones manuales. Se recomienda hacer esto semanalmente.</p>
            <form method="POST">
                <button type="submit" name="backup" class="fc-btn fc-btn-primary fc-w100" style="height: 48px; border-radius: 14px; font-weight: 600;">
                    <i class='bx bx-download' style="font-size: 18px;"></i> Generar Respaldo .SQL
                </button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Restore Section -->
        <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_restore') || !modulesTableExists($pdo)): ?>
        <div class="glass-card">
            <h4>
                <div class="p-icon p-icon-2"><i class='bx bx-cloud-upload'></i></div>
                Restauración del Sistema
            </h4>
            <p style="color: var(--fc-text-sec); margin-bottom: 20px; line-height: 1.6;">Sube un archivo de respaldo `.sql` para restaurar el estado previo del sistema.</p>
            <div style="padding: 15px; background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.2); border-radius: 12px; margin-bottom: 25px;">
                <span style="color: #d97706; font-weight: 700; font-size: 13px; display: flex; align-items: center; gap: 5px;"><i class='bx bx-error-circle'></i> ADVERTENCIA CRÍTICA</span>
                <p style="font-size: 13px; color: var(--fc-text-sec); margin-top: 5px; margin-bottom: 0;">Esta acción sobrescribirá permanentemente todos los datos actuales del sistema. Procede con extrema precaución.</p>
            </div>
            <button type="button" onclick="openRestoreModal()" class="fc-btn fc-btn-outline fc-w100" style="height: 48px; border-radius: 14px; font-weight: 600; border-color: #f59e0b; color: #d97706;">
                <i class='bx bx-upload' style="font-size: 18px;"></i> Subir y Restaurar
            </button>
        </div>
        <?php endif; ?>

        <!-- Menu Init Section -->
        <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_menu_init') || !modulesTableExists($pdo)): ?>
        <div class="glass-card">
            <h4>
                <div class="p-icon p-icon-1"><i class='bx bx-archive-in'></i></div>
                Carga Masiva de Menú
            </h4>
            <p style="color: var(--fc-text-sec); margin-bottom: 25px; line-height: 1.6;">Utiliza nuestra herramienta de inicialización rápida para cargar categorías y productos de forma masiva desde una interfaz simplificada, ideal para nuevas instalaciones.</p>
            <a href="menu_init.php" class="fc-btn fc-btn-primary fc-w100" style="height: 48px; border-radius: 14px; font-weight: 600; display: flex; align-items: center; justify-content: center; text-decoration: none;">
                <i class='bx bx-rocket' style="font-size: 18px; margin-right: 8px;"></i> Abrir Asistente de Menú
            </a>
        </div>
        <?php endif; ?>

        <!-- Reset Section -->
        <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_reset') || !modulesTableExists($pdo)): ?>
        <div class="glass-card" style="border: 1px solid rgba(225, 29, 72, 0.3); background: rgba(225, 29, 72, 0.02);">
            <h4>
                <div class="p-icon p-icon-3"><i class='bx bx-trash'></i></div>
                <span style="color: #e11d48 !important;">Zona de Peligro: Reinicio Total</span>
            </h4>
            <p style="color: #1e293b !important; margin-bottom: 20px; line-height: 1.6;">Esta acción restablecerá el sistema a su estado inicial, eliminando permanentemente todos los productos, facturas, configuración de mesas y usuarios (excepto tu sesión).</p>
            <button type="button" onclick="openSystemResetModal()" class="fc-btn fc-w100" style="height: 48px; border-radius: 14px; font-weight: 800; background: linear-gradient(135deg, #e11d48, #be123c) !important; color: #ffffff !important; border: none; box-shadow: 0 6px 20px rgba(225, 29, 72, 0.3) !important; font-size: 15px; cursor: pointer;">
                <i class='bx bx-error-alt' style="font-size: 20px; color: #ffffff !important;"></i> REINICIAR SISTEMA COMPLETO
            </button>
        </div>
        <?php endif; ?>

    </div>
</div>
<?php endif; ?>
