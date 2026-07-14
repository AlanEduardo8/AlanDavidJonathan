<?php
require_once 'config/database.php';
require_once 'config/auth.php';

if (!estaLogueado()) redirigir('login.php');

$solicitud_id = isset($_GET['solicitud_id']) ? intval($_GET['solicitud_id']) : 0;
if ($solicitud_id <= 0) die('ID de solicitud inválido.');

$usuario_id = $_SESSION['usuario_id'];
$csrf_token = generarTokenCSRF();

$sql_check = "SELECT s.*, 
                     u.nombre as cliente_nombre, u.apellidos as cliente_apellidos, u.foto as cliente_foto,
                     t.nombre as trabajador_nombre, t.apellidos as trabajador_apellidos, t.foto as trabajador_foto
              FROM solicitudes s
              JOIN usuarios u ON s.cliente_id = u.id
              LEFT JOIN usuarios t ON s.trabajador_id = t.id
              WHERE s.id = ?";
$stmt_check = mysqli_prepare($conn, $sql_check);
mysqli_stmt_bind_param($stmt_check, 'i', $solicitud_id);
mysqli_stmt_execute($stmt_check);
$result_check = mysqli_stmt_get_result($stmt_check);
$solicitud = mysqli_fetch_assoc($result_check);

if (!$solicitud) die('Solicitud no encontrada.');

$es_cliente_solicitud = ($usuario_id == $solicitud['cliente_id']);
$es_trabajador_solicitud = ($solicitud['trabajador_id'] && $usuario_id == $solicitud['trabajador_id']);

if (!$es_cliente_solicitud && !$es_trabajador_solicitud) die('No tienes acceso a esta conversación.');

$otro_nombre = $es_cliente_solicitud ? $solicitud['trabajador_nombre'] : $solicitud['cliente_nombre'];
$otro_apellidos = $es_cliente_solicitud ? $solicitud['trabajador_apellidos'] : $solicitud['cliente_apellidos'];
$otro_foto = $es_cliente_solicitud ? $solicitud['trabajador_foto'] : $solicitud['cliente_foto'];
$otro_id = $es_cliente_solicitud ? $solicitud['trabajador_id'] : $solicitud['cliente_id'];

$sql_update = "UPDATE mensajes SET leido = 1 WHERE solicitud_id = ? AND receptor_id = ? AND leido = 0";
$stmt_update = mysqli_prepare($conn, $sql_update);
mysqli_stmt_bind_param($stmt_update, 'ii', $solicitud_id, $usuario_id);
mysqli_stmt_execute($stmt_update);

$sql_mensajes = "SELECT m.*, u.nombre as emisor_nombre, u.foto as emisor_foto
                 FROM mensajes m
                 JOIN usuarios u ON m.emisor_id = u.id
                 WHERE m.solicitud_id = ?
                 ORDER BY m.fecha ASC";
$stmt_mensajes = mysqli_prepare($conn, $sql_mensajes);
mysqli_stmt_bind_param($stmt_mensajes, 'i', $solicitud_id);
mysqli_stmt_execute($stmt_mensajes);
$result_mensajes = mysqli_stmt_get_result($stmt_mensajes);
$mensajes = mysqli_fetch_all($result_mensajes, MYSQLI_ASSOC);

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'enviar_mensaje') {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        $error = 'Solicitud no válida (CSRF).';
    } else {
        $mensaje = trim($_POST['mensaje'] ?? '');
        if (empty($mensaje)) {
            $error = 'El mensaje no puede estar vacío.';
        } else {
            $receptor_id = $otro_id;
            if (!$receptor_id) {
                $error = 'No hay receptor disponible.';
            } else {
                $sql_insert = "INSERT INTO mensajes (solicitud_id, emisor_id, receptor_id, mensaje, fecha) VALUES (?, ?, ?, ?, NOW())";
                $stmt_insert = mysqli_prepare($conn, $sql_insert);
                mysqli_stmt_bind_param($stmt_insert, 'iiis', $solicitud_id, $usuario_id, $receptor_id, $mensaje);
                if (mysqli_stmt_execute($stmt_insert)) {
                    $success = 'Mensaje enviado.';
                    header("Location: mensajes_detalle.php?solicitud_id=" . $solicitud_id);
                    exit;
                } else {
                    $error = 'Error al enviar mensaje.';
                }
            }
        }
    }
}

