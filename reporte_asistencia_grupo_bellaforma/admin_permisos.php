<?php
/**
 * Subadministradores del panel.
 *
 *  - El administrador principal (los que ya existían) lo ve y lo puede todo.
 *  - Un subadministrador entra por el mismo login del panel (correo + contraseña) y solo ve / edita / elimina
 *    lo de los módulos que el administrador principal le marcó.
 *
 * El bloqueo se hace en el SERVIDOR (no solo ocultando botones): conexion.php llama a adminGuardia() en cada
 * petición, y esa función rechaza cualquier página, descarga o acción que no corresponda a un módulo permitido.
 */

/** Módulos que se pueden asignar: clave => [etiqueta, id de la sección en admin.php ("" si es una página aparte)] */
function adminModulos(): array
{
    return [
        "dashboard"         => ["Dashboard", "dashboard"],
        "asistencias"       => ["Asistencias", "asistencias"],
        "historial"         => ["Historial Completo", "historial"],
        "empleados"         => ["Empleados", "empleados"],
        "festivos"          => ["Festivos", "festivos"],
        "codigo_qr"         => ["Código QR", ""],
        "liquidacion"       => ["Liquidación", ""],
        "permisos"          => ["Permisos", "permisos"],
        "materiales"        => ["Materiales", "materiales"],
        "vendedores"        => ["Ejecutivos de negocios", "vendedores"],
        "pedidos"           => ["Pedidos de ejecutivos", "pedidos"],
        "despachos"         => ["Control de despachos", "despachos"],
        "pedidos_despachar" => ["Pedidos para despachar", "pedidos_despachar"],
        "productos"         => ["Productos", "productos_mayoristas"],
        "clientes"          => ["Clientes", "clientes"],
    ];
}

/** Agrega a la tabla `administradores` las columnas que necesitan los subadministradores (una sola vez por petición). */
function adminAsegurarColumnas(mysqli $c): void
{
    static $hecho = false;
    if ($hecho) {
        return;
    }
    $hecho = true;
    $columnas = [
        "rol"       => "VARCHAR(10) NOT NULL DEFAULT 'admin'",
        "permisos"  => "TEXT NULL",
        "nombre"    => "VARCHAR(120) NULL",
        "creado_en" => "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP",
    ];
    foreach ($columnas as $col => $def) {
        try {
            $r = $c->query("SHOW COLUMNS FROM `administradores` LIKE '" . $col . "'");
            if ($r && $r->num_rows === 0) {
                $c->query("ALTER TABLE `administradores` ADD COLUMN `" . $col . "` " . $def);
            }
        } catch (Throwable $e) {
            // si no se pudo crear, el administrador principal sigue funcionando igual
        }
    }
}

/** Convierte el texto guardado en la base de datos (JSON) en una lista de claves de módulos válidas. */
function adminPermisosDesdeTexto($texto): array
{
    $lista = json_decode((string) $texto, true);
    if (!is_array($lista)) {
        return [];
    }
    $validos = array_keys(adminModulos());
    return array_values(array_unique(array_filter($lista, fn($k) => is_string($k) && in_array($k, $validos, true))));
}

/** Lee los módulos marcados en un formulario (campo modulos[]). */
function adminModulosDePost(): array
{
    $enviados = $_POST["modulos"] ?? [];
    if (!is_array($enviados)) {
        return [];
    }
    $validos = array_keys(adminModulos());
    return array_values(array_unique(array_filter($enviados, fn($k) => is_string($k) && in_array($k, $validos, true))));
}

/** ¿La sesión actual es la del administrador principal? (las sesiones anteriores a esta función también lo son) */
function adminEsPrincipal(): bool
{
    return ($_SESSION["admin_rol"] ?? "admin") !== "sub";
}

/** ¿Puede entrar al módulo? El principal siempre; el subadministrador solo si se lo asignaron. */
function adminPuede(string $modulo): bool
{
    if (adminEsPrincipal()) {
        return true;
    }
    $permisos = $_SESSION["admin_permisos"] ?? [];
    return is_array($permisos) && in_array($modulo, $permisos, true);
}

