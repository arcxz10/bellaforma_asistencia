<?php
/**
 * Lógica compartida de pedidos: búsqueda de clientes y guardado (borrador / envío / edición).
 */

function textoCorto($v, int $max, bool $unaLinea = true): string
{
    $v = trim((string) $v);
    if ($unaLinea) {
        $v = preg_replace('/\s+/u', ' ', $v) ?? $v;
    }
    if (function_exists("mb_substr")) {
        return mb_substr($v, 0, $max, "UTF-8");
    }
    return substr($v, 0, $max);
}

function soloDigitos(string $s): string
{
    return preg_replace('/\D+/', '', $s) ?? '';
}

/** Busca un cliente por NIT / cédula (acepta puntos, espacios y dígito de verificación con guion). */
function buscarClientePorDocumento(mysqli $c, string $raw): ?array
{
    $raw = trim($raw);
    $norm = soloDigitos($raw);
    if (strlen($norm) < 5) {
        return null;
    }
    $candidatos = [$norm];
    if (strpos($raw, "-") !== false) {
        $base = soloDigitos(explode("-", $raw)[0]);
        if (strlen($base) >= 5) {
            array_unshift($candidatos, $base);
        }
    }
    foreach ($candidatos as $n) {
        $stmt = $c->prepare("SELECT * FROM clientes WHERE identificacion_norm = ? LIMIT 1");
        $stmt->bind_param("s", $n);
        $stmt->execute();
        $res = $stmt->get_result();
        $fila = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($fila) {
            return $fila;
        }
    }
    return null;
}

/**
 * Guarda un pedido.
 *  $d      datos del cliente y condiciones
 *  $items  [producto_id => cantidad]
 *  $modo   'borrador' | 'enviar'
 *  $rol    'vendedor' | 'admin'
 * Devuelve ['ok'=>bool, 'error'=>string, 'id'=>int, 'total'=>float, 'estado'=>string]
 */
