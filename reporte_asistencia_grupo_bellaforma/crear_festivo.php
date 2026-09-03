<?php
session_start();

// Verificar que el usuario esté autenticado como admin
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.html");
    exit();
}

require 'conexion.php';

$mensaje = '';
$tipo_mensaje = '';
$fecha = '';           // ✅ INICIALIZAR
$descripcion = '';     // ✅ INICIALIZAR

// Procesar el formulario si se envía
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fecha = trim($_POST['fecha'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    
    // Validaciones
    if (empty($fecha)) {
        $mensaje = 'La fecha es requerida.';
        $tipo_mensaje = 'error';
    } else if (empty($descripcion)) {
        $mensaje = 'La descripción es requerida.';
        $tipo_mensaje = 'error';
    } else {
        // Verificar que la fecha no exista ya
        $stmt = $conexion->prepare("SELECT id FROM festivos WHERE fecha = ?");
        $stmt->bind_param("s", $fecha);
        $stmt->execute();
        $resultado = $stmt->get_result();
        
        if ($resultado->num_rows > 0) {
            $mensaje = 'Esta fecha ya existe como festivo.';
            $tipo_mensaje = 'error';
        } else {
            // Insertar el nuevo festivo
            $stmt = $conexion->prepare("INSERT INTO festivos (fecha, descripcion) VALUES (?, ?)");
            $stmt->bind_param("ss", $fecha, $descripcion);
            
            if ($stmt->execute()) {
                $mensaje = 'Festivo agregado correctamente.';
                $tipo_mensaje = 'exito';
                
                // Limpiar el formulario
                $fecha = '';
                $descripcion = '';
            } else {
                $mensaje = 'Error al agregar el festivo: ' . $conexion->error;
                $tipo_mensaje = 'error';
            }
        }
        
        $stmt->close();
    }
    
    $conexion->close();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Festivo | Grupo Bellaforma</title>
    <link rel="stylesheet" href="css/admin.css">
    <link rel="stylesheet" href="css/festivos.css">

</head>
<body>

    <div class="barra">
        <h1>Grupo Bellaforma</h1>
        <div class="barra-derecha">
            <span class="administrador">Admin</span>
            <a href="logout.php" class="cerrar-sesion">Cerrar sesión</a>
        </div>
    </div>

    <div class="contenedor-crear">

        <a href="admin.php" class="enlace-volver">← Volver al panel</a>

        <div class="panel">
            <h3>🎉 Crear Nuevo Festivo</h3>

            <?php if ($mensaje): ?>
                <div class="mensaje <?php echo htmlspecialchars($tipo_mensaje); ?>">
                    <?php echo htmlspecialchars($mensaje); ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="formulario-modal">

                <div>
                    <label for="fecha">Fecha del festivo *</label>
                    <input 
                        type="date" 
                        id="fecha" 
                        name="fecha" 
                        value="<?php echo htmlspecialchars($fecha); ?>"
                        required
                    >
                    <small style="color: #666;">Selecciona la fecha del día festivo</small>
                </div>

                <div>
                    <label for="descripcion">Descripción *</label>
                    <input 
                        type="text" 
                        id="descripcion" 
                        name="descripcion" 
                        placeholder="Ej: Día de Reyes, Navidad, etc."
                        value="<?php echo htmlspecialchars($descripcion); ?>"
                        required
                        maxlength="100"
                    >
                    <small style="color: #666;">Nombre del festivo o descripción breve</small>
                </div>

                <div class="botones-modal">
                    <a href="admin.php" class="boton boton-secundario">Cancelar</a>
                    <button type="submit" class="boton">+ Crear Festivo</button>
                </div>

            </form>
        </div>

    </div>

</body>
</html>
