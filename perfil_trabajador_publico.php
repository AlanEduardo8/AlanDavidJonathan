<?php
require_once 'config/database.php';
require_once 'config/auth.php';

if (!estaLogueado() || !esCliente()) {
    redirigir('login.php');
}

$trabajador_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($trabajador_id <= 0) {
    redirigir('buscar_servicios.php');
}

// Obtener datos del trabajador
$sql = "SELECT u.*, 
               (SELECT COUNT(*) FROM calificaciones WHERE trabajador_id = u.id) as total_calificaciones,
               (SELECT AVG(puntuacion) FROM calificaciones WHERE trabajador_id = u.id) as promedio
        FROM usuarios u
        WHERE u.id = ? AND u.tipo = 'trabajador'";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'i', $trabajador_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$trabajador = mysqli_fetch_assoc($result);

if (!$trabajador) {
    redirigir('buscar_servicios.php');
}

// Obtener trabajos realizados (galería)
$sql_trabajos = "SELECT * FROM trabajos_realizados WHERE trabajador_id = ? AND activo = 1 ORDER BY fecha_realizado DESC LIMIT 20";
$stmt_trabajos = mysqli_prepare($conn, $sql_trabajos);
mysqli_stmt_bind_param($stmt_trabajos, 'i', $trabajador_id);
mysqli_stmt_execute($stmt_trabajos);
$result_trabajos = mysqli_stmt_get_result($stmt_trabajos);
$trabajos = mysqli_fetch_all($result_trabajos, MYSQLI_ASSOC);

// Obtener calificaciones
$sql_calif = "SELECT c.*, u.nombre as cliente_nombre 
              FROM calificaciones c 
              JOIN usuarios u ON c.cliente_id = u.id 
              WHERE c.trabajador_id = ? 
              ORDER BY c.fecha DESC LIMIT 5";
$stmt_calif = mysqli_prepare($conn, $sql_calif);
mysqli_stmt_bind_param($stmt_calif, 'i', $trabajador_id);
mysqli_stmt_execute($stmt_calif);
$result_calif = mysqli_stmt_get_result($stmt_calif);
$calificaciones = mysqli_fetch_all($result_calif, MYSQLI_ASSOC);

// Contar mensajes no leídos
$usuario_id = $_SESSION['usuario_id'];
$sql_total_no_leidos = "SELECT COUNT(*) as total FROM mensajes WHERE receptor_id = ? AND leido = 0";
$stmt_total = mysqli_prepare($conn, $sql_total_no_leidos);
mysqli_stmt_bind_param($stmt_total, 'i', $usuario_id);
mysqli_stmt_execute($stmt_total);
$result_total = mysqli_stmt_get_result($stmt_total);
$row_total = mysqli_fetch_assoc($result_total);
$total_mensajes_no_leidos = $row_total['total'] ?? 0;

