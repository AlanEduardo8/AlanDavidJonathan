<?php
// config/mercadopago.php
define('MP_ACCESS_TOKEN', getenv('MP_ACCESS_TOKEN') ?: 'TU_ACCESS_TOKEN_DE_PRUEBA_O_PRODUCCION');
define('MP_PUBLIC_KEY', getenv('MP_PUBLIC_KEY') ?: 'TU_PUBLIC_KEY');
define('MP_MODE', getenv('MP_MODE') ?: 'test');