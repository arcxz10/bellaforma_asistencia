<?php
session_start();

// Verificar que el usuario esté autenticado como admin
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.html");
    exit();
}

require 'conexion.php';

// Obtener todos los festivos ordenados por fecha
$query = "SELECT id, fecha, descripcion FROM festivos ORDER BY fecha DESC";
$resultado = $conexion->query($query);
$festivos = [];

if ($resultado) {
    while ($fila = $resultado->fetch_assoc()) {
        $festivos[] = $fila;
    }
}

$conexion->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestionar Festivos | Grupo Bellaforma</title>
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

    <div class="contenedor-gestionar">

        <a href="admin.php" class="enlace-volver">← Volver al panel</a>

        <div class="panel">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3>📅 Gestión de Festivos</h3>
                <a href="crear_festivo.php" class="boton-agregar">+ Agregar Nuevo</a>
            </div>

            <?php if (empty($festivos)): ?>
                <div class="mensaje-vacio">
                    <p style="font-size: 18px; font-weight: 600;">No hay festivos registrados</p>
                    <p>Agrega el primer festivo para empezar</p>
                </div>
            <?php else: ?>
                <table class="tabla-festivos">
                    <thead>
                        <tr>
                            <th style="width: 30%;">Fecha</th>
                            <th style="width: 50%;">Descripción</th>
                            <th style="width: 20%;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($festivos as $festivo): 
                            $fecha = new DateTime($festivo['fecha']);
                            $fecha_formateada = $fecha->format('d/m/Y (l)');
                        ?>
                            <tr>
                                <td class="fecha-festivo">
                                    <?php echo htmlspecialchars($fecha_formateada); ?>
                                </td>
                                <td class="descripcion-festivo">
                                    <?php echo htmlspecialchars($festivo['descripcion']); ?>
                                </td>
                                <td>
                                    <div class="acciones-festivo">
                                        <a href="procesar_eliminar_festivo.php?id=<?php echo $festivo['id']; ?>" 
                                           class="btn-eliminar"
                                           onclick="return confirm('¿Estás seguro de que deseas eliminar este festivo?');">
                                            Eliminar
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    </div>

</body>
</html>
