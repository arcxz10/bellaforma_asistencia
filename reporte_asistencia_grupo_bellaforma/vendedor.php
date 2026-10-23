<?php
session_start();

if (empty($_SESSION["vendedor_id"])) {
    // Las llamadas del formulario de pedidos esperan JSON, no una redirección a la pantalla de ingreso
    if (isset($_GET["api"])) {
        http_response_code(401);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(["ok" => false, "sesion_expirada" => true, "error" => "Su sesión expiró (puede pasar cuando se actualiza el sistema). Abra el ingreso de ejecutivos en otra pestaña, inicie sesión y vuelva a abrir el pedido. Lo que ya se guardó automáticamente se conserva."], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header("Location: login_vendedor.php");
    exit;
}

require_once "conexion.php";
require_once "vendedores_db.php";
require_once "pedidos_lib.php";
require_once "cobros_lib.php";
date_default_timezone_set("America/Bogota");
asegurarTablasVendedores($conexion);

function e($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, "UTF-8");
}

$vendedorId = (int) $_SESSION["vendedor_id"];

$stmt = $conexion->prepare("SELECT id, nombre, identificacion, puede_descargar FROM vendedores WHERE id = ? AND activo = 1 LIMIT 1");
$stmt->bind_param("i", $vendedorId);
$stmt->execute();
$res = $stmt->get_result();
$vendedor = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$vendedor) {
    unset($_SESSION["vendedor_id"], $_SESSION["vendedor_nombre"]);
    header("Location: login_vendedor.php");
    exit;
}

// ¿El administrador le autorizó descargar el PDF y el Excel de sus pedidos?
$puedeDescargar = (int) ($vendedor["puede_descargar"] ?? 0) === 1;

if (empty($_SESSION["csrf_pedido"])) {
    $_SESSION["csrf_pedido"] = bin2hex(random_bytes(16));
}

$mensaje = $_SESSION["msg_vendedor"] ?? "";
$tipoMensaje = $_SESSION["msg_vendedor_tipo"] ?? "exito";
unset($_SESSION["msg_vendedor"], $_SESSION["msg_vendedor_tipo"]);

/* ===== API del formulario (vendedor.php?api=1) ===== */
if (isset($_GET["api"])) {
    manejarApiPedido($conexion);
}

/* ===== Eliminar borrador ===== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["accion"] ?? "") === "eliminar_borrador") {
    if (hash_equals($_SESSION["csrf_pedido"], $_POST["csrf"] ?? "")) {
        $pid = (int) ($_POST["pedido_id"] ?? 0);
        $del = $conexion->prepare("DELETE FROM pedidos_vendedores WHERE id = ? AND vendedor_id = ? AND estado = 'borrador'");
        $del->bind_param("ii", $pid, $vendedorId);
        $del->execute();
        $del->close();
        $_SESSION["msg_vendedor"] = "Borrador eliminado.";
        $_SESSION["msg_vendedor_tipo"] = "exito";
    }
    header("Location: vendedor.php#mis-pedidos");
    exit;
}

/* ===== Pedido que se está editando / continuando ===== */
$pedidoEditar = null;
$avisoEdicion = "";
$idEditar = (int) ($_GET["pedido"] ?? 0);
if ($idEditar > 0) {
    $lista = obtenerPedidos($conexion, ["con_borradores" => true], null, $idEditar);
    $p = $lista[0] ?? null;
    if ($p && (int) $p["vendedor_id"] === $vendedorId) {
        if (in_array($p["estado"], ["borrador", "pendiente"], true)) {
            $pedidoEditar = $p;
            $avisoEdicion = $p["estado"] === "borrador"
                ? "Continúas el borrador #" . numeroDocumento($p) . "."
                : "Editando: " . tituloDocumento($p) . ". Podrás cambiarlo hasta que la oficina lo suba a Syscafe.";
            $avisoEdicion .= " (Productos guardados en este pedido: " . count($p["items"]) . ")";
        } else {
            $_SESSION["msg_vendedor"] = tituloDocumento($p) . " ya fue subido a Syscafe o anulado y no se puede editar.";
            $_SESSION["msg_vendedor_tipo"] = "error";
            header("Location: vendedor.php#mis-pedidos");
            exit;
        }
    }
}

