<?php
// Script para generar hash de contraseña para usuario cocina
$password = 'cocina123';
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "Hash generado para 'cocina123':\n";
echo $hash . "\n\n";

// Verificar que funciona
if (password_verify($password, $hash)) {
    echo "✅ El hash es válido\n";
} else {
    echo "❌ Error en el hash\n";
}

echo "\n\nSQL para actualizar:\n";
echo "UPDATE users SET password = '$hash' WHERE username = 'cocina';\n";
?>