function guardarPedido(mysqli $c, int $vendedorId, ?int $pedidoId, array $d, array $items, string $modo, string $rol = "vendedor"): array
{
    $falla = fn(string $m) => ["ok" => false, "error" => $m];

    $existente = null;
    if ($pedidoId) {
        $lista = obtenerPedidos($c, ["con_borradores" => true], null, $pedidoId);
        $existente = $lista[0] ?? null;
        if (!$existente) {
            return $falla("No se encontró el pedido.");
        }
        if ($rol === "vendedor") {
            if ((int) $existente["vendedor_id"] !== $vendedorId) {
                return $falla("Este pedido no te pertenece.");
            }
            if ($existente["estado"] === "procesado") {
                return $falla("Este pedido ya fue subido a Syscafe y ya no se puede editar.");
            }
            if ($existente["estado"] === "anulado") {
                return $falla("Este pedido está anulado y no se puede editar.");
            }
            if ($modo === "borrador" && $existente["estado"] !== "borrador") {
                return $falla("Un pedido ya enviado no vuelve a borrador.");
            }
        }
        $vendedorId = (int) $existente["vendedor_id"];
    }

    // ---- Datos del cliente ----
    $tipo = (($d["tipo_persona"] ?? "") === "natural") ? "natural" : "juridica";
    $tipoDoc = $tipo === "natural" ? "CC" : "NIT";
    $nombre = textoCorto($d["cliente_nombre"] ?? "", 200);
    $comercial = textoCorto($d["cliente_nombre_comercial"] ?? "", 200);
    $ident = textoCorto($d["identificacion"] ?? "", 40);
    $depto = textoCorto($d["departamento"] ?? "", 80);
    $muni = textoCorto($d["municipio"] ?? "", 100);
    $barrio = textoCorto($d["barrio"] ?? "", 120);
    $direccion = textoCorto($d["direccion"] ?? "", 255);
    $puntos = textoCorto($d["puntos_referencia"] ?? "", 255);
    $telefono = textoCorto($d["telefono"] ?? "", 40);
    $email = textoCorto($d["email"] ?? "", 150);
    $fe = !empty($d["factura_electronica"]) ? 1 : 0;
    $emailFe = textoCorto($d["email_fe"] ?? "", 150);
    $condicion = (($d["condicion_pago"] ?? "") === "credito") ? "credito" : "contado";
    $dias = $condicion === "credito" ? (int) ($d["dias_credito"] ?? 0) : null;
    $obs = textoCorto($d["observaciones"] ?? "", 2000, false);
    $clienteId = (int) ($d["cliente_id"] ?? 0) ?: null;

    if ($modo === "enviar") {
        if ($ident === "" || soloDigitos($ident) === "") {
            return $falla(($tipo === "natural" ? "Ingrese la cédula" : "Ingrese el NIT") . " del cliente.");
        }
        if ($nombre === "") {
            return $falla($tipo === "natural" ? "Ingrese el nombre completo del cliente." : "Ingrese la razón social del cliente.");
        }
        $ubicacionOpcional = $existente && $existente["estado"] !== "borrador" && trim((string) ($existente["cliente_departamento"] ?? "")) === "";
        if (!$ubicacionOpcional && ($depto === "" || $muni === "")) {
            return $falla("Seleccione el departamento y el municipio.");
        }
        if ($direccion === "") {
            return $falla("Ingrese la dirección de entrega.");
        }
        if ($telefono === "") {
            return $falla("Ingrese un teléfono de contacto.");
        }
        if ($condicion === "credito" && ($dias === null || $dias < 1 || $dias > 365)) {
            return $falla("Indique cuántos días de crédito (entre 1 y 365).");
        }
        if ($fe) {
            $correoFe = $emailFe !== "" ? $emailFe : $email;
            if (!filter_var($correoFe, FILTER_VALIDATE_EMAIL)) {
                return $falla("Para factura electrónica ingrese un correo válido.");
            }
            if ($emailFe === "") {
                $emailFe = $correoFe;
            }
        }
    }
    if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = "";
    }
    if ($emailFe !== "" && !filter_var($emailFe, FILTER_VALIDATE_EMAIL)) {
        $emailFe = "";
    }

    // ---- Productos (los precios siempre salen de la base de datos) ----
    $cantidades = [];
    foreach ($items as $pid => $cant) {
        $pid = (int) $pid;
        $cant = (int) $cant;
        if ($pid > 0 && $cant > 0 && $cant <= 100000) {
            $cantidades[$pid] = $cant;
        }
    }
    if ($modo === "enviar" && !$cantidades) {
        return $falla("Seleccione al menos un producto con cantidad.");
    }

    $preciosPrevios = [];   // precio ya pactado en un pedido enviado (se respeta al editar)
    $idsPrevios = [];
    if ($existente) {
        foreach ($existente["items"] as $it) {
            $idsPrevios[(int) $it["producto_id"]] = true;
            if ($existente["estado"] !== "borrador") {
                $preciosPrevios[(int) $it["producto_id"]] = (float) $it["precio_unitario"];
            }
        }
    }

    $lineas = [];
    $total = 0.0;
    if ($cantidades) {
        $lista = implode(",", array_map("intval", array_keys($cantidades)));
        $res = $c->query("SELECT id, referencia, nombre, precio, activo FROM productos_mayoristas WHERE id IN ($lista)");
        $porId = [];
        while ($res && ($p = $res->fetch_assoc())) {
            $porId[(int) $p["id"]] = $p;
        }
        foreach ($cantidades as $pid => $cant) {
            if (!isset($porId[$pid])) {
                continue;
            }
            $p = $porId[$pid];
            if ((int) $p["activo"] !== 1 && !isset($idsPrevios[$pid])) {
                continue;
            }
            $precio = $preciosPrevios[$pid] ?? (float) $p["precio"];
            $sub = round($precio * $cant, 2);
            $total += $sub;
            $lineas[] = ["id" => $pid, "referencia" => $p["referencia"], "nombre" => $p["nombre"],
                         "precio" => $precio, "cantidad" => $cant, "subtotal" => $sub];
        }
        if ($modo === "enviar" && !$lineas) {
            return $falla("Los productos seleccionados ya no están disponibles.");
        }
    }
    $total = round($total, 2);

    // ---- Estado final ----
    if ($existente && $rol === "admin") {
        $estado = $existente["estado"];
    } elseif ($modo === "borrador") {
        $estado = "borrador";
    } else {
        $estado = "pendiente";
    }

    $campos = [
        "cliente_id"                => ["i", $clienteId],
        "cliente_tipo_persona"      => ["s", $tipo],
        "cliente_tipo_documento"    => ["s", $tipoDoc],
        "cliente_nombre"            => ["s", $nombre],
        "cliente_nombre_comercial"  => ["s", $comercial],
        "cliente_nit"               => ["s", $ident],
        "cliente_departamento"      => ["s", $depto],
        "cliente_ciudad"            => ["s", $muni],
        "cliente_barrio"            => ["s", $barrio],
        "cliente_direcciones"       => ["s", $direccion],
        "cliente_puntos_referencia" => ["s", $puntos],
        "cliente_telefono"          => ["s", $telefono],
        "cliente_email"             => ["s", $email],
        "factura_electronica"       => ["i", $fe],
        "email_fe"                  => ["s", $emailFe],
        "condicion_pago"            => ["s", $condicion],
        "dias_credito"              => ["i", $dias],
        "observaciones"             => ["s", $obs],
        "total"                     => ["d", $total],
        "estado"                    => ["s", $estado],
    ];

    try {
        $c->begin_transaction();

        if ($existente) {
            $sets = implode(", ", array_map(fn($k) => "`$k` = ?", array_keys($campos)));
            $tipos = implode("", array_map(fn($v) => $v[0], $campos)) . "i";
            $valores = array_values(array_map(fn($v) => $v[1], $campos));
            $valores[] = (int) $existente["id"];
            $st = $c->prepare("UPDATE pedidos_vendedores SET $sets WHERE id = ?");
            $st->bind_param($tipos, ...$valores);
            $st->execute();
            $st->close();
            $idFinal = (int) $existente["id"];
            $c->query("DELETE FROM pedido_vendedor_items WHERE pedido_id = " . $idFinal);
        } else {
            $cols = "vendedor_id, " . implode(", ", array_map(fn($k) => "`$k`", array_keys($campos)));
            $marcas = implode(", ", array_fill(0, count($campos) + 1, "?"));
            $tipos = "i" . implode("", array_map(fn($v) => $v[0], $campos));
            $valores = array_merge([$vendedorId], array_values(array_map(fn($v) => $v[1], $campos)));
            $st = $c->prepare("INSERT INTO pedidos_vendedores ($cols) VALUES ($marcas)");
            $st->bind_param($tipos, ...$valores);
            $st->execute();
            $st->close();
            $idFinal = (int) $c->insert_id;
        }

        $insItem = $c->prepare(
            "INSERT INTO pedido_vendedor_items (pedido_id, producto_id, referencia, nombre, precio_unitario, cantidad, subtotal)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($lineas as $l) {
            $insItem->bind_param("iissdid", $idFinal, $l["id"], $l["referencia"], $l["nombre"], $l["precio"], $l["cantidad"], $l["subtotal"]);
            $insItem->execute();
        }
        $insItem->close();

        $c->commit();
    } catch (Throwable $e) {
        $c->rollback();
        $detalle = trim(preg_replace('/\s+/', " ", $e->getMessage()) ?? "");
        return $falla("No se pudo guardar el pedido. Detalle técnico: " . substr($detalle, 0, 180));
    }

    return ["ok" => true, "id" => $idFinal, "total" => $total, "estado" => $estado];
}

