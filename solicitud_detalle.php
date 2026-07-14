<?php
require_once 'config/database.php';
require_once 'config/auth.php';
require_once 'config/pusher.php';

if (!estaLogueado()) redirigir('login.php');

$solicitud_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($solicitud_id <= 0) die('<div class="container mt-5"><div class="alert alert-danger">ID de solicitud inválido. <a href="dashboard.php">Volver</a></div></div>');

$usuario_id = $_SESSION['usuario_id'];
$error = '';
$success = '';
$csrf_token = generarTokenCSRF();

$sql = "SELECT s.*, 
               u.nombre as cliente_nombre, u.apellidos as cliente_apellidos, 
               u.telefono as cliente_telefono, u.email as cliente_email, u.foto as cliente_foto,
               t.nombre as trabajador_nombre, t.apellidos as trabajador_apellidos, t.foto as trabajador_foto
        FROM solicitudes s
        JOIN usuarios u ON s.cliente_id = u.id
        LEFT JOIN usuarios t ON s.trabajador_id = t.id
        WHERE s.id = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'i', $solicitud_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$solicitud = mysqli_fetch_assoc($result);

if (!$solicitud) die('<div class="container mt-5"><div class="alert alert-danger">La solicitud no existe. <a href="dashboard.php">Volver</a></div></div>');

$es_cliente = ($usuario_id == $solicitud['cliente_id']);
$es_trabajador_asignado = ($solicitud['trabajador_id'] && $usuario_id == $solicitud['trabajador_id']);
$es_trabajador_puede_aceptar = (esTrabajador() && $solicitud['estado'] == 'pendiente' && is_null($solicitud['trabajador_id']));

if (!$es_cliente && !$es_trabajador_asignado && !$es_trabajador_puede_aceptar) {
    die('<div class="container mt-5"><div class="alert alert-danger">No tienes permiso. <a href="dashboard.php">Volver</a></div></div>');
}

// Aceptar con precio
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'aceptar_con_precio' && $es_trabajador_puede_aceptar) {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        $error = 'Solicitud no válida (CSRF).';
    } else {
        $precio = floatval($_POST['precio'] ?? 0);
        $fecha = $_POST['fecha'] ?? '';
        $hora = $_POST['hora'] ?? '';
        if ($precio <= 0 || empty($fecha) || empty($hora)) {
            $error = 'Debes establecer precio, fecha y hora para aceptar la solicitud.';
        } else {
            $update = mysqli_prepare($conn, "UPDATE solicitudes 
                                              SET trabajador_id = ?, estado = 'asignado', fecha_asignacion = NOW(),
                                                  presupuesto = ?, fecha_preferida = ?, hora_preferida = ?,
                                                  precio_aceptado = 0
                                              WHERE id = ? AND estado = 'pendiente'");
            mysqli_stmt_bind_param($update, 'idssi', $usuario_id, $precio, $fecha, $hora, $solicitud_id);
            if (mysqli_stmt_execute($update)) {
                $success = 'Solicitud aceptada. El cliente debe aceptar el precio para continuar.';
                $solicitud['trabajador_id'] = $usuario_id;
                $solicitud['estado'] = 'asignado';
                $solicitud['presupuesto'] = $precio;
                $solicitud['fecha_preferida'] = $fecha;
                $solicitud['hora_preferida'] = $hora;
                $solicitud['precio_aceptado'] = 0;
                $es_trabajador_asignado = true;
                $es_trabajador_puede_aceptar = false;
                
                $sql_t = "SELECT nombre, apellidos, foto FROM usuarios WHERE id = ?";
                $stmt_t = mysqli_prepare($conn, $sql_t);
                mysqli_stmt_bind_param($stmt_t, 'i', $usuario_id);
                mysqli_stmt_execute($stmt_t);
                $result_t = mysqli_stmt_get_result($stmt_t);
                $trabajador = mysqli_fetch_assoc($result_t);
                $solicitud['trabajador_nombre'] = $trabajador['nombre'];
                $solicitud['trabajador_apellidos'] = $trabajador['apellidos'];
                $solicitud['trabajador_foto'] = $trabajador['foto'];
                
                $receptor_id = $solicitud['cliente_id'];
                $mensaje_notificacion = "El trabajador ha aceptado tu solicitud y propone un precio de $".number_format($precio, 2).". Por favor, revisa el detalle y acepta el precio para continuar.";
                $sql_mensaje = "INSERT INTO mensajes (solicitud_id, emisor_id, receptor_id, mensaje, fecha) VALUES (?, ?, ?, ?, NOW())";
                $stmt_mensaje = mysqli_prepare($conn, $sql_mensaje);
                mysqli_stmt_bind_param($stmt_mensaje, 'iiis', $solicitud_id, $usuario_id, $receptor_id, $mensaje_notificacion);
                mysqli_stmt_execute($stmt_mensaje);
            } else {
                $error = 'Error al aceptar la solicitud.';
                error_log('Error aceptar: ' . mysqli_error($conn));
            }
        }
    }
}

