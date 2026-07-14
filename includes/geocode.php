<?php
// includes/geocode.php
function geocodificarDireccion($direccion) {
    if (empty($direccion)) return null;
    $direccion_encoded = urlencode($direccion);
    $url = "https://nominatim.openstreetmap.org/search?q={$direccion_encoded}&format=json&limit=1";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'FixiApp/1.0');
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    if (!empty($data) && isset($data[0]['lat'], $data[0]['lon'])) {
        return ['lat' => (float) $data[0]['lat'], 'lon' => (float) $data[0]['lon']];
    }
    return null;
}