<?php
require_once 'config/database.php';
require_once 'config/auth.php';

if (!estaLogueado()) redirigir('login.php');

$solicitud_id = isset($_GET['solicitud']) ? intval($_GET['solicitud']) : 0;
if ($solicitud_id <= 0) die('Solicitud inválida.');

$usuario_id = $_SESSION['usuario_id'];
$csrf_token = generarTokenCSRF();

$sql = "SELECT * FROM solicitudes WHERE id = ? AND cliente_id = ? AND estado = 'asignado' AND metodo_pago = 'tarjeta'";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'ii', $solicitud_id, $usuario_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$solicitud = mysqli_fetch_assoc($result);

if (!$solicitud) die('No puedes pagar esta solicitud o ya fue pagada.');
if ($solicitud['estado_pago'] === 'pagado') { echo '<div class="alert alert-success">Esta solicitud ya fue pagada.</div>'; exit; }

$error = '';
$simulando = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simular_pago'])) {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        $error = 'Solicitud no válida (CSRF).';
    } else {
        $simulando = true;
        sleep(2);
        $sql_update = "UPDATE solicitudes SET estado_pago = 'pagado' WHERE id = ?";
        $stmt_update = mysqli_prepare($conn, $sql_update);
        mysqli_stmt_bind_param($stmt_update, 'i', $solicitud_id);
        mysqli_stmt_execute($stmt_update);
        header("Location: pago_exitoso.php?solicitud=" . $solicitud_id);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagar con Tarjeta - Fixi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.0/dist/cyborg/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .card-form { max-width: 500px; margin: 0 auto; }
        .card-form .form-control { background: #0a111e; border: 1px solid #1a2a3f; color: #e0e6ed; }
        .card-form .form-control:focus { border-color: #00d4ff; box-shadow: 0 0 0 0.25rem rgba(0,212,255,0.25); }
        .card-form .btn-pagar { background: #00d4ff; color: #030507; border: none; font-weight: 600; }
        .card-form .btn-pagar:hover { background: #00b8e6; }
        .dashboard-content { position: relative; z-index: 1; }
        #loadingSpinner { display: none; }
        .spinner-message { font-size: 0.9rem; margin-top: 5px; color: #8899aa; }
    </style>
</head>
<body>
    <?php include 'assets/estrellas.php'; ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark-custom fixed-top" style="z-index:10;">
        <div class="container">
            <a class="navbar-brand" href="index.php"><img src="assets/img/Logo FIXI.png" alt="Fixi Logo" onerror="this.style.display='none'">FIXI</a>
            <div class="d-flex align-items-center">
                <a href="solicitud_detalle.php?id=<?= $solicitud_id ?>" class="btn btn-outline-info btn-sm me-2"><i class="fas fa-arrow-left me-1"></i>Volver</a>
                <span class="user-info me-2"><i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['usuario_nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                <a href="logout.php" class="btn btn-outline-danger btn-sm">CERRAR SESIÓN</a>
            </div>
        </div>
    </nav>

    <div class="container py-5 dashboard-content" style="margin-top: 60px;">
        <div class="card card-form">
            <div class="card-header"><h4 class="mb-0"><i class="fas fa-credit-card text-primary me-2"></i>Pago con Tarjeta</h4></div>
            <div class="card-body">
                <div class="alert alert-info">
                    <strong>Servicio:</strong> <?= htmlspecialchars($solicitud['servicio'] ?? '', ENT_QUOTES, 'UTF-8') ?><br>
                    <strong>Monto a pagar:</strong> <span class="text-success">$<?= number_format($solicitud['presupuesto'], 2) ?></span>
                </div>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if ($simulando): ?>
                    <div class="alert alert-info text-center"><i class="fas fa-spinner fa-spin me-2"></i> Procesando pago... por favor espera.</div>
                <?php endif; ?>
                <form method="POST" action="" id="paymentForm">
                    <input type="hidden" name="simular_pago" value="1">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <div class="mb-3">
                        <label class="form-label">Número de tarjeta</label>
                        <input type="text" class="form-control" id="cardNumber" placeholder="1234 5678 9012 3456" maxlength="19" value="5031 7557 3453 0604">
                        <div id="cardNumberError" class="text-danger small"></div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Fecha de expiración (MM/AA)</label>
                            <input type="text" class="form-control" id="cardExpiration" placeholder="MM/AA" maxlength="5" value="11/25">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">CVV</label>
                            <input type="text" class="form-control" id="cardSecurityCode" placeholder="123" maxlength="4" value="123">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Titular de la tarjeta</label>
                        <input type="text" class="form-control" id="cardholderName" placeholder="Nombre como aparece en la tarjeta" value="<?= htmlspecialchars($_SESSION['usuario_nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Cantidad de cuotas</label>
                        <select class="form-select" id="installmentsSelect">
                            <option value="1">1 cuota</option>
                            <option value="2">2 cuotas</option>
                            <option value="3">3 cuotas</option>
                            <option value="6">6 cuotas</option>
                            <option value="12">12 cuotas</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-pagar btn-lg w-100" id="payButton">
                        <span id="buttonText"><i class="fas fa-lock me-2"></i>Pagar $<?= number_format($solicitud['presupuesto'], 2) ?></span>
                        <span id="loadingSpinner"><i class="fas fa-spinner fa-spin me-2"></i>Procesando... no cierres esta ventana</span>
                    </button>
                    <div id="loadingMessage" class="spinner-message text-center" style="display:none;">
                        <small>El pago puede tardar unos segundos. Por favor espera.</small>
                    </div>
                </form>
                <p class="text-muted mt-3 small"><i class="fas fa-shield-alt me-1"></i> Tus datos están seguros. El pago se procesa a través de Mercado Pago.</p>
            </div>
        </div>
    </div>

    <script>
        const cardNumber = document.getElementById('cardNumber');
        const cardExpiration = document.getElementById('cardExpiration');
        const cardSecurityCode = document.getElementById('cardSecurityCode');
        const cardholderName = document.getElementById('cardholderName');
        const payButton = document.getElementById('payButton');
        const paymentForm = document.getElementById('paymentForm');
        const buttonText = document.getElementById('buttonText');
        const loadingSpinner = document.getElementById('loadingSpinner');
        const loadingMessage = document.getElementById('loadingMessage');

        cardNumber.addEventListener('input', function(e) {
            let value = this.value.replace(/\D/g, '');
            if (value.length > 16) value = value.slice(0, 16);
            let formatted = '';
            for (let i = 0; i < value.length; i++) {
                if (i > 0 && i % 4 === 0) formatted += ' ';
                formatted += value[i];
            }
            this.value = formatted;
            document.getElementById('cardNumberError').textContent = '';
        });

        cardExpiration.addEventListener('input', function(e) {
            let value = this.value.replace(/\D/g, '');
            if (value.length > 4) value = value.slice(0, 4);
            if (value.length > 2) {
                this.value = value.slice(0, 2) + '/' + value.slice(2);
            } else {
                this.value = value;
            }
        });

        paymentForm.addEventListener('submit', function(e) {
            const rawNumber = cardNumber.value.replace(/\s/g, '');
            if (rawNumber.length < 16) {
                e.preventDefault();
                document.getElementById('cardNumberError').textContent = 'Número de tarjeta inválido.';
                cardNumber.focus();
                return;
            }
            buttonText.style.display = 'none';
            loadingSpinner.style.display = 'inline';
            loadingMessage.style.display = 'block';
            payButton.disabled = true;
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>