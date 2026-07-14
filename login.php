<?php
require_once 'config/database.php';
require_once 'config/auth.php';

if (estaLogueado()) {
    redirigir('dashboard.php');
}

$error = '';
$intentos = $_SESSION['login_attempts'] ?? 0;
$bloqueo_hasta = $_SESSION['login_block_until'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($bloqueo_hasta > time()) {
        $error = 'Demasiados intentos fallidos. Intenta de nuevo en ' . ceil(($bloqueo_hasta - time()) / 60) . ' minutos.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Email y contraseña son obligatorios.';
        } else {
            $sql = "SELECT id, nombre, apellidos, email, password, tipo FROM usuarios WHERE email = ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, 's', $email);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $usuario = mysqli_fetch_assoc($result);

            if ($usuario && password_verify($password, $usuario['password'])) {
                $_SESSION['login_attempts'] = 0;
                unset($_SESSION['login_block_until']);
                iniciarSesion(
                    $usuario['id'],
                    $usuario['nombre'] . ' ' . ($usuario['apellidos'] ?? ''),
                    $usuario['tipo'],
                    $usuario['email']
                );
                redirigir('dashboard.php');
            } else {
                $_SESSION['login_attempts'] = ++$intentos;
                if ($intentos >= 5) {
                    $_SESSION['login_block_until'] = time() + 900;
                    $error = 'Demasiados intentos fallidos. Espera 15 minutos.';
                } else {
                    $error = 'Email o contraseña incorrectos.';
                }
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
    <title>Iniciar Sesión - Fixi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.0/dist/cyborg/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark-custom fixed-top">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <img src="assets/img/Logo FIXI.png" alt="Fixi Logo" onerror="this.style.display='none'">
                FIXI
            </a>
        </div>
    </nav>

    <div class="container py-5" style="margin-top: 60px;">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="card">
                    <div class="card-body">
                        <h2 class="card-title text-center mb-4"><i class="fas fa-sign-in-alt text-primary me-2"></i>Iniciar Sesión</h2>

                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Contraseña</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Ingresar</button>
                        </form>

                        <p class="text-center mt-3">
                            ¿No tienes cuenta? <a href="registro.php" class="text-primary">Regístrate</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>