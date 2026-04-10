<?php
// Script para generar hash de contraseña para usuario mesero
$password = 'mesero123';
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "Hash generado para 'mesero123':\n";
echo $hash . "\n\n";

// Verificar que funciona
if (password_verify($password, $hash)) {
    echo "✅ El hash es válido\n";
} else {
    echo "❌ Error en el hash\n";
}

echo "\n\nSQL para insertar/actualizar:\n";
echo "-- Opción 1: Insertar nuevo usuario\n";
echo "INSERT INTO users (name, email, username, password, role_id, status) VALUES \n";
echo "('Usuario Mesero', 'mesero@example.com', 'mesero', '$hash', 2, 'active');\n\n";

echo "-- Opción 2: Actualizar si ya existe\n";
echo "UPDATE users SET password = '$hash' WHERE username = 'mesero';\n";
?>
