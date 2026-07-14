<?php
require_once 'config/auth.php';

if (!estaLogueado()) {
    redirigir('login.php');
}

if (esCliente()) {
    redirigir('cliente_dashboard.php');
} elseif (esTrabajador()) {
    redirigir('trabajador_dashboard.php');
} elseif (esAdmin()) {
    redirigir('index.php');
} else {
    cerrarSesion();
    redirigir('login.php');
}
?>