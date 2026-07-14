<?php
require_once 'config/database.php';
require_once 'config/auth.php';
require_once 'config/mercadopago.php';

if (!estaLogueado() || !esCliente()) redirigir('login.php');

$solicitud_id = isset($_GET['solicitud']) ? intval($_GET['solicitud']) : 0;
if ($solicitud_id <= 0) die('ID de solicitud inválido.');

$usuario_id = $_SESSION['usuario_id'];
$csrf_token = generarTokenCSRF();

$sql = "SELECT s.*, u.nombre as cliente_nombre, u.email as cliente_email
        FROM solicitudes s
        JOIN usuarios u ON s.cliente_id = u.id
        WHERE s.id = ? AND s.cliente_id = ? AND s.estado != 'cancelado' AND s.estado != 'completado' AND s.metodo_pago = 'tarjeta'";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'ii', $solicitud_id, $usuario_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$solicitud = mysqli_fetch_assoc($result);

if (!$solicitud) die('No puedes pagar esta solicitud o ya está completada.');
if ($solicitud['pagado']) die('Esta solicitud ya fue pagada.');
if (empty($solicitud['presupuesto']) || $solicitud['presupuesto'] <= 0) die('El trabajador aún no ha establecido el precio.');

$monto = $solicitud['presupuesto'];
$descripcion = "Pago de servicio Fixi #" . $solicitud_id . " - " . $solicitud['servicio'];
$cliente_nombre = $solicitud['cliente_nombre'];
$cliente_email = $solicitud['cliente_email'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'procesar_pago') {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        $error = 'Solicitud no válida (CSRF).';
    } else {
        $token = $_POST['token'] ?? '';
        $payment_method_id = $_POST['payment_method_id'] ?? '';
        $installments = intval($_POST['installments'] ?? 1);
        if (empty($token) || empty($payment_method_id)) {
            $error = 'Datos de pago incompletos.';
        } else {
            $payment_data = [
                'transaction_amount' => floatval($monto),
                'token' => $token,
                'description' => $descripcion,
                'installments' => $installments,
                'payment_method_id' => $payment_method_id,
                'payer' => [
                    'email' => $cliente_email,
                    'first_name' => $cliente_nombre,
                    'identification' => ['type' => 'DNI', 'number' => '12345678']
                ],
                'metadata' => ['solicitud_id' => $solicitud_id, 'cliente_id' => $usuario_id]
            ];
            $url = 'https://api.mercadopago.com/v1/payments';
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payment_data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . MP_ACCESS_TOKEN
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
            $resultado = json_decode($response, true);
            if (isset($resultado['id']) && $resultado['status'] === 'approved') {
                $transaction_id = $resultado['id'];
                $update = mysqli_prepare($conn, "UPDATE solicitudes SET pagado = 1, transaction_id = ?, fecha_pago = NOW() WHERE id = ?");
                mysqli_stmt_bind_param($update, 'si', $transaction_id, $solicitud_id);
                mysqli_stmt_execute($update);
                header("Location: pago_exitoso.php?solicitud=" . $solicitud_id);
                exit;
            } else {
                $error = 'Error al procesar el pago: ' . ($resultado['message'] ?? 'Intenta nuevamente.');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pago con Tarjeta - Fixi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.0/dist/cyborg/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://sdk.mercadopago.com/js/v2"></script>
    <style>
        .pago-container { max-width: 500px; margin: 0 auto; }
        .card-pago { background: #0a111e; border: 1px solid #1a2a3f; border-radius: 16px; padding: 30px; }
        .mp-card { border: 1px solid #1a2a3f; border-radius: 8px; padding: 15px; background: #0a111e; }
        .mp-card input, .mp-card select { background: #05080d; border: 1px solid #1a2a3f; color: #e0e6ed; }
        .mp-card input:focus, .mp-card select:focus { border-color: #00d4ff; box-shadow: none; }
    </style>
</head>
<body>
<?php include 'assets/estrellas.php'; ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark-custom fixed-top" style="z-index:10;">
    <div class="container">
        <a class="navbar-brand" href="<?= esCliente() ? 'cliente_dashboard.php' : 'index.php' ?>">
            <img src="assets/img/Logo FIXI.png" alt="Fixi Logo" onerror="this.style.display='none'"> FIXI
        </a>
        <div class="d-flex align-items-center">
            <a href="solicitud_detalle.php?id=<?= $solicitud_id ?>" class="btn btn-outline-info btn-sm me-2"><i class="fas fa-arrow-left me-1"></i>Volver</a>
            <span class="user-info me-2"><i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['usuario_nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?> <span class="badge bg-primary ms-1">Cliente</span></span>
            <a href="logout.php" class="btn btn-outline-danger btn-sm">CERRAR SESIÓN</a>
        </div>
    </div>
</nav>

<div class="container py-5" style="margin-top:60px;position:relative;z-index:1;">
    <div class="pago-container">
        <div class="card-pago">
            <h2 class="text-center mb-4"><i class="fas fa-credit-card text-primary me-2"></i>Pago con Tarjeta</h2>
            <div class="text-center mb-4">
                <h5><?= htmlspecialchars($descripcion, ENT_QUOTES, 'UTF-8') ?></h5>
                <h3 class="text-primary">$<?= number_format($monto, 2) ?></h3>
            </div>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <form id="paymentForm" method="POST" action="">
                <input type="hidden" name="action" value="procesar_pago">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" id="token" name="token">
                <input type="hidden" id="payment_method_id" name="payment_method_id">
                <input type="hidden" id="installments" name="installments" value="1">
                <div class="mp-card">
                    <div class="mb-3">
                        <label class="form-label">Número de tarjeta</label>
                        <input type="text" class="form-control" id="cardNumber" placeholder="1234 5678 9012 3456" maxlength="19" autocomplete="cc-number">
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Mes</label>
                            <select class="form-select" id="cardExpirationMonth">
                                <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?= sprintf('%02d', $i) ?>"><?= sprintf('%02d', $i) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Año</label>
                            <select class="form-select" id="cardExpirationYear">
                                <?php for ($i = date('Y'); $i <= date('Y') + 10; $i++): ?>
                                    <option value="<?= $i ?>"><?= $i ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">CVV</label>
                            <input type="password" class="form-control" id="cardSecurityCode" placeholder="123" maxlength="4" autocomplete="cc-csc">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Nombre del titular</label>
                            <input type="text" class="form-control" id="cardholderName" placeholder="Como aparece en la tarjeta" autocomplete="cc-name">
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-lg w-100 mt-3" id="payButton">
                    <i class="fas fa-lock me-2"></i>Pagar $<?= number_format($monto, 2) ?>
                </button>
            </form>
            <div class="text-center mt-3">
                <small class="text-muted">Datos encriptados y procesados de forma segura.</small>
                <br>
                <small class="text-muted">Usa tarjeta de prueba: <strong>5031 7557 3453 0604</strong> (CVV 123, exp 11/25)</small>
            </div>
        </div>
    </div>
</div>

<script>
    const mp = new MercadoPago('<?= MP_PUBLIC_KEY ?>', { locale: 'es-MX' });
    document.getElementById('paymentForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const cardNumber = document.getElementById('cardNumber').value.replace(/\s/g, '');
        const expMonth = document.getElementById('cardExpirationMonth').value;
        const expYear = document.getElementById('cardExpirationYear').value;
        const securityCode = document.getElementById('cardSecurityCode').value;
        const cardholderName = document.getElementById('cardholderName').value;
        if (!cardNumber || !expMonth || !expYear || !securityCode || !cardholderName) {
            alert('Completa todos los campos.');
            return;
        }
        mp.createCardToken({
            cardNumber: cardNumber,
            expirationMonth: expMonth,
            expirationYear: expYear,
            securityCode: securityCode,
            cardholderName: cardholderName
        }).then(function(response) {
            if (response.status === 200 || response.status === 201) {
                const token = response.response.id;
                document.getElementById('token').value = token;
                document.getElementById('payment_method_id').value = response.response.payment_method_id || 'visa';
                document.getElementById('installments').value = 1;
                document.getElementById('paymentForm').submit();
            } else {
                alert('Error al procesar la tarjeta: ' + (response.response ? response.response.message : 'Intenta nuevamente'));
            }
        }).catch(function(error) {
            alert('Error al crear el token: ' + error.message);
        });
    });
    document.getElementById('cardNumber').addEventListener('input', function(e) {
        let value = this.value.replace(/\D/g, '');
        value = value.replace(/(.{4})/g, '$1 ').trim();
        this.value = value;
    });
    document.getElementById('cardSecurityCode').addEventListener('input', function(e) {
        this.value = this.value.replace(/\D/g, '').slice(0, 4);
    });
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>