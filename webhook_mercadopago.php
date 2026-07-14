<?php
// webhook_mercadopago.php
require_once 'config/database.php';

// Obtener el cuerpo de la notificación
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Verificar firma (si se usa)
$x_signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$secret = getenv('MP_WEBHOOK_SECRET') ?: 'tu_secreto_aqui';
if (!empty($x_signature) && !empty($secret)) {
    $expected = hash_hmac('sha256', $input, $secret);
    if (!hash_equals($expected, $x_signature)) {
        http_response_code(401);
        error_log('Webhook: Firma no válida');
        exit('Firma no válida');
    }
}

if (!$data) {
    // Notificación con GET
    $topic = isset($_GET['topic']) ? $_GET['topic'] : '';
    $id = isset($_GET['id']) ? $_GET['id'] : '';
    if ($topic === 'payment' && $id) {
        $access_token = getenv('MP_ACCESS_TOKEN') ?: 'TU_ACCESS_TOKEN';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.mercadopago.com/v1/payments/$id");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token]);
        $response = curl_exec($ch);
        curl_close($ch);
        $payment = json_decode($response, true);
        if ($payment && isset($payment['status']) && $payment['status'] === 'approved') {
            $external_ref = $payment['external_reference'] ?? '';
            if (strpos($external_ref, 'solicitud_') === 0) {
                $solicitud_id = intval(str_replace('solicitud_', '', $external_ref));
                $payment_id = $payment['id'];
                $update = mysqli_prepare($conn, "UPDATE solicitudes SET estado = 'pagado', fecha_pago = NOW(), payment_id = ? WHERE id = ?");
                mysqli_stmt_bind_param($update, 'si', $payment_id, $solicitud_id);
                mysqli_stmt_execute($update);
                // Notificar al trabajador (puedes enviar correo o mensaje)
            }
        }
    }
    exit;
}

// Procesar JSON (para webhooks avanzados)
if (isset($data['type']) && $data['type'] === 'payment' && isset($data['data']['id'])) {
    $payment_id = $data['data']['id'];
    $access_token = getenv('MP_ACCESS_TOKEN') ?: 'TU_ACCESS_TOKEN';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.mercadopago.com/v1/payments/$payment_id");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token]);
    $response = curl_exec($ch);
    curl_close($ch);
    $payment = json_decode($response, true);
    if ($payment && isset($payment['status']) && $payment['status'] === 'approved') {
        $external_ref = $payment['external_reference'] ?? '';
        if (strpos($external_ref, 'solicitud_') === 0) {
            $solicitud_id = intval(str_replace('solicitud_', '', $external_ref));
            $update = mysqli_prepare($conn, "UPDATE solicitudes SET estado = 'pagado', fecha_pago = NOW(), payment_id = ? WHERE id = ?");
            mysqli_stmt_bind_param($update, 'si', $payment_id, $solicitud_id);
            mysqli_stmt_execute($update);
        }
    }
}

echo 'OK';