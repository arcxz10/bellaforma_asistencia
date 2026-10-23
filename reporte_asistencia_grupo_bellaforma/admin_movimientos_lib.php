<?php
/**
 * Movimientos de los subadministradores.
 *
 * Cada vez que un subadministrador HACE algo en el panel (sube un comprobante, elimina un pedido, edita un cliente,
 * liquida a un empleado, etc.) queda una fila en la tabla `admin_movimientos`. Solo el administrador principal
 * puede ver esa lista (sección «Movimientos subadministradores» de admin.php).
 *
 * Cómo se registra:
 *  - conexion.php llama a movSubIniciar() en cada petición. Si la sesión es de un subadministrador, deja listo un
 *    registro que se escribe al terminar la petición SOLO si la acción salió bien (así no se anotan intentos fallidos).
 *  - Las pocas pantallas que no pasan por ahí (subir comprobante de cobro, ingreso) llaman a movSubRegistrar() directamente.
 *  - Lo que haga el administrador principal NO se registra: esta lista es solo de subadministradores.
 */

require_once __DIR__ . "/admin_permisos.php";

/** Crea la tabla de movimientos (una sola vez por petición). */
function movSubAsegurarTabla(mysqli $c): void
{
    static $listo = false;
    if ($listo) {
        return;
    }
    $listo = true;
    try {
        $c->query("CREATE TABLE IF NOT EXISTS admin_movimientos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            admin_nombre VARCHAR(120) NOT NULL DEFAULT '',
            admin_usuario VARCHAR(100) NOT NULL DEFAULT '',
            modulo VARCHAR(40) NOT NULL DEFAULT '',
            accion VARCHAR(60) NOT NULL DEFAULT '',
            detalle VARCHAR(600) NOT NULL DEFAULT '',
            ip VARCHAR(45) NULL,
            creado_en DATETIME NOT NULL,
            KEY idx_mov_fecha (creado_en),
            KEY idx_mov_admin (admin_id, creado_en)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        // se reintenta en la próxima petición
    }
}

/** accion => [texto corto de lo que hizo, módulo]. Solo lo que está aquí se registra. */
function movSubEtiquetas(): array
{
    return [
        // Empleados / asistencias
        "agregar_empleado"  => ["Creó un empleado", "empleados"],
        "editar_empleado"   => ["Editó un empleado", "empleados"],
        "cambiar_estado"    => ["Activó o desactivó un empleado", "empleados"],
        "editar_asistencia" => ["Editó una asistencia", "historial"],
        // Ejecutivos
        "vend_crear"        => ["Creó un ejecutivo", "vendedores"],
        "vend_editar"       => ["Editó un ejecutivo", "vendedores"],
        "vend_estado"       => ["Activó o desactivó un ejecutivo", "vendedores"],
        "vend_descargas"    => ["Cambió los permisos de un ejecutivo", "vendedores"],
        // Productos
        "prod_crear"        => ["Creó un producto", "productos"],
        "prod_editar"       => ["Editó un producto", "productos"],
        "prod_estado"       => ["Activó o desactivó un producto", "productos"],
        "prod_eliminar"     => ["Eliminó un producto", "productos"],
        "prod_importar"     => ["Importó productos", "productos"],
        // Pedidos
        "ped_estado"        => ["Cambió el estado de un pedido", "pedidos"],
        "ped_numero"        => ["Cambió el tipo o número de un documento", "pedidos"],
        "ped_numeracion"    => ["Cambió la numeración de documentos", "pedidos"],
        "ped_eliminar"      => ["Eliminó un pedido", "pedidos"],
        "pedido_editar"     => ["Editó un pedido", "pedidos"],
        "pedido_comp_subir" => ["Subió el comprobante de un pedido", "pedidos"],
        "pedido_comp_quitar" => ["Quitó el comprobante de un pedido", "pedidos"],
        // Por cobrar
        "cob_cobrado"       => ["Marcó un pedido como cobrado", "por_cobrar"],
        "cob_reabrir"       => ["Reabrió un cobro", "por_cobrar"],
        "comprobante_subir" => ["Subió un comprobante de pago", "por_cobrar"],
        "comprobante_cambiar" => ["Cambió un comprobante de pago", "por_cobrar"],
        // Despachos
        "desp_numeracion"   => ["Cambió la numeración de facturas", "despachos"],
        "desp_campo"        => ["Editó un dato de un despacho", "despachos"],
        "desp_guardar"      => ["Guardó un despacho", "despachos"],
        "desp_mover"        => ["Cambió la fecha de un despacho", "despachos"],
        "desp_eliminar"     => ["Eliminó un despacho", "despachos"],
        // Clientes
        "cli_guardar"       => ["Guardó un cliente", "clientes"],
        "cli_estado"        => ["Activó o desactivó un cliente", "clientes"],
        "cli_importar"      => ["Importó clientes", "clientes"],
        // Otros módulos
        "festivo_crear"     => ["Agregó un festivo", "festivos"],
        "festivo_eliminar"  => ["Eliminó un festivo", "festivos"],
        "solicitud_permiso" => ["Respondió una solicitud de permiso", "permisos"],
        "solicitud_material" => ["Respondió una solicitud de material", "materiales"],
        "liquidar"          => ["Liquidó a un empleado", "liquidacion"],
        "login"             => ["Inició sesión", "acceso"],
    ];
}

/** Nombre legible del módulo. */
function movSubNombreModulo(string $modulo): string
{
    if ($modulo === "acceso") {
        return "Acceso";
    }
    $mods = adminModulos();
    return isset($mods[$modulo]) ? $mods[$modulo][0] : ($modulo !== "" ? $modulo : "—");
}

/** Color de la etiqueta: rojo (elimina), verde (crea / sube), gris (ingreso), azul (el resto). */
function movSubTono(string $accion): string
{
    if (strpos($accion, "eliminar") !== false || strpos($accion, "quitar") !== false) {
        return "rojo";
    }
    if ($accion === "login") {
        return "gris";
    }
    if (preg_match('/crear|agregar|subir|cobrado|importar|liquidar/', $accion)) {
        return "verde";
    }
    return "azul";
}

/** Conexión nueva a la base de datos (la de la petición puede estar ya cerrada cuando termina el script). */
function movSubConexionNueva(): ?mysqli
{
    $cfg = $GLOBALS["bf_db_cfg"] ?? null;
    if (!is_array($cfg)) {
        return null;
    }
    try {
        $n = new mysqli($cfg["host"], $cfg["user"], $cfg["pass"], $cfg["db"], $cfg["port"]);
        if ($n->connect_error) {
            return null;
        }
        $n->set_charset("utf8");
        $n->query("SET time_zone = '-05:00'");
        return $n;
    } catch (Throwable $e) {
        return null;
    }
}

function movSubRecortar(string $t, int $max): string
{
    return function_exists("mb_substr") ? mb_substr($t, 0, $max, "UTF-8") : substr($t, 0, $max);
}

/** Une el mensaje del sistema con el nombre del objetivo: «Ejecutivo activado (Pedro Pérez).» */
function movSubUnir(string $mensaje, string $objetivo): string
{
    $mensaje = trim($mensaje);
    if ($objetivo === "" || $mensaje === "" || stripos($mensaje, $objetivo) !== false) {
        return $mensaje;
    }
    $base = preg_replace('/\.\s*$/u', "", $mensaje);
    if (!is_string($base)) {
        $base = rtrim($mensaje, ". ");
    }
    return $base . " (" . $objetivo . ").";
}

/**
 * Anota un movimiento del subadministrador que tiene la sesión abierta. Si la sesión no es de un subadministrador
 * no hace nada. Nunca interrumpe lo que se estaba haciendo (todo error se ignora).
 *   $c = conexión abierta, o null para abrir una nueva.
 */
function movSubRegistrar(?mysqli $c, string $accion, string $detalle = "", string $modulo = ""): void
{
    if (PHP_SAPI === "cli" || empty($_SESSION["admin_id"]) || ($_SESSION["admin_rol"] ?? "admin") !== "sub") {
        return;
    }
    $adminId = (int) $_SESSION["admin_id"];
    $usuario = (string) ($_SESSION["admin_usuario"] ?? "");
    $et = movSubEtiquetas();
    $mod = $modulo !== "" ? $modulo : ($et[$accion][1] ?? "");
    $ip = (string) ($_SERVER["HTTP_X_FORWARDED_FOR"] ?? ($_SERVER["REMOTE_ADDR"] ?? ""));
    $ip = trim(explode(",", $ip)[0]);

    foreach ([$c, null] as $intento => $con) {
        try {
            if ($intento === 1 || $con === null) {
                $con = movSubConexionNueva();
            }
            if (!$con) {
                return;
            }
            movSubAsegurarTabla($con);

            $nombre = $usuario;
            $st = $con->prepare("SELECT nombre FROM administradores WHERE id = ? LIMIT 1");
            $st->bind_param("i", $adminId);
            $st->execute();
            $f = $st->get_result()->fetch_assoc();
            $st->close();
            if ($f && trim((string) ($f["nombre"] ?? "")) !== "") {
                $nombre = trim((string) $f["nombre"]);
            }

            $nombre = movSubRecortar($nombre, 120);
            $usuario = movSubRecortar($usuario, 100);
            $mod = movSubRecortar($mod, 40);
            $accionTxt = movSubRecortar($accion, 60);
            $detalle = movSubRecortar($detalle, 600);
            $ip = movSubRecortar($ip, 45);

            $st = $con->prepare("INSERT INTO admin_movimientos (admin_id, admin_nombre, admin_usuario, modulo, accion, detalle, ip, creado_en)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $st->bind_param("issssss", $adminId, $nombre, $usuario, $mod, $accionTxt, $detalle, $ip);
            $st->execute();
            $st->close();
            return;
        } catch (Throwable $e) {
            // se intenta una vez más con una conexión nueva
        }
    }
}

/** Dato suelto de la base de datos (o "" si no se pudo leer). */
function movSubConsulta(mysqli $c, string $sql, string $tipos, array $params): ?array
{
    try {
        $st = $c->prepare($sql);
        if (!$st) {
            return null;
        }
        $st->bind_param($tipos, ...$params);
        $st->execute();
        $f = $st->get_result()->fetch_assoc();
        $st->close();
        return $f ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** A quién / a qué se le hizo la acción (para que el mensaje diga «Ejecutivo activado (Pedro Pérez)»). */
function movSubObjetivo(mysqli $c, string $accion, array $post): string
{
    $id = (int) ($post["id"] ?? 0);
    $txt = fn($k) => trim((string) ($post[$k] ?? ""));

    if (strpos($accion, "vend_") === 0) {
        if ($id > 0 && ($f = movSubConsulta($c, "SELECT nombre FROM vendedores WHERE id = ?", "i", [$id]))) {
            return (string) $f["nombre"];
        }
        return $txt("nombre");
    }
    if ($accion === "prod_crear") {
        return $txt("nombre");
    }
    if ($accion === "prod_editar" || $accion === "prod_estado") {
        if ($id > 0 && ($f = movSubConsulta($c, "SELECT referencia, nombre FROM productos_mayoristas WHERE id = ?", "i", [$id]))) {
            $ref = trim((string) $f["referencia"]);
            $nom = trim((string) $f["nombre"]);
            return $ref !== "" && $nom !== "" ? $ref . " · " . $nom : $ref . $nom;
        }
        return $txt("nombre");
    }
    if ($accion === "cli_guardar" || $accion === "cli_estado") {
        if ($id > 0 && function_exists("tablaClientes")) {
            $tabla = preg_replace('/[^A-Za-z0-9_]/', "", tablaClientes());
            if ($tabla !== "" && ($f = movSubConsulta($c, "SELECT razon_social FROM `$tabla` WHERE id = ?", "i", [$id]))) {
                return (string) $f["razon_social"];
            }
        }
        return $txt("razon_social");
    }
    if (in_array($accion, ["agregar_empleado", "editar_empleado", "cambiar_estado"], true)) {
        if ($id > 0 && ($f = movSubConsulta($c, "SELECT nombre FROM empleados WHERE id = ?", "i", [$id]))) {
            return (string) $f["nombre"];
        }
        return $txt("nombre");
    }
    if ($accion === "editar_asistencia") {
        $aid = (int) ($post["asistencia_id"] ?? 0);
        if ($aid > 0 && ($f = movSubConsulta($c, "SELECT e.nombre, a.fecha FROM asistencias a JOIN empleados e ON e.id = a.empleado_id WHERE a.id = ?", "i", [$aid]))) {
            return $f["nombre"] . ($f["fecha"] ? " · " . date("d/m/Y", strtotime($f["fecha"])) : "");
        }
        return "";
    }
    if (in_array($accion, ["desp_guardar", "desp_mover", "desp_eliminar"], true) && $id > 0) {
        return "despacho #" . $id;
    }
    return "";
}

/** «Pedido #4512» de un pedido, o "" si no se encuentra. */
function movSubTituloPedido(mysqli $c, int $id): string
{
    if ($id <= 0) {
        return "";
    }
    $f = movSubConsulta($c, "SELECT id, tipo_pedido, numero_pedido FROM pedidos_vendedores WHERE id = ?", "i", [$id]);
    if (!$f) {
        return "";
    }
    return function_exists("tituloDocumento") ? tituloDocumento($f) : ("Pedido #" . $id);
}

/**
 * Se llama desde conexion.php en cada petición. Si quien la hace es un subadministrador y la petición es de las que
 * modifican algo, deja programado el registro (se escribe al terminar, solo si salió bien).
 */
function movSubIniciar(mysqli $c): void
{
    static $hecho = false;
    if ($hecho) {
        return;
    }
    $hecho = true;
    if (PHP_SAPI === "cli" || empty($_SESSION["admin_id"]) || ($_SESSION["admin_rol"] ?? "admin") !== "sub") {
        return;
    }

    $script = basename((string) ($_SERVER["SCRIPT_NAME"] ?? ""), ".php");
    $metodo = (string) ($_SERVER["REQUEST_METHOD"] ?? "GET");
    $etiquetas = movSubEtiquetas();

    /* ---- Acciones del panel (admin.php, formularios POST) ---- */
    if ($script === "admin" && $metodo === "POST") {
        $accion = (string) ($_POST["accion"] ?? "");
        if (!isset($etiquetas[$accion])) {
            return;
        }
        $post = $_POST;
        unset($_SESSION["mensaje"], $_SESSION["tipo_mensaje"]);   // así el resultado que se lea al final es el de ESTA acción

        register_shutdown_function(function () use ($accion, $post) {
            // Edición de una celda del control de despachos: responde JSON sin pasar por redireccionar()
            if ($accion === "desp_campo") {
                $con = movSubConexionNueva();
                $campo = trim((string) ($post["campo"] ?? ""));
                $valor = movSubRecortar(trim((string) ($post["valor"] ?? "")), 120);
                $did = (int) ($post["id"] ?? 0);
                if ($con && $did > 0 && $campo !== "") {
                    movSubRegistrar($con, $accion, "Despacho #" . $did . ": cambió «" . movSubRecortar($campo, 40) . "» a «" . $valor . "»");
                }
                return;
            }

            $res = $GLOBALS["bf_mov_resultado"] ?? null;
            if (!is_array($res)) {
                $res = [(string) ($_SESSION["mensaje"] ?? ""), (string) ($_SESSION["tipo_mensaje"] ?? "")];
            }
            if (($res[1] ?? "") !== "exito") {
                return;   // no salió bien: no es un movimiento
            }
            $con = movSubConexionNueva();
            $objetivo = $con ? movSubObjetivo($con, $accion, $post) : "";
            movSubRegistrar($con, $accion, movSubUnir((string) $res[0], $objetivo));
        });
        return;
    }

    /* ---- Aprobar / rechazar solicitudes de permisos o materiales (enlace GET en admin.php) ---- */
    if ($script === "admin" && isset($_GET["accion_solicitud"], $_GET["id_solicitud"], $_GET["tipo_solicitud"])) {
        $resp = (string) $_GET["accion_solicitud"];
        if (!in_array($resp, ["aprobado", "rechazado"], true)) {
            return;
        }
        $sid = (int) $_GET["id_solicitud"];
        $esPermiso = ((string) $_GET["tipo_solicitud"]) === "permiso";
        register_shutdown_function(function () use ($resp, $sid, $esPermiso) {
            $con = movSubConexionNueva();
            $quien = "";
            if ($con) {
                $tabla = $esPermiso ? "permisos" : "solicitud_materiales";
                $f = movSubConsulta($con, "SELECT e.nombre FROM `$tabla` s JOIN empleados e ON e.id = s.empleado_id WHERE s.id = ?", "i", [$sid]);
                $quien = $f ? (string) $f["nombre"] : "";
            }
            $txt = ($resp === "aprobado" ? "Aprobó" : "Rechazó") . " la solicitud de " . ($esPermiso ? "permiso" : "material") . " #" . $sid
                . ($quien !== "" ? " de " . $quien : "");
            movSubRegistrar($con, $esPermiso ? "solicitud_permiso" : "solicitud_material", $txt);
        });
        return;
    }

    /* ---- Festivos ---- */
    if ($script === "crear_festivo" && $metodo === "POST") {
        $fecha = trim((string) ($_POST["fecha"] ?? ""));
        $desc = trim((string) ($_POST["descripcion"] ?? ""));
        register_shutdown_function(function () use ($fecha, $desc) {
            if (($GLOBALS["tipo_mensaje"] ?? "") !== "exito") {
                return;
            }
            $f = $fecha !== "" && strtotime($fecha) ? date("d/m/Y", strtotime($fecha)) : $fecha;
            movSubRegistrar(null, "festivo_crear", "Agregó el festivo del " . $f . ($desc !== "" ? " · " . $desc : ""));
        });
        return;
    }
    if ($script === "procesar_eliminar_festivo") {
        $fid = (int) ($_GET["id"] ?? 0);
        $antes = $fid > 0 ? movSubConsulta($c, "SELECT fecha, descripcion FROM festivos WHERE id = ?", "i", [$fid]) : null;
        if (!$antes) {
            return;
        }
        register_shutdown_function(function () use ($fid, $antes) {
            $con = movSubConexionNueva();
            if (!$con || movSubConsulta($con, "SELECT id FROM festivos WHERE id = ?", "i", [$fid])) {
                return;   // el festivo sigue ahí: no se eliminó
            }
            $f = $antes["fecha"] ? date("d/m/Y", strtotime($antes["fecha"])) : "";
            movSubRegistrar($con, "festivo_eliminar", "Eliminó el festivo del " . $f . ($antes["descripcion"] ? " · " . $antes["descripcion"] : ""));
        });
        return;
    }

    /* ---- Liquidación de un empleado ---- */
    if ($script === "liquidacion" && $metodo === "POST" && (string) ($_POST["accion"] ?? "") === "liquidar") {
        $eid = (int) ($_POST["empleado_id"] ?? 0);
        $desde = (string) ($_POST["desde"] ?? "");
        $hasta = (string) ($_POST["hasta"] ?? "");
        $f = $eid > 0 ? movSubConsulta($c, "SELECT nombre FROM empleados WHERE id = ?", "i", [$eid]) : null;
        $nombre = $f ? (string) $f["nombre"] : "";
        register_shutdown_function(function () use ($nombre, $desde, $hasta) {
            if (($_SESSION["tipo_mensaje_liquidacion"] ?? "") !== "exito") {
                return;
            }
            $per = ($desde !== "" && $hasta !== "" && strtotime($desde) && strtotime($hasta))
                ? " (del " . date("d/m/Y", strtotime($desde)) . " al " . date("d/m/Y", strtotime($hasta)) . ")" : "";
            movSubRegistrar(null, "liquidar", "Liquidó el periodo de " . ($nombre !== "" ? $nombre : "un empleado") . $per);
        });
        return;
    }

    /* ---- Editar un pedido / su comprobante (API de pedidos usada desde el panel) ---- */
    $esApiPedido = $script === "pedido_api" || ($script === "admin_pedido_editar" && isset($_GET["api"]));
    if ($esApiPedido && $metodo === "POST") {
        $pedidoId = 0;
        $accion = "";
        if (isset($_POST["op_comprobante"])) {
            if ((string) ($_POST["rol"] ?? "") !== "admin") {
                return;
            }
            $accion = (string) $_POST["op_comprobante"] === "quitar" ? "pedido_comp_quitar" : "pedido_comp_subir";
            $pedidoId = (int) ($_POST["pedido_id"] ?? 0);
        } else {
            $in = json_decode((string) file_get_contents("php://input"), true);
            if (!is_array($in) || (string) ($in["rol"] ?? "") !== "admin" || (string) ($in["accion"] ?? "") !== "guardar") {
                return;
            }
            $accion = "pedido_editar";
            $pedidoId = (int) ($in["pedido_id"] ?? 0);
        }
        if ($pedidoId <= 0) {
            return;
        }
        register_shutdown_function(function () use ($accion, $pedidoId) {
            if ((int) http_response_code() !== 200) {
                return;   // la API respondió con error
            }
            $con = movSubConexionNueva();
            $titulo = $con ? movSubTituloPedido($con, $pedidoId) : "";
            $titulo = $titulo !== "" ? $titulo : ("pedido #" . $pedidoId);
            $texto = [
                "pedido_editar"      => "Editó el ",
                "pedido_comp_subir"  => "Subió el comprobante (anticipo) del ",
                "pedido_comp_quitar" => "Quitó el comprobante (anticipo) del ",
            ][$accion] . movSubMinuscula($titulo);
            movSubRegistrar($con, $accion, $texto);
        });
        return;
    }
}

/** «Pedido #4512» -> «pedido #4512» (para armar frases). */
function movSubMinuscula(string $t): string
{
    if (function_exists("mb_strtolower") && function_exists("mb_substr")) {
        return mb_strtolower(mb_substr($t, 0, 1, "UTF-8"), "UTF-8") . mb_substr($t, 1, null, "UTF-8");
    }
    return lcfirst($t);
}

/**
 * Lista de movimientos para la sección del administrador.
 * Filtros: admin_id, modulo, desde, hasta (Y-m-d), buscar, pagina, por_pagina.
 * Devuelve ["filas" => [...], "total" => n, "pagina" => n, "paginas" => n].
 */
function movSubListar(mysqli $c, array $f): array
{
    movSubAsegurarTabla($c);
    $where = [];
    $tipos = "";
    $params = [];

    $adm = (int) ($f["admin_id"] ?? 0);
    if ($adm > 0) {
        $where[] = "admin_id = ?";
        $tipos .= "i";
        $params[] = $adm;
    }
    $mod = (string) ($f["modulo"] ?? "");
    if ($mod !== "") {
        $where[] = "modulo = ?";
        $tipos .= "s";
        $params[] = $mod;
    }
    $desde = (string) ($f["desde"] ?? "");
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
        $where[] = "DATE(creado_en) >= ?";
        $tipos .= "s";
        $params[] = $desde;
    }
    $hasta = (string) ($f["hasta"] ?? "");
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
        $where[] = "DATE(creado_en) <= ?";
        $tipos .= "s";
        $params[] = $hasta;
    }
    $buscar = trim((string) ($f["buscar"] ?? ""));
    if ($buscar !== "") {
        $where[] = "(detalle LIKE ? OR admin_nombre LIKE ? OR admin_usuario LIKE ?)";
        $tipos .= "sss";
        $like = "%" . $buscar . "%";
        array_push($params, $like, $like, $like);
    }
    $cond = $where ? " WHERE " . implode(" AND ", $where) : "";

    $porPagina = max(10, min(200, (int) ($f["por_pagina"] ?? 50)));
    $pagina = max(1, (int) ($f["pagina"] ?? 1));
    $vacio = ["filas" => [], "total" => 0, "pagina" => 1, "paginas" => 1];

    try {
        $st = $c->prepare("SELECT COUNT(*) AS n FROM admin_movimientos" . $cond);
        if (!$st) {
            return $vacio;
        }
        if ($params) {
            $st->bind_param($tipos, ...$params);
        }
        $st->execute();
        $total = (int) ($st->get_result()->fetch_assoc()["n"] ?? 0);
        $st->close();

        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = min($pagina, $paginas);
        $offset = ($pagina - 1) * $porPagina;

        $st = $c->prepare("SELECT id, admin_id, admin_nombre, admin_usuario, modulo, accion, detalle, ip, creado_en
                           FROM admin_movimientos" . $cond . " ORDER BY creado_en DESC, id DESC LIMIT " . (int) $porPagina . " OFFSET " . (int) $offset);
        if ($params) {
            $st->bind_param($tipos, ...$params);
        }
        $st->execute();
        $filas = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        return ["filas" => $filas, "total" => $total, "pagina" => $pagina, "paginas" => $paginas];
    } catch (Throwable $e) {
        return $vacio;
    }
}
