<?php
require_once 'config/database.php';
require_once 'config/auth.php';

// Usar prepared statements para consistencia
$sql_destacados = "SELECT id, nombre, apellidos, especialidad, foto, calificacion_promedio FROM usuarios WHERE tipo = 'trabajador' AND destacado = 1 ORDER BY calificacion_promedio DESC LIMIT 4";
$stmt_dest = mysqli_prepare($conn, $sql_destacados);
mysqli_stmt_execute($stmt_dest);
$result_dest = mysqli_stmt_get_result($stmt_dest);
$destacados = mysqli_fetch_all($result_dest, MYSQLI_ASSOC);

$sql_testimonios = "SELECT cliente_nombre, texto, puntuacion, fecha FROM testimonios WHERE activo = 1 ORDER BY fecha DESC LIMIT 3";
$stmt_test = mysqli_prepare($conn, $sql_testimonios);
mysqli_stmt_execute($stmt_test);
$result_test = mysqli_stmt_get_result($stmt_test);
$testimonios = mysqli_fetch_all($result_test, MYSQLI_ASSOC);

$stats = [];
$sql_stats = "SELECT (SELECT COUNT(*) FROM usuarios WHERE tipo='trabajador') as profesionales,
                     (SELECT COUNT(*) FROM solicitudes) as servicios,
                     (SELECT COUNT(*) FROM usuarios WHERE tipo='cliente') as clientes,
                     (SELECT AVG(puntuacion) FROM calificaciones) as calificacion";
