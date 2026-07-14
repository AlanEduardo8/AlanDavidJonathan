<?php
require_once 'config/database.php';
require_once 'config/auth.php';

// Si no está logueado, redirigir al login
if (!estaLogueado()) {
    redirigir('login.php');
}

// Si no es cliente, redirigir a su dashboard
if (!esCliente()) {
    redirigir('dashboard.php');
}

$usuario_id = $_SESSION['usuario_id'];
$busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';
$servicio_filtro = isset($_GET['servicio']) ? $_GET['servicio'] : '';
$ciudad_filtro = isset($_GET['ciudad']) ? trim($_GET['ciudad']) : '';
$orden = isset($_GET['orden']) ? $_GET['orden'] : 'calificacion';

// Construir la consulta de trabajadores con filtros
$sql = "SELECT u.id, u.nombre, u.apellidos, u.especialidad, u.foto, u.calificacion_promedio,
               u.ciudad, u.estado, u.biografia, u.verificado,
               (SELECT COUNT(*) FROM calificaciones WHERE trabajador_id = u.id) as total_calificaciones
        FROM usuarios u
        WHERE u.tipo = 'trabajador' AND u.disponible = 1";

// Filtro por búsqueda general (nombre, especialidad, descripción)
if (!empty($busqueda)) {
    $busqueda_escaped = mysqli_real_escape_string($conn, $busqueda);
    $sql .= " AND (u.nombre LIKE '%$busqueda_escaped%' 
                  OR u.apellidos LIKE '%$busqueda_escaped%' 
                  OR u.especialidad LIKE '%$busqueda_escaped%' 
                  OR u.biografia LIKE '%$busqueda_escaped%')";
}

// Filtro por servicio específico
if (!empty($servicio_filtro)) {
    $servicio_escaped = mysqli_real_escape_string($conn, $servicio_filtro);
    $sql .= " AND u.especialidad = '$servicio_escaped'";
}

// Filtro por ciudad
if (!empty($ciudad_filtro)) {
    $ciudad_escaped = mysqli_real_escape_string($conn, $ciudad_filtro);
    $sql .= " AND u.ciudad LIKE '%$ciudad_escaped%'";
}

// Ordenamiento
switch ($orden) {
    case 'calificacion':
        $sql .= " ORDER BY u.calificacion_promedio DESC, u.nombre ASC";
        break;
    case 'nombre':
        $sql .= " ORDER BY u.nombre ASC";
        break;
    case 'reciente':
        $sql .= " ORDER BY u.fecha_registro DESC";
        break;
    default:
        $sql .= " ORDER BY u.calificacion_promedio DESC";
}

$result = mysqli_query($conn, $sql);
$trabajadores = mysqli_fetch_all($result, MYSQLI_ASSOC);

// Obtener lista de especialidades para el filtro
$sql_especialidades = "SELECT DISTINCT especialidad FROM usuarios WHERE tipo = 'trabajador' AND especialidad IS NOT NULL AND especialidad != '' ORDER BY especialidad";
$result_esp = mysqli_query($conn, $sql_especialidades);
$especialidades = mysqli_fetch_all($result_esp, MYSQLI_ASSOC);

// Obtener lista de ciudades para el filtro
$sql_ciudades = "SELECT DISTINCT ciudad FROM usuarios WHERE tipo = 'trabajador' AND ciudad IS NOT NULL AND ciudad != '' ORDER BY ciudad";
$result_cd = mysqli_query($conn, $sql_ciudades);
$ciudades = mysqli_fetch_all($result_cd, MYSQLI_ASSOC);

// Contar mensajes no leídos
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
    <title>Explorar Servicios - Fixi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.0/dist/cyborg/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .trabajador-card {
            transition: transform 0.3s, box-shadow 0.3s;
            cursor: pointer;
        }
        .trabajador-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 212, 255, 0.2);
        }
        .trabajador-foto {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border: 2px solid #00d4ff;
        }
        .filtros-sidebar {
            position: sticky;
            top: 80px;
        }
        .badge-verificado {
            background: #28a745;
            color: #fff;
        }
        .resultado-vacio {
            padding: 60px 20px;
            text-align: center;
        }
        .resultado-vacio i {
            font-size: 4rem;
            color: #00d4ff;
            margin-bottom: 20px;
        }
        .btn-mensajear {
            border-color: #28a745;
            color: #28a745;
        }
        .btn-mensajear:hover {
            background: #28a745;
            color: #030507;
        }
    </style>