$sql_total_no_leidos = "SELECT COUNT(*) as total FROM mensajes WHERE receptor_id = ? AND leido = 0";
$stmt_total = mysqli_prepare($conn, $sql_total_no_leidos);
mysqli_stmt_bind_param($stmt_total, 'i', $usuario_id);
mysqli_stmt_execute($stmt_total);
$result_total = mysqli_stmt_get_result($stmt_total);
$row_total = mysqli_fetch_assoc($result_total);
$total_mensajes_no_leidos = $row_total['total'] ?? 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conversación - Fixi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.0/dist/cyborg/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .dashboard-content { position: relative; z-index: 1; }
        .chat-box {
            max-height: 500px;
            overflow-y: auto;
            background: #0a111e;
            border-radius: 8px;
            padding: 15px;
            display: flex;
            flex-direction: column;
        }
        .mensaje { margin-bottom: 12px; padding: 10px 16px; border-radius: 20px; max-width: 75%; word-wrap: break-word; }
        .mensaje-propio { background: #00d4ff; color: #030507; align-self: flex-end; border-bottom-right-radius: 4px; }
        .mensaje-otro { background: #1a2a3f; color: #e0e6ed; align-self: flex-start; border-bottom-left-radius: 4px; }
        .mensaje-fecha { font-size: 0.65rem; color: #8899aa; margin-top: 4px; }
        .mensaje-propio .mensaje-fecha { color: #0a2a3a; }
        .mensaje-usuario { font-weight: bold; margin-bottom: 2px; }
        .mensaje-propio .mensaje-usuario { color: #0a2a3a; }
        .mensaje-otro .mensaje-usuario { color: #00d4ff; }
        .foto-perfil-chat { width: 40px; height: 40px; object-fit: cover; border: 2px solid #00d4ff; }
        .chat-header { border-bottom: 1px solid #1a2a3f; padding-bottom: 10px; margin-bottom: 15px; }
    </style>
</head>
<body>
    <?php include 'assets/estrellas.php'; ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark-custom fixed-top" style="z-index:10;">
        <div class="container">
            <a class="navbar-brand" href="<?= esCliente() ? 'cliente_dashboard.php' : (esTrabajador() ? 'trabajador_dashboard.php' : 'index.php') ?>">
                <img src="assets/img/Logo FIXI.png" alt="Fixi Logo" onerror="this.style.display='none'">
                FIXI
            </a>
            <div class="d-flex align-items-center">
                <a href="mensajes.php" class="btn btn-outline-info btn-sm me-2"><i class="fas fa-arrow-left me-1"></i>Volver</a>
                <span class="user-info me-2">
                    <i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['usuario_nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                    <span class="badge bg-primary ms-1"><?= htmlspecialchars($_SESSION['usuario_tipo'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                </span>
                <a href="logout.php" class="btn btn-outline-danger btn-sm">CERRAR SESIÓN</a>
            </div>
        </div>
    </nav>

    <div class="container py-4 dashboard-content" style="margin-top: 60px;">
        <div class="row">
            <div class="col-lg-8 mx-auto">
                <div class="card mb-4">
                    <div class="card-body chat-header">
                        <div class="d-flex align-items-center">
                            <img src="<?= htmlspecialchars($otro_foto ?? 'https://via.placeholder.com/40', ENT_QUOTES, 'UTF-8') ?>" class="rounded-circle foto-perfil-chat me-3">
                            <div>
                                <h5 class="mb-0"><?= htmlspecialchars(($otro_nombre ?? '') . ' ' . ($otro_apellidos ?? ''), ENT_QUOTES, 'UTF-8') ?></h5>
                                <small class="text-muted">Solicitud #<?= $solicitud_id ?> - <?= htmlspecialchars($solicitud['servicio'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                            </div>
                            <div class="ms-auto">
                                <a href="solicitud_detalle.php?id=<?= $solicitud_id ?>" class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-file-alt me-1"></i>Ver solicitud
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if ($success): ?>
                            <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>

                        <div class="chat-box" id="chatBox">
                            <?php if (count($mensajes) > 0): ?>
                                <?php foreach ($mensajes as $msg): ?>
                                    <div class="mensaje <?= ($msg['emisor_id'] == $usuario_id) ? 'mensaje-propio' : 'mensaje-otro' ?>">
                                        <div class="mensaje-usuario">
                                            <?= htmlspecialchars($msg['emisor_nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                            <?php if ($msg['emisor_id'] == $usuario_id): ?>
                                                <span class="badge bg-secondary ms-1">Tú</span>
                                            <?php endif; ?>
                                        </div>
                                        <div><?= nl2br(htmlspecialchars($msg['mensaje'] ?? '', ENT_QUOTES, 'UTF-8')) ?></div>
                                        <div class="mensaje-fecha"><?= date('d/m/Y H:i', strtotime($msg['fecha'])) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted text-center">No hay mensajes aún. Inicia la conversación.</p>
                            <?php endif; ?>
                        </div>

                        <form method="POST" action="" class="mt-3">
                            <input type="hidden" name="accion" value="enviar_mensaje">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <div class="input-group">
                                <input type="text" class="form-control" name="mensaje" id="mensajeInput" placeholder="Escribe tu mensaje..." required>
                                <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        var chatBox = document.getElementById('chatBox');
        if (chatBox) chatBox.scrollTop = chatBox.scrollHeight;
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>