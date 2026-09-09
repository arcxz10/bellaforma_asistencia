<?php
session_start();

if (!isset($_SESSION["admin_id"])) {
    http_response_code(403);
    exit;
}

require_once "conexion.php";

$empleadoId = (int) ($_GET["empleado_id"] ?? 0);
$desde = $_GET["desde"] ?? "";
$hasta = $_GET["hasta"] ?? "";

if ($empleadoId <= 0) {
    echo json_encode([]);
    exit;
}

$sql = "
    SELECT 
        id, 
        fecha, 
        hora_entrada, 
        hora_salida, 
        minutos_retraso, 
        minutos_extra, 
        minutos_deuda, 
        justificacion
    FROM asistencias
    WHERE empleado_id = ?
";

$params = [$empleadoId];
$types = "i";

if (!empty($desde) && !empty($hasta)) {
    $sql .= " AND fecha BETWEEN ? AND ?";
    $params[] = $desde;
    $params[] = $hasta;
    $types .= "ss";
}

$sql .= " ORDER BY fecha DESC";

$stmt = $conexion->prepare($sql);

if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    $dias = [];
    while ($fila = $resultado->fetch_assoc()) {
        $dias[] = $fila;
    }
    
    $stmt->close();
    header('Content-Type: application/json');
    echo json_encode($dias);
} else {
    header('Content-Type: application/json');
    echo json_encode([]);
}