/** ¿Tiene al menos uno de estos módulos? */
function adminPuedeAlguno(array $modulos): bool
{
    foreach ($modulos as $m) {
        if (adminPuede($m)) {
            return true;
        }
    }
    return false;
}

/** Ids de las secciones de admin.php que el subadministrador puede abrir (null = el principal, sin restricción). */
function adminSeccionesPermitidas(): ?array
{
    if (adminEsPrincipal()) {
        return null;
    }
    $ids = [];
    foreach (adminModulos() as $clave => $datos) {
        if ($datos[1] !== "" && adminPuede($clave)) {
            $ids[] = $datos[1];
        }
    }
    return $ids;
}

/** Responde "sin permiso": JSON si la llamada viene de un fetch, y si no, vuelve al panel con un aviso. */
function adminDenegar(string $mensaje, bool $cerrarSesion = false): void
{
    if ($cerrarSesion) {
        unset($_SESSION["admin_id"], $_SESSION["admin_usuario"], $_SESSION["admin_rol"], $_SESSION["admin_permisos"], $_SESSION["tipo_sesion"]);
    }

    $esFetch = ($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") !== ""
        || ($_SERVER["HTTP_SEC_FETCH_DEST"] ?? "") === "empty"
        || stripos((string) ($_SERVER["HTTP_ACCEPT"] ?? ""), "application/json") === 0;

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if ($esFetch) {
        http_response_code(403);
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode(["ok" => false, "error" => $mensaje, "mensaje" => $mensaje], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($cerrarSesion) {
        header("Location: login.html");
        exit;
    }

    $script = basename((string) ($_SERVER["SCRIPT_NAME"] ?? ""), ".php");
    if ($script === "admin" && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION["mensaje"] = $mensaje;
        $_SESSION["tipo_mensaje"] = "error";
        header("Location: admin.php");
        exit;
    }

    http_response_code(403);
    header("Content-Type: text/html; charset=UTF-8");
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Sin permiso</title></head>'
        . '<body style="font-family:Arial,sans-serif;text-align:center;padding:60px 20px;color:#223">'
        . '<h2>Sin permiso</h2><p>' . htmlspecialchars($mensaje, ENT_QUOTES, "UTF-8") . '</p>'
        . '<p><a href="admin.php">Volver al panel</a></p></body></html>';
    exit;
}

/**
 * Módulos que abren una acción de admin.php (POST o GET). Devuelve:
 *   - array con claves  => basta con tener UNO de esos módulos
 *   - array vacío       => solo el administrador principal
 *   - null              => acción desconocida (se rechaza a los subadministradores)
 */
function adminModulosDeAccion(string $accion): ?array
{
    if (in_array($accion, ["agregar_empleado", "editar_empleado", "cambiar_estado"], true)) {
        return ["empleados"];
    }
    if ($accion === "editar_asistencia") {
        return ["asistencias", "historial"];
    }
    if (strpos($accion, "vend_") === 0) {
        return ["vendedores"];
    }
    if (strpos($accion, "prod_") === 0) {
        return ["productos"];
    }
    if (strpos($accion, "ped_") === 0) {
        return ["pedidos"];
    }
    if (strpos($accion, "desp_") === 0) {
        return ["despachos", "pedidos_despachar"];
    }
    if (strpos($accion, "cli_") === 0) {
        return ["clientes"];
    }
    if (strpos($accion, "subadm_") === 0) {
        return [];
    }
    return null;
}

/** Qué módulos abren las páginas y descargas sueltas (nombre del archivo sin .php). */
function adminMapaScripts(): array
{
    $pedidos = ["pedidos", "despachos", "pedidos_despachar"];
    return [
        "crear_festivo"             => ["festivos"],
        "gestionar_festivos"        => ["festivos"],
        "procesar_eliminar_festivo" => ["festivos"],
        "codigo_qr"                 => ["codigo_qr"],
        "liquidacion"               => ["liquidacion"],
        "reporte_empleado_pdf"      => ["historial", "empleados", "liquidacion", "asistencias"],
        "obtener_dias_empleado"     => ["asistencias", "historial", "empleados", "liquidacion"],
        "foto"                      => ["productos", "pedidos", "despachos", "pedidos_despachar"],
        "despacho_rotulo"           => ["despachos", "pedidos_despachar"],
        "pedido_excel"              => $pedidos,
        "pedido_pdf"                => $pedidos,
        "pedido_syscafe"            => $pedidos,
        "pedido_api"                => $pedidos,
        "pedido_comprobante"        => $pedidos,
        "admin_pedido_editar"       => ["pedidos"],
        "productos_plantilla"       => ["productos"],
        "clientes_plantilla"        => ["clientes"],
    ];
}

/**
 * Guardia de permisos. Se ejecuta desde conexion.php en cada petición.
 * Al administrador principal no le cuesta nada (retorna de inmediato); al subadministrador le lee de la base de datos
 * sus permisos actuales, así que si se los cambian, lo desactivan o lo eliminan, el cambio aplica en el siguiente clic.
 */
function adminGuardia(mysqli $c): void
{
    if (PHP_SAPI === "cli" || !isset($_SESSION) || empty($_SESSION["admin_id"])) {
        return;
    }
    if (($_SESSION["admin_rol"] ?? "admin") !== "sub") {
        return;
    }

    // Permisos vigentes (si algo falla, se cierra: nunca se abre por error)
    $fila = null;
    try {
        $id = (int) $_SESSION["admin_id"];
        $st = $c->prepare("SELECT activo, rol, permisos FROM administradores WHERE id = ? LIMIT 1");
        $st->bind_param("i", $id);
        $st->execute();
        $fila = $st->get_result()->fetch_assoc();
        $st->close();
    } catch (Throwable $e) {
        $fila = null;
    }
    if (!$fila || (int) $fila["activo"] !== 1 || ($fila["rol"] ?? "") !== "sub") {
        adminDenegar("Su acceso al panel ya no está activo. Comuníquese con el administrador.", true);
    }
    $_SESSION["admin_permisos"] = adminPermisosDesdeTexto($fila["permisos"] ?? "");

    $script = basename((string) ($_SERVER["SCRIPT_NAME"] ?? ""), ".php");
    $metodo = $_SERVER["REQUEST_METHOD"] ?? "GET";

    // admin.php: depende de la acción
    if ($script === "admin") {
        $accion = "";
        if ($metodo === "POST") {
            $accion = (string) ($_POST["accion"] ?? "");
            if ($accion === "" && empty($_POST)) {
                return;   // envío vacío o demasiado grande: admin.php mismo lo avisa
            }
        } elseif (isset($_GET["accion_solicitud"], $_GET["id_solicitud"], $_GET["tipo_solicitud"])) {
            $mods = [($_GET["tipo_solicitud"] === "permiso") ? "permisos" : "materiales"];
            if (!adminPuedeAlguno($mods)) {
                adminDenegar("No tiene permiso para esta acción.");
            }
            return;
        } else {
            return;   // ver el panel: cada sección se muestra solo si el módulo está asignado
        }

        $mods = adminModulosDeAccion($accion);
        if ($mods === null || $mods === [] || !adminPuedeAlguno($mods)) {
            adminDenegar("No tiene permiso para realizar esta acción.");
        }
        return;
    }

    // Páginas y descargas sueltas
    $mapa = adminMapaScripts();
    if (!isset($mapa[$script])) {
        return;
    }
    // Las pantallas de pedidos también las usan los ejecutivos con su propia sesión
    if (in_array($script, ["pedido_api", "pedido_comprobante"], true) && !empty($_SESSION["vendedor_id"])) {
        return;
    }
    if (!adminPuedeAlguno($mapa[$script])) {
        adminDenegar("No tiene permiso para entrar a esta sección.");
    }
}