/** Productos para mostrar en el formulario (activos + los que ya estén en el pedido que se edita). */
function productosParaFormulario(mysqli $c, ?array $pedido): array
{
    $extra = [];
    $precios = [];
    if ($pedido) {
        foreach ($pedido["items"] as $it) {
            $extra[] = (int) $it["producto_id"];
            if ($pedido["estado"] !== "borrador") {
                $precios[(int) $it["producto_id"]] = (float) $it["precio_unitario"];
            }
        }
    }
    $or = $extra ? " OR p.id IN (" . implode(",", $extra) . ")" : "";
    if ($pedido && $pedido["items"]) {
        $refs = array_map(fn($it) => "'" . $c->real_escape_string((string) $it["referencia"]) . "'", $pedido["items"]);
        $or .= " OR p.referencia IN (" . implode(",", $refs) . ")";
    }
    $res = $c->query(
        "SELECT p.id, p.referencia, p.nombre, p.precio, p.activo,
                (SELECT UNIX_TIMESTAMP(f.actualizado_en) FROM producto_fotos f WHERE f.producto_id = p.id) AS foto_v
         FROM productos_mayoristas p WHERE p.activo = 1 $or ORDER BY p.referencia ASC"
    );
    $lista = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    foreach ($lista as &$p) {
        if (isset($precios[(int) $p["id"]])) {
            $p["precio"] = $precios[(int) $p["id"]];
        }
    }
    unset($p);
    return $lista;
}

/** Pedidos para PDF / Excel según los parámetros GET (id único o filtros de la sección Pedidos). */
function pedidosParaExportar(mysqli $c): array
{
    $idUnico = (int) ($_GET["id"] ?? 0);
    if ($idUnico > 0) {
        return obtenerPedidos($c, ["con_borradores" => true], null, $idUnico);
    }
    return obtenerPedidos($c, [
        "vendedor" => (int) ($_GET["vendedor_p"] ?? 0),
        "estado"   => $_GET["estado_p"] ?? "",
        "desde"    => $_GET["desde_p"] ?? "",
        "hasta"    => $_GET["hasta_p"] ?? "",
        "buscar"   => $_GET["buscar_p"] ?? "",
    ], 500);
}


/* ================= API JSON del formulario de pedido =================
 * Se llama desde vendedor.php?api=1 (ejecutivos) y admin_pedido_editar.php?api=1 (administrador).
 * Acciones: buscar_cliente | guardar | eliminar_borrador
 */
