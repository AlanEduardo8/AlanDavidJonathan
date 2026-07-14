<?php
// config/pusher.php
define('PUSHER_APP_ID', getenv('PUSHER_APP_ID') ?: 'TU_APP_ID');
define('PUSHER_APP_KEY', getenv('PUSHER_APP_KEY') ?: 'TU_APP_KEY');
define('PUSHER_APP_SECRET', getenv('PUSHER_APP_SECRET') ?: 'TU_APP_SECRET');
define('PUSHER_APP_CLUSTER', getenv('PUSHER_APP_CLUSTER') ?: 'TU_CLUSTER');

function pusher_trigger($channel, $event, $data) {
    $app_id = PUSHER_APP_ID;
    $key = PUSHER_APP_KEY;
    $secret = PUSHER_APP_SECRET;
    $cluster = PUSHER_APP_CLUSTER;
    $data_json = json_encode($data);
    $url = "https://api-{$cluster}.pusher.com/apps/{$app_id}/events";
    $query = http_build_query(['name' => $event, 'channel' => $channel, 'data' => $data_json]);
    $auth_string = $key . ':' . $secret;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
        'Authorization: Basic ' . base64_encode($auth_string)
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}