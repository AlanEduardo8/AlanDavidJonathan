<?php
require_once 'config/database.php';
require_once 'config/auth.php';
require_once 'includes/geocode.php';

if (!estaLogueado() || !esCliente()) {
    redirigir('login.php');
}

$usuario_id = $_SESSION['usuario_id'];
$usuario_data = obtenerDatosUsuario($conn, $usuario_id);

// Si el perfil está incompleto, redirigir al perfil, PERO solo si no estamos ya en perfil_usuario.php
if (!perfilCompleto($usuario_data) && basename($_SERVER['PHP_SELF']) != 'perfil_usuario.php') {
    redirigir('perfil_usuario.php?primeravez=si');
}

$error = '';
$success = '';

$tab = isset($_GET['tab']) ? $_GET['tab'] : 'inicio';
$trabajador_id_directo = isset($_GET['trabajador_id']) ? intval($_GET['trabajador_id']) : 0;
$trabajador_seleccionado = null;
$especialidad_preseleccionada = '';
$es_fijo = false;

if ($trabajador_id_directo > 0 && $tab === 'nueva') {
    $check_trabajador = mysqli_prepare($conn, "SELECT id, nombre, especialidad FROM usuarios WHERE id = ? AND tipo = 'trabajador' AND verificado = 1");
    mysqli_stmt_bind_param($check_trabajador, 'i', $trabajador_id_directo);
    mysqli_stmt_execute($check_trabajador);
    $result_trabajador = mysqli_stmt_get_result($check_trabajador);
    $trabajador = mysqli_fetch_assoc($result_trabajador);
    if ($trabajador) {
        $trabajador_seleccionado = $trabajador;
        $especialidad_preseleccionada = $trabajador['especialidad'] ?? '';
        $es_fijo = true;
    } else {
        $error = 'Trabajador no encontrado o no verificado.';
        $trabajador_id_directo = 0;
    }
}

// --- BÚSQUEDA DE TRABAJADORES ---
$resultados_busqueda = [];
$termino_busqueda = '';
$filtro_especialidad = '';

if (isset($_GET['buscar']) || isset($_GET['especialidad'])) {
    $termino_busqueda = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
    $filtro_especialidad = isset($_GET['especialidad']) ? trim($_GET['especialidad']) : '';

    $sql_buscar = "SELECT id, nombre, apellidos, especialidad, foto, calificacion_promedio, biografia, experiencia 
                   FROM usuarios 
                   WHERE tipo = 'trabajador' AND verificado = 1";
    
    $params = [];
    $types = '';

    if (!empty($termino_busqueda)) {
        $sql_buscar .= " AND (nombre LIKE ? OR apellidos LIKE ? OR especialidad LIKE ?)";
        $like = '%' . $termino_busqueda . '%';
        $params = array_merge($params, [$like, $like, $like]);
        $types .= 'sss';
    }

    if (!empty($filtro_especialidad)) {
        $sql_buscar .= " AND especialidad = ?";
        $params[] = $filtro_especialidad;
        $types .= 's';
    }

    $sql_buscar .= " ORDER BY calificacion_promedio DESC LIMIT 20";

    $stmt_buscar = mysqli_prepare($conn, $sql_buscar);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt_buscar, $types, ...$params);
    }
    mysqli_stmt_execute($stmt_buscar);
    $result_buscar = mysqli_stmt_get_result($stmt_buscar);
    $resultados_busqueda = mysqli_fetch_all($result_buscar, MYSQLI_ASSOC);
}

