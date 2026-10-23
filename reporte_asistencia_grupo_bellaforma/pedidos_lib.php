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
        $tabla = tablaClientes();
        $stmt = $c->prepare("SELECT * FROM `$tabla` WHERE identificacion_norm = ? LIMIT 1");
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
 * Mantiene la base de clientes al enviar un pedido:
 *  - Si el NIT / cédula NO existe, crea el cliente con los datos que se llenaron en el pedido.
 *  - Si ya existe (y es de la misma lista: mayorista / detal), solo COMPLETA los datos que tenía vacíos; nunca pisa los que ya tiene.
 * Devuelve el id del cliente o null. Si algo falla no interrumpe el guardado del pedido.
 */
function sincronizarClienteDesdePedido(mysqli $c, array $d): ?int
{
    try {
        require_once __DIR__ . "/clientes_lib.php";
        $raw = trim((string) ($d["identificacion"] ?? ""));
        $partes = explode("-", $raw);
        $norm = soloDigitos($partes[0]);
        $dv = isset($partes[1]) ? soloDigitos($partes[1]) : "";
        if (strlen($norm) < 5 || trim((string) ($d["razon_social"] ?? "")) === "") {
            return null;
        }
        $lista = listaValida($d["tipo_cliente"] ?? "");
        $cli = buscarClientePorDocumento($c, $raw);

        if (!$cli) {
            $r = guardarCliente($c, [
                "tipo_cliente" => $lista,
                "tipo_persona" => $d["tipo_persona"] ?? "",
                "identificacion" => $norm,
                "dv" => $dv,
                "razon_social" => $d["razon_social"],
                "nombre_comercial" => $d["nombre_comercial"] ?? "",
                "telefono1" => $d["telefono"] ?? "",
                "email" => $d["email"] ?? "",
                "email_fe" => $d["email_fe"] ?? "",
                "departamento" => $d["departamento"] ?? "",
                "municipio" => $d["municipio"] ?? "",
                "barrio" => $d["barrio"] ?? "",
                "direccion" => $d["direccion"] ?? "",
                "puntos_referencia" => $d["puntos_referencia"] ?? "",
                "condicion_pago" => $d["condicion_pago"] ?? "contado",
                "dias_credito" => $d["dias_credito"] ?? "",
            ], null);
            return !empty($r["ok"]) ? (int) $r["id"] : null;
        }

        // Cliente registrado con la otra lista de precios: no se mezcla ni se modifica
        if (($cli["tipo_cliente"] ?? "mayorista") !== $lista) {
            return null;
        }

        // Cliente existente: se llenan solo los campos que estaban vacíos
        $sets = [];
        $tipos = "";
        $vals = [];
        foreach (["nombre_comercial", "email", "email_fe", "departamento", "municipio", "barrio", "direccion", "puntos_referencia"] as $col) {
            $nuevo = trim((string) ($d[$col] ?? ""));
            if ($nuevo !== "" && trim((string) ($cli[$col] ?? "")) === "") {
                $sets[] = "`$col` = ?";
                $tipos .= "s";
                $vals[] = $nuevo;
            }
        }
        $tel = trim((string) ($d["telefono"] ?? ""));
        if ($tel !== "" && trim((string) ($cli["telefono1"] ?? "")) === "" && trim((string) ($cli["movil"] ?? "")) === "") {
            $sets[] = "`telefono1` = ?";
            $tipos .= "s";
            $vals[] = $tel;
        }
        if ($dv !== "" && trim((string) ($cli["dv"] ?? "")) === "") {
            $sets[] = "`dv` = ?";
            $tipos .= "s";
            $vals[] = $dv;
        }
        if ($sets) {
            $tabla = tablaClientes();
            $st = $c->prepare("UPDATE `$tabla` SET " . implode(", ", $sets) . " WHERE id = ?");
            $tipos .= "i";
            $vals[] = (int) $cli["id"];
            $st->bind_param($tipos, ...$vals);
            $st->execute();
            $st->close();
        }
        return (int) $cli["id"];
    } catch (Throwable $e) {
        return null;
    }
}