// Establecer precio
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'establecer_precio' && $es_trabajador_asignado) {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        $error = 'Solicitud no válida (CSRF).';
    } else {
        if ($solicitud['estado'] == 'asignado' && ($solicitud['presupuesto'] == 0 || is_null($solicitud['presupuesto']))) {
            $precio = floatval($_POST['precio'] ?? 0);
            $fecha = $_POST['fecha'] ?? '';
            $hora = $_POST['hora'] ?? '';
            if ($precio <= 0 || empty($fecha)) {
                $error = 'Debes establecer precio y fecha.';
            } else {
                $update = mysqli_prepare($conn, "UPDATE solicitudes 
                                                  SET presupuesto = ?, fecha_preferida = ?, hora_preferida = ?,
                                                      precio_aceptado = 0
                                                  WHERE id = ?");
                mysqli_stmt_bind_param($update, 'dssi', $precio, $fecha, $hora, $solicitud_id);
                if (mysqli_stmt_execute($update)) {
                    $success = 'Precio establecido. El cliente debe aceptarlo para continuar.';
                    $solicitud['presupuesto'] = $precio;
                    $solicitud['fecha_preferida'] = $fecha;
                    $solicitud['hora_preferida'] = $hora;
                    $solicitud['precio_aceptado'] = 0;
                    
                    $receptor_id = $solicitud['cliente_id'];
                    $mensaje_notificacion = "El trabajador ha establecido un precio de $".number_format($precio, 2)." para tu solicitud. Por favor, revisa y acepta o rechaza.";
                    $sql_mensaje = "INSERT INTO mensajes (solicitud_id, emisor_id, receptor_id, mensaje, fecha) VALUES (?, ?, ?, ?, NOW())";
                    $stmt_mensaje = mysqli_prepare($conn, $sql_mensaje);
                    mysqli_stmt_bind_param($stmt_mensaje, 'iiis', $solicitud_id, $usuario_id, $receptor_id, $mensaje_notificacion);
                    mysqli_stmt_execute($stmt_mensaje);
                } else {
                    $error = 'Error al establecer el precio.';
                }
            }
        } else {
            $error = 'No puedes establecer precio en este estado.';
        }
    }
}

// Aceptar precio (cliente)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'aceptar_precio' && $es_cliente) {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        $error = 'Solicitud no válida (CSRF).';
    } else {
        if ($solicitud['estado'] == 'asignado' && $solicitud['precio_aceptado'] == 0 && $solicitud['presupuesto'] > 0) {
            $update = mysqli_prepare($conn, "UPDATE solicitudes SET precio_aceptado = 1 WHERE id = ?");
            mysqli_stmt_bind_param($update, 'i', $solicitud_id);
            if (mysqli_stmt_execute($update)) {
                $success = 'Has aceptado el precio. El trabajador podrá iniciar el trabajo.';
                $solicitud['precio_aceptado'] = 1;
                $receptor_id = $solicitud['trabajador_id'];
                $mensaje_notificacion = "El cliente ha aceptado el precio de $".number_format($solicitud['presupuesto'], 2).". Puedes iniciar el trabajo.";
                $sql_mensaje = "INSERT INTO mensajes (solicitud_id, emisor_id, receptor_id, mensaje, fecha) VALUES (?, ?, ?, ?, NOW())";
                $stmt_mensaje = mysqli_prepare($conn, $sql_mensaje);
                mysqli_stmt_bind_param($stmt_mensaje, 'iiis', $solicitud_id, $usuario_id, $receptor_id, $mensaje_notificacion);
                mysqli_stmt_execute($stmt_mensaje);
            } else {
                $error = 'Error al aceptar el precio.';
            }
        } else {
            $error = 'No puedes aceptar este precio.';
        }
    }
}

