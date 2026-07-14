<?php
require_once 'config/database.php';
require_once 'config/auth.php';

if (!estaLogueado() || !esTrabajador()) redirigir('login.php');

$usuario_id = $_SESSION['usuario_id'];
$usuario_data = obtenerDatosUsuario($conn, $usuario_id);

if (!perfilCompleto($usuario_data)) redirigir('perfil_usuario.php?primeravez=si');

$error = '';
$success = '';
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'inicio';
$especialidad = $usuario_data['especialidad'] ?? '';
$csrf_token = generarTokenCSRF();

// Subir trabajo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'subir_trabajo') {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        $error = 'Solicitud no válida (CSRF).';
    } else {
        $titulo = trim($_POST['titulo'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $fecha_realizado = trim($_POST['fecha_realizado'] ?? '');
        if (empty($titulo) || empty($descripcion) || empty($fecha_realizado)) {
            $error = 'Título, descripción y fecha son obligatorios.';
        } else {
            $imagen_path = '';
            if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $_FILES['imagen']['tmp_name']);
                finfo_close($finfo);
                $allowed = ['image/jpeg', 'image/png', 'image/webp'];
                if (in_array($mime, $allowed) && $_FILES['imagen']['size'] <= 5*1024*1024) {
                    $carpeta_destino = 'uploads/trabajos/';
                    if (!is_dir($carpeta_destino)) mkdir($carpeta_destino, 0777, true);
                    $ext = pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION);
                    $ruta = $carpeta_destino . uniqid() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    if (move_uploaded_file($_FILES['imagen']['tmp_name'], $ruta)) {
                        $imagen_path = $ruta;
                    } else {
                        $error = 'Error al subir la imagen.';
                    }
                } else {
                    $error = 'Formato de imagen no permitido o tamaño excesivo (máx. 5MB).';
                }
            } else {
                $error = 'Debes seleccionar una imagen para el trabajo.';
            }
            if (empty($error)) {
                $sql = "INSERT INTO trabajos_realizados (trabajador_id, titulo, descripcion, imagen, fecha_realizado) VALUES (?, ?, ?, ?, ?)";
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, 'issss', $usuario_id, $titulo, $descripcion, $imagen_path, $fecha_realizado);
                if (mysqli_stmt_execute($stmt)) {
                    $success = 'Trabajo publicado exitosamente.';
                    $_POST = [];
                } else {
                    $error = 'Error al publicar. Intenta más tarde.';
                    error_log('Error trabajo: ' . mysqli_error($conn));
                }
            }
        }
    }
}

// Eliminar trabajo
if (isset($_GET['eliminar_trabajo']) && is_numeric($_GET['eliminar_trabajo'])) {
    $trabajo_id = intval($_GET['eliminar_trabajo']);
    $check = mysqli_prepare($conn, "SELECT id, imagen FROM trabajos_realizados WHERE id = ? AND trabajador_id = ?");
    mysqli_stmt_bind_param($check, 'ii', $trabajo_id, $usuario_id);
    mysqli_stmt_execute($check);
    mysqli_stmt_store_result($check);
    if (mysqli_stmt_num_rows($check) > 0) {
        $stmt_img = mysqli_prepare($conn, "SELECT imagen FROM trabajos_realizados WHERE id = ?");
        mysqli_stmt_bind_param($stmt_img, 'i', $trabajo_id);
        mysqli_stmt_execute($stmt_img);
        $result_img = mysqli_stmt_get_result($stmt_img);
        $row_img = mysqli_fetch_assoc($result_img);
        if (!empty($row_img['imagen']) && file_exists($row_img['imagen'])) unlink($row_img['imagen']);
        $delete = mysqli_prepare($conn, "DELETE FROM trabajos_realizados WHERE id = ? AND trabajador_id = ?");
        mysqli_stmt_bind_param($delete, 'ii', $trabajo_id, $usuario_id);
        if (mysqli_stmt_execute($delete)) {
            $success = 'Trabajo eliminado.';
            header("Location: trabajador_dashboard.php?tab=trabajos");
            exit;
        } else {
            $error = 'Error al eliminar.';
        }
    } else {
        $error = 'No tienes permiso para eliminar este trabajo.';
    }
}