function api_responder(array $d, int $codigo = 200): void
{
    http_response_code($codigo);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

function manejarApiPedido(mysqli $conexion): void
{
    header("Content-Type: application/json; charset=utf-8");
    date_default_timezone_set("America/Bogota");
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        api_responder(["ok" => false, "error" => "Método no permitido."], 405);
    }

    $in = json_decode(file_get_contents("php://input"), true);
    if (!is_array($in)) {
        api_responder(["ok" => false, "error" => "Solicitud no válida."], 400);
    }

    if (empty($_SESSION["csrf_pedido"]) || !hash_equals($_SESSION["csrf_pedido"], (string) ($in["csrf"] ?? ""))) {
        api_responder(["ok" => false, "error" => "La sesión expiró. Recargue la página."], 403);
    }

    try {
        asegurarTablasVendedores($conexion);

        $rol = ($in["rol"] ?? "") === "admin" ? "admin" : "vendedor";
        $vendedorId = 0;

        if ($rol === "admin") {
            if (empty($_SESSION["admin_id"])) {
                api_responder(["ok" => false, "error" => "Sesión de administrador no válida."], 401);
            }
        } else {
            $vendedorId = (int) ($_SESSION["vendedor_id"] ?? 0);
            $st = $conexion->prepare("SELECT id FROM vendedores WHERE id = ? AND activo = 1");
            $st->bind_param("i", $vendedorId);
            $st->execute();
            $ok = $st->get_result()->fetch_assoc();
            $st->close();
            if (!$vendedorId || !$ok) {
                api_responder(["ok" => false, "error" => "Sesión no válida. Ingrese de nuevo."], 401);
            }
        }

        $accion = $in["accion"] ?? "";

        if ($accion === "buscar_cliente") {
            $cli = buscarClientePorDocumento($conexion, (string) ($in["doc"] ?? ""));
            if (!$cli) {
                api_responder(["ok" => true, "encontrado" => false]);
            }
            $ident = $cli["identificacion"] . (($cli["dv"] ?? "") !== "" && $cli["tipo_persona"] === "juridica" ? "-" . $cli["dv"] : "");
            api_responder(["ok" => true, "encontrado" => true, "cliente" => [
                "id" => (int) $cli["id"],
                "tipo_persona" => $cli["tipo_persona"],
                "identificacion" => $ident,
                "razon_social" => $cli["razon_social"],
                "nombre_comercial" => $cli["nombre_comercial"],
                "telefono" => $cli["telefono1"] ?: ($cli["movil"] ?: ""),
                "email" => $cli["email"],
                "email_fe" => $cli["email_fe"],
                "departamento" => $cli["departamento"],
                "municipio" => $cli["municipio"],
                "barrio" => $cli["barrio"],
                "direccion" => $cli["direccion"],
                "puntos_referencia" => $cli["puntos_referencia"],
                "condicion_pago" => $cli["condicion_pago"],
                "dias_credito" => $cli["dias_credito"],
                "inactivo" => (int) $cli["inactivo"],
            ]]);
        }

        if ($accion === "guardar") {
            $modo = ($in["modo"] ?? "") === "enviar" ? "enviar" : "borrador";
            $pedidoId = (int) ($in["pedido_id"] ?? 0) ?: null;
            $datos = is_array($in["datos"] ?? null) ? $in["datos"] : [];
            $items = is_array($in["items"] ?? null) ? $in["items"] : [];

            if ($rol === "admin" && !$pedidoId) {
                api_responder(["ok" => false, "error" => "Falta el pedido a editar."], 400);
            }

            $r = guardarPedido($conexion, $vendedorId, $pedidoId, $datos, $items, $modo, $rol);
            if (!$r["ok"]) {
                api_responder($r, 422);
            }

            if ($modo === "enviar" && $rol === "vendedor") {
                $_SESSION["msg_vendedor"] = ($pedidoId ? "Pedido #" : "Pedido #") . numeroPedido($r["id"]) .
                    ($pedidoId ? " actualizado" : " enviado") . " por " . formatoCOP($r["total"]) . ".";
                $_SESSION["msg_vendedor_tipo"] = "exito";
            }
            if ($rol === "admin") {
                $_SESSION["mensaje"] = "Pedido #" . numeroPedido($r["id"]) . " actualizado.";
                $_SESSION["tipo_mensaje"] = "exito";
            }
            api_responder($r);
        }

        if ($accion === "eliminar_borrador" && $rol === "vendedor") {
            $pid = (int) ($in["pedido_id"] ?? 0);
            $st = $conexion->prepare("DELETE FROM pedidos_vendedores WHERE id = ? AND vendedor_id = ? AND estado = 'borrador'");
            $st->bind_param("ii", $pid, $vendedorId);
            $st->execute();
            $st->close();
            api_responder(["ok" => true]);
        }

        api_responder(["ok" => false, "error" => "Acción no válida."], 400);
    } catch (Throwable $e) {
        api_responder(["ok" => false, "error" => "Error del servidor. Intente de nuevo."], 500);
    }
}
