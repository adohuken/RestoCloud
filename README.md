# System_FoodCord

Sistema de gestión para FoodCord con control de inventario, POS y facturación.

## 🚀 Guía de Sincronización (Casa <-> Trabajo)

Usa estos comandos para mantener tus computadoras sincronizadas a través de GitHub.

### 🏠 Al empezar el día (En cualquier PC)
Antes de empezar a programar, trae los cambios más recientes que hayas hecho en la otra computadora:
```powershell
git pull origin main
```

### 🏢 Al terminar el día (En cualquier PC)
Para guardar tus avances y que estén disponibles en la otra computadora:

1. **Exportar Base de Datos (Muy importante)**:
   ```powershell
   C:\xampp\mysql\bin\mysqldump.exe -u root foodcorp_system > database_current.sql
   ```

2. **Subir a GitHub**:
   ```powershell
   git add .
   git commit -m "Resumen de lo trabajado hoy"
   git push origin main
   ```

---

## 🛠️ Requisitos de Configuración
- **PHP**: 8.1+
- **Base de Datos**: MySQL (XAMPP predeterminado)
- **Nombre de BD**: `foodcorp_system`

## ✅ Automatización (Workflows)
Este repositorio tiene habilitado un **Workflow** que verifica automáticamente la sintaxis de tus archivos PHP en cada subida. Puedes ver el estado (verde/rojo) en la pestaña **Actions** de GitHub.