$misPedidos = obtenerPedidos($conexion, ["vendedor" => $vendedorId, "con_borradores" => true], 150);
$borradores = array_values(array_filter($misPedidos, fn($p) => $p["estado"] === "borrador"));
$enviados = array_values(array_filter($misPedidos, fn($p) => $p["estado"] !== "borrador"));

// Pedidos a crédito que aún no se han pagado: van de primeros, del que vence antes al que vence después
$porPagar = [];
try {
    $porIdPedido = [];
    foreach ($misPedidos as $mp) {
        $porIdPedido[(int) $mp["id"]] = $mp;
    }
    $listaCobro = cobroObtenerPedidos($conexion, ["vendedor" => $vendedorId, "estado_cobro" => "por_cobrar", "orden" => "dias_menos"]);
    foreach ($listaCobro as $cp) {
        $idCp = (int) $cp["id"];
        $completo = $porIdPedido[$idCp] ?? (obtenerPedidos($conexion, ["con_borradores" => true], null, $idCp)[0] ?? null);
        if ($completo) {
            $porPagar[] = $completo;
        }
    }
    $idsPorPagar = [];
    foreach ($porPagar as $pp) {
        $idsPorPagar[(int) $pp["id"]] = true;
    }
    $enviados = array_values(array_filter($enviados, fn($p) => !isset($idsPorPagar[(int) $p["id"]])));
} catch (Throwable $e) {
    $porPagar = [];   // si algo falla, la lista se ve como antes
}
// Estado del comprobante de cada pedido (id => [cobrado, tiene, subido_en])
$cobroEstados = cobroEstadoPorPedido($conexion, array_map(fn($p) => (int) $p["id"], array_merge($porPagar, $enviados)));
// id => número visible del documento (para mostrar «Unida al pedido #…» en las remisiones)
$numerosPedidos = [];
foreach ($misPedidos as $mp) {
    $numerosPedidos[(int) $mp["id"]] = numeroDocumento($mp);
}

$pf = [
    "rol" => "vendedor",
    "editar_numero" => $puedeDescargar,   // ejecutivo autorizado: puede escribir el número del documento
    "csrf" => $_SESSION["csrf_pedido"],
    "pedido" => $pedidoEditar,
    // Pedidos del ejecutivo a los que puede unir una remisión
    "pedidos_union" => pedidosParaUnirRemision($conexion, $vendedorId, $pedidoEditar ? (int) ($pedidoEditar["pedido_vinculado_id"] ?? 0) : null),
    "productos" => productosParaFormulario($conexion, $pedidoEditar),
    "volver" => "vendedor.php#mis-pedidos",
    "autosave" => !$pedidoEditar || $pedidoEditar["estado"] === "borrador",
    "url_base" => "vendedor.php",
    "api" => "vendedor.php?api=1",
    "texto_enviar" => ($pedidoEditar && $pedidoEditar["estado"] === "pendiente") ? "Guardar cambios" : "Enviar pedido",
];

