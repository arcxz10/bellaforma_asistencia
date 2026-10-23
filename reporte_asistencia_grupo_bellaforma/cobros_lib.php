<?php
/**
 * Pedidos por cobrar (pedidos a crédito).
 *
 *  - Un pedido «cuenta» como crédito cuando su forma de pago es «dias_N» (o, en pedidos viejos sin forma de pago,
 *    cuando la condición es crédito con días). Solo los documentos tipo «Pedido de cliente», enviados y no anulados.
 *  - Los días corren desde la fecha en que se creó el pedido (creado_en). Vence = fecha del pedido + días de crédito.
 *  - El ejecutivo sube el comprobante de pago; queda en `pedido_cobros` (imagen o PDF guardado en la base de datos).
 *    El administrador lo revisa y marca el pedido como «cobrado»: desde ahí sale de «Pedidos por cobrar».
 */

/** Crea la tabla de cobros (una sola vez por petición). */
function asegurarTablaCobros(mysqli $c): void
{
    static $listo = false;
    if ($listo) {
        return;
    }
    $listo = true;
    $def = "pedido_id INT NOT NULL PRIMARY KEY,
        mime VARCHAR(40) NULL,
        datos MEDIUMBLOB NULL,
        subido_en DATETIME NULL,
        visto TINYINT(1) NOT NULL DEFAULT 0,
        cobrado TINYINT(1) NOT NULL DEFAULT 0,
        cobrado_en DATETIME NULL";
    try {
        try {
            $c->query("CREATE TABLE IF NOT EXISTS pedido_cobros ($def,
                CONSTRAINT fk_cobro_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos_vendedores(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
            $c->query("CREATE TABLE IF NOT EXISTS pedido_cobros ($def) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Throwable $e) {
        // se reintenta en la próxima visita
    }
}

/** Días de crédito de un pedido (0 si no es a crédito). */
function cobroDiasCredito(array $p): int
{
    $fp = (string) ($p["forma_pago"] ?? "");
    if ($fp !== "") {
        return (int) (formaPagoDias($fp) ?? 0);
    }
    if (($p["condicion_pago"] ?? "contado") === "credito") {
        return max(0, (int) ($p["dias_credito"] ?? 0));
    }
    return 0;
}

/** ¿El pedido es de los que se cobran a crédito y siguen vigentes (enviado, no anulado)? */
function cobroEsACredito(array $p): bool
{
    return ($p["tipo_pedido"] ?? "pedido") === "pedido"
        && in_array($p["estado"] ?? "", ["pendiente", "procesado"], true)
        && cobroDiasCredito($p) > 0;
}

/**
 * Plazo de un pedido a crédito:
 *   dias       días de crédito pactados
 *   base       fecha del pedido (Y-m-d)
 *   vence      fecha límite de pago (Y-m-d)
 *   restantes  días que le quedan (0 = vence hoy, negativo = ya venció)
 */
function cobroPlazo(array $p, ?DateTimeImmutable $hoy = null): array
{
    $hoy = $hoy ?: new DateTimeImmutable("today", new DateTimeZone("America/Bogota"));
    $dias = cobroDiasCredito($p);
    $base = new DateTimeImmutable(substr((string) ($p["creado_en"] ?? "now"), 0, 10) ?: "today", new DateTimeZone("America/Bogota"));
    $vence = $base->modify("+" . $dias . " days");
    $dif = $hoy->diff($vence);
    $restantes = (int) $dif->days * ($dif->invert ? -1 : 1);
    return [
        "dias" => $dias,
        "base" => $base->format("Y-m-d"),
        "vence" => $vence->format("Y-m-d"),
        "restantes" => $restantes,
    ];
}

/** Texto corto: «Quedan 12 días», «Vence hoy», «Vencido hace 3 días». */
function cobroTextoRestantes(int $r): string
{
    if ($r > 1) {
        return "Quedan " . $r . " días";
    }
    if ($r === 1) {
        return "Queda 1 día";
    }
    if ($r === 0) {
        return "Vence hoy";
    }
    $n = abs($r);
    return "Vencido hace " . $n . ($n === 1 ? " día" : " días");
}

/** Clase de color según urgencia: ok (verde azulado), pronto (≤5 días), hoy, vencido. */
function cobroClaseRestantes(int $r): string
{
    if ($r < 0) {
        return "vencido";
    }
    if ($r === 0) {
        return "hoy";
    }
    if ($r <= 5) {
        return "pronto";
    }
    return "ok";
}

/** Normaliza texto para buscar sin importar mayúsculas ni tildes. */
function cobroNorm(string $t): string
{
    $t = function_exists("mb_strtolower") ? mb_strtolower($t, "UTF-8") : strtolower($t);
    return strtr($t, ["á" => "a", "é" => "e", "í" => "i", "ó" => "o", "ú" => "u", "ü" => "u", "ñ" => "n"]);
}

/**
 * Pedidos a crédito con su plazo y su estado de cobro.
 * Filtros: buscar (cliente / comercial / NIT / N° / ejecutivo), desde, hasta (fecha del pedido), vendedor,
 *          estado_cobro = por_cobrar (por defecto) | con_comprobante | sin_comprobante | cobrados | todos,
 *          orden = dias_menos (por defecto) | dias_mas | valor_mayor | valor_menor
 */
function cobroObtenerPedidos(mysqli $c, array $f = []): array
{
    asegurarTablaCobros($c);
    $sql = "SELECT p.*, v.nombre AS vendedor_nombre,
                   cb.cobrado AS cb_cobrado, cb.cobrado_en AS cb_cobrado_en, cb.subido_en AS cb_subido_en,
                   cb.visto AS cb_visto, cb.mime AS cb_mime, (cb.datos IS NOT NULL) AS cb_tiene
            FROM pedidos_vendedores p
            JOIN vendedores v ON v.id = p.vendedor_id
            LEFT JOIN pedido_cobros cb ON cb.pedido_id = p.id
            WHERE p.tipo_pedido = 'pedido' AND p.estado IN ('pendiente','procesado')
              AND (p.forma_pago LIKE 'dias\\_%' OR (COALESCE(p.forma_pago, '') = '' AND p.condicion_pago = 'credito'))";
    $tipos = "";
    $params = [];

    $vend = (int) ($f["vendedor"] ?? 0);
    if ($vend > 0) {
        $sql .= " AND p.vendedor_id = ?";
        $tipos .= "i";
        $params[] = $vend;
    }
    $desde = (string) ($f["desde"] ?? "");
    $hasta = (string) ($f["hasta"] ?? "");
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
        $sql .= " AND DATE(p.creado_en) >= ?";
        $tipos .= "s";
        $params[] = $desde;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
        $sql .= " AND DATE(p.creado_en) <= ?";
        $tipos .= "s";
        $params[] = $hasta;
    }
    $sql .= " ORDER BY p.creado_en DESC, p.id DESC LIMIT 3000";

    $st = $c->prepare($sql);
    if (!$st) {
        return [];
    }
    if ($params) {
        $st->bind_param($tipos, ...$params);
    }
    $st->execute();
    $res = $st->get_result();
    $filas = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $st->close();

    $hoy = new DateTimeImmutable("today", new DateTimeZone("America/Bogota"));
    $buscar = trim((string) ($f["buscar"] ?? ""));
    $buscarN = cobroNorm($buscar);
    $buscarDig = preg_replace('/\D+/', '', $buscar) ?? "";
    $estadoCobro = (string) ($f["estado_cobro"] ?? "por_cobrar");

    $salida = [];
    foreach ($filas as $p) {
        if (cobroDiasCredito($p) < 1) {
            continue;
        }
        $p["cobrado"] = (int) ($p["cb_cobrado"] ?? 0) === 1;
        $p["tiene_comprobante"] = (int) ($p["cb_tiene"] ?? 0) === 1;
        $p["comprobante_visto"] = (int) ($p["cb_visto"] ?? 0) === 1;

        switch ($estadoCobro) {
            case "todos":
                break;
            case "cobrados":
                if (!$p["cobrado"]) { continue 2; }
                break;
            case "con_comprobante":
                if ($p["cobrado"] || !$p["tiene_comprobante"]) { continue 2; }
                break;
            case "sin_comprobante":
                if ($p["cobrado"] || $p["tiene_comprobante"]) { continue 2; }
                break;
            default:   // por_cobrar
                if ($p["cobrado"]) { continue 2; }
        }

        if ($buscar !== "") {
            $pajar = cobroNorm(implode(" ", [
                $p["cliente_nombre"] ?? "", $p["cliente_nombre_comercial"] ?? "", $p["cliente_ciudad"] ?? "",
                $p["vendedor_nombre"] ?? "", numeroDocumento($p),
            ]));
            $nitDig = preg_replace('/\D+/', '', (string) ($p["cliente_nit"] ?? "")) ?? "";
            $coincide = strpos($pajar, $buscarN) !== false
                || ($buscarDig !== "" && ($nitDig !== "" && strpos($nitDig, $buscarDig) !== false))
                || ltrim($buscar, "#") === numeroDocumento($p);
            if (!$coincide) {
                continue;
            }
        }

        $p["plazo"] = cobroPlazo($p, $hoy);
        $salida[] = $p;
    }

    $orden = (string) ($f["orden"] ?? "dias_menos");
    usort($salida, function ($a, $b) use ($orden) {
        switch ($orden) {
            case "dias_mas":
                $r = $b["plazo"]["restantes"] <=> $a["plazo"]["restantes"];
                break;
            case "valor_mayor":
                $r = (float) $b["total"] <=> (float) $a["total"];
                break;
            case "valor_menor":
                $r = (float) $a["total"] <=> (float) $b["total"];
                break;
            default:   // dias_menos: el que menos días tiene primero (los vencidos arriba)
                $r = $a["plazo"]["restantes"] <=> $b["plazo"]["restantes"];
        }
        return $r !== 0 ? $r : ((int) $b["id"] <=> (int) $a["id"]);
    });
    return $salida;
}

/**
 * Estado de cobro de los pedidos de un ejecutivo (para vendedor.php): id => [cobrado, tiene_comprobante].
 */
function cobroEstadoPorPedido(mysqli $c, array $ids): array
{
    asegurarTablaCobros($c);
    $ids = array_values(array_filter(array_map("intval", $ids), fn($i) => $i > 0));
    if (!$ids) {
        return [];
    }
    $out = [];
    try {
        $res = $c->query("SELECT pedido_id, cobrado, (datos IS NOT NULL) AS tiene, subido_en
                          FROM pedido_cobros WHERE pedido_id IN (" . implode(",", $ids) . ")");
        while ($res && ($f = $res->fetch_assoc())) {
            $out[(int) $f["pedido_id"]] = [
                "cobrado" => (int) $f["cobrado"] === 1,
                "tiene" => (int) $f["tiene"] === 1,
                "subido_en" => $f["subido_en"],
            ];
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/** Comprobantes subidos que el administrador todavía no ha abierto (los de la campanita). */
function cobroContarNuevos(mysqli $c): int
{
    asegurarTablaCobros($c);
    try {
        $r = $c->query("SELECT COUNT(*) AS n FROM pedido_cobros cb
                        JOIN pedidos_vendedores p ON p.id = cb.pedido_id
                        WHERE cb.datos IS NOT NULL AND cb.visto = 0 AND cb.cobrado = 0 AND p.estado IN ('pendiente','procesado')");
        $f = $r ? $r->fetch_assoc() : null;
        return (int) ($f["n"] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** Cantidad de pedidos que faltan por cobrar (para la insignia del menú). */
function cobroContarPorCobrar(mysqli $c): int
{
    return count(cobroObtenerPedidos($c, ["estado_cobro" => "por_cobrar"]));
}

/** Borra el cobro de un pedido (por si no existe la llave foránea). Nunca interrumpe. */
function borrarCobroDePedido(mysqli $c, int $pedidoId): void
{
    try {
        $st = $c->prepare("DELETE FROM pedido_cobros WHERE pedido_id = ?");
        $st->bind_param("i", $pedidoId);
        $st->execute();
        $st->close();
    } catch (Throwable $e) {
    }
}
