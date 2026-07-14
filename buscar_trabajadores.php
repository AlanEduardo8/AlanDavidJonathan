<?php
require_once 'config/database.php';
require_once 'config/auth.php';

if (!estaLogueado() || !esCliente()) {
    redirigir('login.php');
}

$usuario_id = $_SESSION['usuario_id'];
$busqueda = isset($_GET['q']) ? trim($_GET['q']) : '';
$servicio = isset($_GET['servicio']) ? trim($_GET['servicio']) : '';
$ciudad = isset($_GET['ciudad']) ? trim($_GET['ciudad']) : '';
$calificacion_min = isset($_GET['calificacion']) ? intval($_GET['calificacion']) : 0;

// Construir consulta de búsqueda
$sql = "SELECT u.*, 
               (SELECT COUNT(*) FROM calificaciones c WHERE c.trabajador_id = u.id) as total_calificaciones,
               (SELECT COUNT(*) FROM trabajos_realizados tr WHERE tr.trabajador_id = u.id) as total_trabajos
        FROM usuarios u 
        WHERE u.tipo = 'trabajador' AND u.verificado = 1";

$params = [];
$types = '';

if (!empty($busqueda)) {
    $sql .= " AND (u.nombre LIKE ? OR u.apellidos LIKE ? OR u.especialidad LIKE ? OR u.biografia LIKE ?)";
    $like = '%' . $busqueda . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}

if (!empty($servicio)) {
    $sql .= " AND u.especialidad = ?";
    $params[] = $servicio;
    $types .= 's';
}

if (!empty($ciudad)) {
    $sql .= " AND u.ciudad LIKE ?";
    $params[] = '%' . $ciudad . '%';
    $types .= 's';
}

if ($calificacion_min > 0) {
    $sql .= " AND u.calificacion_promedio >= ?";
    $params[] = $calificacion_min;
    $types .= 'd';
}

$sql .= " ORDER BY u.calificacion_promedio DESC, u.destacado DESC, u.fecha_registro DESC";

// Preparar y ejecutar consulta
$stmt = mysqli_prepare($conn, $sql);
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$trabajadores = mysqli_fetch_all($result, MYSQLI_ASSOC);

