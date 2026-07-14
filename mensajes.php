<?php
require_once 'config/database.php';
require_once 'config/auth.php';

if (!estaLogueado()) redirigir('login.php');

$usuario_id = $_SESSION['usuario_id'];
$es_cliente = esCliente();
$es_trabajador = esTrabajador();

if (!$es_cliente && !$es_trabajador) redirigir('index.php');

if ($es_cliente) {
    $sql = "SELECT DISTINCT s.id, s.servicio, s.estado, s.presupuesto,
                   u.nombre as otro_nombre, u.apellidos as otro_apellidos, u.foto as otro_foto,
                   (SELECT mensaje FROM mensajes WHERE solicitud_id = s.id ORDER BY fecha DESC LIMIT 1) as ultimo_mensaje,
                   (SELECT fecha FROM mensajes WHERE solicitud_id = s.id ORDER BY fecha DESC LIMIT 1) as ultima_fecha,
                   (SELECT COUNT(*) FROM mensajes WHERE solicitud_id = s.id AND receptor_id = ? AND leido = 0) as no_leidos
            FROM solicitudes s
            JOIN usuarios u ON s.trabajador_id = u.id
            WHERE s.cliente_id = ? AND s.trabajador_id IS NOT NULL
            ORDER BY ultima_fecha DESC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ii', $usuario_id, $usuario_id);
} else {
    $sql = "SELECT DISTINCT s.id, s.servicio, s.estado, s.presupuesto,
                   u.nombre as otro_nombre, u.apellidos as otro_apellidos, u.foto as otro_foto,
                   (SELECT mensaje FROM mensajes WHERE solicitud_id = s.id ORDER BY fecha DESC LIMIT 1) as ultimo_mensaje,
                   (SELECT fecha FROM mensajes WHERE solicitud_id = s.id ORDER BY fecha DESC LIMIT 1) as ultima_fecha,
                   (SELECT COUNT(*) FROM mensajes WHERE solicitud_id = s.id AND receptor_id = ? AND leido = 0) as no_leidos
            FROM solicitudes s
            JOIN usuarios u ON s.cliente_id = u.id
            WHERE s.trabajador_id = ? AND s.trabajador_id IS NOT NULL
            ORDER BY ultima_fecha DESC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ii', $usuario_id, $usuario_id);
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$conversaciones = mysqli_fetch_all($result, MYSQLI_ASSOC);

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
    <title>Mis Mensajes - Fixi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.0/dist/cyborg/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .dashboard-content { position: relative; z-index: 1; }
        .conversacion-item { transition: background 0.2s; cursor: pointer; }
        .conversacion-item:hover { background: #0f1a2b; }
        .foto-conversacion { width: 50px; height: 50px; object-fit: cover; border: 2px solid #00d4ff; }
        .no-leidos-badge { background: #dc3545; color: #fff; border-radius: 50%; padding: 2px 8px; font-size: 0.8rem; }
        .estado-badge { font-size: 0.7rem; }
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
                    <li class="nav-item"><a class="nav-link" href="<?= esCliente() ? 'cliente_dashboard.php' : 'trabajador_dashboard.php' ?>"><i class="fas fa-home me-1"></i>Inicio</a></li>
                    <li class="nav-item"><a class="nav-link active" href="mensajes.php"><i class="fas fa-envelope me-1"></i>Mis Mensajes</a></li>
                </ul>
                <div class="d-flex align-items-center">
                    <a href="perfil_usuario.php" class="btn btn-outline-info btn-sm me-2"><i class="fas fa-user-edit me-1"></i>Mi Perfil</a>
                    <?php if ($total_mensajes_no_leidos > 0): ?>
                        <span class="badge bg-danger rounded-pill me-2"><?= $total_mensajes_no_leidos ?></span>
                    <?php endif; ?>
                    <span class="user-info me-2">
                        <i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['usuario_nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                        <span class="badge bg-primary ms-1"><?= htmlspecialchars($_SESSION['usuario_tipo'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                    </span>
                    <a href="logout.php" class="btn btn-outline-danger btn-sm">CERRAR SESIÓN</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container py-4 dashboard-content" style="margin-top: 60px;">
        <h1 class="display-6 mb-4"><i class="fas fa-envelope text-primary me-2"></i>Mis Mensajes</h1>

        <?php if (count($conversaciones) > 0): ?>
            <div class="list-group">
                <?php foreach ($conversaciones as $conv): ?>
                    <a href="mensajes_detalle.php?solicitud_id=<?= $conv['id'] ?>" class="list-group-item list-group-item-action bg-dark-custom text-light border-secondary conversacion-item">
                        <div class="d-flex align-items-center">
                            <img src="<?= htmlspecialchars($conv['otro_foto'] ?? 'https://via.placeholder.com/50', ENT_QUOTES, 'UTF-8') ?>" class="rounded-circle foto-conversacion me-3">
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0"><?= htmlspecialchars(($conv['otro_nombre'] ?? '') . ' ' . ($conv['otro_apellidos'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h6>
                                    <small class="text-muted"><?= date('d/m/Y H:i', strtotime($conv['ultima_fecha'])) ?></small>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <small class="text-muted"><?= htmlspecialchars($conv['servicio'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                                        <span class="badge estado-badge bg-<?= ['pendiente'=>'warning','asignado'=>'info','en_curso'=>'primary','completado'=>'success','cancelado'=>'danger'][$conv['estado']] ?? 'secondary' ?> ms-2"><?= strtoupper($conv['estado']) ?></span>
                                        <?php if ($conv['presupuesto']): ?>
                                            <span class="badge bg-secondary ms-1">$<?= number_format($conv['presupuesto'], 2) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($conv['no_leidos'] > 0): ?>
                                        <span class="no-leidos-badge"><?= $conv['no_leidos'] ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($conv['ultimo_mensaje']): ?>
                                    <p class="mb-0 small text-muted"><?= htmlspecialchars(substr($conv['ultimo_mensaje'], 0, 60), ENT_QUOTES, 'UTF-8') ?>...</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-inbox fa-3x mb-3"></i>
                <h5>No tienes conversaciones aún</h5>
                <p class="text-muted">Cuando aceptes o te asignen solicitudes, podrás chatear con la otra persona.</p>
                <?php if (esCliente()): ?>
                    <a href="buscar_servicios.php" class="btn btn-primary">Buscar profesionales</a>
                <?php else: ?>
                    <a href="trabajador_dashboard.php?tab=disponibles" class="btn btn-primary">Ver solicitudes disponibles</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>