function tarjetaPedido(array $ped, string $csrf, bool $puedeDescargar = false): void
{
    global $numerosPedidos, $cobroEstados;
    $esBorrador = $ped["estado"] === "borrador";
    // Crédito: días que le quedan para pagar y estado del comprobante
    $esCredito = !$esBorrador && cobroEsACredito($ped);
    $estCobro = $esCredito ? ($cobroEstados[(int) $ped["id"]] ?? ["cobrado" => false, "tiene" => false, "subido_en" => null]) : null;
    $plazo = $esCredito ? cobroPlazo($ped) : null;
    $puedeSubirComp = $esCredito && !$estCobro["cobrado"];
    $nombre = $ped["cliente_nombre"] !== "" ? $ped["cliente_nombre"] : "(sin cliente todavía)";
    ?>
    <details class="pedido-item">
        <summary>
            <span class="p-num"><?= $esBorrador ? "Borrador" : e(etiquetaTipoDocumento($ped["tipo_pedido"] ?? "pedido")) ?> #<?= e(numeroDocumento($ped)) ?></span>
            <span class="estado <?= claseEstadoPedido($ped["estado"]) ?>"><?= e(etiquetaEstadoPedido($ped["estado"])) ?></span>
            <span class="p-cli"><?= e($nombre) ?> <span class="pf-tag-lista <?= e(listaValida($ped["tipo_cliente"] ?? "")) ?>"><?= e(etiquetaTipoCliente($ped["tipo_cliente"] ?? "mayorista")) ?></span></span>
            <span class="p-fecha"><?= date("d/m/Y H:i", strtotime($ped["actualizado_en"] ?? $ped["creado_en"])) ?></span>
            <span class="p-total"><?= formatoCOP($ped["total"]) ?></span>
            <?php
            // Descargas debajo del pedido: el PDF de las cotizaciones lo ven todos; el PDF de cualquier documento y el Excel, solo los ejecutivos autorizados
            $esCotizacion = ($ped["tipo_pedido"] ?? "pedido") === "cotizacion";
            $verPdf = !$esBorrador && ($esCotizacion || $puedeDescargar);
            $verExcel = !$esBorrador && $puedeDescargar;
            ?>
            <?php if ($esCredito): ?>
                <span class="cr-linea">
                    <?php if ($estCobro["cobrado"]): ?>
                        <span class="cr-badge cr-pagado">Pagado</span>
                        <span class="cr-detalle">Crédito a <?= (int) $plazo["dias"] ?> días</span>
                    <?php else: ?>
                        <span class="cr-badge cr-<?= e(cobroClaseRestantes($plazo["restantes"])) ?>"><?= e(cobroTextoRestantes($plazo["restantes"])) ?></span>
                        <span class="cr-detalle">Crédito a <?= (int) $plazo["dias"] ?> días · vence <?= date("d/m/Y", strtotime($plazo["vence"])) ?></span>
                        <?php if ($estCobro["tiene"]): ?><span class="cr-badge cr-enviado">Comprobante enviado</span><?php endif; ?>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
            <?php $puedeEditar = $ped["estado"] === "pendiente"; ?>
            <?php if ($verPdf || $verExcel || $puedeEditar || $puedeSubirComp): ?>
                <span style="grid-column:1 / -1; display:flex; gap:8px; margin-top:6px; flex-wrap:wrap;">
                    <?php if ($puedeEditar): ?>
                        <a class="btn btn-azul" style="min-height:40px; padding:6px 14px; font-size:14px;" href="vendedor.php?pedido=<?= (int) $ped["id"] ?>"><svg class="vi" aria-hidden="true"><use href="#vi-edit"/></svg>Editar <?= e(nombreCortoDocumento($ped["tipo_pedido"] ?? "pedido")) ?></a>
                    <?php endif; ?>
                    <?php if ($puedeSubirComp): ?>
                        <button type="button" class="btn btn-verde-pago" style="min-height:40px; padding:6px 14px; font-size:14px;"
                            onclick="event.preventDefault(); event.stopPropagation(); bfSubirComprobante(<?= (int) $ped["id"] ?>);"><svg class="vi" aria-hidden="true"><use href="#vi-download"/></svg><?= $estCobro["tiene"] ? "Cambiar comprobante" : "Subir comprobante de pago" ?></button>
                    <?php endif; ?>
                    <?php if ($verPdf): ?>
                        <a class="btn btn-azul" style="min-height:40px; padding:6px 14px; font-size:14px;" href="pedido_pdf.php?id=<?= (int) $ped["id"] ?>"><svg class="vi" aria-hidden="true"><use href="#vi-file"/></svg>Descargar PDF</a>
                    <?php endif; ?>
                    <?php if ($verExcel): ?>
                        <a class="btn btn-azul" style="min-height:40px; padding:6px 14px; font-size:14px; background:#00695C;" href="pedido_syscafe.php?id=<?= (int) $ped["id"] ?>"><svg class="vi" aria-hidden="true"><use href="#vi-download"/></svg>Excel para Syscafe</a>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
            <?php if ($esBorrador): ?>
                <span style="grid-column:1 / -1; display:flex; gap:8px; margin-top:6px;">
                    <button type="button" class="btn btn-rojo" style="min-height:40px; padding:6px 14px; font-size:14px;"
                        onclick="event.preventDefault(); event.stopPropagation(); bfEliminarBorrador(document.getElementById('del-borrador-<?= (int) $ped["id"] ?>'));"><svg class="vi" aria-hidden="true"><use href="#vi-trash"/></svg>Eliminar borrador</button>
                </span>
            <?php endif; ?>
        </summary>
        <div class="cuerpo">
            <div class="datos">
                <?php if ($ped["cliente_nit"] !== ""): ?><?= e(etiquetaDocumento($ped["cliente_tipo_persona"] ?? "juridica")) ?>: <?= e($ped["cliente_nit"]) ?><br><?php endif; ?>
                <?php if (!empty($ped["cliente_nombre_comercial"])): ?>Nombre comercial: <?= e($ped["cliente_nombre_comercial"]) ?><br><?php endif; ?>
                <?php if ($ped["cliente_telefono"] !== ""): ?>Tel: <?= e($ped["cliente_telefono"]) ?><br><?php endif; ?>
                <?php if ($ped["cliente_ciudad"] !== ""): ?><?= e($ped["cliente_ciudad"]) ?><?= !empty($ped["cliente_departamento"]) ? ", " . e($ped["cliente_departamento"]) : "" ?><br><?php endif; ?>
                <?php if (!empty($ped["cliente_direcciones"])): ?>Dirección: <?= e($ped["cliente_direcciones"]) ?><?= !empty($ped["cliente_barrio"]) ? " · " . e($ped["cliente_barrio"]) : "" ?><br><?php endif; ?>
                <?php if (!empty($ped["cliente_puntos_referencia"])): ?>Referencia: <?= e($ped["cliente_puntos_referencia"]) ?><br><?php endif; ?>
                <?php if ($esCredito && $estCobro["tiene"]): ?><a href="cobro_comprobante.php?id=<?= (int) $ped["id"] ?>" target="_blank" rel="noopener">Ver el comprobante que envió<?= !empty($estCobro["subido_en"]) ? " (" . e(date("d/m/Y H:i", strtotime($estCobro["subido_en"]))) . ")" : "" ?></a><br><?php endif; ?>
                Pago: <?= e(etiquetaCondicion($ped)) ?><?= !empty($ped["transportadora"]) ? " · Transportadora: " . e(etiquetaTransportadora($ped["transportadora"])) : "" ?> · Factura electrónica: <?= !empty($ped["factura_electronica"]) ? "Sí" : "No" ?>
                <?php if (($ped["tipo_pedido"] ?? "") === "remision" && !empty($ped["pedido_vinculado_id"]) && isset($numerosPedidos[(int) $ped["pedido_vinculado_id"]])): ?><br><svg class="vi" aria-hidden="true"><use href="#vi-link"/></svg>Unida al pedido #<?= e($numerosPedidos[(int) $ped["pedido_vinculado_id"]]) ?><?php endif; ?>
                <?php if (!empty($ped["observaciones"])): ?><br>Obs.: <?= nl2br(e($ped["observaciones"])) ?><?php endif; ?>
            </div>
            <?php if ($ped["items"]): ?>
                <table class="p-items">
                    <?php foreach ($ped["items"] as $it): $esBon = !empty($it["bonificado"]); ?>
                        <tr<?= $esBon ? ' class="bonif"' : "" ?>>
                            <td><b><?= e($it["referencia"]) ?></b><?= $esBon ? ' <span class="pf-tag-bonif"><svg class="vi" aria-hidden="true"><use href="#vi-gift"/></svg>' . ((int) $it["bonificado"] === 3 ? "APOYO A FLETE · " : ((int) $it["bonificado"] === 2 ? "DOCENA DE 13 · " : "")) . 'BONIFICADO</span>' : "" ?><br><?= e($it["nombre"]) ?></td>
                            <td class="n"><?= (int) $it["cantidad"] ?> × <?= formatoCOP($it["precio_unitario"]) ?><br><b><?= formatoCOP($esBon ? 0 : $it["subtotal"]) ?></b></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>

            <?php if ($ped["estado"] !== "pendiente"): ?>   <?php /* el botón «Editar» de los pedidos enviados ya está arriba, junto a las descargas */ ?>
            <div class="acciones-ped">
                <?php if ($esBorrador): ?>
                    <a class="btn btn-azul" href="vendedor.php?pedido=<?= (int) $ped["id"] ?>"><svg class="vi" aria-hidden="true"><use href="#vi-edit"/></svg>Continuar</a>
                    <form method="POST" action="vendedor.php" id="del-borrador-<?= (int) $ped["id"] ?>" onsubmit="return bfEliminarBorrador(this)">
                        <input type="hidden" name="accion" value="eliminar_borrador">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="pedido_id" value="<?= (int) $ped["id"] ?>">
                        <button type="submit" class="btn btn-rojo"><svg class="vi" aria-hidden="true"><use href="#vi-trash"/></svg>Eliminar</button>
                    </form>
                <?php else: ?>
                    <p class="bloqueado"><svg class="vi" aria-hidden="true"><use href="#vi-lock"/></svg><?= $ped["estado"] === "procesado" ? "Ya fue subido a Syscafe: no se puede editar." : "Pedido anulado." ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </details>
    <?php
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Pedidos | Grupo Bellaforma</title>
    <link rel="icon" type="image/x-icon" href="img/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="img/favicon-32x32.png">
    <link rel="stylesheet" href="css/vendedor.css">
    <style>
        .cr-linea { grid-column: 1 / -1; display: flex; align-items: center; flex-wrap: wrap; gap: 6px 8px; margin-top: 4px; }
        .cr-badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; white-space: nowrap; }
        .cr-ok { background: #E0F4F8; color: #0E6A7E; }
        .cr-pronto { background: #FFF4D6; color: #8A5F00; }
        .cr-hoy { background: #FFE3CC; color: #B24A00; }
        .cr-vencido { background: #FDEBED; color: #A0192B; }
        .cr-pagado, .cr-enviado { background: #E4F5E7; color: #1D6821; }
        .cr-detalle { font-size: 13px; color: var(--tinta-suave); }
        .btn-verde-pago { background: linear-gradient(180deg, #33B540, #27983A); color: #fff; box-shadow: 0 10px 20px -12px rgba(39, 152, 58, .85); }
        .bf-toast { position: fixed; left: 50%; bottom: 24px; transform: translateX(-50%); z-index: 9999; max-width: 90vw; padding: 13px 18px; border-radius: 12px; background: #0E1B33; color: #fff; font-size: 15px; font-weight: 600; box-shadow: 0 18px 40px -16px rgba(0, 0, 0, .5); }
        .bf-toast.error { background: #C62839; }
        .bf-toast.exito { background: #1D6821; }
    </style>
</head>

<body>

    <div class="topbar">
        <img src="img/logo-blanco.png" alt="Grupo Bella Forma S.A.S.">
        <div class="quien">
            <span><?= e($vendedor["nombre"]) ?><br>ID <?= e($vendedor["identificacion"]) ?></span>
            <a class="salir" href="logout_vendedor.php">Salir</a>
        </div>
    </div>

    <div class="wrap">

        <?php if ($mensaje !== ""): ?>
            <div class="mensaje <?= e($tipoMensaje) ?>"><?= e($mensaje) ?></div>
        <?php endif; ?>

        <div class="tabs">
            <button type="button" class="tab-btn activo" data-panel="nuevo"><?= $pedidoEditar ? '<svg class="vi" aria-hidden="true"><use href="#vi-edit"/></svg> Pedido' : '<svg class="vi" aria-hidden="true"><use href="#vi-cart"/></svg> Nuevo pedido' ?></button>
            <button type="button" class="tab-btn" data-panel="mis-pedidos"><svg class="vi" aria-hidden="true"><use href="#vi-clipboard"/></svg>Mis pedidos<?= $borradores ? " (" . count($borradores) . " borrador" . (count($borradores) > 1 ? "es" : "") . ")" : "" ?></button>
        </div>

        <div id="panel-nuevo" class="panel activo">
            <?php if ($avisoEdicion !== ""): ?>
                <div class="aviso-edicion"><?= e($avisoEdicion) ?></div>
            <?php endif; ?>
            <?php include __DIR__ . "/pedido_form.php"; ?>
        </div>

        <div id="panel-mis-pedidos" class="panel">
            <?php if (!$misPedidos): ?>
                <div class="card"><p class="sin-resultados">Todavía no ha creado pedidos.</p></div>
            <?php endif; ?>

            <?php if ($borradores): ?>
                <div class="ped-grupo">Borradores (sin terminar)</div>
                <?php foreach ($borradores as $ped) { tarjetaPedido($ped, $_SESSION["csrf_pedido"]); } ?>
            <?php endif; ?>

            <?php if ($porPagar): ?>
                <div class="ped-grupo">Créditos por pagar · del que vence primero al más lejano</div>
                <?php foreach ($porPagar as $ped) { tarjetaPedido($ped, $_SESSION["csrf_pedido"], $puedeDescargar); } ?>
            <?php endif; ?>

            <?php if ($enviados): ?>
                <div class="ped-grupo">Pedidos enviados</div>
                <?php foreach ($enviados as $ped) { tarjetaPedido($ped, $_SESSION["csrf_pedido"], $puedeDescargar); } ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="pf-dialogo" id="bfDialogo" hidden>
        <div class="pf-dialogo-caja" role="dialog" aria-modal="true" aria-labelledby="bfDialogoTitulo">
            <div class="pf-dialogo-icono"><svg class="vi" aria-hidden="true"><use href="#vi-trash"/></svg></div>
            <h3 id="bfDialogoTitulo">¿Eliminar borrador?</h3>
            <p>Se eliminará este borrador y no se podrá recuperar. Los pedidos ya enviados no se pueden eliminar.</p>
            <div class="pf-dialogo-botones">
                <button type="button" class="pf-dialogo-no" id="bfDialogoNo">Cancelar</button>
                <button type="button" class="pf-dialogo-si peligro" id="bfDialogoSi">Sí, eliminar</button>
            </div>
        </div>
    </div>

    <input type="file" id="bfArchivoPago" accept="image/*,application/pdf" hidden>

    <script>
        /* ---------- Comprobante de pago de pedidos a crédito ---------- */
        var BF_CSRF = <?= json_encode($_SESSION["csrf_pedido"]) ?>;
        var bfPedidoPago = 0;

        function bfToast(texto, tipo) {
            var t = document.createElement("div");
            t.className = "bf-toast " + (tipo || "");
            t.textContent = texto;
            document.body.appendChild(t);
            setTimeout(function () { t.remove(); }, tipo === "error" ? 6000 : 3500);
        }
        function bfSubirComprobante(id) {
            bfPedidoPago = id;
            var inp = document.getElementById("bfArchivoPago");
            inp.value = "";
            inp.click();
        }
        // Las fotos del celular pesan mucho: se reducen antes de enviarlas (los PDF van tal cual)
        function bfReducirImagen(archivo) {
            return new Promise(function (resolve) {
                var url = URL.createObjectURL(archivo);
                var img = new Image();
                img.onload = function () {
                    var max = 1600, w = img.naturalWidth, h = img.naturalHeight;
                    var k = Math.min(1, max / Math.max(w, h));
                    var c = document.createElement("canvas");
                    c.width = Math.round(w * k); c.height = Math.round(h * k);
                    c.getContext("2d").drawImage(img, 0, 0, c.width, c.height);
                    URL.revokeObjectURL(url);
                    c.toBlob(function (b) { resolve(b || archivo); }, "image/jpeg", 0.85);
                };
                img.onerror = function () { URL.revokeObjectURL(url); resolve(archivo); };
                img.src = url;
            });
        }
        document.getElementById("bfArchivoPago").addEventListener("change", function () {
            var f = this.files && this.files[0];
            if (!f || !bfPedidoPago) { return; }
            var esPdf = f.type === "application/pdf" || /\.pdf$/i.test(f.name);
            if (!esPdf && f.type.indexOf("image/") !== 0) { bfToast("Elija una foto o un PDF del comprobante.", "error"); return; }
            bfToast("Subiendo comprobante…");
            var id = bfPedidoPago;
            (esPdf ? Promise.resolve(f) : bfReducirImagen(f)).then(function (blob) {
                if (blob.size > 6 * 1024 * 1024) { throw new Error("El archivo es muy pesado (máximo 6 MB)."); }
                var fd = new FormData();
                fd.append("op", "subir");
                fd.append("pedido_id", id);
                fd.append("csrf", BF_CSRF);
                fd.append("archivo", blob, esPdf ? "comprobante.pdf" : "comprobante.jpg");
                return fetch("cobro_comprobante.php", { method: "POST", body: fd, credentials: "same-origin" });
            }).then(function (r) { return r.json(); }).then(function (j) {
                if (!j.ok) { throw new Error(j.error || "No se pudo subir el comprobante."); }
                bfToast(j.mensaje || "Comprobante enviado.", "exito");
                setTimeout(function () { location.href = "vendedor.php#mis-pedidos"; location.reload(); }, 900);
            }).catch(function (e) {
                bfToast((e && e.message) || "No se pudo subir el comprobante. Intente de nuevo.", "error");
            });
        });

        var bfFormBorrador = null;
        function bfCerrarDialogo() { document.getElementById("bfDialogo").hidden = true; bfFormBorrador = null; }
        function bfEliminarBorrador(form) {
            bfFormBorrador = form;
            document.getElementById("bfDialogo").hidden = false;
            return false;
        }
        document.getElementById("bfDialogoNo").addEventListener("click", bfCerrarDialogo);
        document.getElementById("bfDialogoSi").addEventListener("click", function () {
            var f = bfFormBorrador;
            bfCerrarDialogo();
            if (f) { f.submit(); }
        });
        document.getElementById("bfDialogo").addEventListener("click", function (e) { if (e.target === this) { bfCerrarDialogo(); } });

        function abrirPanel(nombre) {
            var panel = document.getElementById("panel-" + nombre);
            if (!panel) { nombre = "nuevo"; panel = document.getElementById("panel-nuevo"); }
            document.querySelectorAll(".panel").forEach(function (p) { p.classList.remove("activo"); });
            document.querySelectorAll(".tab-btn").forEach(function (b) { b.classList.remove("activo"); });
            panel.classList.add("activo");
            var boton = document.querySelector('.tab-btn[data-panel="' + nombre + '"]');
            if (boton) { boton.classList.add("activo"); }
            var barra = document.getElementById("pfBarra");
            if (barra) { barra.style.display = nombre === "nuevo" ? "" : "none"; }
        }
        document.querySelectorAll(".tab-btn").forEach(function (b) {
            b.addEventListener("click", function () {
                history.replaceState(null, "", location.pathname + location.search + "#" + b.dataset.panel);
                abrirPanel(b.dataset.panel);
                window.scrollTo(0, 0);
            });
        });
        abrirPanel(location.hash.replace("#", "") || "nuevo");
    </script>

    <script src="js/bellaforma-ui.js"></script>
</body>

</html>
