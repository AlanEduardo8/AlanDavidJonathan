<?php
require_once 'config/auth.php';
if (!estaLogueado()) redirigir('login.php');
?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Pago Pendiente - Fixi</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.0/dist/cyborg/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<link rel="stylesheet" href="assets/css/style.css"></head>
<body>
<?php include 'assets/estrellas.php'; ?>
<div class="container py-5" style="position:relative;z-index:1;">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card text-center">
                <div class="card-body">
                    <i class="fas fa-clock text-warning" style="font-size:5rem;"></i>
                    <h2 class="mt-3">Pago en Proceso</h2>
                    <p class="text-muted">Tu pago está siendo procesado. Recibirás una confirmación en breve.</p>
                    <a href="cliente_dashboard.php" class="btn btn-primary">Ir al panel</a>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>