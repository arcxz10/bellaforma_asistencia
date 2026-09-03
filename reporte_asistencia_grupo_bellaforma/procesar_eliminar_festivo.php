<?php
session_start();

// Verificar que el usuario esté autenticado como admin
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.html");
    exit();
}

require 'conexion.php';

// Obtener el ID del festivo a eliminar
$id = $_GET['id'] ?? 0;
$id = intval($id);

if ($id > 0) {
    // Preparar la consulta para eliminar
    $stmt = $conexion->prepare("DELETE FROM festivos WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        // Redirigir con mensaje de éxito
        header("Location: gestionar_festivos.php?mensaje=eliminado&tipo=exito");
    } else {
        // Redirigir con error
        header("Location: gestionar_festivos.php?mensaje=error&tipo=error");
    }
    
    $stmt->close();
} else {
    header("Location: gestionar_festivos.php?mensaje=invalido&tipo=error");
}

$conexion->close();
exit();
