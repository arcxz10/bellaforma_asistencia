<?php
/**
 * Pedidos contraentrega.
 *
 *  - Cuenta como contraentrega todo pedido (tipo «Pedido de cliente», enviado y no anulado) cuya forma de pago es «contra_entrega».
 *  - La transportadora paga los días 15 y 30 de cada mes (en febrero, el último día del mes). La fecha de cobro sale del DÍA en que se creó el pedido:
 *        del 1 al 13   -> se cobra el 15 de ese mes
 *        del 14 al 27  -> se cobra el 30 de ese mes (fin de mes)
 *        del 28 al 31  -> se cobra el 15 del mes siguiente
 *  - El administrador marca el pedido como «cobrado» cuando llega el pago; se guarda en la misma tabla `pedido_cobros` que usa «Pedidos por cobrar».
 */

require_once __DIR__ . "/cobros_lib.php";

/** Fecha en que se cobra un pedido contraentrega, según el día en que se creó (creado_en: "Y-m-d H:i:s" o "Y-m-d"). */
function contraentregaFechaCobro(string $creadoEn): DateTimeImmutable
{
    $tz = new DateTimeZone("America/Bogota");
    $f = new DateTimeImmutable(substr($creadoEn, 0, 10) ?: "today", $tz);
    $dia = (int) $f->format("j");
    $ultimo = (int) $f->format("t");

    if ($dia <= 13) {
        return $f->setDate((int) $f->format("Y"), (int) $f->format("n"), 15);
    }
    if ($dia <= 27) {
        return $f->setDate((int) $f->format("Y"), (int) $f->format("n"), min(30, $ultimo));
    }
    $sig = $f->modify("first day of next month");
    return $sig->setDate((int) $sig->format("Y"), (int) $sig->format("n"), 15);
}

/** Texto corto de los días que faltan para el cobro. */
function contraentregaTextoRestantes(int $r): string
{
    if ($r > 1) {
        return "Faltan " . $r . " días";
    }
    if ($r === 1) {
        return "Falta 1 día";
    }
    if ($r === 0) {
        return "Se cobra hoy";
    }
    $n = abs($r);
    return "Atrasado " . $n . ($n === 1 ? " día" : " días");
}

/**
 * Pedidos contraentrega con su fecha de cobro.
 * Filtros: buscar, desde, hasta (fecha del pedido), vendedor, cobro (fecha exacta de cobro Y-m-d),
 *          estado_cobro = por_cobrar (por defecto) | cobrados | todos,
 *          orden = cobro_cerca (por defecto) | cobro_lejos | valor_mayor | valor_menor
 */
function contraentregaObtenerPedidos(mysqli $c, array $f = []): array
{
    asegurarTablaCobros($c);
    $sql = "SELECT p.*, v.nombre AS vendedor_nombre,
                   cb.cobrado AS cb_cobrado, cb.cobrado_en AS cb_cobrado_en
            FROM pedidos_vendedores p
            JOIN vendedores v ON v.id = p.vendedor_id
            LEFT JOIN pedido_cobros cb ON cb.pedido_id = p.id
            WHERE p.tipo_pedido = 'pedido' AND p.estado IN ('pendiente','procesado')
              AND p.forma_pago = 'contra_entrega'";
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
    $cobroExacto = (string) ($f["cobro"] ?? "");

    $salida = [];
    foreach ($filas as $p) {
        $p["cobrado"] = (int) ($p["cb_cobrado"] ?? 0) === 1;

        if ($estadoCobro === "cobrados" && !$p["cobrado"]) {
            continue;
        }
        if ($estadoCobro === "por_cobrar" && $p["cobrado"]) {
            continue;
        }

        $fechaPedido = substr((string) ($p["creado_en"] ?? ""), 0, 10);
        $cobro = contraentregaFechaCobro((string) ($p["creado_en"] ?? "now"));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $cobroExacto) && $cobro->format("Y-m-d") !== $cobroExacto) {
            continue;
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

        $dif = $hoy->diff($cobro);
        $p["fecha_pedido"] = $fechaPedido;
        $p["fecha_cobro"] = $cobro->format("Y-m-d");
        $p["restantes"] = (int) $dif->days * ($dif->invert ? -1 : 1);
        $salida[] = $p;
    }

    $orden = (string) ($f["orden"] ?? "cobro_cerca");
    usort($salida, function ($a, $b) use ($orden) {
        switch ($orden) {
            case "cobro_lejos":
                $r = strcmp($b["fecha_cobro"], $a["fecha_cobro"]);
                break;
            case "valor_mayor":
                $r = (float) $b["total"] <=> (float) $a["total"];
                break;
            case "valor_menor":
                $r = (float) $a["total"] <=> (float) $b["total"];
                break;
            default:   // cobro_cerca: la fecha de cobro más próxima primero
                $r = strcmp($a["fecha_cobro"], $b["fecha_cobro"]);
        }
        return $r !== 0 ? $r : ((int) $b["id"] <=> (int) $a["id"]);
    });
    return $salida;
}

/** Cantidad de pedidos contraentrega que faltan por cobrar (insignia del menú). */
function contraentregaContarPorCobrar(mysqli $c): int
{
    try {
        return count(contraentregaObtenerPedidos($c, ["estado_cobro" => "por_cobrar"]));
    } catch (Throwable $e) {
        return 0;
    }
}
