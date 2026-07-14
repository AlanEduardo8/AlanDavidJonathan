<?php
require_once 'config/database.php';
require_once 'config/auth.php';

if (!estaLogueado()) redirigir('login.php');

$usuario_id = $_SESSION['usuario_id'];
$usuario_data = obtenerDatosUsuario($conn, $usuario_id);
$error = '';
$success = '';

$es_primera_vez = isset($_GET['primeravez']) && $_GET['primeravez'] === 'si';
$perfil_completo = perfilCompleto($usuario_data);

// CSRF token
$csrf_token = generarTokenCSRF();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'actualizar_perfil') {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        $error = 'Solicitud no válida (CSRF).';
    } else {
        $nombre = trim($_POST['nombre'] ?? '');
        $apellidos = trim($_POST['apellidos'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $ciudad = trim($_POST['ciudad'] ?? '');
        $estado = trim($_POST['estado'] ?? '');
        $codigo_postal = trim($_POST['codigo_postal'] ?? '');
        $fecha_nacimiento = trim($_POST['fecha_nacimiento'] ?? '');
        $identificacion = trim($_POST['identificacion'] ?? '');
        $especialidad = trim($_POST['especialidad'] ?? '');
        $experiencia = trim($_POST['experiencia'] ?? '');
        $biografia = trim($_POST['biografia'] ?? '');

        if (empty($nombre) || empty($apellidos) || empty($telefono) || empty($direccion) || 
            empty($ciudad) || empty($estado) || empty($codigo_postal) || empty($fecha_nacimiento) || 
            empty($identificacion)) {
            $error = 'Todos los campos marcados con * son obligatorios.';
        } elseif (esTrabajador() && (empty($especialidad) || empty($experiencia) || empty($biografia))) {
            $error = 'Especialidad, experiencia y biografía son obligatorios para trabajadores.';
        } else {
            $foto_path = $usuario_data['foto'];
            if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $_FILES['foto']['tmp_name']);
                finfo_close($finfo);
                $allowed = ['image/jpeg', 'image/png', 'image/webp'];
                if (in_array($mime, $allowed) && $_FILES['foto']['size'] <= 5*1024*1024) {
                    $carpeta_destino = 'uploads/perfiles/';
                    if (!is_dir($carpeta_destino)) mkdir($carpeta_destino, 0777, true);
                    $ext = pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION);
                    $ruta = $carpeta_destino . uniqid() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    if (move_uploaded_file($_FILES['foto']['tmp_name'], $ruta)) {
                        $foto_path = $ruta;
                    } else {
                        $error = 'Error al subir la foto de perfil.';
                    }
                } else {
                    $error = 'Formato de imagen no permitido o tamaño excesivo (máx. 5MB).';
                }
            }

            $documento_path = $usuario_data['documento_identidad'];
            if (isset($_FILES['documento_identidad']) && $_FILES['documento_identidad']['error'] === UPLOAD_ERR_OK) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $_FILES['documento_identidad']['tmp_name']);
                finfo_close($finfo);
                $allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
                if (in_array($mime, $allowed) && $_FILES['documento_identidad']['size'] <= 10*1024*1024) {
                    $carpeta_destino = 'uploads/documentos/';
                    if (!is_dir($carpeta_destino)) mkdir($carpeta_destino, 0777, true);
                    $ext = pathinfo($_FILES['documento_identidad']['name'], PATHINFO_EXTENSION);
                    $ruta = $carpeta_destino . uniqid() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    if (move_uploaded_file($_FILES['documento_identidad']['tmp_name'], $ruta)) {
                        $documento_path = $ruta;
                    } else {
                        $error = 'Error al subir el documento de identidad.';
                    }
                } else {
                    $error = 'Formato de documento no permitido o tamaño excesivo (máx. 10MB).';
                }
            }

            if (empty($error)) {
                $sql = "UPDATE usuarios SET 
                        nombre=?, apellidos=?, telefono=?, direccion=?, ciudad=?, estado=?, 
                        codigo_postal=?, fecha_nacimiento=?, identificacion=?, especialidad=?, 
                        experiencia=?, biografia=?, foto=?, documento_identidad=?, verificado=1 
                        WHERE id=?";
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, 'ssssssssssssssi', 
                    $nombre, $apellidos, $telefono, $direccion, $ciudad, $estado,
                    $codigo_postal, $fecha_nacimiento, $identificacion, $especialidad,
                    $experiencia, $biografia, $foto_path, $documento_path, $usuario_id
                );
                if (mysqli_stmt_execute($stmt)) {
                    $success = 'Perfil actualizado correctamente.';
                    $_SESSION['usuario_nombre'] = $nombre . ' ' . ($apellidos ?? '');
                    $usuario_data = obtenerDatosUsuario($conn, $usuario_id);
                    $perfil_completo = perfilCompleto($usuario_data);
                    if ($perfil_completo && $es_primera_vez) redirigir('dashboard.php');
                } else {
                    $error = 'Error al actualizar. Intenta más tarde.';
                    error_log('Error perfil: ' . mysqli_error($conn));
                }
            }
        }
    }
}

