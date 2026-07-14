<?php
// config/auth.php
session_start();

// Regenerar ID de sesión
function regenerarSesion() {
    session_regenerate_id(true);
}

// Generar token CSRF
function generarTokenCSRF() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Verificar token CSRF
function verificarTokenCSRF($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function estaLogueado() {
    return isset($_SESSION['usuario_id']);
}

function obtenerUsuario() {
    return $_SESSION['usuario'] ?? null;
}

function esCliente() {
    return isset($_SESSION['usuario_tipo']) && $_SESSION['usuario_tipo'] === 'cliente';
}

function esTrabajador() {
    return isset($_SESSION['usuario_tipo']) && $_SESSION['usuario_tipo'] === 'trabajador';
}

function esAdmin() {
    return isset($_SESSION['usuario_tipo']) && $_SESSION['usuario_tipo'] === 'admin';
}

function iniciarSesion($id, $nombre, $tipo, $email) {
    regenerarSesion();
    $_SESSION['usuario_id'] = $id;
    $_SESSION['usuario_nombre'] = $nombre;
    $_SESSION['usuario_tipo'] = $tipo;
    $_SESSION['usuario_email'] = $email;
    $_SESSION['usuario'] = [
        'id' => $id,
        'nombre' => $nombre,
        'tipo' => $tipo,
        'email' => $email
    ];
    generarTokenCSRF();
}

function cerrarSesion() {
    $_SESSION = [];
    session_destroy();
}

function redirigir($url) {
    header("Location: $url");
    exit;
}

function obtenerDatosUsuario($conn, $id) {
    $sql = "SELECT * FROM usuarios WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return mysqli_fetch_assoc($result);
}

function perfilCompleto($usuario_data) {
    if (empty($usuario_data['fecha_nacimiento'])) return false;
    if (empty($usuario_data['telefono'])) return false;
    if (empty($usuario_data['direccion'])) return false;
    if (empty($usuario_data['ciudad'])) return false;
    if (empty($usuario_data['estado'])) return false;
    if (empty($usuario_data['codigo_postal'])) return false;
    if (empty($usuario_data['documento_identidad'])) return false;
    if (empty($usuario_data['identificacion'])) return false;
    if ($usuario_data['tipo'] === 'trabajador') {
        if (empty($usuario_data['especialidad'])) return false;
        if (empty($usuario_data['experiencia'])) return false;
        if (empty($usuario_data['biografia'])) return false;
    }
    return true;
}
?>