// Rechazar precio (cliente)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'rechazar_precio' && $es_cliente) {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        $error = 'Solicitud no válida (CSRF).';
    } else {
        if ($solicitud['estado'] == 'asignado' && $solicitud['precio_aceptado'] == 0 && $solicitud['presupuesto'] > 0) {
            $update = mysqli_prepare($conn, "UPDATE solicitudes SET estado = 'cancelado' WHERE id = ?");
            mysqli_stmt_bind_param($update, 'i', $solicitud_id);
            if (mysqli_stmt_execute($update)) {
                $success = 'Has rechazado el precio. La solicitud ha sido cancelada.';
                $solicitud['estado'] = 'cancelado';
                $receptor_id = $solicitud['trabajador_id'];
                $mensaje_notificacion = "El cliente ha rechazado el precio de $".number_format($solicitud['presupuesto'], 2).". La solicitud ha sido cancelada.";
                $sql_mensaje = "INSERT INTO mensajes (solicitud_id, emisor_id, receptor_id, mensaje, fecha) VALUES (?, ?, ?, ?, NOW())";
                $stmt_mensaje = mysqli_prepare($conn, $sql_mensaje);
                mysqli_stmt_bind_param($stmt_mensaje, 'iiis', $solicitud_id, $usuario_id, $receptor_id, $mensaje_notificacion);
                mysqli_stmt_execute($stmt_mensaje);
            } else {
                $error = 'Error al rechazar el precio.';
            }
        } else {
            $error = 'No puedes rechazar este precio.';
        }
    }
}

// Iniciar trabajo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'iniciar_trabajo' && $es_trabajador_asignado) {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        $error = 'Solicitud no válida (CSRF).';
    } else {
        if ($solicitud['estado'] == 'asignado' && $solicitud['precio_aceptado'] == 1) {
            $tiempo_estimado = trim($_POST['tiempo_estimado'] ?? '');
            if (empty($tiempo_estimado)) {
                $error = 'Debes indicar un tiempo estimado de llegada.';
            } else {
                $update = mysqli_prepare($conn, "UPDATE solicitudes 
                                                  SET estado = 'en_curso', 
                                                      tiempo_estimado = ? 
                                                  WHERE id = ?");
                mysqli_stmt_bind_param($update, 'si', $tiempo_estimado, $solicitud_id);
                if (mysqli_stmt_execute($update)) {
                    $success = 'Has iniciado el trabajo. El cliente ha sido notificado.';
                    $solicitud['estado'] = 'en_curso';
                    $solicitud['tiempo_estimado'] = $tiempo_estimado;
                    $receptor_id = $solicitud['cliente_id'];
                    $mensaje_notificacion = "El trabajador ha iniciado el trabajo. Tiempo estimado de llegada: $tiempo_estimado";
                    $sql_mensaje = "INSERT INTO mensajes (solicitud_id, emisor_id, receptor_id, mensaje, fecha) VALUES (?, ?, ?, ?, NOW())";
                    $stmt_mensaje = mysqli_prepare($conn, $sql_mensaje);
                    mysqli_stmt_bind_param($stmt_mensaje, 'iiis', $solicitud_id, $usuario_id, $receptor_id, $mensaje_notificacion);
                    mysqli_stmt_execute($stmt_mensaje);
                } else {
                    $error = 'Error al iniciar el trabajo.';
                }
            }
        } else {
            $error = 'No puedes iniciar este trabajo porque el cliente no ha aceptado el precio o la solicitud no está en estado asignado.';
        }
    }
}