// Estadísticas para trabajador
$total_trabajos = $total_calificaciones = 0;
if (esTrabajador()) {
    $sql_count = "SELECT COUNT(*) as total FROM solicitudes WHERE trabajador_id = ? AND estado = 'completado'";
    $stmt_count = mysqli_prepare($conn, $sql_count);
    mysqli_stmt_bind_param($stmt_count, 'i', $usuario_id);
    mysqli_stmt_execute($stmt_count);
    $result_count = mysqli_stmt_get_result($stmt_count);
    $row_count = mysqli_fetch_assoc($result_count);
    $total_trabajos = $row_count['total'] ?? 0;
    
    $sql_calif_count = "SELECT COUNT(*) as total FROM calificaciones WHERE trabajador_id = ?";
    $stmt_calif_count = mysqli_prepare($conn, $sql_calif_count);
    mysqli_stmt_bind_param($stmt_calif_count, 'i', $usuario_id);
    mysqli_stmt_execute($stmt_calif_count);
    $result_calif_count = mysqli_stmt_get_result($stmt_calif_count);
    $row_calif_count = mysqli_fetch_assoc($result_calif_count);
    $total_calificaciones = $row_calif_count['total'] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Perfil - Fixi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.0/dist/cyborg/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
    <style>
        .perfil-incompleto { border-left: 4px solid #ffc107; background: #0f1a2b; }
        .perfil-completo { border-left: 4px solid #28a745; background: #0a1a0f; }
        .campo-obligatorio::after { content: " *"; color: #ff4444; }
        .preview-documento { max-width: 100%; max-height: 200px; border-radius: 8px; margin-top: 10px; }
        .badge-verificado { background: #28a745; color: #fff; }
        .badge-no-verificado { background: #ffc107; color: #000; }
        .badge-pendiente { background: #ff4444; color: #fff; }
        .dashboard-content { position: relative; z-index: 1; }
    </style>
</head>
<body>
    <?php include 'assets/estrellas.php'; ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark-custom fixed-top">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <img src="assets/img/Logo FIXI.png" alt="Fixi Logo" onerror="this.style.display='none'">
                FIXI
            </a>
            <div class="d-flex align-items-center">
                <?php if (!$es_primera_vez): ?>
                <a href="<?= esCliente() ? 'cliente_dashboard.php' : 'trabajador_dashboard.php' ?>" class="btn btn-outline-info btn-sm me-2">
                    <i class="fas fa-arrow-left me-1"></i>Volver
                </a>
                <?php endif; ?>
                <span class="user-info me-2">
                    <i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['usuario_nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                    <span class="badge bg-primary ms-1"><?= htmlspecialchars($_SESSION['usuario_tipo'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                </span>
                <a href="logout.php" class="btn btn-outline-danger btn-sm">CERRAR SESIÓN</a>
            </div>
        </div>
    </nav>

    <div class="container py-5 dashboard-content" style="margin-top: 60px;">
        <div class="row justify-content-center">
            <div class="col-lg-9">
                <?php if ($es_primera_vez && !$perfil_completo): ?>
                    <div class="alert alert-warning text-center">
                        <h5><i class="fas fa-exclamation-triangle me-2"></i>¡Completa tu perfil para continuar!</h5>
                        <p class="mb-0">Por seguridad, debes llenar todos los campos obligatorios antes de usar la plataforma.</p>
                    </div>
                <?php endif; ?>

                <div class="card <?= $perfil_completo ? 'perfil-completo' : 'perfil-incompleto' ?>">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-user-edit text-primary me-2"></i>Mi Perfil</h5>
                        <span>
                            <?php if ($usuario_data['verificado'] && $perfil_completo): ?>
                                <span class="badge badge-verificado"><i class="fas fa-check-circle me-1"></i>Verificado</span>
                            <?php elseif ($perfil_completo && !$usuario_data['verificado']): ?>
                                <span class="badge badge-pendiente"><i class="fas fa-clock me-1"></i>Pendiente de revisión</span>
                            <?php else: ?>
                                <span class="badge badge-no-verificado"><i class="fas fa-edit me-1"></i>Incompleto</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if ($success): ?>
                            <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>

                        <div class="row mb-4 align-items-center">
                            <div class="col-md-2 text-center">
                                <?php if (!empty($usuario_data['foto'])): ?>
                                    <img src="<?= htmlspecialchars($usuario_data['foto'], ENT_QUOTES, 'UTF-8') ?>" class="rounded-circle img-fluid" style="width:100px;height:100px;object-fit:cover;border:3px solid #00d4ff;">
                                <?php else: ?>
                                    <i class="fas fa-user-circle fa-5x text-muted"></i>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <h4><?= htmlspecialchars(($usuario_data['nombre'] ?? '') . ' ' . ($usuario_data['apellidos'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h4>
                                <p class="text-muted"><i class="fas fa-envelope me-1"></i> <?= htmlspecialchars($usuario_data['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                                <?php if (esTrabajador()): ?>
                                    <p class="text-muted"><i class="fas fa-star text-warning me-1"></i> <?= $total_calificaciones ?> calificaciones</p>
                                    <p class="text-muted"><i class="fas fa-check-circle text-success me-1"></i> <?= $total_trabajos ?> trabajos completados</p>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4 text-end">
                                <?php if ($perfil_completo && $usuario_data['verificado']): ?>
                                    <span class="badge badge-verificado p-2"><i class="fas fa-shield-alt me-1"></i>Identidad verificada</span>
                                <?php elseif ($perfil_completo && !$usuario_data['verificado']): ?>
                                    <span class="badge badge-pendiente p-2"><i class="fas fa-clock me-1"></i>En revisión</span>
                                <?php else: ?>
                                    <span class="badge badge-no-verificado p-2"><i class="fas fa-exclamation-triangle me-1"></i>Completa tu perfil</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <hr>

                        <form method="POST" enctype="multipart/form-data" id="perfilForm">
                            <input type="hidden" name="accion" value="actualizar_perfil">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

                            <h6 class="text-primary mb-3"><i class="fas fa-user me-2"></i>Datos personales</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="nombre" class="form-label campo-obligatorio">Nombre</label>
                                    <input type="text" class="form-control" id="nombre" name="nombre" value="<?= htmlspecialchars($usuario_data['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="apellidos" class="form-label campo-obligatorio">Apellidos</label>
                                    <input type="text" class="form-control" id="apellidos" name="apellidos" value="<?= htmlspecialchars($usuario_data['apellidos'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="fecha_nacimiento" class="form-label campo-obligatorio">Fecha de nacimiento</label>
                                    <input type="date" class="form-control" id="fecha_nacimiento" name="fecha_nacimiento" value="<?= htmlspecialchars($usuario_data['fecha_nacimiento'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="telefono" class="form-label campo-obligatorio">Teléfono</label>
                                    <input type="text" class="form-control" id="telefono" name="telefono" placeholder="Ej. 55-1234-5678" value="<?= htmlspecialchars($usuario_data['telefono'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                            </div>

                            <h6 class="text-primary mt-4 mb-3"><i class="fas fa-id-card me-2"></i>Identificación oficial</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="identificacion" class="form-label campo-obligatorio">Número de identificación (INE / Pasaporte)</label>
                                    <input type="text" class="form-control" id="identificacion" name="identificacion" placeholder="Ej. GARM950101HDFRRN09" value="<?= htmlspecialchars($usuario_data['identificacion'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                    <small class="text-muted">Ingresa el número de tu INE o pasaporte.</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="documento_identidad" class="form-label campo-obligatorio">Foto de identificación oficial (INE / Pasaporte)</label>
                                    <input type="file" class="form-control" id="documento_identidad" name="documento_identidad" accept="image/*,application/pdf" <?= empty($usuario_data['documento_identidad']) ? 'required' : '' ?>>
                                    <small class="text-muted">Sube una foto clara de tu identificación (INE, pasaporte, cédula).</small>
                                    <?php if (!empty($usuario_data['documento_identidad'])): ?>
                                        <div class="mt-2">
                                            <a href="<?= htmlspecialchars($usuario_data['documento_identidad'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="btn btn-sm btn-outline-info">
                                                <i class="fas fa-eye me-1"></i>Ver documento actual
                                            </a>
                                        </div>
                                        <div class="mt-2">
                                            <img src="<?= htmlspecialchars($usuario_data['documento_identidad'], ENT_QUOTES, 'UTF-8') ?>" class="preview-documento" id="documentoPreview" alt="Documento actual">
                                        </div>
                                    <?php endif; ?>
                                    <div id="ocrResult" class="mt-2 text-muted small"></div>
                                </div>
                            </div>

                            <h6 class="text-primary mt-4 mb-3"><i class="fas fa-map-pin me-2"></i>Dirección</h6>
                            <div class="mb-3">
                                <label for="direccion" class="form-label campo-obligatorio">Dirección</label>
                                <input type="text" class="form-control" id="direccion" name="direccion" value="<?= htmlspecialchars($usuario_data['direccion'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="ciudad" class="form-label campo-obligatorio">Ciudad</label>
                                    <input type="text" class="form-control" id="ciudad" name="ciudad" value="<?= htmlspecialchars($usuario_data['ciudad'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="estado" class="form-label campo-obligatorio">Estado</label>
                                    <input type="text" class="form-control" id="estado" name="estado" value="<?= htmlspecialchars($usuario_data['estado'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="codigo_postal" class="form-label campo-obligatorio">Código Postal</label>
                                <input type="text" class="form-control" id="codigo_postal" name="codigo_postal" value="<?= htmlspecialchars($usuario_data['codigo_postal'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>

                            <?php if (esTrabajador()): ?>
                                <h6 class="text-primary mt-4 mb-3"><i class="fas fa-briefcase me-2"></i>Información profesional</h6>
                                <div class="mb-3">
                                    <label for="especialidad" class="form-label campo-obligatorio">Especialidad</label>
                                    <select class="form-select" id="especialidad" name="especialidad" required>
                                        <option value="">Selecciona tu especialidad</option>
                                        <option value="Plomería" <?= ($usuario_data['especialidad'] ?? '') === 'Plomería' ? 'selected' : '' ?>>Plomería</option>
                                        <option value="Electricidad" <?= ($usuario_data['especialidad'] ?? '') === 'Electricidad' ? 'selected' : '' ?>>Electricidad</option>
                                        <option value="Carpintería" <?= ($usuario_data['especialidad'] ?? '') === 'Carpintería' ? 'selected' : '' ?>>Carpintería</option>
                                        <option value="Pintura" <?= ($usuario_data['especialidad'] ?? '') === 'Pintura' ? 'selected' : '' ?>>Pintura</option>
                                        <option value="Jardinería" <?= ($usuario_data['especialidad'] ?? '') === 'Jardinería' ? 'selected' : '' ?>>Jardinería</option>
                                        <option value="Albañilería" <?= ($usuario_data['especialidad'] ?? '') === 'Albañilería' ? 'selected' : '' ?>>Albañilería</option>
                                        <option value="Herrería" <?= ($usuario_data['especialidad'] ?? '') === 'Herrería' ? 'selected' : '' ?>>Herrería</option>
                                        <option value="Mantenimiento General" <?= ($usuario_data['especialidad'] ?? '') === 'Mantenimiento General' ? 'selected' : '' ?>>Mantenimiento General</option>
                                        <option value="Electrodomésticos" <?= ($usuario_data['especialidad'] ?? '') === 'Electrodomésticos' ? 'selected' : '' ?>>Electrodomésticos</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="experiencia" class="form-label campo-obligatorio">Experiencia</label>
                                    <textarea class="form-control" id="experiencia" name="experiencia" rows="2" required><?= htmlspecialchars($usuario_data['experiencia'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                                    <small class="text-muted">Describe tu experiencia laboral (años, áreas, logros).</small>
                                </div>
                                <div class="mb-3">
                                    <label for="biografia" class="form-label campo-obligatorio">Biografía / Descripción</label>
                                    <textarea class="form-control" id="biografia" name="biografia" rows="3" required><?= htmlspecialchars($usuario_data['biografia'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                                    <small class="text-muted">Cuéntanos sobre ti, tus habilidades y lo que ofreces.</small>
                                </div>
                            <?php endif; ?>

                            <h6 class="text-primary mt-4 mb-3"><i class="fas fa-camera me-2"></i>Foto de perfil</h6>
                            <div class="mb-3">
                                <label for="foto" class="form-label">Foto de perfil</label>
                                <input type="file" class="form-control" id="foto" name="foto" accept="image/*">
                                <small class="text-muted">Sube una foto para tu perfil (opcional).</small>
                            </div>

                            <div class="d-flex justify-content-between mt-4">
                                <?php if ($es_primera_vez && !$perfil_completo): ?>
                                    <button type="submit" class="btn btn-primary btn-lg w-100"><i class="fas fa-save me-2"></i>Guardar y continuar</button>
                                <?php else: ?>
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Guardar cambios</button>
                                    <a href="<?= esCliente() ? 'cliente_dashboard.php' : 'trabajador_dashboard.php' ?>" class="btn btn-outline-secondary">Cancelar</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const documentoInput = document.getElementById('documento_identidad');
        const preview = document.getElementById('documentoPreview');
        const ocrResult = document.getElementById('ocrResult');
        if (documentoInput) {
            documentoInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (!file) return;
                const reader = new FileReader();
                reader.onload = function(event) {
                    if (preview) { preview.src = event.target.result; preview.style.display = 'block'; }
                };
                reader.readAsDataURL(file);
                ocrResult.innerHTML = '<span class="text-primary"><i class="fas fa-spinner fa-spin me-1"></i>Extrayendo información...</span>';
                Tesseract.recognize(
                    file, 'spa', {
                        logger: m => {
                            if (m.status === 'recognizing text') {
                                ocrResult.innerHTML = `<span class="text-primary"><i class="fas fa-spinner fa-spin me-1"></i>Procesando... ${Math.round(m.progress * 100)}%</span>`;
                            }
                        }
                    }
                ).then(({ data: { text } }) => {
                    const curpRegex = /[A-Z]{4}[0-9]{6}[A-Z]{6}[0-9]{2}/i;
                    const curpMatch = text.match(curpRegex);
                    if (curpMatch) {
                        document.getElementById('identificacion').value = curpMatch[0].toUpperCase();
                        ocrResult.innerHTML = `<span class="text-success"><i class="fas fa-check-circle me-1"></i>Identificación detectada: ${curpMatch[0].toUpperCase()}</span>`;
                    } else {
                        ocrResult.innerHTML = `<span class="text-warning"><i class="fas fa-exclamation-triangle me-1"></i>No se detectó automáticamente. Ingresa manualmente.</span>`;
                    }
                    const nombreRegex = /NOMBRE\s*[:.]?\s*([A-ZÁÉÍÓÚÑ\s]+)/i;
                    const nombreMatch = text.match(nombreRegex);
                    if (nombreMatch && !document.getElementById('nombre').value) {
                        const nombreCompleto = nombreMatch[1].trim().split(/\s+/);
                        if (nombreCompleto.length >= 2) {
                            document.getElementById('nombre').value = nombreCompleto[0];
                            document.getElementById('apellidos').value = nombreCompleto.slice(1).join(' ');
                        }
                    }
                    const fechaRegex = /FECHA\s*DE\s*NACIMIENTO\s*[:.]?\s*([0-9]{2}\/[0-9]{2}\/[0-9]{4})/i;
                    const fechaMatch = text.match(fechaRegex);
                    if (fechaMatch && !document.getElementById('fecha_nacimiento').value) {
                        const fechaParts = fechaMatch[1].split('/');
                        if (fechaParts.length === 3) {
                            document.getElementById('fecha_nacimiento').value = `${fechaParts[2]}-${fechaParts[1]}-${fechaParts[0]}`;
                        }
                    }
                }).catch(err => {
                    ocrResult.innerHTML = `<span class="text-danger"><i class="fas fa-times-circle me-1"></i>Error al leer el documento.</span>`;
                });
            });
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>