// --- CREAR SOLICITUD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'crear_solicitud') {
    $servicio = trim($_POST['servicio'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    
    $calle = trim($_POST['calle'] ?? '');
    $numero = trim($_POST['numero'] ?? '');
    $colonia = trim($_POST['colonia'] ?? '');
    $ciudad_solicitud = trim($_POST['ciudad_solicitud'] ?? '');
    $estado_dir = trim($_POST['estado_dir'] ?? '');
    $codigo_postal_solicitud = trim($_POST['codigo_postal_solicitud'] ?? '');
    
    $direccion = "$calle $numero, $colonia, $ciudad_solicitud, $estado_dir, $codigo_postal_solicitud";
    
    $trabajador_id_asignado = isset($_POST['trabajador_id']) && !empty($_POST['trabajador_id']) ? intval($_POST['trabajador_id']) : NULL;
    $urgencia = isset($_POST['urgencia']) ? trim($_POST['urgencia']) : 'media';
    $instrucciones = isset($_POST['instrucciones']) ? trim($_POST['instrucciones']) : '';
    $metodo_pago = isset($_POST['metodo_pago']) ? trim($_POST['metodo_pago']) : 'efectivo';

    if (empty($servicio) || empty($descripcion) || empty($calle) || empty($numero) || empty($colonia) || empty($ciudad_solicitud) || empty($estado_dir) || empty($codigo_postal_solicitud)) {
        $error = 'Servicio, descripción y todos los campos de dirección son obligatorios.';
    } else {
        $coords = geocodificarDireccion($direccion);
        $latitud = $coords['lat'] ?? NULL;
        $longitud = $coords['lon'] ?? NULL;

        $imagenes_subidas = [];
        if (isset($_FILES['imagenes']) && !empty($_FILES['imagenes']['name'][0])) {
            $carpeta_destino = 'uploads/solicitudes/';
            if (!is_dir($carpeta_destino)) mkdir($carpeta_destino, 0777, true);
            foreach ($_FILES['imagenes']['name'] as $i => $nombre) {
                if ($_FILES['imagenes']['error'][$i] === UPLOAD_ERR_OK) {
                    $ext = pathinfo($nombre, PATHINFO_EXTENSION);
                    $ruta = $carpeta_destino . uniqid() . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['imagenes']['tmp_name'][$i], $ruta)) {
                        $imagenes_subidas[] = $ruta;
                    }
                }
            }
        }
        $imagenes_str = !empty($imagenes_subidas) ? implode(',', $imagenes_subidas) : NULL;

        $estado_inicial = ($trabajador_id_asignado) ? 'asignado' : 'pendiente';

        $sql = "INSERT INTO solicitudes 
                (cliente_id, trabajador_id, servicio, descripcion, direccion, 
                 latitud, longitud, urgencia, instrucciones, imagenes, estado, fecha_creacion,
                 calle, numero, colonia, ciudad, codigo_postal, estado_dir, metodo_pago) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'iisssddsssssssssss', 
            $usuario_id, 
            $trabajador_id_asignado, 
            $servicio, 
            $descripcion,
            $direccion,
            $latitud,
            $longitud,
            $urgencia,
            $instrucciones,
            $imagenes_str,
            $estado_inicial,
            $calle,
            $numero,
            $colonia,
            $ciudad_solicitud,
            $codigo_postal_solicitud,
            $estado_dir,
            $metodo_pago
        );
        if (mysqli_stmt_execute($stmt)) {
            $success = 'Solicitud creada exitosamente. ';
            if ($trabajador_id_asignado) {
                $success .= 'El trabajador ha sido notificado.';
            } else {
                $success .= 'Los trabajadores disponibles podrán ver tu solicitud y contactarte.';
            }
            $_POST = [];
            header("Location: cliente_dashboard.php?tab=solicitudes");
            exit;
        } else {
            $error = 'Error al crear la solicitud: ' . mysqli_error($conn);
        }
    }
}

// --- CALIFICAR ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'calificar') {
    $solicitud_id = intval($_POST['solicitud_id'] ?? 0);
    $puntuacion = intval($_POST['puntuacion'] ?? 0);
    $comentario = trim($_POST['comentario'] ?? '');

    if ($solicitud_id <= 0 || $puntuacion < 1 || $puntuacion > 5) {
        $error = 'Datos de calificación inválidos.';
    } else {
        $check = mysqli_prepare($conn, "SELECT id, trabajador_id FROM solicitudes WHERE id = ? AND cliente_id = ? AND estado = 'completado'");
        mysqli_stmt_bind_param($check, 'ii', $solicitud_id, $usuario_id);
        mysqli_stmt_execute($check);
        $result = mysqli_stmt_get_result($check);
        $solicitud = mysqli_fetch_assoc($result);
        if (!$solicitud) {
            $error = 'No puedes calificar esta solicitud.';
        } else {
            $trabajador_id = $solicitud['trabajador_id'];
            $sql = "INSERT INTO calificaciones (solicitud_id, trabajador_id, cliente_id, puntuacion, comentario, fecha) VALUES (?, ?, ?, ?, ?, NOW())";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, 'iiiss', $solicitud_id, $trabajador_id, $usuario_id, $puntuacion, $comentario);
            if (mysqli_stmt_execute($stmt)) {
                $update = "UPDATE usuarios SET calificacion_promedio = (SELECT AVG(puntuacion) FROM calificaciones WHERE trabajador_id = ?) WHERE id = ?";
                $stmt2 = mysqli_prepare($conn, $update);
                mysqli_stmt_bind_param($stmt2, 'ii', $trabajador_id, $trabajador_id);
                mysqli_stmt_execute($stmt2);
                $success = 'Calificación guardada exitosamente.';
            } else {
                $error = 'Error al guardar la calificación.';
            }
        }
    }
}