</head>
<body>
    <?php include 'assets/estrellas.php'; ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark-custom fixed-top">
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
                    <li class="nav-item"><a class="nav-link" href="cliente_dashboard.php"><i class="fas fa-home me-1"></i>Inicio</a></li>
                    <li class="nav-item"><a class="nav-link active" href="buscar_servicios.php"><i class="fas fa-search me-1"></i>Explorar</a></li>
                    <li class="nav-item"><a class="nav-link" href="cliente_dashboard.php?tab=solicitudes"><i class="fas fa-list me-1"></i>Mis Solicitudes</a></li>
                    <li class="nav-item"><a class="nav-link" href="conversaciones.php"><i class="fas fa-envelope me-1"></i>Mis Mensajes</a></li>
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

    <div class="container py-5" style="margin-top: 60px;">
        <div class="row">
            <!-- Sidebar con filtros -->
            <div class="col-lg-3">
                <div class="filtros-sidebar">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-filter text-primary me-2"></i>Filtros</h5>
                        </div>
                        <div class="card-body">
                            <form method="GET" action="">
                                <!-- Buscador general -->
                                <div class="mb-3">
                                    <label for="busqueda" class="form-label">Buscar</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="busqueda" name="busqueda" placeholder="Nombre, especialidad..." value="<?= htmlspecialchars($busqueda) ?>">
                                        <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i></button>
                                    </div>
                                </div>

                                <!-- Filtro por especialidad -->
                                <div class="mb-3">
                                    <label for="servicio" class="form-label">Especialidad</label>
                                    <select class="form-select" id="servicio" name="servicio" onchange="this.form.submit()">
                                        <option value="">Todas</option>
                                        <?php foreach ($especialidades as $esp): ?>
                                            <option value="<?= htmlspecialchars($esp['especialidad']) ?>" <?= ($servicio_filtro == $esp['especialidad']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($esp['especialidad']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Filtro por ciudad -->
                                <div class="mb-3">
                                    <label for="ciudad" class="form-label">Ciudad</label>
                                    <select class="form-select" id="ciudad" name="ciudad" onchange="this.form.submit()">
                                        <option value="">Todas</option>
                                        <?php foreach ($ciudades as $cd): ?>
                                            <option value="<?= htmlspecialchars($cd['ciudad']) ?>" <?= ($ciudad_filtro == $cd['ciudad']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($cd['ciudad']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Ordenar -->
                                <div class="mb-3">
                                    <label for="orden" class="form-label">Ordenar por</label>
                                    <select class="form-select" id="orden" name="orden" onchange="this.form.submit()">
                                        <option value="calificacion" <?= ($orden == 'calificacion') ? 'selected' : '' ?>>Mejor calificación</option>
                                        <option value="nombre" <?= ($orden == 'nombre') ? 'selected' : '' ?>>Nombre</option>
                                        <option value="reciente" <?= ($orden == 'reciente') ? 'selected' : '' ?>>Más reciente</option>
                                    </select>
                                </div>

                                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search me-2"></i>Aplicar filtros</button>
                                <a href="buscar_servicios.php" class="btn btn-outline-secondary w-100 mt-2"><i class="fas fa-undo me-2"></i>Limpiar</a>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Resultados -->
            <div class="col-lg-9">
                <h4 class="mb-3">
                    <i class="fas fa-users text-primary me-2"></i>
                    <?php if (!empty($busqueda) || !empty($servicio_filtro) || !empty($ciudad_filtro)): ?>
                        Resultados de búsqueda
                    <?php else: ?>
                        Explorar profesionales
                    <?php endif; ?>
                    <span class="badge bg-secondary ms-2"><?= count($trabajadores) ?> encontrados</span>
                </h4>

                <?php if (count($trabajadores) > 0): ?>
                    <div class="row g-4">
                        <?php foreach ($trabajadores as $t): ?>
                            <div class="col-md-6 col-xl-4">
                                <div class="card trabajador-card h-100" onclick="window.location.href='perfil_trabajador_publico.php?id=<?= $t['id'] ?>'">
                                    <div class="card-body text-center">
                                        <div class="position-relative">
                                            <?php if (!empty($t['foto'])): ?>
                                                <img src="<?= $t['foto'] ?>" class="rounded-circle trabajador-foto" alt="<?= htmlspecialchars($t['nombre']) ?>">
                                            <?php else: ?>
                                                <i class="fas fa-user-circle fa-5x text-muted"></i>
                                            <?php endif; ?>
                                            <?php if ($t['verificado']): ?>
                                                <span class="badge badge-verificado position-absolute top-0 end-0" style="transform: translate(10px, -10px);">
                                                    <i class="fas fa-check-circle"></i>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <h5 class="mt-2"><?= htmlspecialchars($t['nombre'] . ' ' . ($t['apellidos'] ?? '')) ?></h5>
                                        <p class="text-primary small mb-1"><?= htmlspecialchars($t['especialidad'] ?? 'General') ?></p>
                                        <?php if (!empty($t['ciudad'])): ?>
                                            <p class="text-muted small"><i class="fas fa-map-pin me-1"></i><?= htmlspecialchars($t['ciudad']) ?></p>
                                        <?php endif; ?>
                                        <div class="mb-2">
                                            <?php
                                            $estrellas = round($t['calificacion_promedio'] ?? 0);
                                            for ($i = 1; $i <= 5; $i++) {
                                                echo $i <= $estrellas ? '<i class="fas fa-star text-warning"></i>' : '<i class="far fa-star text-muted"></i>';
                                            }
                                            ?>
                                            <span class="text-muted small ms-1">(<?= $t['total_calificaciones'] ?? 0 ?>)</span>
                                        </div>
                                        <?php if (!empty($t['biografia'])): ?>
                                            <p class="text-muted small"><?= htmlspecialchars(substr($t['biografia'], 0, 80)) ?>...</p>
                                        <?php endif; ?>
                                        <div class="d-grid gap-2">
                                            <a href="perfil_trabajador_publico.php?id=<?= $t['id'] ?>" class="btn btn-outline-primary btn-sm">
                                                <i class="fas fa-eye me-1"></i>Ver perfil
                                            </a>
                                            <a href="conversacion_detalle.php?trabajador_id=<?= $t['id'] ?>" class="btn btn-outline-success btn-sm">
                                                <i class="fas fa-comment me-1"></i>Mensajear
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="resultado-vacio">
                        <i class="fas fa-search"></i>
                        <h3>No se encontraron resultados</h3>
                        <p class="text-muted">Prueba con otros términos o elimina algunos filtros.</p>
                        <a href="buscar_servicios.php" class="btn btn-primary"><i class="fas fa-undo me-2"></i>Ver todos</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>