<?php
session_start();
include_once 'conexion.php';

$empleado_id = $_SESSION['empleado_id'] ?? $_GET['empleado_id'] ?? null;
$mensaje = "";
$tipo_alerta = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $empleado_id_post = $_POST['empleado_id'] ?? $empleado_id;
    $material = trim($_POST['material']);
    $cantidad = intval($_POST['cantidad']);

    if ($empleado_id_post && $material && $cantidad > 0) {
        $stmt = $conexion->prepare("INSERT INTO solicitud_materiales (empleado_id, material, cantidad) VALUES (?, ?, ?)");
        $stmt->bind_param("isi", $empleado_id_post, $material, $cantidad);
        if ($stmt->execute()) {
            $mensaje = "¡Solicitud de material enviada!";
            $tipo_alerta = "success";
        } else {
            $mensaje = "Error al registrar la solicitud.";
            $tipo_alerta = "error";
        }
        $stmt->close();
    } else {
        $mensaje = "Ingresa un material válido y cantidad mayor a 0.";
        $tipo_alerta = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Pedir Material - Bellaforma</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #e8f5e9; margin: 0; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .card { background: white; padding: 30px; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); width: 100%; max-width: 450px; }
        h2 { color: #2e7d32; text-align: center; margin-bottom: 20px; }
        label { display: block; margin-top: 15px; font-weight: 600; color: #333; font-size: 14px; }
        input { width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #ccc; border-radius: 8px; box-sizing: border-box; font-family: inherit; }
        button { width: 100%; background-color: #2e7d32; color: white; border: none; padding: 12px; border-radius: 8px; margin-top: 20px; font-weight: 600; cursor: pointer; font-size: 16px; }
        button:hover { background-color: #1b5e20; }
        .back-link { display: block; text-align: center; margin-top: 15px; color: #555; text-decoration: none; font-size: 14px; }
        .alert { padding: 10px; border-radius: 8px; margin-bottom: 15px; text-align: center; font-size: 14px; }
        .alert.success { background-color: #d4edda; color: #155724; }
        .alert.error { background-color: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Pedir Material</h2>
        <?php if($mensaje): ?>
            <div class="alert <?= $tipo_alerta ?>"><?= $mensaje ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="empleado_id" value="<?= htmlspecialchars($empleado_id) ?>">
            <label>Nombre del Material / Insumo:</label>
            <input type="text" name="material" placeholder="Ej. Guantes talla M, Tinta..." required>

            <label>Cantidad:</label>
            <input type="number" name="cantidad" value="1" min="1" required>

            <button type="submit">Solicitar Material</button>
        </form>
        <a href="registro_inicial.php?id=<?= $empleado_id ?>" class="back-link">← Volver al panel de registro</a>
    </div>
</body>
</html>
