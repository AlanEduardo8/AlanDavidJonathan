<?php
require_once 'config/database.php';
require_once 'config/auth.php';

if (estaLogueado()) {
    redirigir('dashboard.php');
}

$error = '';
$success = '';

// Función para validar email con Hunter.io (sin Composer)
function validarEmailConHunter($email) {
    $api_key = getenv('HUNTER_API_KEY') ?: '';
    if (empty($api_key)) return true;
    $url = "https://api.hunter.io/v2/email-verifier?email={$email}&api_key={$api_key}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    return isset($data['data']['status']) && $data['data']['status'] === 'valid';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $apellidos = trim($_POST['apellidos'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $tipo = $_POST['tipo'] ?? 'cliente';
    $telefono = trim($_POST['telefono'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $ciudad = trim($_POST['ciudad'] ?? '');
    $estado = trim($_POST['estado'] ?? '');
    $codigo_postal = trim($_POST['codigo_postal'] ?? '');
    $especialidad = trim($_POST['especialidad'] ?? '');
    $experiencia = trim($_POST['experiencia'] ?? '');
    $biografia = trim($_POST['biografia'] ?? '');

    if (empty($nombre) || empty($email) || empty($password)) {
        $error = 'Nombre, email y contraseña son obligatorios.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email no válido.';
    } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/', $password)) {
        $error = 'La contraseña debe tener al menos 8 caracteres, una mayúscula, una minúscula, un número y un carácter especial.';
    } else {
        if (!validarEmailConHunter($email)) {
            $error = 'El email proporcionado no parece ser válido o no existe.';
        }
        if (empty($error)) {
            $check = mysqli_prepare($conn, "SELECT id FROM usuarios WHERE email = ?");
            mysqli_stmt_bind_param($check, 's', $email);
            mysqli_stmt_execute($check);
            mysqli_stmt_store_result($check);
            if (mysqli_stmt_num_rows($check) > 0) {
                $error = 'Este email ya está registrado.';
            } else {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $sql = "INSERT INTO usuarios (nombre, apellidos, email, password, tipo, telefono, direccion, ciudad, estado, codigo_postal, especialidad, experiencia, biografia, fecha_registro) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, 'sssssssssssss', $nombre, $apellidos, $email, $password_hash, $tipo, $telefono, $direccion, $ciudad, $estado, $codigo_postal, $especialidad, $experiencia, $biografia);
                if (mysqli_stmt_execute($stmt)) {
                    $success = 'Registro exitoso. Ya puedes <a href="login.php">iniciar sesión</a>.';
                    $_POST = [];
                } else {
                    $error = 'Error al registrar. Intenta más tarde.';
                    error_log('Error registro: ' . mysqli_error($conn));
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
    <title>Registro - Fixi</title>
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
            <div class="col-md-8 col-lg-7">
                <div class="card">
                    <div class="card-body">
                        <h2 class="card-title text-center mb-4"><i class="fas fa-user-plus text-primary me-2"></i>Registro</h2>

                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if ($success): ?>
                            <div class="alert alert-success"><?= $success ?></div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="nombre" class="form-label">Nombre *</label>
                                    <input type="text" class="form-control" id="nombre" name="nombre" value="<?= htmlspecialchars($_POST['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="apellidos" class="form-label">Apellidos</label>
                                    <input type="text" class="form-control" id="apellidos" name="apellidos" value="<?= htmlspecialchars($_POST['apellidos'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="email" class="form-label">Email *</label>
                                <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="password" class="form-label">Contraseña *</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                                <small class="text-muted">Mínimo 8 caracteres, una mayúscula, una minúscula, un número y un carácter especial.</small>
                            </div>

                            <div class="mb-3">
                                <label for="tipo" class="form-label">Tipo de usuario *</label>
                                <select class="form-select" id="tipo" name="tipo" required>
                                    <option value="cliente">Cliente</option>
                                    <option value="trabajador">Trabajador</option>
                                </select>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="telefono" class="form-label">Teléfono</label>
                                    <input type="text" class="form-control" id="telefono" name="telefono" value="<?= htmlspecialchars($_POST['telefono'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="ciudad" class="form-label">Ciudad</label>
                                    <input type="text" class="form-control" id="ciudad" name="ciudad" value="<?= htmlspecialchars($_POST['ciudad'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="estado" class="form-label">Estado</label>
                                    <input type="text" class="form-control" id="estado" name="estado" value="<?= htmlspecialchars($_POST['estado'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="codigo_postal" class="form-label">Código Postal</label>
                                    <input type="text" class="form-control" id="codigo_postal" name="codigo_postal" value="<?= htmlspecialchars($_POST['codigo_postal'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="direccion" class="form-label">Dirección</label>
                                <input type="text" class="form-control" id="direccion" name="direccion" value="<?= htmlspecialchars($_POST['direccion'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </div>

                            <div id="campos-trabajador">
                                <hr>
                                <h6 class="text-muted">Información profesional (opcional para clientes)</h6>
                                <div class="mb-3">
                                    <label for="especialidad" class="form-label">Especialidad</label>
                                    <select class="form-select" id="especialidad" name="especialidad">
                                        <option value="">Selecciona una especialidad</option>
                                        <option value="Plomería">Plomería</option>
                                        <option value="Electricidad">Electricidad</option>
                                        <option value="Carpintería">Carpintería</option>
                                        <option value="Pintura">Pintura</option>
                                        <option value="Jardinería">Jardinería</option>
                                        <option value="Albañilería">Albañilería</option>
                                        <option value="Herrería">Herrería</option>
                                        <option value="Mantenimiento General">Mantenimiento General</option>
                                        <option value="Electrodomésticos">Electrodomésticos</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="experiencia" class="form-label">Experiencia</label>
                                    <textarea class="form-control" id="experiencia" name="experiencia" rows="2"><?= htmlspecialchars($_POST['experiencia'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                                </div>
                                <div class="mb-3">
                                    <label for="biografia" class="form-label">Biografía</label>
                                    <textarea class="form-control" id="biografia" name="biografia" rows="3"><?= htmlspecialchars($_POST['biografia'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">Registrarse</button>
                        </form>

                        <p class="text-center mt-3">
                            ¿Ya tienes cuenta? <a href="login.php" class="text-primary">Inicia sesión</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('tipo').addEventListener('change', function() {
            document.getElementById('campos-trabajador').style.display = this.value === 'trabajador' ? 'block' : 'none';
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>