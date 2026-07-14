<?php
require_once 'config/database.php';
require_once 'config/auth.php';

if (!estaLogueado()) {
    exit(json_encode(['error' => 'No autorizado']));
}

$usuario_id = $_SESSION['usuario_id'];
$solicitud_id = isset($_GET['solicitud_id']) ? intval($_GET['solicitud_id']) : 0;

if ($solicitud_id > 0) {
    // Verificar que el usuario tiene acceso a esta solicitud (por seguridad)
    $check = mysqli_prepare($conn, "SELECT id FROM solicitudes WHERE id = ? AND (cliente_id = ? OR trabajador_id = ?)");
    mysqli_stmt_bind_param($check, 'iii', $solicitud_id, $usuario_id, $usuario_id);
    mysqli_stmt_execute($check);
    mysqli_stmt_store_result($check);
    if (mysqli_stmt_num_rows($check) === 0) {
        exit(json_encode(['error' => 'Acceso denegado']));
    }
    $sql = "SELECT COUNT(*) as total FROM mensajes WHERE solicitud_id = ? AND receptor_id = ? AND leido = 0";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ii', $solicitud_id, $usuario_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    exit(json_encode(['total' => $row['total']]));
} else {
    $sql = "SELECT m.solicitud_id, COUNT(*) as total 
            FROM mensajes m 
            JOIN solicitudes s ON m.solicitud_id = s.id 
            WHERE (s.cliente_id = ? OR s.trabajador_id = ?) 
            AND m.receptor_id = ? AND m.leido = 0 
            GROUP BY m.solicitud_id";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'iii', $usuario_id, $usuario_id, $usuario_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $notificaciones = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $notificaciones[$row['solicitud_id']] = $row['total'];
    }
    exit(json_encode(['notificaciones' => $notificaciones]));
}