// Calcular promedio
$promedio = round($trabajador['promedio'] ?? 0, 1);
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
        .perfil-header {
            background: linear-gradient(135deg, #0f1a2b 0%, #1a2a3f 100%);
            border-bottom: 2px solid #00d4ff;
            padding: 2rem 0;
        }
        .foto-perfil-grande {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border: 3px solid #00d4ff;
        }
        .galeria-img {
            height: 200px;
            object-fit: cover;
            border-radius: 8px;
            transition: transform 0.3s;
            cursor: pointer;
        }
        .galeria-img:hover {
            transform: scale(1.05);
        }
        .calificacion-item {
            background: #0a111e;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            border-left: 3px solid #00d4ff;
        }
        .badge-especialidad {
            background: #00d4ff;
            color: #030507;
            padding: 6px 15px;
            border-radius: 20px;
            font-weight: 600;
        }
        .modal-imagen {
            max-width: 100%;
            max-height: 80vh;
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
            <!-- Logo dinámico -->
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
                    <li class="nav-item"><a class="nav-link" href="buscar_servicios.php"><i class="fas fa-search me-1"></i>Explorar</a></li>
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

    <!-- Header del perfil -->
    <div class="perfil-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-2 text-center">
                    <?php if (!empty($trabajador['foto'])): ?>
                        <img src="<?= $trabajador['foto'] ?>" class="rounded-circle foto-perfil-grande" alt="<?= htmlspecialchars($trabajador['nombre']) ?>">
                    <?php else: ?>
                        <i class="fas fa-user-circle fa-7x text-muted"></i>
                    <?php endif; ?>
                </div>
                <div class="col-md-7">
                    <h2><?= htmlspecialchars($trabajador['nombre'] . ' ' . ($trabajador['apellidos'] ?? '')) ?></h2>
                    <p class="text-muted">
                        <i class="fas fa-map-pin me-1"></i> <?= htmlspecialchars($trabajador['ciudad'] ?? 'Ciudad no especificada') ?>
                        <?php if ($trabajador['estado']): ?>, <?= htmlspecialchars($trabajador['estado']) ?><?php endif; ?>
                    </p>
                    <div class="mb-2">
                        <span class="badge-especialidad"><?= htmlspecialchars($trabajador['especialidad'] ?? 'General') ?></span>
                        <?php if ($trabajador['verificado']): ?>
                            <span class="badge badge-verificado ms-2"><i class="fas fa-check-circle me-1"></i>Verificado</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <?= $i <= round($promedio) ? '<i class="fas fa-star text-warning"></i>' : '<i class="far fa-star text-muted"></i>' ?>
                        <?php endfor; ?>
                        <span class="text-muted ms-2">(<?= $trabajador['total_calificaciones'] ?? 0 ?> calificaciones)</span>
                    </div>
                </div>
                <div class="col-md-3 text-end">
                    <div class="d-grid gap-2">
                        <a href="cliente_dashboard.php?tab=nueva&trabajador_id=<?= $trabajador_id ?>" class="btn btn-primary btn-lg">
                            <i class="fas fa-handshake me-2"></i>Contratar
                        </a>
                        <a href="conversacion_detalle.php?trabajador_id=<?= $trabajador_id ?>" class="btn btn-outline-success btn-lg">
                            <i class="fas fa-comment me-2"></i>Mensajear
                        </a>
                    </div>
                    <small class="text-muted d-block mt-1">Chatea antes de contratar</small>
                </div>
            </div>
        </div>
    </div>

    <div class="container py-4">
        <div class="row">
            <div class="col-lg-8">
                <!-- Biografía -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-user text-primary me-2"></i>Acerca de</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($trabajador['biografia'])): ?>
                            <p><?= nl2br(htmlspecialchars($trabajador['biografia'])) ?></p>
                        <?php else: ?>
                            <p class="text-muted">Este profesional aún no ha completado su biografía.</p>
                        <?php endif; ?>
                        <?php if (!empty($trabajador['experiencia'])): ?>
                            <h6 class="mt-3"><i class="fas fa-briefcase text-primary me-2"></i>Experiencia</h6>
                            <p><?= nl2br(htmlspecialchars($trabajador['experiencia'])) ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Galería de trabajos realizados -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-images text-primary me-2"></i>Trabajos realizados</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($trabajos) > 0): ?>
                            <div class="row g-3">
                                <?php foreach ($trabajos as $t): ?>
                                    <div class="col-md-4 col-6">
                                        <div class="position-relative">
                                            <img src="<?= $t['imagen'] ?>" class="galeria-img w-100" alt="<?= htmlspecialchars($t['titulo']) ?>" data-bs-toggle="modal" data-bs-target="#modalGaleria" data-img="<?= $t['imagen'] ?>" data-title="<?= htmlspecialchars($t['titulo']) ?>" data-desc="<?= htmlspecialchars($t['descripcion'] ?? '') ?>">
                                            <?php if (!empty($t['titulo'])): ?>
                                                <div class="position-absolute bottom-0 start-0 end-0 p-2" style="background: rgba(0,0,0,0.7); border-radius: 0 0 8px 8px;">
                                                    <small class="text-white"><?= htmlspecialchars($t['titulo']) ?></small>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted">Este profesional aún no ha subido trabajos realizados.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Calificaciones -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-star text-warning me-2"></i>Calificaciones recientes</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($calificaciones) > 0): ?>
                            <?php foreach ($calificaciones as $c): ?>
                                <div class="calificacion-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <strong><?= htmlspecialchars($c['cliente_nombre']) ?></strong>
                                        <small class="text-muted"><?= date('d/m/Y', strtotime($c['fecha'])) ?></small>
                                    </div>
                                    <div class="mb-1">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <?= $i <= $c['puntuacion'] ? '⭐' : '☆' ?>
                                        <?php endfor; ?>
                                    </div>
                                    <?php if (!empty($c['comentario'])): ?>
                                        <p class="mb-0 text-muted small">"<?= htmlspecialchars($c['comentario']) ?>"</p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted">Este profesional aún no tiene calificaciones.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Sidebar derecho -->
            <div class="col-lg-4">
                <!-- Información rápida -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-info-circle text-primary me-2"></i>Información</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled">
                            <li class="mb-2"><i class="fas fa-phone text-primary me-2"></i> <strong>Teléfono:</strong> <?= htmlspecialchars($trabajador['telefono'] ?? 'No disponible') ?></li>
                            <li class="mb-2"><i class="fas fa-envelope text-primary me-2"></i> <strong>Email:</strong> <?= htmlspecialchars($trabajador['email']) ?></li>
                            <li class="mb-2"><i class="fas fa-map-pin text-primary me-2"></i> <strong>Ubicación:</strong> <?= htmlspecialchars($trabajador['ciudad'] ?? 'No especificada') ?></li>
                            <?php if (!empty($trabajador['especialidad'])): ?>
                                <li class="mb-2"><i class="fas fa-tools text-primary me-2"></i> <strong>Especialidad:</strong> <?= htmlspecialchars($trabajador['especialidad']) ?></li>
                            <?php endif; ?>
                            <li><i class="fas fa-star text-warning me-2"></i> <strong>Calificación:</strong> <?= $promedio ?> ⭐ (<?= $trabajador['total_calificaciones'] ?? 0 ?> calificaciones)</li>
                        </ul>
                    </div>
                </div>

                <!-- Botones de acción -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-bolt text-primary me-2"></i>Acciones</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="cliente_dashboard.php?tab=nueva&trabajador_id=<?= $trabajador_id ?>" class="btn btn-primary">
                                <i class="fas fa-handshake me-2"></i>Contratar
                            </a>
                            <a href="conversacion_detalle.php?trabajador_id=<?= $trabajador_id ?>" class="btn btn-outline-success">
                                <i class="fas fa-comment me-2"></i>Mensajear
                            </a>
                            <a href="buscar_servicios.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i>Volver a resultados
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para ver imagen ampliada -->
    <div class="modal fade" id="modalGaleria" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content bg-dark-custom">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalGaleriaTitle">Título</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <img src="" class="modal-imagen" id="modalGaleriaImg" alt="">
                    <p class="mt-2 text-muted" id="modalGaleriaDesc"></p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Galería - pasar datos al modal
        const modalGaleria = document.getElementById('modalGaleria');
        modalGaleria.addEventListener('show.bs.modal', function(event) {
            const img = event.relatedTarget;
            document.getElementById('modalGaleriaImg').src = img.getAttribute('data-img');
            document.getElementById('modalGaleriaTitle').textContent = img.getAttribute('data-title') || 'Trabajo realizado';
            document.getElementById('modalGaleriaDesc').textContent = img.getAttribute('data-desc') || '';
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>