// --- CANCELAR ---
if (isset($_GET['cancelar']) && is_numeric($_GET['cancelar'])) {
    $solicitud_id = intval($_GET['cancelar']);
    $check = mysqli_prepare($conn, "SELECT id FROM solicitudes WHERE id = ? AND cliente_id = ? AND estado = 'pendiente'");
    mysqli_stmt_bind_param($check, 'ii', $solicitud_id, $usuario_id);
    mysqli_stmt_execute($check);
    mysqli_stmt_store_result($check);
    if (mysqli_stmt_num_rows($check) > 0) {
        $update = mysqli_prepare($conn, "UPDATE solicitudes SET estado = 'cancelado' WHERE id = ?");
        mysqli_stmt_bind_param($update, 'i', $solicitud_id);
        mysqli_stmt_execute($update);
        $success = 'Solicitud cancelada.';
        header("Location: cliente_dashboard.php?tab=solicitudes");
        exit;
    } else {
        $error = 'No puedes cancelar esta solicitud.';
    }
}

// --- OBTENER SOLICITUDES ---
$sql_solicitudes = "SELECT s.*, u.nombre as trabajador_nombre, u.apellidos as trabajador_apellidos 
                    FROM solicitudes s 
                    LEFT JOIN usuarios u ON s.trabajador_id = u.id 
                    WHERE s.cliente_id = ? 
                    ORDER BY s.fecha_creacion DESC";
$stmt_solicitudes = mysqli_prepare($conn, $sql_solicitudes);
mysqli_stmt_bind_param($stmt_solicitudes, 'i', $usuario_id);
mysqli_stmt_execute($stmt_solicitudes);
$result_solicitudes = mysqli_stmt_get_result($stmt_solicitudes);
$solicitudes = mysqli_fetch_all($result_solicitudes, MYSQLI_ASSOC);

$total_solicitudes = count($solicitudes);
$solicitudes_pendientes = 0;
$solicitudes_activas = 0;
$solicitudes_completadas = 0;
$presupuesto_total = 0;

foreach ($solicitudes as $s) {
    if ($s['estado'] === 'pendiente') $solicitudes_pendientes++;
    if (in_array($s['estado'], ['asignado', 'en_curso'])) $solicitudes_activas++;
    if ($s['estado'] === 'completado') {
        $solicitudes_completadas++;
        $presupuesto_total += floatval($s['presupuesto'] ?? 0);
    }
}
$progreso_general = ($total_solicitudes > 0) ? round(($solicitudes_completadas / $total_solicitudes) * 100) : 0;

// --- DESTACADOS ---
$sql_destacados = "SELECT id, nombre, apellidos, especialidad, foto, calificacion_promedio, biografia 
                   FROM usuarios 
                   WHERE tipo = 'trabajador' AND destacado = 1 AND verificado = 1
                   ORDER BY calificacion_promedio DESC 
                   LIMIT 4";
$result_destacados = mysqli_query($conn, $sql_destacados);
$destacados = mysqli_fetch_all($result_destacados, MYSQLI_ASSOC);

// --- ESPECIALIDADES ---
$sql_especialidades = "SELECT DISTINCT especialidad FROM usuarios WHERE tipo = 'trabajador' AND especialidad IS NOT NULL AND especialidad != '' ORDER BY especialidad";
$result_especialidades = mysqli_query($conn, $sql_especialidades);
$especialidades = mysqli_fetch_all($result_especialidades, MYSQLI_ASSOC);

// --- MENSAJES NO LEÍDOS ---
$sql_total_no_leidos = "SELECT COUNT(*) as total FROM mensajes WHERE receptor_id = ? AND leido = 0";
$stmt_total = mysqli_prepare($conn, $sql_total_no_leidos);
mysqli_stmt_bind_param($stmt_total, 'i', $usuario_id);
mysqli_stmt_execute($stmt_total);
$result_total = mysqli_stmt_get_result($stmt_total);
$row_total = mysqli_fetch_assoc($result_total);
$total_mensajes_no_leidos = $row_total['total'] ?? 0;