// Aceptar solicitud directa
if (isset($_GET['aceptar']) && is_numeric($_GET['aceptar'])) {
    $solicitud_id = intval($_GET['aceptar']);
    $check = mysqli_prepare($conn, "SELECT id FROM solicitudes WHERE id = ? AND estado = 'pendiente' AND trabajador_id IS NULL");
    mysqli_stmt_bind_param($check, 'i', $solicitud_id);
    mysqli_stmt_execute($check);
    mysqli_stmt_store_result($check);
    if (mysqli_stmt_num_rows($check) > 0) {
        $update = mysqli_prepare($conn, "UPDATE solicitudes SET trabajador_id = ?, estado = 'asignado', fecha_asignacion = NOW(), precio_aceptado = 0 WHERE id = ?");
        mysqli_stmt_bind_param($update, 'ii', $usuario_id, $solicitud_id);
        if (mysqli_stmt_execute($update)) {
            $success = 'Solicitud aceptada. Establece el precio en el detalle.';
            header("Location: trabajador_dashboard.php?tab=solicitudes");
            exit;
        } else {
            $error = 'Error al aceptar.';
        }
    } else {
        $error = 'No disponible.';
    }
}

// Cambiar estado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'cambiar_estado') {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        $error = 'Solicitud no válida (CSRF).';
    } else {
        $solicitud_id = intval($_POST['solicitud_id'] ?? 0);
        $nuevo_estado = $_POST['nuevo_estado'] ?? '';
        $estados_validos = ['en_curso', 'completado', 'cancelado'];
        if (in_array($nuevo_estado, $estados_validos)) {
            $check = mysqli_prepare($conn, "SELECT id, precio_aceptado FROM solicitudes WHERE id = ? AND trabajador_id = ? AND estado NOT IN ('completado', 'cancelado')");
            mysqli_stmt_bind_param($check, 'ii', $solicitud_id, $usuario_id);
            mysqli_stmt_execute($check);
            $result_check = mysqli_stmt_get_result($check);
            $row_check = mysqli_fetch_assoc($result_check);
            if ($row_check) {
                if ($nuevo_estado === 'en_curso' && $row_check['precio_aceptado'] == 0) {
                    $error = 'El cliente aún no ha aceptado el precio. No puedes iniciar el trabajo.';
                } else {
                    if ($nuevo_estado === 'completado') {
                        $update = mysqli_prepare($conn, "UPDATE solicitudes SET estado = ?, fecha_completado = NOW() WHERE id = ?");
                    } else {
                        $update = mysqli_prepare($conn, "UPDATE solicitudes SET estado = ? WHERE id = ?");
                    }
                    mysqli_stmt_bind_param($update, 'si', $nuevo_estado, $solicitud_id);
                    if (mysqli_stmt_execute($update)) {
                        $success = 'Estado actualizado.';
                        header("Location: trabajador_dashboard.php?tab=solicitudes");
                        exit;
                    } else {
                        $error = 'Error al actualizar.';
                    }
                }
            } else {
                $error = 'No puedes modificar esta solicitud.';
            }
        } else {
            $error = 'Estado no válido.';
        }
    }
}

// Obtener trabajos realizados
$sql_trabajos = "SELECT * FROM trabajos_realizados WHERE trabajador_id = ? ORDER BY fecha_registro DESC";
$stmt_trabajos = mysqli_prepare($conn, $sql_trabajos);
mysqli_stmt_bind_param($stmt_trabajos, 'i', $usuario_id);
mysqli_stmt_execute($stmt_trabajos);
$result_trabajos = mysqli_stmt_get_result($stmt_trabajos);
$trabajos_realizados = mysqli_fetch_all($result_trabajos, MYSQLI_ASSOC);

// Mis solicitudes
$sql_mis = "SELECT s.*, u.nombre as cliente_nombre, u.apellidos as cliente_apellidos, u.foto as cliente_foto,
            (SELECT COUNT(*) FROM mensajes WHERE solicitud_id = s.id AND receptor_id = ? AND leido = 0) as mensajes_no_leidos
            FROM solicitudes s 
            JOIN usuarios u ON s.cliente_id = u.id 
            WHERE s.trabajador_id = ? 
            ORDER BY s.fecha_creacion DESC";