$stmt_stats = mysqli_prepare($conn, $sql_stats);
mysqli_stmt_execute($stmt_stats);
$result_stats = mysqli_stmt_get_result($stmt_stats);
$stats = mysqli_fetch_assoc($result_stats);
if (!$stats) $stats = ['profesionales'=>0, 'servicios'=>0, 'clientes'=>0, 'calificacion'=>0];
$stats['calificacion'] = number_format($stats['calificacion'] ?: 0, 1);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fixi - Servicios profesionales</title>
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
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarFixi">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarFixi">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item"><a class="nav-link" href="#servicios">SERVICIOS</a></li>
                    <li class="nav-item"><a class="nav-link" href="#como-funciona">CÓMO FUNCIONA</a></li>
                    <li class="nav-item"><a class="nav-link" href="#por-que">POR QUÉ FIXI</a></li>
                    <li class="nav-item"><a class="nav-link" href="#sobre">SOBRE FIXI</a></li>
                    <li class="nav-item"><a class="nav-link" href="#opiniones">OPINIONES</a></li>
                    <li class="nav-item"><a class="nav-link" href="#faq">FAQ</a></li>
                    <li class="nav-item"><a class="nav-link" href="#contacto">CONTACTO</a></li>
                </ul>
                <div class="d-flex align-items-center">
                    <?php if (estaLogueado()): ?>
                        <a href="dashboard.php" class="text-decoration-none text-light">
                            <span class="user-info me-2">
                                <i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['usuario_nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                <span class="badge bg-primary ms-1"><?= htmlspecialchars($_SESSION['usuario_tipo'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                            </span>
                        </a>
                        <a href="logout.php" class="btn btn-outline-danger btn-sm">CERRAR SESIÓN</a>
                    <?php else: ?>
                        <a href="login.php" class="btn btn-outline-primary btn-sm me-2">INICIAR SESIÓN</a>
                        <a href="registro.php" class="btn btn-primary btn-sm">REGISTRARSE</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <!-- Carrusel (sin cambios) -->
    <section class="carousel-section">
        <div id="carouselFixi" class="carousel slide" data-bs-ride="carousel">
            <div class="carousel-indicators">
                <button type="button" data-bs-target="#carouselFixi" data-bs-slide-to="0" class="active"></button>
                <button type="button" data-bs-target="#carouselFixi" data-bs-slide-to="1"></button>
                <button type="button" data-bs-target="#carouselFixi" data-bs-slide-to="2"></button>
                <button type="button" data-bs-target="#carouselFixi" data-bs-slide-to="3"></button>
                <button type="button" data-bs-target="#carouselFixi" data-bs-slide-to="4"></button>
                <button type="button" data-bs-target="#carouselFixi" data-bs-slide-to="5"></button>
            </div>
            <div class="carousel-inner">
                <div class="carousel-item active">
                    <img src="https://images.unsplash.com/photo-1581147036325-7ab3febf6f01?w=1200&h=500&fit=crop" class="d-block w-100">
                    <div class="carousel-caption"><h5>Reparaciones</h5><p>Encuentra al experto adecuado.</p></div>
                </div>
                <div class="carousel-item">
                    <img src="https://images.unsplash.com/photo-1504328345606-18bbc8c9d7d1?w=1200&h=500&fit=crop" class="d-block w-100">
                    <div class="carousel-caption"><h5>Mantenimiento</h5><p>Cuida tu hogar.</p></div>
                </div>
                <div class="carousel-item">
                    <img src="https://images.unsplash.com/photo-1581578731548-c64695cc6952?w=1200&h=500&fit=crop" class="d-block w-100">
                    <div class="carousel-caption"><h5>Electricidad</h5><p>Soluciones seguras.</p></div>
                </div>
                <div class="carousel-item">
                    <img src="https://images.unsplash.com/photo-1589939705384-5185137a7f0f?w=1200&h=500&fit=crop" class="d-block w-100">
                    <div class="carousel-caption"><h5>Pintura</h5><p>Renueva tus espacios.</p></div>
                </div>
                <div class="carousel-item">
                    <img src="https://images.unsplash.com/photo-1526304640581-d334cdbbf45e?w=1200&h=500&fit=crop" class="d-block w-100">
                    <div class="carousel-caption"><h5>Jardinería</h5><p>Mantén tu jardín.</p></div>
                </div>
                <div class="carousel-item">
                    <img src="https://images.unsplash.com/photo-1581578731548-c64695cc6952?w=1200&h=500&fit=crop" class="d-block w-100">
                    <div class="carousel-caption"><h5>Carpintería</h5><p>Muebles a medida.</p></div>
                </div>
            </div>
            <button class="carousel-control-prev" type="button" data-bs-target="#carouselFixi" data-bs-slide="prev">
                <span class="carousel-control-prev-icon"></span>
            </button>
            <button class="carousel-control-next" type="button" data-bs-target="#carouselFixi" data-bs-slide="next">
                <span class="carousel-control-next-icon"></span>
            </button>
        </div>
    </section>

    <!-- Secciones (solo se escapan las salidas) -->
    <section class="py-5 bg-dark-custom" id="servicios">
        <div class="container">
            <h2 class="section-title text-center d-block">NUESTROS SERVICIOS</h2>
            <p class="text-center text-muted mb-5">Profesionales verificados para todas tus necesidades del hogar</p>
            <div class="row g-4">
                <div class="col-md-3 col-6">
                    <div class="card h-100 text-center p-3">
                        <div class="card-body">
                            <i class="fas fa-wrench fa-2x text-primary mb-2"></i>
                            <h5 class="card-title">PLOMERÍA</h5>
                            <p class="card-text small">Fugas, instalaciones y mantenimiento general.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="card h-100 text-center p-3">
                        <div class="card-body">
                            <i class="fas fa-bolt fa-2x text-primary mb-2"></i>
                            <h5 class="card-title">ELECTRICIDAD</h5>
                            <p class="card-text small">Instalaciones, reparaciones y mantenimiento seguro.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="card h-100 text-center p-3">
                        <div class="card-body">
                            <i class="fas fa-hammer fa-2x text-primary mb-2"></i>
                            <h5 class="card-title">HERRERÍA</h5>
                            <p class="card-text small">Trabajos en metal, puertas, rejas y estructuras.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="card h-100 text-center p-3">
                        <div class="card-body">
                            <i class="fas fa-chair fa-2x text-primary mb-2"></i>
                            <h5 class="card-title">CARPINTERÍA</h5>
                            <p class="card-text small">Muebles a medida, reparaciones y trabajos en madera.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-5 bg-light" id="como-funciona">
        <div class="container">
            <h2 class="section-title text-center d-block">¿CÓMO FUNCIONA?</h2>
            <p class="text-center text-muted mb-5">Tres simples pasos para obtener el servicio que necesitas</p>
            <div class="row g-4">
                <div class="col-md-4 text-center">
                    <div class="step-circle">1</div>
                    <h5>SOLICITA EL SERVICIO</h5>
                    <p class="text-muted">Selecciona el servicio que necesitas y describe tu problema.</p>
                </div>
                <div class="col-md-4 text-center">
                    <div class="step-circle">2</div>
                    <h5>ENCUENTRA UN PROFESIONAL</h5>
                    <p class="text-muted">Te conectamos con profesionales verificados cerca de ti.</p>
                </div>
                <div class="col-md-4 text-center">
                    <div class="step-circle">3</div>
                    <h5>RECIBE EL SERVICIO</h5>
                    <p class="text-muted">El profesional llega a tu hogar y resuelve tu problema.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="py-5 bg-dark-custom" id="por-que">
        <div class="container">
            <h2 class="section-title text-center d-block">¿POR QUÉ ELEGIR FIXI?</h2>
            <p class="text-center text-muted mb-5">Confianza, rapidez y calidad en cada servicio</p>
            <div class="row g-4">
                <div class="col-md-4 text-center">
                    <i class="fas fa-user-check fa-3x text-primary mb-3"></i>
                    <h5>PROFESIONALES VERIFICADOS</h5>
                    <p class="text-muted">Todos nuestros profesionales pasan por un riguroso proceso de verificación de identidad y habilidades.</p>
                </div>
                <div class="col-md-4 text-center">
                    <i class="fas fa-clock fa-3x text-primary mb-3"></i>
                    <h5>SERVICIO RÁPIDO</h5>
                    <p class="text-muted">Respuesta en minutos. Nuestro sistema te conecta con el profesional más cercano y disponible.</p>
                </div>
                <div class="col-md-4 text-center">
                    <i class="fas fa-star fa-3x text-primary mb-3"></i>
                    <h5>CALIFICACIONES Y COMENTARIOS</h5>
                    <p class="text-muted">Consulta la reputación de cada profesional y deja tu propia calificación al finalizar el servicio.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="py-5" style="background:#030507;" id="sobre">
        <div class="container">
            <div class="row g-5 align-items-center">
                <div class="col-md-6">
                    <h2 class="section-title">SOBRE FIXI</h2>
                    <p>Fixi nació con la misión de simplificar la forma en que las personas encuentran servicios profesionales para sus hogares y negocios. Sabemos lo frustrante que es buscar un plomero, electricista o carpintero de confianza en momentos de emergencia.</p>
                    <p>Nuestra plataforma conecta a clientes con profesionales verificados, ofreciendo transparencia, rapidez y seguridad. Cada profesional en Fixi ha pasado por un proceso de validación para garantizar la calidad del servicio.</p>
                    <p>Creemos en un ecosistema donde tanto clientes como trabajadores se benefician de una experiencia justa y eficiente. Por eso, impulsamos la confianza a través de calificaciones y comentarios reales.</p>
                </div>
                <div class="col-md-6">
                    <div class="row g-4 text-center">
                        <div class="col-6">
                            <div class="stats-number">+<?= number_format($stats['profesionales']) ?></div>
                            <p class="text-muted">Profesionales Activos</p>
                        </div>
                        <div class="col-6">
                            <div class="stats-number">+<?= number_format($stats['servicios']) ?></div>
                            <p class="text-muted">Servicios Realizados</p>
                        </div>
                        <div class="col-6">
                            <div class="stats-number">+<?= number_format($stats['clientes']) ?></div>
                            <p class="text-muted">Clientes Satisfechos</p>
                        </div>
                        <div class="col-6">
                            <div class="stats-number"><?= $stats['calificacion'] ?> <i class="fas fa-star text-warning" style="font-size:2rem;"></i></div>
                            <p class="text-muted">Calificación Promedio</p>
                        </div>
                    </div>
                    <div class="row g-3 mt-4">
                        <div class="col-md-6">
                            <div class="card p-3 h-100 text-center">
                                <i class="fas fa-clock fa-2x text-primary mb-2"></i>
                                <h6>DISPONIBILIDAD 24/7</h6>
                                <small class="text-muted">Servicio disponible a cualquier hora.</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card p-3 h-100 text-center">
                                <i class="fas fa-coins fa-2x text-primary mb-2"></i>
                                <h6>PRECIOS TRANSPARENTES</h6>
                                <small class="text-muted">Cotiza y acuerda sin sorpresas.</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card p-3 h-100 text-center">
                                <i class="fas fa-shield-alt fa-2x text-primary mb-2"></i>
                                <h6>PROFESIONALES VERIFICADOS</h6>
                                <small class="text-muted">Todos nuestros expertos están certificados.</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card p-3 h-100 text-center">
                                <i class="fas fa-headset fa-2x text-primary mb-2"></i>
                                <h6>SOPORTE DEDICADO</h6>
                                <small class="text-muted">Atención personalizada para resolver tus dudas.</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-5 bg-light" id="opiniones">
        <div class="container">
            <h2 class="section-title text-center d-block">PROFESIONALES DESTACADOS</h2>
            <p class="text-center text-muted mb-5">Expertos con la mejor calificación en Fixi</p>
            <div class="row g-4">
                <?php if (count($destacados) > 0): ?>
                    <?php foreach ($destacados as $prof): ?>
                        <div class="col-md-3 col-sm-6">
                            <div class="card h-100 text-center p-3">
                                <img src="<?= htmlspecialchars($prof['foto'] ?? 'https://via.placeholder.com/150', ENT_QUOTES, 'UTF-8') ?>" class="rounded-circle mx-auto mt-2" style="width:120px;height:120px;object-fit:cover;border:2px solid #00d4ff;">
                                <div class="card-body">
                                    <h5 class="card-title"><?= htmlspecialchars(($prof['nombre'] ?? '') . ' ' . ($prof['apellidos'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h5>
                                    <p class="card-text text-primary"><?= htmlspecialchars($prof['especialidad'] ?? 'General', ENT_QUOTES, 'UTF-8') ?></p>
                                    <p class="card-text">
                                        <?php
                                        $estrellas = round($prof['calificacion_promedio'] ?: 0);
                                        for ($i = 1; $i <= 5; $i++) {
                                            echo $i <= $estrellas ? '<i class="fas fa-star text-warning"></i>' : '<i class="far fa-star text-muted"></i>';
                                        }
                                        ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-center text-muted">No hay profesionales destacados aún.</p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="py-5 bg-dark-custom">
        <div class="container">
            <h2 class="section-title text-center d-block">LO QUE DICEN NUESTROS USUARIOS</h2>
            <p class="text-center text-muted mb-5">Opiniones reales de clientes y profesionales</p>
            <div class="row g-4">
                <?php if (count($testimonios) > 0): ?>
                    <?php foreach ($testimonios as $test): ?>
                        <div class="col-md-4">
                            <div class="card testimonial-card h-100 p-3">
                                <div class="card-body">
                                    <div class="mb-2">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <?= $i <= $test['puntuacion'] ? '<i class="fas fa-star text-warning"></i>' : '<i class="far fa-star text-muted"></i>' ?>
                                        <?php endfor; ?>
                                    </div>
                                    <p class="card-text">"<?= htmlspecialchars($test['texto'] ?? '', ENT_QUOTES, 'UTF-8') ?>"</p>
                                    <footer class="blockquote-footer text-primary">— <?= htmlspecialchars($test['cliente_nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?></footer>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-center text-muted">No hay testimonios aún.</p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="py-5 bg-light" id="faq">
        <div class="container">
            <h2 class="section-title text-center d-block">PREGUNTAS FRECUENTES</h2>
            <div class="accordion" id="faqAccordion">
                <div class="accordion-item faq-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                            ¿Cómo me registro como trabajador?
                        </button>
                    </h2>
                    <div id="faq1" class="accordion-collapse collapse show">
                        <div class="accordion-body">
                            Solo debes crear una cuenta seleccionando la opción "Trabajador" en el formulario de registro. Luego completa tu perfil con tu especialidad, experiencia y disponibilidad.
                        </div>
                    </div>
                </div>
                <div class="accordion-item faq-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                            ¿Cuánto cuesta publicar una solicitud?
                        </button>
                    </h2>
                    <div id="faq2" class="accordion-collapse collapse">
                        <div class="accordion-body">
                            Publicar una solicitud es completamente gratuito. Solo pagas el servicio acordado con el profesional.
                        </div>
                    </div>
                </div>
                <div class="accordion-item faq-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                            ¿Qué pasa si el profesional no llega?
                        </button>
                    </h2>
                    <div id="faq3" class="accordion-collapse collapse">
                        <div class="accordion-body">
                            Puedes cancelar la solicitud y contactar a nuestro soporte. Nosotros nos encargamos de encontrar otro profesional disponible.
                        </div>
                    </div>
                </div>
                <div class="accordion-item faq-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                            ¿Cómo sé que el profesional es confiable?
                        </button>
                    </h2>
                    <div id="faq4" class="accordion-collapse collapse">
                        <div class="accordion-body">
                            Todos nuestros profesionales pasan por una verificación de identidad y sus perfiles muestran calificaciones y comentarios de otros clientes.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-5 bg-light" id="contacto">
        <div class="container">
            <div class="row g-5">
                <div class="col-md-6">
                    <h2 class="section-title">CONTACTA CON NOSOTROS</h2>
                    <p class="text-muted">¿Tienes alguna pregunta o sugerencia? Escríbenos y te respondremos a la brevedad.</p>
                    <ul class="list-unstyled">
                        <li><i class="fas fa-envelope text-primary me-2"></i> <strong>Email:</strong> soporte@fixi.com</li>
                        <li><i class="fas fa-phone-alt text-primary me-2"></i> <strong>Teléfono:</strong> +52 55 1234 5678</li>
                        <li><i class="fas fa-map-marker-alt text-primary me-2"></i> <strong>Dirección:</strong> Ciudad de México, México</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <form>
                        <div class="mb-3">
                            <input type="text" class="form-control" placeholder="Tu nombre">
                        </div>
                        <div class="mb-3">
                            <input type="email" class="form-control" placeholder="Tu correo">
                        </div>
                        <div class="mb-3">
                            <textarea class="form-control" rows="4" placeholder="Mensaje"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-2"></i>ENVIAR MENSAJE</button>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <section class="py-5 text-center cta-final">
        <div class="container">
            <h2 class="display-6">¿LISTO PARA RESOLVER TUS PROBLEMAS?</h2>
            <p class="lead text-muted">Regístrate ahora y comienza a disfrutar de servicios profesionales en minutos.</p>
            <a href="registro.php" class="btn btn-primary btn-lg"><i class="fas fa-user-plus me-2"></i>ÚNETE A FIXI</a>
        </div>
    </section>

    <footer class="footer py-4 bg-dark-custom text-center">
        <div class="container">
            <p>
                <a href="#">Fixi</a> &nbsp;|&nbsp;
                <a href="#">Términos y Condiciones</a> &nbsp;|&nbsp;
                <a href="#">Política de Privacidad</a> &nbsp;|&nbsp;
                <a href="#">Servicios para tu hogar</a>
            </p>
            <p class="text-muted small">&copy; 2026 Fixi. Hecho con <i class="fas fa-heart text-danger"></i></p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>