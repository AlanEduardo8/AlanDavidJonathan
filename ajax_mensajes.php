<?php
require_once 'config/database.php';
require_once 'config/auth.php';

if (!estaLogueado()) {
    exit(json_encode(['error' => 'No autorizado']));
}

$usuario_id = $_SESSION['usuario_id'];
$solicitud_id = isset($_GET['solicitud_id']) ? intval($_GET['solicitud_id']) : 0;
$accion = isset($_GET['accion']) ? $_GET['accion'] : '';

// Verificar acceso a la solicitud
if ($solicitud_id > 0) {
    $check = mysqli_prepare($conn, "SELECT cliente_id, trabajador_id FROM solicitudes WHERE id = ?");
    mysqli_stmt_bind_param($check, 'i', $solicitud_id);
    mysqli_stmt_execute($check);
    $result = mysqli_stmt_get_result($check);
    $solicitud = mysqli_fetch_assoc($result);
    if (!$solicitud) exit(json_encode(['error' => 'Solicitud no encontrada']));
    $es_cliente = ($usuario_id == $solicitud['cliente_id']);
    $es_trabajador = ($usuario_id == $solicitud['trabajador_id']);
    if (!$es_cliente && !$es_trabajador) exit(json_encode(['error' => 'No tienes acceso a esta solicitud']));
} else {
    exit(json_encode(['error' => 'ID de solicitud inválido']));
}

// Enviar mensaje
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'enviar') {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        exit(json_encode(['error' => 'CSRF inválido']));
    }
    $mensaje = trim($_POST['mensaje'] ?? '');
    if (empty($mensaje)) exit(json_encode(['error' => 'El mensaje no puede estar vacío']));
    $receptor_id = ($es_cliente) ? $solicitud['trabajador_id'] : $solicitud['cliente_id'];
    if (!$receptor_id) exit(json_encode(['error' => 'No hay receptor disponible']));
    $sql = "INSERT INTO mensajes (solicitud_id, emisor_id, receptor_id, mensaje, fecha) VALUES (?, ?, ?, ?, NOW())";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'iiis', $solicitud_id, $usuario_id, $receptor_id, $mensaje);
    if (mysqli_stmt_execute($stmt)) {
        exit(json_encode(['success' => true, 'mensaje' => 'Mensaje enviado']));
    } else {
        exit(json_encode(['error' => 'Error al enviar mensaje']));
    }
}

// Marcar como leídos
if ($accion === 'marcar_leidos') {
    $sql = "UPDATE mensajes SET leido = 1 WHERE solicitud_id = ? AND receptor_id = ? AND leido = 0";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ii', $solicitud_id, $usuario_id);
    mysqli_stmt_execute($stmt);
    exit(json_encode(['success' => true]));
}

// Obtener mensajes
if ($accion === 'obtener') {
    $ultimo_id = isset($_GET['ultimo_id']) ? intval($_GET['ultimo_id']) : 0;
    $sql = "SELECT m.*, u.nombre as emisor_nombre, u.foto as emisor_foto 
            FROM mensajes m 
            JOIN usuarios u ON m.emisor_id = u.id 
            WHERE m.solicitud_id = ? AND m.id > ? 
            ORDER BY m.fecha ASC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ii', $solicitud_id, $ultimo_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $mensajes = mysqli_fetch_all($result, MYSQLI_ASSOC);
    exit(json_encode(['mensajes' => $mensajes]));
}

// Contar no leídos
if ($accion === 'contar_no_leidos') {
    $sql = "SELECT COUNT(*) as total FROM mensajes WHERE solicitud_id = ? AND receptor_id = ? AND leido = 0";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ii', $solicitud_id, $usuario_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    exit(json_encode(['total' => $row['total']]));
}

exit(json_encode(['error' => 'Acción no válida']));