/** ¿El administrador autorizó a este ejecutivo (descargas + editar el número del documento)? */
function vendedorPuedeEditarNumero(mysqli $c, int $vendedorId): bool
{
    if ($vendedorId <= 0) {
        return false;
    }
    $st = $c->prepare("SELECT puede_descargar FROM vendedores WHERE id = ? AND activo = 1 LIMIT 1");
    $st->bind_param("i", $vendedorId);
    $st->execute();
    $res = $st->get_result();
    $f = $res ? $res->fetch_assoc() : null;
    $st->close();
    return $f && (int) $f["puede_descargar"] === 1;
}

/**
 * Guarda un pedido.
 *  $d      datos del cliente y condiciones
 *  $items  [producto_id => cantidad]
 *  $bonos  [producto_id => cantidad] productos BONIFICADOS (se entregan sin costo)
 *  $modo   'borrador' | 'enviar'
 *  $rol    'vendedor' | 'admin'
 * Devuelve ['ok'=>bool, 'error'=>string, 'id'=>int, 'total'=>float, 'estado'=>string]
 */
function guardarPedido(mysqli $c, int $vendedorId, ?int $pedidoId, array $d, array $items, string $modo, string $rol = "vendedor", array $bonos = []): array
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

    // ---- Tipo de cliente (define la lista de precios) ----
    $tipoCliente = $d["tipo_cliente"] ?? "";
    if (!in_array($tipoCliente, ["mayorista", "detal"], true)) {
        if ($modo === "enviar" && !$existente) {
            return $falla("Elija primero el tipo de cliente: mayorista o detal.");
        }
        $tipoCliente = $existente["tipo_cliente"] ?? "mayorista";
    }

    // ---- Tipo de documento (pedido de cliente / remisión / cotización) y número ----
    $tipoDoc = isset($d["tipo_documento"]) && array_key_exists((string) $d["tipo_documento"], tiposDocumentoPedido())
        ? (string) $d["tipo_documento"]
        : ($existente["tipo_pedido"] ?? "pedido");
    $nombreDoc = nombreCortoDocumento($tipoDoc);
    // Pueden escribir o cambiar el número el administrador y los ejecutivos autorizados; los demás conservan el que ya tengan (vacío = automático)
    $puedeNumero = $rol === "admin" || (array_key_exists("numero", $d) && vendedorPuedeEditarNumero($c, $vendedorId));
    $numeroDoc = $puedeNumero ? trim((string) ($d["numero"] ?? "")) : trim((string) ($existente["numero_pedido"] ?? ""));
    if ($numeroDoc !== "" && (!preg_match('/^\d{1,10}$/', $numeroDoc) || (int) $numeroDoc < 1)) {
        return $falla("El número de la " . $nombreDoc . " solo puede tener dígitos (máximo 10) y debe ser mayor que 0.");
    }
    $mensajeNumeroEnUso = fn(string $n) => "El número " . $n . " ya está en uso por otra " . $nombreDoc . ". Escriba un número distinto.";
    if ($modo === "enviar") {
        // Número escrito a mano, o el automático de un documento que ya existe (un borrador al enviarse, por ejemplo)
        $numeroARevisar = $numeroDoc !== "" ? (int) $numeroDoc : ($existente ? (int) $existente["id"] : 0);
        if ($numeroARevisar > 0 && numeroDocumentoEnUso($c, $tipoDoc, $numeroARevisar, (int) ($existente["id"] ?? 0))) {
            return $falla($mensajeNumeroEnUso($numeroDoc !== "" ? $numeroDoc : numeroPedido($numeroARevisar)));
        }
    }

    // ---- Remisión unida a un pedido (solo aplica a remisiones; se ve en el Control de despachos) ----
    $pedidoVinculado = null;
    if ($tipoDoc === "remision") {
        $vinculoActual = !empty($existente["pedido_vinculado_id"]) ? (int) $existente["pedido_vinculado_id"] : null;
        // Si la solicitud no trae el dato (pantalla del administrador, pantalla vieja), se conserva el que ya tenía
        $pedidoVinculado = array_key_exists("pedido_vinculado_id", $d) ? ((int) $d["pedido_vinculado_id"] ?: null) : $vinculoActual;
        if ($pedidoVinculado !== null && $pedidoVinculado !== $vinculoActual) {
            // Solo se valida si cambió: debe ser un pedido de cliente enviado, no anulado, del mismo ejecutivo, y no la remisión misma
            $stv = $c->prepare("SELECT vendedor_id FROM pedidos_vendedores WHERE id = ? AND tipo_pedido = 'pedido' AND estado IN ('pendiente','procesado') LIMIT 1");
            $stv->bind_param("i", $pedidoVinculado);
            $stv->execute();
            $filaV = $stv->get_result()->fetch_assoc();
            $stv->close();
            $valido = $filaV && ($rol === "admin" || (int) $filaV["vendedor_id"] === $vendedorId) && $pedidoVinculado !== (int) ($existente["id"] ?? 0);
            if (!$valido) {
                return $falla("El pedido al que quiere unir la remisión ya no está disponible. Elija otro o déjela sin unir.");
            }
        }
    }

    // ---- Datos del cliente ----
    $tipo = (($d["tipo_persona"] ?? "") === "natural") ? "natural" : "juridica";
    $tipoDocCliente = $tipo === "natural" ? "CC" : "NIT";
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

    // Forma de pago y transportadora (alimentan el Control de despachos). Si la solicitud no las trae (pantalla vieja), se conserva lo que ya tenía el pedido.
    $formaPago = array_key_exists("forma_pago", $d) ? (string) $d["forma_pago"] : (string) ($existente["forma_pago"] ?? "");
    $transportadora = array_key_exists("transportadora", $d) ? (string) $d["transportadora"] : (string) ($existente["transportadora"] ?? "");
    if (!array_key_exists($formaPago, formasPagoDespacho())) {
        $formaPago = "";
    }
    if (!array_key_exists($transportadora, transportadorasDespacho())) {
        $transportadora = "";
    }
    if ($formaPago !== "") {
        // La forma de pago define la condición comercial que usan el PDF, el Excel y Syscafe
        $diasFp = formaPagoDias($formaPago);
        $condicion = $diasFp ? "credito" : "contado";
        $dias = $diasFp ?: null;
    }
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
        if (!$ubicacionOpcional && $depto === "") {
            return $falla("Seleccione el departamento.");
        }
        if ($direccion === "") {
            return $falla("Ingrese la dirección de entrega.");
        }
        if ($telefono === "") {
            return $falla("Ingrese un teléfono de contacto.");
        }
        // Documentos nuevos: forma de pago y transportadora son obligatorias.
        // (Un documento ya enviado antes de existir este dato puede seguir editándose sin completarlo.)
        $enviadoAntes = $existente && $existente["estado"] !== "borrador";
        if ($formaPago === "" && !($enviadoAntes && trim((string) ($existente["forma_pago"] ?? "")) === "")) {
            return $falla("Seleccione la forma de pago.");
        }
        if ($transportadora === "" && !($enviadoAntes && trim((string) ($existente["transportadora"] ?? "")) === "")) {
            return $falla("Seleccione la transportadora.");
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
    $cantBonos = [];
    foreach ($bonos as $pid => $cant) {
        $pid = (int) $pid;
        $cant = (int) $cant;
        if ($pid > 0 && $cant > 0 && $cant <= 100000) {
            $cantBonos[$pid] = $cant;
        }
    }
    if ($modo === "enviar" && !$cantidades && !$cantBonos) {
        return $falla("Seleccione al menos un producto con cantidad.");
    }

    $preciosPrevios = [];   // precio ya pactado en un pedido enviado (se respeta al editar)
    $idsPrevios = [];
    $mismaLista = $existente && ($existente["tipo_cliente"] ?? "mayorista") === $tipoCliente;
    if ($mismaLista) {
        foreach ($existente["items"] as $it) {
            $idsPrevios[(int) $it["producto_id"]] = true;
            if ($existente["estado"] !== "borrador") {
                $preciosPrevios[(int) $it["producto_id"]] = (float) $it["precio_unitario"];
            }
        }
    }

    $lineas = [];
    $total = 0.0;
    if ($cantidades || $cantBonos) {
        $idsTodos = array_values(array_unique(array_merge(array_keys($cantidades), array_keys($cantBonos))));
        $idsLista = implode(",", array_map("intval", $idsTodos));
        $tablaProd = tablaProductos($tipoCliente);
        $colPrecio = columnaPrecio($tipoCliente);   // precio_mayorista o precio_detal
        $res = $c->query("SELECT id, referencia, nombre, `$colPrecio` AS precio, activo FROM `$tablaProd` WHERE id IN ($idsLista)");
        $porId = [];
        while ($res && ($p = $res->fetch_assoc())) {
            $porId[(int) $p["id"]] = $p;
        }
        foreach ($idsTodos as $pid) {
            if (!isset($porId[$pid])) {
                continue;
            }
            $p = $porId[$pid];
            if ((int) $p["activo"] !== 1 && !isset($idsPrevios[$pid])) {
                continue;
            }
            $precio = $preciosPrevios[$pid] ?? (float) $p["precio"];
            if ($precio <= 0) {
                continue;   // sin precio en esta lista: no se puede pedir
            }
            if (isset($cantidades[$pid])) {
                $cant = $cantidades[$pid];
                $sub = round($precio * $cant, 2);
                $total += $sub;
                $lineas[] = ["id" => $pid, "referencia" => $p["referencia"], "nombre" => $p["nombre"],
                             "precio" => $precio, "cantidad" => $cant, "subtotal" => $sub, "bonificado" => 0];
            }
            if (isset($cantBonos[$pid])) {
                // Bonificado: queda el precio de lista como referencia, pero no se cobra (subtotal 0)
                $lineas[] = ["id" => $pid, "referencia" => $p["referencia"], "nombre" => $p["nombre"],
                             "precio" => $precio, "cantidad" => $cantBonos[$pid], "subtotal" => 0.0, "bonificado" => 1];
            }
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

    // ---- Base de clientes: al ENVIAR se crea el cliente nuevo y se completan los datos que le falten (los borradores no) ----
    if ($modo === "enviar") {
        $idCliente = sincronizarClienteDesdePedido($c, [
            "tipo_cliente" => $tipoCliente, "tipo_persona" => $tipo, "identificacion" => $ident, "razon_social" => $nombre,
            "nombre_comercial" => $comercial, "telefono" => $telefono, "email" => $email, "email_fe" => $emailFe,
            "departamento" => $depto, "municipio" => $muni, "barrio" => $barrio, "direccion" => $direccion,
            "puntos_referencia" => $puntos, "condicion_pago" => $condicion, "dias_credito" => $dias,
        ]);
        if ($idCliente) {
            $clienteId = $idCliente;
        }
    }

    $campos = [
        "tipo_cliente"              => ["s", $tipoCliente],
        "tipo_pedido"               => ["s", $tipoDoc],
        "numero_pedido"             => ["s", $numeroDoc !== "" ? $numeroDoc : null],
        "cliente_id"                => ["i", $clienteId],
        "cliente_tipo_persona"      => ["s", $tipo],
        "cliente_tipo_documento"    => ["s", $tipoDocCliente],
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
        "forma_pago"                => ["s", $formaPago !== "" ? $formaPago : null],
        "transportadora"            => ["s", $transportadora !== "" ? $transportadora : null],
        "pedido_vinculado_id"       => ["i", $pedidoVinculado],
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
            // Documento nuevo sin número escrito: su número automático es el id; no puede chocar con uno escrito a mano
            if ($modo === "enviar" && $numeroDoc === "" && numeroDocumentoEnUso($c, $tipoDoc, $idFinal, $idFinal)) {
                throw new RuntimeException("NUMERO_EN_USO");
            }
        }

        $insItem = $c->prepare(
            "INSERT INTO pedido_vendedor_items (pedido_id, producto_id, referencia, nombre, precio_unitario, cantidad, subtotal, bonificado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($lineas as $l) {
            $insItem->bind_param("iissdidi", $idFinal, $l["id"], $l["referencia"], $l["nombre"], $l["precio"], $l["cantidad"], $l["subtotal"], $l["bonificado"]);
            $insItem->execute();
        }
        $insItem->close();

        $c->commit();

        // Al enviarse por primera vez, el documento entra al Control de despachos del día de hoy
        if ($modo === "enviar" && $estado === "pendiente" && (!$existente || $existente["estado"] === "borrador")) {
            crearDespachoDePedido($c, $idFinal);
        }
    } catch (Throwable $e) {
        $c->rollback();
        if ($e->getMessage() === "NUMERO_EN_USO") {
            return $falla("El número que el sistema iba a asignar ya está en uso por otra " . $nombreDoc . ". Escriba un número manualmente en el paso 1.");
        }
        $detalle = trim(preg_replace('/\s+/', " ", $e->getMessage()) ?? "");
        return $falla("No se pudo guardar el pedido. Detalle técnico: " . substr($detalle, 0, 180));
    }

    $titulo = etiquetaTipoDocumento($tipoDoc) . " #" . ($numeroDoc !== "" ? $numeroDoc : numeroPedido($idFinal));
    return ["ok" => true, "id" => $idFinal, "total" => $total, "estado" => $estado, "titulo" => $titulo];
}

/**
 * Pedidos de cliente del ejecutivo a los que se puede unir una remisión (enviados, no anulados), los más recientes primero.
 * $incluirId: pedido al que la remisión ya está unida (se incluye aunque sea antiguo o haya cambiado de estado).
 */
function pedidosParaUnirRemision(mysqli $c, int $vendedorId, ?int $incluirId = null): array
{
    $incluir = (int) $incluirId;
    $st = $c->prepare(
        "SELECT id, numero_pedido, cliente_nombre, cliente_nombre_comercial, total, creado_en
         FROM pedidos_vendedores
         WHERE vendedor_id = ? AND tipo_pedido = 'pedido' AND (estado IN ('pendiente','procesado') OR id = ?)
         ORDER BY creado_en DESC, id DESC
         LIMIT 120"
    );
    $st->bind_param("ii", $vendedorId, $incluir);
    $st->execute();
    $res = $st->get_result();
    $lista = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $st->close();
    return $lista;
}

/**
 * Productos para el formulario: un solo catálogo con los dos precios (precio_mayorista y precio_detal).
 * Incluye los activos y los que ya estén en el pedido que se edita (conservando el precio pactado).
 */
function productosParaFormulario(mysqli $c, ?array $pedido): array
{
    $listaPedido = $pedido["tipo_cliente"] ?? "mayorista";
    $colPedido = columnaPrecio($listaPedido);
    $tabla = tablaProductos();
    $tablaFoto = tablaFotos();
    $precios = [];
    $or = "";
    if ($pedido && $pedido["items"]) {
        $extra = [];
        foreach ($pedido["items"] as $it) {
            $extra[] = (int) $it["producto_id"];
            if ($pedido["estado"] !== "borrador") {
                $precios[(int) $it["producto_id"]] = (float) $it["precio_unitario"];
            }
        }
        $or = " OR p.id IN (" . implode(",", $extra) . ")";
        $refs = array_map(fn($it) => "'" . $c->real_escape_string((string) $it["referencia"]) . "'", $pedido["items"]);
        $or .= " OR p.referencia IN (" . implode(",", $refs) . ")";
    }
    $res = $c->query(
        "SELECT p.id, p.referencia, p.nombre, p.precio_mayorista, p.precio_detal, p.activo,
                (SELECT UNIX_TIMESTAMP(f.actualizado_en) FROM `$tablaFoto` f WHERE f.producto_id = p.id) AS foto_v
         FROM `$tabla` p WHERE p.activo = 1 $or ORDER BY p.referencia ASC"
    );
    $filas = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    foreach ($filas as &$p) {
        if (isset($precios[(int) $p["id"]])) {
            $p[$colPedido] = $precios[(int) $p["id"]];   // precio ya pactado en el pedido
        }
    }
    unset($p);
    return $filas;
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
        "tipo_cliente" => $_GET["tipo_p"] ?? "",
        "tipo_documento" => $_GET["doc_p"] ?? "",
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
            // El cliente existe, pero está registrado con otro tipo (mayorista / detal): no se mezclan las listas de precios
            $listaPedida = listaValida($in["lista"] ?? "");
            if (($cli["tipo_cliente"] ?? "mayorista") !== $listaPedida) {
                api_responder(["ok" => true, "encontrado" => false, "otro_tipo" => $cli["tipo_cliente"]]);
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
            $bonos = is_array($in["bonos"] ?? null) ? $in["bonos"] : [];

            if ($rol === "admin" && !$pedidoId) {
                api_responder(["ok" => false, "error" => "Falta el pedido a editar."], 400);
            }

            $r = guardarPedido($conexion, $vendedorId, $pedidoId, $datos, $items, $modo, $rol, $bonos);
            if (!$r["ok"]) {
                api_responder($r, 422);
            }

            if ($modo === "enviar" && $rol === "vendedor") {
                $_SESSION["msg_vendedor"] = $r["titulo"] .
                    ($pedidoId ? " actualizado" : " enviado") . " por " . formatoCOP($r["total"]) . ".";
                $_SESSION["msg_vendedor_tipo"] = "exito";
            }
            if ($rol === "admin") {
                $_SESSION["mensaje"] = $r["titulo"] . " actualizado.";
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