$stmt_mis = mysqli_prepare($conn, $sql_mis);
mysqli_stmt_bind_param($stmt_mis, 'ii', $usuario_id, $usuario_id);
mysqli_stmt_execute($stmt_mis);
$result_mis = mysqli_stmt_get_result($stmt_mis);
$mis_solicitudes = mysqli_fetch_all($result_mis, MYSQLI_ASSOC);

// Disponibles
$sql_disponibles = "SELECT s.*, u.nombre as cliente_nombre, u.apellidos as cliente_apellidos, u.foto as cliente_foto
                    FROM solicitudes s 
                    JOIN usuarios u ON s.cliente_id = u.id 
                    WHERE s.estado = 'pendiente' AND s.trabajador_id IS NULL";
if (!empty($especialidad)) {
    $sql_disponibles .= " AND s.servicio = ?";
    $stmt_disp = mysqli_prepare($conn, $sql_disponibles);
    mysqli_stmt_bind_param($stmt_disp, 's', $especialidad);
} else {
    $stmt_disp = mysqli_prepare($conn, $sql_disponibles);
}
mysqli_stmt_execute($stmt_disp);
$result_disponibles = mysqli_stmt_get_result($stmt_disp);
$disponibles = mysqli_fetch_all($result_disponibles, MYSQLI_ASSOC);

// Calificaciones
$sql_calif = "SELECT c.*, u.nombre as cliente_nombre 
              FROM calificaciones c 
              JOIN usuarios u ON c.cliente_id = u.id 
              WHERE c.trabajador_id = ? 
              ORDER BY c.fecha DESC";
$stmt_calif = mysqli_prepare($conn, $sql_calif);
mysqli_stmt_bind_param($stmt_calif, 'i', $usuario_id);
mysqli_stmt_execute($stmt_calif);
$result_calif = mysqli_stmt_get_result($stmt_calif);
$calificaciones = mysqli_fetch_all($result_calif, MYSQLI_ASSOC);

$total_asignadas = count($mis_solicitudes);
$total_disponibles = count($disponibles);
$total_completadas = 0;
$promedio = 0;
if (count($calificaciones) > 0) {
    $suma = array_sum(array_column($calificaciones, 'puntuacion'));
    $promedio = round($suma / count($calificaciones), 1);
}
foreach ($mis_solicitudes as $s) if ($s['estado'] === 'completado') $total_completadas++;

$sql_total_no_leidos = "SELECT COUNT(*) as total FROM mensajes WHERE receptor_id = ? AND leido = 0";
$stmt_total = mysqli_prepare($conn, $sql_total_no_leidos);
mysqli_stmt_bind_param($stmt_total, 'i', $usuario_id);
mysqli_stmt_execute($stmt_total);
$result_total = mysqli_stmt_get_result($stmt_total);
$row_total = mysqli_fetch_assoc($result_total);
$total_mensajes_no_leidos = $row_total['total'] ?? 0;

$solicitudes_recientes = array_slice($mis_solicitudes, 0, 3);
$sql_populares = "SELECT servicio, COUNT(*) as total FROM solicitudes WHERE estado != 'cancelado' GROUP BY servicio ORDER BY total DESC LIMIT 10";
$result_populares = mysqli_query($conn, $sql_populares);
$populares = mysqli_fetch_all($result_populares, MYSQLI_ASSOC);