// Obtener lista de especialidades para el filtro
$sql_especialidades = "SELECT DISTINCT especialidad FROM usuarios WHERE tipo = 'trabajador' AND especialidad IS NOT NULL AND especialidad != ''";
$result_esp = mysqli_query($conn, $sql_especialidades);
$especialidades = mysqli_fetch_all($result_esp, MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buscar Trabajadores - Fixi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.0/dist/cyborg/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .worker-card {
            transition: transform 0.3s, box-shadow 0.3s;
            cursor: pointer;
        }
        .worker-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 40px rgba(0, 212, 255, 0.2);
        }
        .worker-card img {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border: 2px solid #00d4ff;
        }
        .search-box {
            background: #0a111e;
            padding: 20px;
            border-radius: 12px;
            border: 1px solid #1a2a3f;
            margin-bottom: 30px;
        }
        .badge-especialidad {
            background: #00d4ff;
            color: #030507;
            padding: 5px 12px;
            border-radius: 20px;
            font-weight: 600;
        }
        .sin-resultados {
            text-align: center;
            padding: 60px 20px;
        }
        .sin-resultados i {
            font-size: 4rem;
            color: #1a2a3f;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <?php include 'assets/estrellas.php'; ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark-custom fixed-top">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <img src="assets/img/Logo FIXI.png" alt="Fixi Logo" onerror="this.style.display='none'">
                <i class=""></i>FIXI
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarFixi">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarFixi">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item"><a class="nav-link" href="cliente_dashboard.php"><i class="fas fa-home me-1"></i>Inicio</a></li>
                    <li class="nav-item"><a class="nav-link active" href="buscar_trabajadores.php"><i class="fas fa-search me-1"></i>Buscar</a></li>
                    <li class="nav-item"><a class="nav-link" href="cliente_dashboard.php#mis-solicitudes"><i class="fas fa-list me-1"></i>Mis Solicitudes</a></li>
                </ul>
                <div class="d-flex align-items-center">
                    <a href="perfil_usuario.php" class="btn btn-outline-info btn-sm me-2">
                        <i class="fas fa-user-edit me-1"></i>Mi Perfil
                    </a>
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
        <h1 class="display-6 mb-4"><i class="fas fa-search text-primary me-2"></i>Buscar Trabajadores</h1>
        <p class="text-muted">Encuentra al profesional ideal para tu proyecto.</p>

        <!-- Filtros -->
        <div class="search-box">
            <form method="GET" action="buscar_trabajadores.php" class="row g-3">
                <div class="col-md-4">
                    <label for="q" class="form-label"><i class="fas fa-search me-1"></i>Buscar</label>
                    <input type="text" class="form-control" id="q" name="q" placeholder="Nombre, especialidad..." value="<?= htmlspecialchars($busqueda) ?>">
                </div>
                <div class="col-md-3">
                    <label for="servicio" class="form-label"><i class="fas fa-briefcase me-1"></i>Servicio</label>
                    <select class="form-select" id="servicio" name="servicio">
                        <option value="">Todas las especialidades</option>
                        <?php foreach ($especialidades as $esp): ?>
                            <?php if (!empty($esp['especialidad'])): ?>
                                <option value="<?= htmlspecialchars($esp['especialidad']) ?>" <?= $servicio === $esp['especialidad'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($esp['especialidad']) ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="ciudad" class="form-label"><i class="fas fa-map-pin me-1"></i>Ciudad</label>
                    <input type="text" class="form-control" id="ciudad" name="ciudad" placeholder="Ej. CDMX" value="<?= htmlspecialchars($ciudad) ?>">
                </div>
                <div class="col-md-2">
                    <label for="calificacion" class="form-label"><i class="fas fa-star me-1"></i>Calificación</label>
                    <select class="form-select" id="calificacion" name="calificacion">
                        <option value="0">Todas</option>
                        <option value="3" <?= $calificacion_min === 3 ? 'selected' : '' ?>>3+ estrellas</option>
                        <option value="4" <?= $calificacion_min === 4 ? 'selected' : '' ?>>4+ estrellas</option>
                        <option value="4.5" <?= $calificacion_min === 4.5 ? 'selected' : '' ?>>4.5+ estrellas</option>
                    </select>
                </div>
                <div class="col-12 text-end">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search me-2"></i>Buscar</button>
                    <?php if (!empty($busqueda) || !empty($servicio) || !empty($ciudad) || $calificacion_min > 0): ?>
                        <a href="buscar_trabajadores.php" class="btn btn-outline-secondary">Limpiar</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Resultados -->
        <?php if (count($trabajadores) > 0): ?>
            <div class="row g-4">
                <?php foreach ($trabajadores as $t): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card worker-card h-100" onclick="window.location.href='perfil_trabajador.php?id=<?= $t['id'] ?>'">
                            <div class="card-body text-center">
                                <img src="<?= $t['foto'] ?? 'https://via.placeholder.com/100/0a111e/00d4ff?text=' . urlencode(substr($t['nombre'], 0, 1)) ?>" 
                                     class="rounded-circle mb-3" alt="<?= htmlspecialchars($t['nombre']) ?>">
                                <h5 class="card-title"><?= htmlspecialchars($t['nombre'] . ' ' . ($t['apellidos'] ?? '')) ?></h5>
                                <p class="text-primary">
                                    <span class="badge-especialidad"><?= htmlspecialchars($t['especialidad'] ?? 'General') ?></span>
                                </p>
                                <div class="mb-2">
                                    <?php
                                    $estrellas = round($t['calificacion_promedio'] ?? 0);
                                    for ($i = 1; $i <= 5; $i++) {
                                        echo $i <= $estrellas ? '<i class="fas fa-star text-warning"></i>' : '<i class="far fa-star text-muted"></i>';
                                    }
                                    ?>
                                    <span class="text-muted ms-1">(<?= $t['total_calificaciones'] ?? 0 ?>)</span>
                                </div>
                                <p class="text-muted small">
                                    <i class="fas fa-map-pin me-1"></i> <?= htmlspecialchars($t['ciudad'] ?? 'Ubicación no especificada') ?>
                                </p>
                                <p class="text-muted small">
                                    <i class="fas fa-briefcase me-1"></i> <?= $t['total_trabajos'] ?? 0 ?> trabajos realizados
                                </p>
                                <button class="btn btn-outline-primary btn-sm mt-2">
                                    <i class="fas fa-eye me-1"></i>Ver perfil
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="sin-resultados">
                <i class="fas fa-user-slash"></i>
                <h4>No se encontraron trabajadores</h4>
                <p class="text-muted">Prueba con otros términos de búsqueda o elimina algunos filtros.</p>
                <a href="buscar_trabajadores.php" class="btn btn-outline-primary">Ver todos</a>
            </div>
        <?php endif; ?>
    </div>

    <footer class="footer py-3 bg-dark-custom text-center">
        <div class="container">
            <p class="text-muted small mb-0">&copy; 2026 Fixi - Encuentra al profesional que necesitas</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>