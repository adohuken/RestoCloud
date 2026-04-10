<?php
// generate_hash.php - Script temporal para generar hash de contraseña
// Ejecuta este archivo en el navegador para obtener el hash

$password = 'admin123';
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "<h2>Generador de Hash de Contraseña</h2>";
echo "<p><strong>Contraseña:</strong> $password</p>";
echo "<p><strong>Hash generado:</strong></p>";
echo "<code style='background:#f0f0f0;padding:10px;display:block;'>$hash</code>";
echo "<hr>";
echo "<p>Copia este hash y úsalo en la base de datos.</p>";

// Verificar que el hash funciona
if (password_verify($password, $hash)) {
    echo "<p style='color:green;'>✅ El hash es válido y funciona correctamente.</p>";
} else {
    echo "<p style='color:red;'>❌ Error: El hash no es válido.</p>";
}
?>
