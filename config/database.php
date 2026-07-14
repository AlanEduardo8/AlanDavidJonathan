<?php
// config/database.php
// Usar variables de entorno para seguridad
$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';
$dbname = getenv('DB_NAME') ?: 'fixi';

$conn = mysqli_connect($host, $user, $password, $dbname);

if (!$conn) {
    error_log('Error de conexión: ' . mysqli_connect_error());
    die('Error de conexión a la base de datos. Por favor, intente más tarde.');
}

mysqli_set_charset($conn, 'utf8mb4');

// No mostrar errores en producción
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
?>