$categorias = ['Plomería', 'Electricidad', 'Carpintería', 'Pintura', 'Jardinería', 'Albañilería', 'Herrería', 'Mantenimiento General', 'Electrodomésticos'];
$iconos_servicios = [
    'Plomería' => 'fa-wrench',
    'Electricidad' => 'fa-bolt',
    'Carpintería' => 'fa-hammer',
    'Pintura' => 'fa-paint-roller',
    'Jardinería' => 'fa-leaf',
    'Albañilería' => 'fa-hard-hat',
    'Herrería' => 'fa-tools',
    'Mantenimiento General' => 'fa-toolbox',
    'Electrodomésticos' => 'fa-tv'
];
$imagenes_servicios = [
    'Plomería' => 'https://images.unsplash.com/photo-1607472586893-edb57bdc0e39?w=1200&h=500&fit=crop',
    'Electricidad' => 'https://images.unsplash.com/photo-1621905251189-08b45d6a269e?w=1200&h=500&fit=crop',
    'Carpintería' => 'https://images.unsplash.com/photo-1581147036325-7ab3febf6f01?w=1200&h=500&fit=crop',
    'Pintura' => 'https://images.unsplash.com/photo-1589939705384-5185137a7f0f?w=1200&h=500&fit=crop',
    'Jardinería' => 'https://images.unsplash.com/photo-1526304640581-d334cdbbf45e?w=1200&h=500&fit=crop',
    'Albañilería' => 'https://images.unsplash.com/photo-1503387762-592deb58ef4e?w=1200&h=500&fit=crop',
    'Herrería' => 'https://images.unsplash.com/photo-1581091226033-d5c48150dbaa?w=1200&h=500&fit=crop',
    'Mantenimiento General' => 'https://images.unsplash.com/photo-1581578731548-c64695cc6952?w=1200&h=500&fit=crop',
    'Electrodomésticos' => 'https://images.unsplash.com/photo-1574267432553-4b4628081c31?w=1200&h=500&fit=crop'
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Cliente - Fixi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.0/dist/cyborg/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .dashboard-content { position: relative; z-index: 1; }
        .card-trabajador { transition: transform 0.2s; }
        .card-trabajador:hover { transform: translateY(-5px); box-shadow: 0 8px 30px rgba(0,212,255,0.15); }
        .badge-especialidad { background: #1a2a3f; color: #00d4ff; }
        .btn-ver-perfil { border-color: #00d4ff; color: #00d4ff; }
        .btn-ver-perfil:hover { background: #00d4ff; color: #030507; }
        .nav-link.active { color: #00d4ff !important; border-bottom: 2px solid #00d4ff; }
        .nav-link { color: #8899aa; border-bottom: 2px solid transparent; }
        .nav-link:hover { color: #00d4ff; border-bottom: 2px solid #00d4ff; }
        .campo-fijo { background-color: #1a2a3f !important; color: #8899aa !important; cursor: not-allowed; opacity: 0.7; }
        .form-section { border-left: 3px solid #00d4ff; padding-left: 15px; margin-bottom: 20px; }
        .form-section-title { font-size: 0.9rem; text-transform: uppercase; color: #00d4ff; letter-spacing: 1px; margin-bottom: 15px; }

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
        .stats-card-custom:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(0,212,255,0.15);
        }
        .stats-card-custom .icon-circle {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: rgba(0, 212, 255, 0.1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 10px;
            color: #00d4ff;
            font-size: 1.8rem;
        }
        .stats-card-custom .stats-number {
            font-size: 2.4rem;
            font-weight: 300;
            color: #fff;
            line-height: 1.2;
        }
        .stats-card-custom .stats-label {
            color: #8899aa;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 5px;
        }
        .stats-card-custom .stats-sub {
            color: #00d4ff;
            font-size: 0.8rem;
            margin-top: 5px;
        }

        .carousel-servicios .carousel-item {
            height: 400px;
        }
        .carousel-servicios .carousel-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            filter: brightness(0.5);
            border-radius: 16px;
        }
        .carousel-servicios .carousel-caption {
            top: 0;
            bottom: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            background: rgba(0,0,0,0.3);
            border-radius: 16px;
            padding: 20px;
        }
        .carousel-servicios .btn-servicio {
            background: rgba(0, 212, 255, 0.2);
            border: 2px solid #00d4ff;
            color: #fff;
            padding: 16px 40px;
            border-radius: 60px;
            font-size: 1.5rem;
            font-weight: 700;
            transition: 0.3s;
            text-decoration: none;
            display: inline-block;
            backdrop-filter: blur(5px);
            letter-spacing: 1px;
        }
        .carousel-servicios .btn-servicio:hover {
            background: #00d4ff;
            color: #030507;
            transform: scale(1.08);
            box-shadow: 0 0 30px rgba(0,212,255,0.6);
        }
        .carousel-servicios .btn-servicio i {
            margin-right: 12px;
        }
        .carousel-servicios .carousel-control-prev-icon,
        .carousel-servicios .carousel-control-next-icon {
            background-color: rgba(0,212,255,0.4);
            border-radius: 50%;
            padding: 25px;
            background-size: 60%;
        }
        .btn-mensajear {
            border-color: #28a745;
            color: #28a745;
        }
        .btn-mensajear:hover {
            background: #28a745;
            color: #030507;
        }
        .foto-destacado {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border: 2px solid #00d4ff;
        }

        @media (max-width: 768px) {
            .stats-card-custom .stats-number {
                font-size: 1.8rem;
            }
            .carousel-servicios .carousel-item {
                height: 250px;
            }
            .carousel-servicios .btn-servicio {
                font-size: 1rem;
                padding: 10px 20px;
            }
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
                    <li class="nav-item"><a class="nav-link <?= $tab === 'solicitudes' ? 'active' : '' ?>" href="?tab=solicitudes"><i class="fas fa-list me-1"></i>Mis Solicitudes</a></li>
                    <li class="nav-item"><a class="nav-link <?= $tab === 'nueva' ? 'active' : '' ?>" href="?tab=nueva"><i class="fas fa-plus-circle me-1"></i>Nueva Solicitud</a></li>
                    <li class="nav-item"><a class="nav-link" href="mensajes.php"><i class="fas fa-envelope me-1"></i>Mis Mensajes</a></li>
                </ul>
                <div class="d-flex align-items-center">
                    <a href="perfil_usuario.php" class="btn btn-outline-info btn-sm me-2">
                        <i class="fas fa-user-edit me-1"></i>Mi Perfil
                    </a>
                    <?php if ($total_mensajes_no_leidos > 0): ?>
                        <span class="badge bg-danger rounded-pill me-2"><?= $total_mensajes_no_leidos ?></span>
                    <?php endif; ?>
                    <span class="user-info me-2">
                        <i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['usuario_nombre']) ?>
                        <span class="badge bg-primary ms-1">Cliente</span>
                    </span>
                    <a href="logout.php" class="btn btn-outline-danger btn-sm">CERRAR SESIÓN</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container py-4 dashboard-content" style="margin-top: 60px;">
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if ($tab === 'inicio'): ?>
            
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-search text-primary me-2"></i>Buscar profesionales</h5>
                </div>
                <div class="card-body">
                    <form method="GET" action="" class="row g-3">
                        <input type="hidden" name="tab" value="inicio">
                        <div class="col-md-6">
                            <input type="text" class="form-control" name="buscar" placeholder="Buscar por nombre, especialidad..." value="<?= htmlspecialchars($termino_busqueda) ?>">
                        </div>
                        <div class="col-md-4">
                            <select class="form-select" name="especialidad">
                                <option value="">Todas las especialidades</option>
                                <?php foreach ($especialidades as $esp): ?>
                                    <option value="<?= htmlspecialchars($esp['especialidad']) ?>" <?= ($filtro_especialidad == $esp['especialidad']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($esp['especialidad']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search me-1"></i>Buscar</button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if (isset($_GET['buscar']) || isset($_GET['especialidad'])): ?>
                <?php if (count($resultados_busqueda) > 0): ?>
                    <div class="row">
                        <?php foreach ($resultados_busqueda as $trabajador): ?>
                            <div class="col-md-6 col-lg-4 mb-3">
                                <div class="card card-trabajador h-100">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center mb-2">
                                            <img src="<?= $trabajador['foto'] ?? 'https://via.placeholder.com/60' ?>" class="rounded-circle me-3" style="width:60px;height:60px;object-fit:cover;border:2px solid #00d4ff;">
                                            <div>
                                                <h6 class="mb-0"><?= htmlspecialchars($trabajador['nombre'] . ' ' . ($trabajador['apellidos'] ?? '')) ?></h6>
                                                <span class="badge badge-especialidad"><?= htmlspecialchars($trabajador['especialidad'] ?? 'General') ?></span>
                                                <div class="small mt-1">
                                                    <?php
                                                    $estrellas = round($trabajador['calificacion_promedio'] ?? 0);
                                                    for ($i=1; $i<=5; $i++) echo $i <= $estrellas ? '⭐' : '☆';
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                        <p class="text-muted small"><?= htmlspecialchars(substr($trabajador['biografia'] ?? '', 0, 80)) ?>...</p>
                                        <div class="d-grid gap-2">
                                            <a href="perfil_trabajador.php?id=<?= $trabajador['id'] ?>" class="btn btn-ver-perfil btn-sm">
                                                <i class="fas fa-eye me-1"></i>Ver perfil completo
                                            </a>
                                            <a href="conversacion_detalle.php?trabajador_id=<?= $trabajador['id'] ?>" class="btn btn-outline-success btn-sm">
                                                <i class="fas fa-comment me-1"></i>Mensajear
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">No se encontraron trabajadores con esos criterios.</div>
                <?php endif; ?>
            <?php else: ?>

                <div class="carousel-servicios mb-4">
                    <h5 class="text-primary mb-3"><i class="fas fa-images me-2"></i>Explora por categoría</h5>
                    <div id="carouselServicios" class="carousel slide" data-bs-ride="carousel">
                        <div class="carousel-indicators">
                            <?php for ($i = 0; $i < count($categorias); $i++): ?>
                                <button type="button" data-bs-target="#carouselServicios" data-bs-slide-to="<?= $i ?>" class="<?= $i === 0 ? 'active' : '' ?>"></button>
                            <?php endfor; ?>
                        </div>
                        <div class="carousel-inner">
                            <?php foreach ($categorias as $index => $cat): ?>
                                <div class="carousel-item <?= $index === 0 ? 'active' : '' ?>">
                                    <img src="<?= $imagenes_servicios[$cat] ?>" class="d-block w-100" alt="<?= $cat ?>">
                                    <div class="carousel-caption">
                                        <a href="buscar_servicios.php?servicio=<?= urlencode($cat) ?>" class="btn-servicio">
                                            <i class="fas <?= $iconos_servicios[$cat] ?? 'fa-tools' ?>"></i> <?= $cat ?>
                                        </a>
                                        <p class="text-light mt-2">Encuentra profesionales en <?= $cat ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button class="carousel-control-prev" type="button" data-bs-target="#carouselServicios" data-bs-slide="prev">
                            <span class="carousel-control-prev-icon"></span>
                        </button>
                        <button class="carousel-control-next" type="button" data-bs-target="#carouselServicios" data-bs-slide="next">
                            <span class="carousel-control-next-icon"></span>
                        </button>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-star text-warning me-2"></i>Profesionales Destacados</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($destacados) > 0): ?>
                            <div class="row g-3">
                                <?php foreach ($destacados as $prof): ?>
                                    <div class="col-md-3 col-6">
                                        <div class="card h-100 text-center p-2 card-trabajador">
                                            <img src="<?= $prof['foto'] ?? 'https://via.placeholder.com/100' ?>" class="rounded-circle mx-auto mt-2 foto-destacado" alt="<?= htmlspecialchars($prof['nombre']) ?>">
                                            <div class="card-body">
                                                <h6 class="card-title"><?= htmlspecialchars($prof['nombre'] . ' ' . ($prof['apellidos'] ?? '')) ?></h6>
                                                <p class="text-primary small"><?= htmlspecialchars($prof['especialidad'] ?? 'General') ?></p>
                                                <p class="small">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <?= $i <= round($prof['calificacion_promedio'] ?? 0) ? '⭐' : '☆' ?>
                                                    <?php endfor; ?>
                                                </p>
                                                <div class="d-grid gap-1">
                                                    <a href="perfil_trabajador.php?id=<?= $prof['id'] ?>" class="btn btn-ver-perfil btn-sm">
                                                        <i class="fas fa-eye me-1"></i>Ver
                                                    </a>
                                                    <a href="conversacion_detalle.php?trabajador_id=<?= $prof['id'] ?>" class="btn btn-outline-success btn-sm">
                                                        <i class="fas fa-comment me-1"></i>Mensajear
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted">No hay profesionales destacados en este momento.</p>
                        <?php endif; ?>
                    </div>
                </div>

            <?php endif; ?>

        <?php elseif ($tab === 'solicitudes'): ?>
            <div class="row g-3 mb-4">
                <div class="col-md-3 col-6">
                    <div class="stats-card-custom">
                        <div class="icon-circle"><i class="fas fa-clipboard-list"></i></div>
                        <div class="stats-number"><?= $total_solicitudes ?></div>
                        <div class="stats-label">Total solicitudes</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stats-card-custom">
                        <div class="icon-circle"><i class="fas fa-hourglass-half"></i></div>
                        <div class="stats-number"><?= $solicitudes_pendientes ?></div>
                        <div class="stats-label">Pendientes</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stats-card-custom">
                        <div class="icon-circle"><i class="fas fa-play-circle"></i></div>
                        <div class="stats-number"><?= $solicitudes_activas ?></div>
                        <div class="stats-label">En curso / Asignadas</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stats-card-custom">
                        <div class="icon-circle"><i class="fas fa-check-circle"></i></div>
                        <div class="stats-number"><?= $solicitudes_completadas ?></div>
                        <div class="stats-label">Completadas</div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-list text-primary me-2"></i>Mis Solicitudes</h5>
                </div>
                <div class="card-body">
                    <?php if (count($solicitudes) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-dark table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Servicio</th>
                                        <th>Profesional</th>
                                        <th>Estado</th>
                                        <th>Precio</th>
                                        <th>Pago</th>
                                        <th>Fecha</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($solicitudes as $s): ?>
                                        <tr>
                                            <td><?= $s['id'] ?></td>
                                            <td><?= htmlspecialchars($s['servicio']) ?></td>
                                            <td><?= $s['trabajador_nombre'] ? htmlspecialchars($s['trabajador_nombre'] . ' ' . ($s['trabajador_apellidos'] ?? '')) : 'Pendiente' ?></td>
                                            <td>
                                                <?php
                                                $estado_colores = ['pendiente' => 'warning', 'asignado' => 'info', 'en_curso' => 'primary', 'completado' => 'success', 'cancelado' => 'danger'];
                                                $color = $estado_colores[$s['estado']] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?= $color ?>"><?= strtoupper($s['estado']) ?></span>
                                                <?php if ($s['estado'] == 'en_curso' && $s['en_camino'] == 1): ?>
                                                    <span class="badge bg-warning text-dark"><i class="fas fa-truck"></i></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($s['presupuesto']): ?>
                                                    $<?= number_format($s['presupuesto'], 2) ?>
                                                    <?php if ($s['precio_aceptado'] == 1): ?>
                                                        <span class="badge bg-success ms-1">Aceptado</span>
                                                    <?php elseif ($s['estado'] == 'asignado' && $s['precio_aceptado'] == 0): ?>
                                                        <span class="badge bg-warning text-dark ms-1">Pendiente</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    Por acordar
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
                                                <a href="solicitud_detalle.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-info">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <?php if ($s['estado'] === 'pendiente'): ?>
                                                    <a href="?cancelar=<?= $s['id'] ?>&tab=solicitudes" class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Cancelar?')">
                                                        <i class="fas fa-times"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if ($s['estado'] === 'completado'): ?>
                                                    <?php
                                                    $check_calif = mysqli_prepare($conn, "SELECT id FROM calificaciones WHERE solicitud_id = ?");
                                                    mysqli_stmt_bind_param($check_calif, 'i', $s['id']);
                                                    mysqli_stmt_execute($check_calif);
                                                    mysqli_stmt_store_result($check_calif);
                                                    $ya_calificado = mysqli_stmt_num_rows($check_calif) > 0;
                                                    ?>
                                                    <?php if (!$ya_calificado): ?>
                                                        <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#calificarModal" data-solicitud-id="<?= $s['id'] ?>" data-servicio="<?= htmlspecialchars($s['servicio']) ?>">Calificar</button>
                                                    <?php else: ?>
                                                        <span class="text-success">Calificado</span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted">No tienes solicitudes aún. <a href="?tab=nueva">Crea una ahora</a></p>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($tab === 'nueva'): ?>
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-plus-circle text-primary me-2"></i>
                        <?= $trabajador_id_directo > 0 ? 'Contratar a ' . htmlspecialchars($trabajador_seleccionado['nombre'] ?? '') : 'Crear Nueva Solicitud' ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if ($trabajador_id_directo > 0 && isset($trabajador_seleccionado)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Estás contratando directamente a <strong><?= htmlspecialchars($trabajador_seleccionado['nombre']) ?></strong>.
                            El campo <strong>"Servicio"</strong> ha sido fijado con su especialidad: <strong><?= htmlspecialchars($especialidad_preseleccionada) ?></strong>.
                            <br><small>Podrás chatear con él/ella antes de que acepte la solicitud.</small>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="" enctype="multipart/form-data">
                        <input type="hidden" name="accion" value="crear_solicitud">
                        <?php if ($trabajador_id_directo > 0): ?>
                            <input type="hidden" name="trabajador_id" value="<?= $trabajador_id_directo ?>">
                        <?php endif; ?>

                        <div class="form-section">
                            <div class="form-section-title"><i class="fas fa-tools me-2"></i>Servicio</div>
                            <div class="mb-3">
                                <label for="servicio" class="form-label">Servicio *</label>
                                <?php if ($es_fijo && !empty($especialidad_preseleccionada)): ?>
                                    <input type="text" class="form-control campo-fijo" 
                                           value="<?= htmlspecialchars($especialidad_preseleccionada) ?>" 
                                           disabled readonly>
                                    <input type="hidden" name="servicio" value="<?= htmlspecialchars($especialidad_preseleccionada) ?>">
                                    <small class="text-muted">Este servicio está fijado según la especialidad del trabajador.</small>
                                <?php else: ?>
                                    <select class="form-select" id="servicio" name="servicio" required>
                                        <option value="">Selecciona el servicio</option>
                                        <?php foreach ($categorias as $cat): ?>
                                            <option value="<?= $cat ?>"><?= $cat ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-section">
                            <div class="form-section-title"><i class="fas fa-info-circle me-2"></i>Detalles del problema</div>
                            <div class="mb-3">
                                <label for="descripcion" class="form-label">Descripción *</label>
                                <textarea class="form-control" id="descripcion" name="descripcion" rows="4" required><?= htmlspecialchars($_POST['descripcion'] ?? '') ?></textarea>
                                <small class="text-muted">Describe detalladamente lo que necesitas. El precio y la fecha se acordarán en el chat.</small>
                            </div>
                        </div>

                        <div class="form-section">
                            <div class="form-section-title"><i class="fas fa-map-pin me-2"></i>Dirección</div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="calle" class="form-label">Calle *</label>
                                    <input type="text" class="form-control" id="calle" name="calle" placeholder="Ej. Av. Insurgentes" value="<?= htmlspecialchars($_POST['calle'] ?? '') ?>" required>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="numero" class="form-label">Número *</label>
                                    <input type="text" class="form-control" id="numero" name="numero" placeholder="Ej. 123" value="<?= htmlspecialchars($_POST['numero'] ?? '') ?>" required>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="colonia" class="form-label">Colonia *</label>
                                    <input type="text" class="form-control" id="colonia" name="colonia" placeholder="Ej. Del Valle" value="<?= htmlspecialchars($_POST['colonia'] ?? '') ?>" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="ciudad_solicitud" class="form-label">Ciudad *</label>
                                    <input type="text" class="form-control" id="ciudad_solicitud" name="ciudad_solicitud" placeholder="Ej. Ciudad de México" value="<?= htmlspecialchars($_POST['ciudad_solicitud'] ?? '') ?>" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="estado_dir" class="form-label">Estado *</label>
                                    <input type="text" class="form-control" id="estado_dir" name="estado_dir" placeholder="Ej. CDMX" value="<?= htmlspecialchars($_POST['estado_dir'] ?? '') ?>" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="codigo_postal_solicitud" class="form-label">Código Postal *</label>
                                    <input type="text" class="form-control" id="codigo_postal_solicitud" name="codigo_postal_solicitud" placeholder="Ej. 03100" value="<?= htmlspecialchars($_POST['codigo_postal_solicitud'] ?? '') ?>" required>
                                </div>
                            </div>
                            <small class="text-muted">La dirección completa se usará para que el trabajador te ubique.</small>
                        </div>

                        <div class="form-section">
                            <div class="form-section-title"><i class="fas fa-credit-card me-2"></i>Método de pago</div>
                            <div class="mb-3">
                                <label for="metodo_pago" class="form-label">Selecciona cómo deseas pagar *</label>
                                <select class="form-select" id="metodo_pago" name="metodo_pago" required>
                                    <option value="efectivo" <?= (isset($_POST['metodo_pago']) && $_POST['metodo_pago'] === 'efectivo') ? 'selected' : '' ?>>Efectivo</option>
                                    <option value="tarjeta" <?= (isset($_POST['metodo_pago']) && $_POST['metodo_pago'] === 'tarjeta') ? 'selected' : '' ?>>Tarjeta (pago en línea)</option>
                                </select>
                                <small class="text-muted">Si eliges tarjeta, podrás pagar con Mercado Pago cuando el trabajador establezca el precio.</small>
                            </div>
                        </div>

                        <div class="form-section">
                            <div class="form-section-title"><i class="fas fa-clock me-2"></i>Información adicional</div>
                            <div class="mb-3">
                                <label for="urgencia" class="form-label">Urgencia</label>
                                <select class="form-select" id="urgencia" name="urgencia">
                                    <option value="baja">Baja</option>
                                    <option value="media" selected>Media</option>
                                    <option value="alta">Alta</option>
                                    <option value="emergencia">Emergencia</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="instrucciones" class="form-label">Instrucciones adicionales</label>
                                <textarea class="form-control" id="instrucciones" name="instrucciones" rows="3"><?= htmlspecialchars($_POST['instrucciones'] ?? '') ?></textarea>
                                <small class="text-muted">Acceso, herramientas necesarias, contactos alternativos, etc.</small>
                            </div>
                        </div>

                        <div class="form-section">
                            <div class="form-section-title"><i class="fas fa-camera me-2"></i>Imágenes</div>
                            <div class="mb-3">
                                <label for="imagenes" class="form-label">Subir fotos (opcional)</label>
                                <input type="file" class="form-control" id="imagenes" name="imagenes[]" accept="image/*" multiple>
                                <small class="text-muted">Puedes seleccionar varias imágenes para mostrar el estado de la situación.</small>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg w-100 mt-3">
                            <i class="fas fa-paper-plane me-2"></i>
                            <?= $trabajador_id_directo > 0 ? 'Enviar solicitud a este profesional' : 'Publicar solicitud' ?>
                        </button>
                        <?php if ($trabajador_id_directo > 0): ?>
                            <a href="cliente_dashboard.php?tab=inicio" class="btn btn-outline-secondary mt-2 w-100">Cancelar</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal calificar -->
    <div class="modal fade" id="calificarModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content bg-dark-custom">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-star text-warning me-2"></i>Calificar</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="calificar">
                        <input type="hidden" name="solicitud_id" id="solicitud_id">
                        <p><strong id="servicio_nombre"></strong></p>
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
                        <button type="submit" class="btn btn-primary">Enviar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        const calificarModal = document.getElementById('calificarModal');
        calificarModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            document.getElementById('solicitud_id').value = button.getAttribute('data-solicitud-id');
            document.getElementById('servicio_nombre').textContent = button.getAttribute('data-servicio');
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>