<?php
require_once 'config/database.php';
require_once 'config/auth.php';
if (!estaLogueado()) redirigir('login.php');
$solicitud_id = isset($_GET['solicitud']) ? intval($_GET['solicitud']) : 0;
if ($solicitud_id <= 0) die('Solicitud no válida.');
?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Pago Fallido - Fixi</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.0/dist/cyborg/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<link rel="stylesheet" href="assets/css/style.css"></head>
<body>
<?php include 'assets/estrellas.php'; ?>
<div class="container py-5" style="margin-top:60px;position:relative;z-index:1;">
    <div class="alert alert-danger text-center">
        <i class="fas fa-times-circle fa-3x mb-3"></i>
        <h2>¡Hubo un problema con el pago!</h2>
        <p>No se pudo procesar tu pago para la solicitud #<?= $solicitud_id ?>.</p>
        <p>Por favor, intenta nuevamente o contacta a soporte.</p>
        <a href="pago.php?solicitud=<?= $solicitud_id ?>" class="btn btn-primary mt-3"><i class="fas fa-credit-card me-2"></i>Reintentar pago</a>
        <a href="cliente_dashboard.php" class="btn btn-outline-secondary mt-3"><i class="fas fa-home me-2"></i>Ir al inicio</a>
    </div>
</div>
</body>
</html>