$imagenes_servicios = [
    'Plomería' => 'https://images.unsplash.com/photo-1607472586893-edb57bdc0e39?w=400&h=300&fit=crop',
    'Electricidad' => 'https://images.unsplash.com/photo-1621905251189-08b45d6a269e?w=400&h=300&fit=crop',
    'Carpintería' => 'https://images.unsplash.com/photo-1581147036325-7ab3febf6f01?w=400&h=300&fit=crop',
    'Pintura' => 'https://images.unsplash.com/photo-1589939705384-5185137a7f0f?w=400&h=300&fit=crop',
    'Jardinería' => 'https://images.unsplash.com/photo-1526304640581-d334cdbbf45e?w=400&h=300&fit=crop',
    'Albañilería' => 'https://images.unsplash.com/photo-1503387762-592deb58ef4e?w=400&h=300&fit=crop',
    'Herrería' => 'https://images.unsplash.com/photo-1581091226033-d5c48150dbaa?w=400&h=300&fit=crop',
    'Mantenimiento General' => 'https://images.unsplash.com/photo-1581578731548-c64695cc6952?w=400&h=300&fit=crop',
    'Electrodomésticos' => 'https://images.unsplash.com/photo-1574267432553-4b4628081c31?w=400&h=300&fit=crop'
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Trabajador - Fixi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.0/dist/cyborg/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .dashboard-content { position: relative; z-index: 1; }
        .card-trabajador { transition: transform 0.2s; }
        .card-trabajador:hover { transform: translateY(-5px); box-shadow: 0 8px 30px rgba(0,212,255,0.15); }
        .nav-link.active { color: #00d4ff !important; border-bottom: 2px solid #00d4ff; }
        .nav-link { color: #8899aa; border-bottom: 2px solid transparent; }
        .nav-link:hover { color: #00d4ff; border-bottom: 2px solid #00d4ff; }
        .stats-card-custom {
            background: rgba(10, 17, 30, 0.85);
            backdrop-filter: blur(10px);
            border: 1px solid #1a2a3f;
            border-radius: 16px;
            padding: 20px 15px;
            text-align: center;
            transition: transform 0.3s, box-shadow 0.3s;
            height: 100%;
        }
        .stats-card-custom:hover { transform: translateY(-5px); box-shadow: 0 12px 40px rgba(0,212,255,0.15); }
        .stats-card-custom .icon-circle {
            width: 60px; height: 60px; border-radius: 50%;
            background: rgba(0, 212, 255, 0.1);
            display: inline-flex; align-items: center; justify-content: center;
            margin-bottom: 10px; color: #00d4ff; font-size: 1.8rem;
        }
        .stats-card-custom .stats-number { font-size: 2.4rem; font-weight: 300; color: #fff; line-height: 1.2; }
        .stats-card-custom .stats-label { color: #8899aa; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 1px; margin-top: 5px; }
        .stats-card-custom .stats-sub { color: #00d4ff; font-size: 0.8rem; margin-top: 5px; }
        .solicitud-reciente { border-left: 3px solid #00d4ff; padding-left: 15px; margin-bottom: 15px; }
        .foto-cliente { width: 40px; height: 40px; object-fit: cover; border: 2px solid #00d4ff; }
        .carousel-populares .carousel-item { height: 250px; }
        .carousel-populares .carousel-item img { height: 100%; object-fit: cover; border-radius: 12px; }
        .trabajo-item img { width: 100%; height: 200px; object-fit: cover; border-radius: 8px; }
        @media (max-width: 768px) {
            .stats-card-custom .stats-number { font-size: 1.8rem; }
            .carousel-populares .carousel-item { height: 180px; }
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
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarFixi">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarFixi">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item"><a class="nav-link <?= $tab === 'inicio' ? 'active' : '' ?>" href="?tab=inicio"><i class="fas fa-home me-1"></i>Inicio</a></li>
                    <li class="nav-item"><a class="nav-link <?= $tab === 'solicitudes' ? 'active' : '' ?>" href="?tab=solicitudes"><i class="fas fa-tasks me-1"></i>Mis Solicitudes</a></li>
                    <li class="nav-item"><a class="nav-link <?= $tab === 'disponibles' ? 'active' : '' ?>" href="?tab=disponibles"><i class="fas fa-clipboard-list me-1"></i>Disponibles</a></li>
                    <li class="nav-item"><a class="nav-link <?= $tab === 'trabajos' ? 'active' : '' ?>" href="?tab=trabajos"><i class="fas fa-images me-1"></i>Mis Trabajos</a></li>
                    <li class="nav-item"><a class="nav-link <?= $tab === 'calificaciones' ? 'active' : '' ?>" href="?tab=calificaciones"><i class="fas fa-star me-1"></i>Calificaciones</a></li>
                    <li class="nav-item"><a class="nav-link" href="mensajes.php"><i class="fas fa-envelope me-1"></i>Mis Mensajes</a></li>
                </ul>
                <div class="d-flex align-items-center">
                    <a href="perfil_usuario.php" class="btn btn-outline-info btn-sm me-2"><i class="fas fa-user-edit me-1"></i>Mi Perfil</a>
                    <?php if ($total_mensajes_no_leidos > 0): ?>
                        <span class="badge bg-danger rounded-pill me-2"><?= $total_mensajes_no_leidos ?></span>
                    <?php endif; ?>
                    <span class="user-info me-2">
                        <i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['usuario_nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                        <span class="badge bg-primary ms-1">Trabajador</span>
                    </span>
                    <a href="logout.php" class="btn btn-outline-danger btn-sm">CERRAR SESIÓN</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container py-4 dashboard-content" style="margin-top: 60px;">
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($tab === 'inicio'): ?>
            <div class="row mb-4">
                <div class="col">
                    <h1 class="display-6">Bienvenido, <?= htmlspecialchars($_SESSION['usuario_nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?></h1>
                    <?php if (!empty($especialidad)): ?>
                        <p class="text-muted">Tu especialidad: <strong><?= htmlspecialchars($especialidad, ENT_QUOTES, 'UTF-8') ?></strong> | <a href="perfil_usuario.php">Cambiar</a></p>
                    <?php else: ?>
                        <p class="text-muted">No has definido especialidad. <a href="perfil_usuario.php">Configura tu perfil</a></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-3 col-6">
                    <div class="stats-card-custom">
                        <div class="icon-circle"><i class="fas fa-clipboard-list"></i></div>
                        <div class="stats-number"><?= $total_asignadas ?></div>
                        <div class="stats-label">Asignadas</div>
                        <div class="stats-sub">Tus solicitudes</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stats-card-custom">
                        <div class="icon-circle"><i class="fas fa-hourglass-half"></i></div>
                        <div class="stats-number"><?= $total_disponibles ?></div>
                        <div class="stats-label">Disponibles</div>
                        <div class="stats-sub">Por tomar</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stats-card-custom">
                        <div class="icon-circle"><i class="fas fa-check-circle"></i></div>
                        <div class="stats-number"><?= $total_completadas ?></div>
                        <div class="stats-label">Completadas</div>
                        <div class="stats-sub">Finalizadas</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stats-card-custom">
                        <div class="icon-circle"><i class="fas fa-star"></i></div>
                        <div class="stats-number"><?= $promedio ?> ⭐</div>
                        <div class="stats-label">Calificación</div>
                        <div class="stats-sub">(<?= count($calificaciones) ?> reseñas)</div>
                    </div>
                </div>
            </div>

            <?php if (count($populares) > 0): ?>
            <div class="card mb-4">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-fire text-danger me-2"></i>Servicios más solicitados</h5></div>
                <div class="card-body">
                    <div id="carouselPopulares" class="carousel slide carousel-populares" data-bs-ride="carousel">
                        <div class="carousel-inner">
                            <?php $chunks = array_chunk($populares, 4); foreach ($chunks as $index => $chunk): ?>
                            <div class="carousel-item <?= $index === 0 ? 'active' : '' ?>">
                                <div class="row g-2">
                                    <?php foreach ($chunk as $servicio): ?>
                                        <div class="col-md-3 col-6 text-center">
                                            <div class="card bg-dark-custom border-0 h-100">
                                                <img src="<?= $imagenes_servicios[$servicio['servicio']] ?? 'https://via.placeholder.com/400x300/0a111e/00d4ff?text='.urlencode($servicio['servicio']) ?>" class="card-img-top rounded" style="height:150px;object-fit:cover;">
                                                <div class="card-body p-2">
                                                    <h6 class="card-title mb-0"><?= htmlspecialchars($servicio['servicio'] ?? '', ENT_QUOTES, 'UTF-8') ?></h6>
                                                    <small class="text-muted"><?= $servicio['total'] ?> solicitudes</small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button class="carousel-control-prev" type="button" data-bs-target="#carouselPopulares" data-bs-slide="prev">
                            <span class="carousel-control-prev-icon"></span>
                        </button>
                        <button class="carousel-control-next" type="button" data-bs-target="#carouselPopulares" data-bs-slide="next">
                            <span class="carousel-control-next-icon"></span>
                        </button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-history text-primary me-2"></i>Mis Solicitudes Recientes</h5>
                    <a href="?tab=solicitudes" class="btn btn-sm btn-outline-primary">Ver todas</a>
                </div>
                <div class="card-body">
                    <?php if (count($solicitudes_recientes) > 0): ?>
                        <?php foreach ($solicitudes_recientes as $s): ?>
                            <div class="solicitud-reciente">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong><?= htmlspecialchars($s['servicio'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span class="badge bg-<?= ['pendiente'=>'warning','asignado'=>'info','en_curso'=>'primary','completado'=>'success','cancelado'=>'danger'][$s['estado']] ?? 'secondary' ?> ms-2"><?= strtoupper($s['estado'] ?? '') ?></span>
                                        <div class="text-muted small">
                                            <i class="fas fa-user me-1"></i> <?= htmlspecialchars(($s['cliente_nombre'] ?? '') . ' ' . ($s['cliente_apellidos'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                            <span class="ms-2"><i class="far fa-calendar-alt me-1"></i> <?= date('d/m/Y', strtotime($s['fecha_creacion'])) ?></span>
                                            <?php if ($s['presupuesto']): ?>
                                                <span class="ms-2"><i class="fas fa-tag me-1"></i> $<?= number_format($s['presupuesto'], 2) ?></span>
                                            <?php endif; ?>
                                            <?php if ($s['precio_aceptado'] == 0 && $s['estado'] == 'asignado'): ?>
                                                <span class="badge bg-warning text-dark ms-2">Esperando aceptación del cliente</span>
                                            <?php endif; ?>
                                            <?php if ($s['mensajes_no_leidos'] > 0): ?>
                                                <span class="badge bg-danger ms-2"><?= $s['mensajes_no_leidos'] ?> nuevos</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <a href="solicitud_detalle.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-info"><i class="fas fa-eye"></i> Ver</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted">No tienes solicitudes asignadas aún. <a href="?tab=disponibles">Revisa las disponibles</a></p>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($tab === 'solicitudes'): ?>
            <div class="card">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-tasks text-primary me-2"></i>Mis Solicitudes</h5></div>
                <div class="card-body">
                    <?php if (count($mis_solicitudes) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-dark table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th><th>Cliente</th><th>Servicio</th><th>Estado</th>
                                        <th>Presupuesto</th><th>Precio aceptado</th><th>Pago</th>
                                        <th>Fecha</th><th>Mensajes</th><th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($mis_solicitudes as $s): ?>
                                        <tr>
                                            <td><?= $s['id'] ?></td>
                                            <td>
                                                <?php if (!empty($s['cliente_foto'])): ?>
                                                    <img src="<?= htmlspecialchars($s['cliente_foto'], ENT_QUOTES, 'UTF-8') ?>" class="rounded-circle foto-cliente me-1">
                                                <?php else: ?>
                                                    <i class="fas fa-user-circle me-1"></i>
                                                <?php endif; ?>
                                                <?= htmlspecialchars(($s['cliente_nombre'] ?? '') . ' ' . ($s['cliente_apellidos'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                            </td>
                                            <td><?= htmlspecialchars($s['servicio'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>
                                                <span class="badge bg-<?= ['pendiente'=>'warning','asignado'=>'info','en_curso'=>'primary','completado'=>'success','cancelado'=>'danger'][$s['estado']] ?? 'secondary' ?>"><?= strtoupper($s['estado'] ?? '') ?></span>
                                                <?php if ($s['estado'] == 'en_curso' && $s['en_camino'] == 1): ?>
                                                    <span class="badge bg-warning text-dark"><i class="fas fa-truck"></i></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= $s['presupuesto'] ? '$' . number_format($s['presupuesto'], 2) : 'Por acordar' ?></td>
                                            <td>
                                                <?php if ($s['precio_aceptado'] == 1): ?>
                                                    <span class="badge bg-success"><i class="fas fa-check"></i> Aceptado</span>
                                                <?php elseif ($s['precio_aceptado'] == 0 && $s['estado'] == 'asignado'): ?>
                                                    <span class="badge bg-warning text-dark">Pendiente</span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($s['metodo_pago']): ?>
                                                    <span class="badge bg-info"><?= strtoupper($s['metodo_pago']) ?></span>
                                                    <?php if ($s['estado_pago'] === 'pagado'): ?>
                                                        <span class="badge bg-success ms-1"><i class="fas fa-check"></i></span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= date('d/m/Y', strtotime($s['fecha_creacion'])) ?></td>
                                            <td>
                                                <?php if ($s['mensajes_no_leidos'] > 0): ?>
                                                    <span class="badge bg-danger rounded-pill"><?= $s['mensajes_no_leidos'] ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">0</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="solicitud_detalle.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-info"><i class="fas fa-eye"></i></a>
                                                <?php if ($s['estado'] === 'asignado' && $s['precio_aceptado'] == 1): ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="accion" value="cambiar_estado">
                                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                                        <input type="hidden" name="solicitud_id" value="<?= $s['id'] ?>">
                                                        <input type="hidden" name="nuevo_estado" value="en_curso">
                                                        <button type="submit" class="btn btn-sm btn-outline-primary" title="Iniciar trabajo"><i class="fas fa-play"></i></button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($s['estado'] === 'en_curso'): ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="accion" value="cambiar_estado">
                                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                                        <input type="hidden" name="solicitud_id" value="<?= $s['id'] ?>">
                                                        <input type="hidden" name="nuevo_estado" value="completado">
                                                        <button type="submit" class="btn btn-sm btn-outline-success" title="Completar trabajo"><i class="fas fa-check"></i></button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if (in_array($s['estado'], ['pendiente','asignado','en_curso'])): ?>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('¿Cancelar esta solicitud?')">
                                                        <input type="hidden" name="accion" value="cambiar_estado">
                                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                                        <input type="hidden" name="solicitud_id" value="<?= $s['id'] ?>">
                                                        <input type="hidden" name="nuevo_estado" value="cancelado">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Cancelar"><i class="fas fa-times"></i></button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted">No tienes solicitudes asignadas. <a href="?tab=disponibles">Revisa las disponibles</a></p>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($tab === 'disponibles'): ?>
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-clipboard-list text-success me-2"></i>Solicitudes Disponibles</h5>
                    <?php if (!empty($especialidad)): ?>
                        <small class="text-muted">Mostrando coincidencias con tu especialidad: <strong><?= htmlspecialchars($especialidad, ENT_QUOTES, 'UTF-8') ?></strong></small>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (count($disponibles) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-dark table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th><th>Cliente</th><th>Servicio</th><th>Descripción</th>
                                        <th>Urgencia</th><th>Fecha</th><th>Acción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($disponibles as $s): ?>
                                        <tr>
                                            <td><?= $s['id'] ?></td>
                                            <td>
                                                <?php if (!empty($s['cliente_foto'])): ?>
                                                    <img src="<?= htmlspecialchars($s['cliente_foto'], ENT_QUOTES, 'UTF-8') ?>" class="rounded-circle foto-cliente me-1">
                                                <?php else: ?>
                                                    <i class="fas fa-user-circle me-1"></i>
                                                <?php endif; ?>
                                                <?= htmlspecialchars(($s['cliente_nombre'] ?? '') . ' ' . ($s['cliente_apellidos'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                            </td>
                                            <td><?= htmlspecialchars($s['servicio'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(substr($s['descripcion'] ?? '', 0, 50), ENT_QUOTES, 'UTF-8') ?>...</td>
                                            <td>
                                                <?php
                                                $urgencia_colores = ['baja'=>'secondary', 'media'=>'info', 'alta'=>'warning', 'emergencia'=>'danger'];
                                                $color = $urgencia_colores[$s['urgencia'] ?? 'media'] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?= $color ?>"><?= strtoupper($s['urgencia'] ?? 'Media') ?></span>
                                            </td>
                                            <td><?= date('d/m/Y', strtotime($s['fecha_creacion'])) ?></td>
                                            <td>
                                                <a href="solicitud_detalle.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-success"><i class="fas fa-eye me-1"></i>Ver y aceptar</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No hay solicitudes disponibles en este momento.</p>
                            <?php if (empty($especialidad)): ?>
                                <small class="text-warning">Define tu especialidad en tu perfil para ver coincidencias.</small>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($tab === 'trabajos'): ?>
            <div class="row">
                <div class="col-lg-4 mb-4">
                    <div class="card">
                        <div class="card-header"><h5 class="mb-0"><i class="fas fa-upload text-primary me-2"></i>Publicar trabajo realizado</h5></div>
                        <div class="card-body">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="accion" value="subir_trabajo">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                <div class="mb-3">
                                    <label class="form-label">Título *</label>
                                    <input type="text" class="form-control" name="titulo" placeholder="Ej. Reparación de fuga" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Descripción *</label>
                                    <textarea class="form-control" name="descripcion" rows="3" placeholder="Describe el trabajo realizado" required></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Fecha de realización *</label>
                                    <input type="date" class="form-control" name="fecha_realizado" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Imagen *</label>
                                    <input type="file" class="form-control" name="imagen" accept="image/*" required>
                                    <small class="text-muted">Sube una foto del trabajo realizado.</small>
                                </div>
                                <button type="submit" class="btn btn-primary w-100">Publicar trabajo</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header"><h5 class="mb-0"><i class="fas fa-images text-primary me-2"></i>Mis trabajos publicados</h5></div>
                        <div class="card-body">
                            <?php if (count($trabajos_realizados) > 0): ?>
                                <div class="row">
                                    <?php foreach ($trabajos_realizados as $t): ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="card trabajo-item h-100">
                                                <img src="<?= htmlspecialchars($t['imagen'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="card-img-top" alt="<?= htmlspecialchars($t['titulo'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                                <div class="card-body">
                                                    <h6 class="card-title"><?= htmlspecialchars($t['titulo'] ?? '', ENT_QUOTES, 'UTF-8') ?></h6>
                                                    <p class="text-muted small"><?= htmlspecialchars($t['descripcion'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                                                    <p class="text-muted small"><i class="far fa-calendar-alt me-1"></i> <?= date('d/m/Y', strtotime($t['fecha_realizado'])) ?></p>
                                                    <a href="?eliminar_trabajo=<?= $t['id'] ?>&tab=trabajos" class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Eliminar este trabajo?')"><i class="fas fa-trash"></i> Eliminar</a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted">Aún no has publicado trabajos realizados.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($tab === 'calificaciones'): ?>
            <div class="card">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-star text-warning me-2"></i>Calificaciones</h5></div>
                <div class="card-body">
                    <?php if (count($calificaciones) > 0): ?>
                        <div class="mb-3"><h6>Promedio: <span class="text-primary"><?= $promedio ?> ⭐</span> (<?= count($calificaciones) ?> calificaciones)</h6></div>
                        <div class="table-responsive">
                            <table class="table table-dark table-hover">
                                <thead><tr><th>Cliente</th><th>Puntuación</th><th>Comentario</th><th>Fecha</th></tr></thead>
                                <tbody>
                                    <?php foreach ($calificaciones as $c): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($c['cliente_nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>
                                                <?php for ($i=1; $i<=5; $i++): ?>
                                                    <?= $i <= $c['puntuacion'] ? '⭐' : '☆' ?>
                                                <?php endfor; ?>
                                            </td>
                                            <td><?= htmlspecialchars($c['comentario'] ?? 'Sin comentario', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= date('d/m/Y', strtotime($c['fecha'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-star fa-3x text-muted mb-3"></i>
                            <p class="text-muted">Aún no tienes calificaciones.</p>
                            <small>Completa trabajos para recibir reseñas de tus clientes.</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>