// Completar trabajo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'completar_trabajo' && $es_trabajador_asignado) {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        $error = 'Solicitud no válida (CSRF).';
    } else {
        if ($solicitud['estado'] == 'en_curso' && $solicitud['precio_aceptado'] == 1) {
            $update = mysqli_prepare($conn, "UPDATE solicitudes SET estado = 'completado', fecha_completado = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($update, 'i', $solicitud_id);
            if (mysqli_stmt_execute($update)) {
                $success = 'Trabajo completado. El cliente podrá calificarte.';
                $solicitud['estado'] = 'completado';
                $receptor_id = $solicitud['cliente_id'];
                $mensaje_notificacion = "El trabajo ha sido completado. Por favor, califica al trabajador.";
                $sql_mensaje = "INSERT INTO mensajes (solicitud_id, emisor_id, receptor_id, mensaje, fecha) VALUES (?, ?, ?, ?, NOW())";
                $stmt_mensaje = mysqli_prepare($conn, $sql_mensaje);
                mysqli_stmt_bind_param($stmt_mensaje, 'iiis', $solicitud_id, $usuario_id, $receptor_id, $mensaje_notificacion);
                mysqli_stmt_execute($stmt_mensaje);
            } else {
                $error = 'Error al completar el trabajo.';
            }
        } else {
            $error = 'El trabajo no está en curso o el precio no ha sido aceptado.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalle Solicitud #<?= $solicitud_id ?> - Fixi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.0/dist/cyborg/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .dashboard-content { position: relative; z-index: 1; }
        .foto-perfil { width: 80px; height: 80px; object-fit: cover; border: 2px solid #00d4ff; }
        #map { height: 300px; border-radius: 8px; margin-top: 10px; }
        .modal-aceptar .modal-content { background: #0a111e; border: 1px solid #00d4ff; }
        .btn-aceptar { background: #00d4ff; color: #030507; border: none; }
        .btn-aceptar:hover { background: #00b8e6; color: #030507; }
        .btn-pagar { background: #28a745; color: #fff; border: none; }
        .btn-pagar:hover { background: #218838; color: #fff; }
        .info-box { background: #0a111e; border-radius: 8px; padding: 15px; margin-top: 10px; border-left: 4px solid #00d4ff; }
        .btn-chatear { background: #17a2b8; color: #fff; }
        .btn-chatear:hover { background: #138496; color: #fff; }
        .tiempo-estimado { font-size: 1.2rem; font-weight: bold; color: #00d4ff; }
        .btn-precio { background: #ffc107; color: #000; border: none; }
        .btn-precio:hover { background: #e0a800; color: #000; }
        .trabajador-en-camino {
            background: rgba(0, 212, 255, 0.15);
            border: 1px solid #00d4ff;
            border-radius: 8px;
            padding: 15px;
            margin-top: 10px;
            text-align: center;
        }
        .trabajador-en-camino i { font-size: 2rem; color: #00d4ff; animation: pulse 1.5s ease-in-out infinite; }
        @keyframes pulse {
            0% { opacity: 0.4; transform: scale(1); }
            50% { opacity: 1; transform: scale(1.1); }
            100% { opacity: 0.4; transform: scale(1); }
        }
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
                <a href="<?= esCliente() ? 'cliente_dashboard.php' : 'trabajador_dashboard.php' ?>" class="btn btn-outline-info btn-sm me-2">
                    <i class="fas fa-arrow-left me-1"></i>Volver
                </a>
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
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <!-- Detalle -->
                <div class="card mb-4">
                    <div class="card-header"><h5 class="mb-0"><i class="fas fa-file-alt text-primary me-2"></i>Solicitud #<?= $solicitud_id ?></h5></div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6"><strong>Servicio:</strong> <?= htmlspecialchars($solicitud['servicio'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="col-md-6"><strong>Urgencia:</strong> 
                                <?php
                                $urgencia_colores = ['baja'=>'secondary', 'media'=>'info', 'alta'=>'warning', 'emergencia'=>'danger'];
                                $color = $urgencia_colores[$solicitud['urgencia'] ?? 'media'] ?? 'secondary';
                                ?>
                                <span class="badge bg-<?= $color ?>"><?= strtoupper($solicitud['urgencia'] ?? 'Media') ?></span>
                            </div>
                        </div>
                        <div class="mt-2"><strong>Descripción:</strong><p><?= nl2br(htmlspecialchars($solicitud['descripcion'] ?? '', ENT_QUOTES, 'UTF-8')) ?></p></div>
                        <div><strong>Dirección:</strong> <?= htmlspecialchars($solicitud['direccion'] ?? 'No especificada', ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if ($solicitud['presupuesto'] && $solicitud['presupuesto'] > 0): ?>
                            <div><strong>Precio propuesto:</strong> $<?= number_format($solicitud['presupuesto'], 2) ?>
                                <?php if ($solicitud['precio_aceptado'] == 1): ?>
                                    <span class="badge bg-success ms-2"><i class="fas fa-check"></i> Aceptado por el cliente</span>
                                <?php elseif ($solicitud['precio_aceptado'] == 0 && $solicitud['estado'] == 'asignado'): ?>
                                    <span class="badge bg-warning text-dark ms-2">Esperando aceptación del cliente</span>
                                <?php elseif ($solicitud['estado'] == 'en_curso' || $solicitud['estado'] == 'completado'): ?>
                                    <span class="badge bg-success ms-2"><i class="fas fa-check"></i> Aceptado</span>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div><strong>Precio:</strong> <span class="text-muted">No definido aún</span></div>
                        <?php endif; ?>
                        <?php if ($solicitud['fecha_preferida']): ?>
                            <div><strong>Fecha acordada:</strong> <?= date('d/m/Y', strtotime($solicitud['fecha_preferida'])) ?></div>
                        <?php endif; ?>
                        <?php if ($solicitud['hora_preferida']): ?>
                            <div><strong>Hora acordada:</strong> <?= date('h:i A', strtotime($solicitud['hora_preferida'])) ?></div>
                        <?php endif; ?>
                        <?php if ($solicitud['tiempo_estimado'] && ($solicitud['estado'] == 'en_curso' || $solicitud['estado'] == 'completado')): ?>
                            <div class="info-box">
                                <i class="fas fa-clock text-primary me-2"></i>
                                <strong>Tiempo estimado de llegada:</strong> 
                                <span class="tiempo-estimado"><?= htmlspecialchars($solicitud['tiempo_estimado'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($solicitud['metodo_pago']): ?>
                            <div class="mt-2"><strong>Método de pago:</strong> 
                                <span class="badge bg-info"><?= strtoupper($solicitud['metodo_pago']) ?></span>
                                <?php if ($solicitud['metodo_pago'] === 'tarjeta'): ?>
                                    <?php if ($solicitud['estado_pago'] === 'pagado'): ?>
                                        <span class="badge bg-success ms-2"><i class="fas fa-check-circle"></i> Pagado</span>
                                    <?php elseif ($solicitud['estado_pago'] === 'fallido'): ?>
                                        <span class="badge bg-danger ms-2">Pago fallido</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark ms-2">Pendiente de pago</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($solicitud['metodo_pago'] === 'tarjeta' && $solicitud['estado_pago'] !== 'pagado' && $solicitud['presupuesto'] > 0 && $es_cliente && ($solicitud['estado'] == 'asignado' || $solicitud['estado'] == 'en_curso')): ?>
                                    <a href="pago.php?solicitud=<?= $solicitud_id ?>" class="btn btn-pagar btn-sm ms-2"><i class="fas fa-credit-card me-1"></i>Pagar con tarjeta</a>
                                <?php elseif ($solicitud['metodo_pago'] === 'efectivo' && $solicitud['presupuesto'] > 0 && $es_cliente): ?>
                                    <span class="text-success ms-2"><i class="fas fa-check-circle"></i> Pagarás en efectivo al trabajador</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($solicitud['instrucciones']): ?>
                            <div class="mt-2"><strong>Instrucciones adicionales:</strong><p><?= nl2br(htmlspecialchars($solicitud['instrucciones'], ENT_QUOTES, 'UTF-8')) ?></p></div>
                        <?php endif; ?>
                        <div class="mt-2">
                            <strong>Estado:</strong>
                            <span class="badge bg-<?= ['pendiente'=>'warning','asignado'=>'info','en_curso'=>'primary','completado'=>'success','cancelado'=>'danger'][$solicitud['estado']] ?? 'secondary' ?>"><?= strtoupper($solicitud['estado']) ?></span>
                            <?php if ($solicitud['estado'] == 'en_curso'): ?>
                                <span class="badge bg-warning text-dark ms-2"><i class="fas fa-truck"></i> En camino</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($solicitud['imagenes']): ?>
                            <hr>
                            <h6>Imágenes</h6>
                            <div class="row">
                                <?php foreach (explode(',', $solicitud['imagenes']) as $img): ?>
                                    <div class="col-md-4 mb-3">
                                        <img src="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>" class="img-fluid rounded" alt="Imagen">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($solicitud['estado'] == 'en_curso' || $solicitud['estado'] == 'completado'): ?>
                            <?php if (!empty($solicitud['latitud']) && !empty($solicitud['longitud'])): ?>
                                <div id="map" style="height:300px; margin-top:15px;"></div>
                                <div class="trabajador-en-camino">
                                    <i class="fas fa-truck"></i>
                                    <p class="mb-0 mt-2"><strong>El trabajador está en camino</strong></p>
                                    <?php if ($solicitud['tiempo_estimado']): ?>
                                        <small>Tiempo estimado: <?= htmlspecialchars($solicitud['tiempo_estimado'], ENT_QUOTES, 'UTF-8') ?></small>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info mt-3">
                                    <i class="fas fa-info-circle me-2"></i>
                                    El trabajador ha iniciado el trabajo.
                                    <?php if ($solicitud['tiempo_estimado']): ?>
                                        <br><strong>Tiempo estimado:</strong> <?= htmlspecialchars($solicitud['tiempo_estimado'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Cliente -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-user text-primary me-2"></i>Cliente</h5>
                        <?php if ($es_trabajador_asignado && $solicitud['trabajador_id']): ?>
                            <a href="mensajes_detalle.php?solicitud_id=<?= $solicitud_id ?>" class="btn btn-chatear btn-sm"><i class="fas fa-comment me-1"></i>Chatear con el cliente</a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-2 text-center">
                                <?php if (!empty($solicitud['cliente_foto'])): ?>
                                    <img src="<?= htmlspecialchars($solicitud['cliente_foto'], ENT_QUOTES, 'UTF-8') ?>" class="rounded-circle img-fluid foto-perfil">
                                <?php else: ?>
                                    <i class="fas fa-user-circle fa-4x text-muted"></i>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-10">
                                <h5><?= htmlspecialchars(($solicitud['cliente_nombre'] ?? '') . ' ' . ($solicitud['cliente_apellidos'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h5>
                                <p class="text-muted mb-1"><i class="fas fa-envelope me-1"></i> <?= htmlspecialchars($solicitud['cliente_email'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="text-muted mb-1"><i class="fas fa-phone me-1"></i> <?= htmlspecialchars($solicitud['cliente_telefono'] ?? 'No disponible', ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Trabajador -->
                <?php if ($solicitud['trabajador_id']): ?>
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-user-tie text-primary me-2"></i>Trabajador</h5>
                        <?php if ($es_cliente && $solicitud['trabajador_id']): ?>
                            <a href="mensajes_detalle.php?solicitud_id=<?= $solicitud_id ?>" class="btn btn-chatear btn-sm"><i class="fas fa-comment me-1"></i>Chatear con el trabajador</a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-2 text-center">
                                <?php if (!empty($solicitud['trabajador_foto'])): ?>
                                    <img src="<?= htmlspecialchars($solicitud['trabajador_foto'], ENT_QUOTES, 'UTF-8') ?>" class="rounded-circle img-fluid foto-perfil">
                                <?php else: ?>
                                    <i class="fas fa-user-circle fa-4x text-muted"></i>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-10">
                                <h5><?= htmlspecialchars(($solicitud['trabajador_nombre'] ?? '') . ' ' . ($solicitud['trabajador_apellidos'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h5>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Acciones -->
                <div class="card">
                    <div class="card-header"><h5 class="mb-0"><i class="fas fa-cogs text-primary me-2"></i>Acciones</h5></div>
                    <div class="card-body">
                        <?php if ($es_trabajador_puede_aceptar): ?>
                            <button type="button" class="btn btn-success btn-lg w-100 mb-2" data-bs-toggle="modal" data-bs-target="#modalAceptar">
                                <i class="fas fa-check me-2"></i>Aceptar solicitud y establecer precio
                            </button>
                            <p class="text-muted small">Establece el precio, fecha y hora acordados. El cliente deberá aceptar.</p>
                        <?php endif; ?>

                        <?php if ($es_trabajador_asignado && $solicitud['estado'] == 'asignado' && ($solicitud['presupuesto'] == 0 || is_null($solicitud['presupuesto']))): ?>
                            <button type="button" class="btn btn-warning btn-lg w-100 mb-2" data-bs-toggle="modal" data-bs-target="#modalEstablecerPrecio">
                                <i class="fas fa-dollar-sign me-2"></i>Establecer precio
                            </button>
                            <p class="text-muted small">Define el precio y la fecha acordada. El cliente deberá aceptar.</p>
                        <?php endif; ?>

                        <?php if ($es_cliente && $solicitud['estado'] == 'asignado' && $solicitud['precio_aceptado'] == 0 && $solicitud['presupuesto'] > 0): ?>
                            <div class="d-grid gap-2">
                                <form method="POST" action="">
                                    <input type="hidden" name="accion" value="aceptar_precio">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                    <button type="submit" class="btn btn-primary btn-lg w-100 mb-2" onclick="return confirm('¿Aceptas el precio de $<?= number_format($solicitud['presupuesto'], 2) ?>?')">
                                        <i class="fas fa-check-circle me-2"></i>Aceptar precio ($<?= number_format($solicitud['presupuesto'], 2) ?>)
                                    </button>
                                </form>
                                <form method="POST" action="">
                                    <input type="hidden" name="accion" value="rechazar_precio">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                    <button type="submit" class="btn btn-outline-danger w-100" onclick="return confirm('¿Rechazar el precio y cancelar la solicitud?')">
                                        <i class="fas fa-times me-2"></i>Rechazar precio y cancelar
                                    </button>
                                </form>
                            </div>
                            <p class="text-muted small">Al aceptar, el trabajador podrá iniciar el trabajo. Al rechazar, la solicitud se cancelará.</p>
                        <?php endif; ?>

                        <?php if ($es_trabajador_asignado && $solicitud['estado'] == 'asignado' && $solicitud['precio_aceptado'] == 1): ?>
                            <button type="button" class="btn btn-primary btn-lg w-100 mb-2" data-bs-toggle="modal" data-bs-target="#modalIniciar">
                                <i class="fas fa-play me-2"></i>Iniciar trabajo
                            </button>
                            <p class="text-muted small">Al iniciar, se notificará al cliente con el tiempo estimado.</p>
                        <?php endif; ?>

                        <?php if ($es_trabajador_asignado && $solicitud['estado'] == 'en_curso'): ?>
                            <form method="POST" action="">
                                <input type="hidden" name="accion" value="completar_trabajo">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                <button type="submit" class="btn btn-success btn-lg w-100 mb-2" onclick="return confirm('¿Completar este trabajo?')">
                                    <i class="fas fa-check-double me-2"></i>Marcar como completado
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($es_cliente && $solicitud['estado'] == 'completado'): ?>
                            <?php
                            $check_calif = mysqli_prepare($conn, "SELECT id FROM calificaciones WHERE solicitud_id = ?");
                            mysqli_stmt_bind_param($check_calif, 'i', $solicitud_id);
                            mysqli_stmt_execute($check_calif);
                            mysqli_stmt_store_result($check_calif);
                            $ya_calificado = mysqli_stmt_num_rows($check_calif) > 0;
                            ?>
                            <?php if (!$ya_calificado): ?>
                                <button type="button" class="btn btn-warning btn-lg w-100 mb-2" data-bs-toggle="modal" data-bs-target="#calificarModal">
                                    <i class="fas fa-star me-2"></i>Calificar al trabajador
                                </button>
                            <?php else: ?>
                                <div class="alert alert-success">Ya calificaste este trabajo.</div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if (empty($solicitud['trabajador_id']) && $solicitud['estado'] == 'pendiente' && $es_cliente): ?>
                            <a href="?cancelar=<?= $solicitud_id ?>" class="btn btn-outline-danger w-100" onclick="return confirm('¿Cancelar solicitud?')">
                                <i class="fas fa-times me-2"></i>Cancelar solicitud
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($solicitud['trabajador_id']): ?>
                    <div class="text-center mt-3">
                        <a href="mensajes_detalle.php?solicitud_id=<?= $solicitud_id ?>" class="btn btn-outline-primary">
                            <i class="fas fa-comments me-2"></i>Ver conversación completa
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modales -->
    <div class="modal fade" id="modalAceptar" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content modal-aceptar">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-handshake text-primary me-2"></i>Aceptar solicitud</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="aceptar_con_precio">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <p>Establece el precio y la fecha/hora para este trabajo.</p>
                        <div class="mb-3">
                            <label for="precio" class="form-label">Precio acordado (MXN) *</label>
                            <input type="number" class="form-control" id="precio" name="precio" placeholder="Ej. 500" min="0" step="0.01" required>
                        </div>
                        <div class="mb-3">
                            <label for="fecha" class="form-label">Fecha acordada *</label>
                            <input type="date" class="form-control" id="fecha" name="fecha" required>
                        </div>
                        <div class="mb-3">
                            <label for="hora" class="form-label">Hora acordada (opcional)</label>
                            <input type="time" class="form-control" id="hora" name="hora">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-aceptar">Aceptar y enviar precio</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalEstablecerPrecio" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content modal-aceptar">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-dollar-sign text-warning me-2"></i>Establecer precio</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="establecer_precio">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <p>Define el precio y la fecha acordada con el cliente.</p>
                        <div class="mb-3">
                            <label for="precio2" class="form-label">Precio (MXN) *</label>
                            <input type="number" class="form-control" id="precio2" name="precio" placeholder="Ej. 500" min="0" step="0.01" required>
                        </div>
                        <div class="mb-3">
                            <label for="fecha2" class="form-label">Fecha acordada *</label>
                            <input type="date" class="form-control" id="fecha2" name="fecha" required>
                        </div>
                        <div class="mb-3">
                            <label for="hora2" class="form-label">Hora acordada (opcional)</label>
                            <input type="time" class="form-control" id="hora2" name="hora">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-warning">Establecer precio</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalIniciar" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content bg-dark-custom">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-play text-primary me-2"></i>Iniciar trabajo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="iniciar_trabajo">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <p>Indica el tiempo estimado de llegada al lugar del cliente.</p>
                        <div class="mb-3">
                            <label for="tiempo_estimado" class="form-label">Tiempo estimado *</label>
                            <input type="text" class="form-control" id="tiempo_estimado" name="tiempo_estimado" placeholder="Ej. 30 minutos, 14:30, 1 hora" required>
                            <small class="text-muted">Ejemplo: "20 minutos", "Llegaré a las 3:00 PM"</small>
                        </div>
                        <div class="alert alert-info small">
                            <i class="fas fa-info-circle me-1"></i> Al iniciar, se notificará al cliente con el tiempo estimado.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Iniciar trabajo</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="calificarModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content bg-dark-custom">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-star text-warning me-2"></i>Calificar al trabajador</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="cliente_dashboard.php">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="calificar">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="solicitud_id" value="<?= $solicitud_id ?>">
                        <p><strong>Servicio:</strong> <?= htmlspecialchars($solicitud['servicio'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                        <div class="mb-3">
                            <label for="puntuacion" class="form-label">Puntuación</label>
                            <select class="form-select" id="puntuacion" name="puntuacion" required>
                                <option value="5">5 - Excelente</option>
                                <option value="4">4 - Bueno</option>
                                <option value="3">3 - Regular</option>
                                <option value="2">2 - Malo</option>
                                <option value="1">1 - Pésimo</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="comentario" class="form-label">Comentario</label>
                            <textarea class="form-control" id="comentario" name="comentario" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Enviar calificación</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        <?php if (!empty($solicitud['latitud']) && !empty($solicitud['longitud'])): ?>
            var map = L.map('map').setView([<?= $solicitud['latitud'] ?>, <?= $solicitud['longitud'] ?>], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);
            var marker = L.marker([<?= $solicitud['latitud'] ?>, <?= $solicitud['longitud'] ?>])
                .addTo(map)
                .bindPopup('Ubicación del servicio');
            L.circle([<?= $solicitud['latitud'] ?>, <?= $solicitud['longitud'] ?>], {
                color: '#00d4ff',
                fillColor: '#00d4ff',
                fillOpacity: 0.1,
                radius: 200
            }).addTo(map);
        <?php endif; ?>
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>