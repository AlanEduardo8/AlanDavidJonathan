<?php
require_once 'config/database.php';
require_once 'config/auth.php';

if (!estaLogueado()) {
    redirigir('login.php');
}

// Solo clientes pueden ver el perfil público (opcional)
if (!esCliente()) {
    redirigir('dashboard.php');
}

$trabajador_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($trabajador_id <= 0) {
    die('<div class="container mt-5"><div class="alert alert-danger">ID de trabajador inválido. <a href="cliente_dashboard.php">Volver</a></div></div>');
}

// Obtener datos del trabajador
$sql_trabajador = "SELECT * FROM usuarios WHERE id = ? AND tipo = 'trabajador' AND verificado = 1";
$stmt = mysqli_prepare($conn, $sql_trabajador);
mysqli_stmt_bind_param($stmt, 'i', $trabajador_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$trabajador = mysqli_fetch_assoc($result);

if (!$trabajador) {
    die('<div class="container mt-5"><div class="alert alert-danger">Trabajador no encontrado o no verificado. <a href="cliente_dashboard.php">Volver</a></div></div>');
}

// Obtener trabajos realizados
$sql_trabajos = "SELECT * FROM trabajos_realizados WHERE trabajador_id = ? ORDER BY fecha_realizado DESC";
$stmt_trabajos = mysqli_prepare($conn, $sql_trabajos);
mysqli_stmt_bind_param($stmt_trabajos, 'i', $trabajador_id);
mysqli_stmt_execute($stmt_trabajos);
$result_trabajos = mysqli_stmt_get_result($stmt_trabajos);
$trabajos = mysqli_fetch_all($result_trabajos, MYSQLI_ASSOC);

// Obtener calificaciones del trabajador
$sql_calificaciones = "SELECT c.*, u.nombre as cliente_nombre, u.foto as cliente_foto 
                       FROM calificaciones c 
                       JOIN usuarios u ON c.cliente_id = u.id 
                       WHERE c.trabajador_id = ? 
                       ORDER BY c.fecha DESC";
$stmt_calif = mysqli_prepare($conn, $sql_calificaciones);
mysqli_stmt_bind_param($stmt_calif, 'i', $trabajador_id);
mysqli_stmt_execute($stmt_calif);
$result_calif = mysqli_stmt_get_result($stmt_calif);
$calificaciones = mysqli_fetch_all($result_calif, MYSQLI_ASSOC);

// Calcular promedio
$promedio = 0;
if (count($calificaciones) > 0) {
    $suma = array_sum(array_column($calificaciones, 'puntuacion'));
    $promedio = round($suma / count($calificaciones), 1);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($trabajador['nombre'] . ' ' . ($trabajador['apellidos'] ?? '')) ?> - Fixi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.0/dist/cyborg/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .perfil-trabajador { padding-top: 20px; }
        .foto-perfil-grande { width: 150px; height: 150px; object-fit: cover; border: 3px solid #00d4ff; }
        .galeria-imagen { height: 200px; object-fit: cover; border-radius: 8px; transition: transform 0.3s; }
        .galeria-imagen:hover { transform: scale(1.05); }
        .calificacion-item { border-left: 3px solid #00d4ff; padding-left: 15px; margin-bottom: 15px; }
        .especialidad-badge { background: #0a111e; color: #00d4ff; border: 1px solid #00d4ff; }
        .btn-contratar { background: #00d4ff; color: #030507; border: none; }
        .btn-contratar:hover { background: #00b8e6; color: #030507; }
        .no-trabajos { color: #8899aa; font-style: italic; }
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
            <div class="d-flex align-items-center">
                <a href="cliente_dashboard.php?tab=inicio" class="btn btn-outline-info btn-sm me-2">
                    <i class="fas fa-arrow-left me-1"></i>Volver
                </a>
                <span class="user-info me-2">
                    <i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['usuario_nombre']) ?>
                    <span class="badge bg-primary ms-1">Cliente</span>
                </span>
                <a href="logout.php" class="btn btn-outline-danger btn-sm">CERRAR SESIÓN</a>
            </div>
        </div>
    </nav>

    <div class="container perfil-trabajador" style="margin-top: 70px;">
        <div class="row">
            <div class="col-lg-8 mx-auto">
                <!-- Tarjeta principal -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 text-center">
                                <img src="<?= $trabajador['foto'] ?? 'https://via.placeholder.com/150' ?>" class="rounded-circle foto-perfil-grande" alt="<?= htmlspecialchars($trabajador['nombre']) ?>">
                            </div>
                            <div class="col-md-9">
                                <h2><?= htmlspecialchars($trabajador['nombre'] . ' ' . ($trabajador['apellidos'] ?? '')) ?></h2>
                                <p>
                                    <span class="badge especialidad-badge"><?= htmlspecialchars($trabajador['especialidad'] ?? 'General') ?></span>
                                    <span class="badge bg-success ms-2">
                                        <?php for ($i=1; $i<=5; $i++): ?>
                                            <?= $i <= $promedio ? '⭐' : '☆' ?>
                                        <?php endfor; ?>
                                        (<?= $promedio ?> / 5)
                                    </span>
                                    <span class="badge bg-info ms-2"><?= count($calificaciones) ?> calificaciones</span>
                                </p>
                                <p class="text-muted"><i class="fas fa-map-pin me-1"></i> <?= htmlspecialchars($trabajador['ciudad'] ?? '') ?>, <?= htmlspecialchars($trabajador['estado'] ?? '') ?></p>
                                <p><?= nl2br(htmlspecialchars($trabajador['biografia'] ?? '')) ?></p>
                                <p><strong>Experiencia:</strong> <?= nl2br(htmlspecialchars($trabajador['experiencia'] ?? '')) ?></p>
                                <a href="cliente_dashboard.php?tab=inicio&buscar=<?= urlencode($trabajador['especialidad']) ?>" class="btn btn-outline-primary btn-sm">Buscar más como él</a>
                                <!-- Botón CONTRATAR DIRECTO -->
                                <a href="cliente_dashboard.php?tab=nueva&trabajador_id=<?= $trabajador_id ?>" class="btn btn-contratar btn-sm ms-2">
                                    <i class="fas fa-handshake me-1"></i>Contratar directamente
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Galería de trabajos realizados -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-images text-primary me-2"></i>Trabajos realizados</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($trabajos) > 0): ?>
                            <div class="row">
                                <?php foreach ($trabajos as $trabajo): ?>
                                    <div class="col-md-4 col-sm-6 mb-3">
                                        <div class="card h-100">
                                            <img src="<?= $trabajo['imagen'] ?? 'https://via.placeholder.com/300x200/0a111e/00d4ff?text=Sin+imagen' ?>" class="galeria-imagen card-img-top" alt="<?= htmlspecialchars($trabajo['titulo']) ?>">
                                            <div class="card-body">
                                                <h6 class="card-title"><?= htmlspecialchars($trabajo['titulo']) ?></h6>
                                                <p class="card-text small text-muted"><?= htmlspecialchars($trabajo['descripcion'] ?? '') ?></p>
                                                <small class="text-muted"><i class="far fa-calendar-alt me-1"></i> <?= date('d/m/Y', strtotime($trabajo['fecha_realizado'])) ?></small>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="no-trabajos">Este trabajador aún no ha subido trabajos realizados.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Calificaciones recientes -->
                <?php if (count($calificaciones) > 0): ?>
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-star text-warning me-2"></i>Calificaciones recientes</h5>
                    </div>
                    <div class="card-body">
                        <?php foreach (array_slice($calificaciones, 0, 5) as $calif): ?>
                            <div class="calificacion-item">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <strong><?= htmlspecialchars($calif['cliente_nombre']) ?></strong>
                                        <span class="small">
                                            <?php for ($i=1; $i<=5; $i++): ?>
                                                <?= $i <= $calif['puntuacion'] ? '⭐' : '☆' ?>
                                            <?php endfor; ?>
                                        </span>
                                    </div>
                                    <small class="text-muted"><?= date('d/m/Y', strtotime($calif['fecha'])) ?></small>
                                </div>
                                <p class="mb-0 small"><?= htmlspecialchars($calif['comentario'] ?? 'Sin comentario') ?></p>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($calificaciones) > 5): ?>
                            <p class="text-muted small">... y <?= count($calificaciones) - 5 ?> calificaciones más.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>