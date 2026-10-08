<?php
session_start();

if (empty($_SESSION["vendedor_id"])) {
    header("Location: login_vendedor.php");
    exit;
}

require_once "conexion.php";
require_once "vendedores_db.php";
require_once "pedidos_lib.php";
date_default_timezone_set("America/Bogota");
asegurarTablasVendedores($conexion);

function e($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, "UTF-8");
}

$vendedorId = (int) $_SESSION["vendedor_id"];

$stmt = $conexion->prepare("SELECT id, nombre, identificacion FROM vendedores WHERE id = ? AND activo = 1 LIMIT 1");
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

if (empty($_SESSION["csrf_pedido"])) {
    $_SESSION["csrf_pedido"] = bin2hex(random_bytes(16));
}

$mensaje = $_SESSION["msg_vendedor"] ?? "";
$tipoMensaje = $_SESSION["msg_vendedor_tipo"] ?? "exito";
unset($_SESSION["msg_vendedor"], $_SESSION["msg_vendedor_tipo"]);

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
                ? "Continúas el borrador #" . numeroPedido($p["id"]) . "."
                : "Editando el pedido #" . numeroPedido($p["id"]) . ". Podrás cambiarlo hasta que la oficina lo suba a Syscafe.";
        } else {
            $_SESSION["msg_vendedor"] = "El pedido #" . numeroPedido($p["id"]) . " ya fue subido a Syscafe o anulado y no se puede editar.";
            $_SESSION["msg_vendedor_tipo"] = "error";
            header("Location: vendedor.php#mis-pedidos");
            exit;
        }
    }
}

$misPedidos = obtenerPedidos($conexion, ["vendedor" => $vendedorId, "con_borradores" => true], 150);
$borradores = array_values(array_filter($misPedidos, fn($p) => $p["estado"] === "borrador"));
$enviados = array_values(array_filter($misPedidos, fn($p) => $p["estado"] !== "borrador"));

$pf = [
    "rol" => "vendedor",
    "csrf" => $_SESSION["csrf_pedido"],
    "pedido" => $pedidoEditar,
    "productos" => productosParaFormulario($conexion, $pedidoEditar),
    "volver" => "vendedor.php#mis-pedidos",
    "autosave" => !$pedidoEditar || $pedidoEditar["estado"] === "borrador",
    "url_base" => "vendedor.php",
    "texto_enviar" => ($pedidoEditar && $pedidoEditar["estado"] === "pendiente") ? "Guardar cambios" : "Enviar pedido",
];

function tarjetaPedido(array $ped, string $csrf): void
{
    $esBorrador = $ped["estado"] === "borrador";
    $nombre = $ped["cliente_nombre"] !== "" ? $ped["cliente_nombre"] : "(sin cliente todavía)";
    ?>
    <details class="pedido-item">
        <summary>
            <span class="p-num"><?= $esBorrador ? "Borrador" : "Pedido" ?> #<?= numeroPedido($ped["id"]) ?></span>
            <span class="estado <?= claseEstadoPedido($ped["estado"]) ?>"><?= e(etiquetaEstadoPedido($ped["estado"])) ?></span>
            <span class="p-cli"><?= e($nombre) ?></span>
            <span class="p-fecha"><?= date("d/m/Y H:i", strtotime($ped["actualizado_en"] ?? $ped["creado_en"])) ?></span>
            <span class="p-total"><?= formatoCOP($ped["total"]) ?></span>
        </summary>
        <div class="cuerpo">
            <div class="datos">
                <?php if ($ped["cliente_nit"] !== ""): ?><?= e(etiquetaDocumento($ped["cliente_tipo_persona"] ?? "juridica")) ?>: <?= e($ped["cliente_nit"]) ?><br><?php endif; ?>
                <?php if (!empty($ped["cliente_nombre_comercial"])): ?>Nombre comercial: <?= e($ped["cliente_nombre_comercial"]) ?><br><?php endif; ?>
                <?php if ($ped["cliente_telefono"] !== ""): ?>Tel: <?= e($ped["cliente_telefono"]) ?><br><?php endif; ?>
                <?php if ($ped["cliente_ciudad"] !== ""): ?><?= e($ped["cliente_ciudad"]) ?><?= !empty($ped["cliente_departamento"]) ? ", " . e($ped["cliente_departamento"]) : "" ?><br><?php endif; ?>
                <?php if (!empty($ped["cliente_direcciones"])): ?>Dirección: <?= e($ped["cliente_direcciones"]) ?><?= !empty($ped["cliente_barrio"]) ? " · " . e($ped["cliente_barrio"]) : "" ?><br><?php endif; ?>
                <?php if (!empty($ped["cliente_puntos_referencia"])): ?>Referencia: <?= e($ped["cliente_puntos_referencia"]) ?><br><?php endif; ?>
                Pago: <?= e(etiquetaCondicion($ped)) ?> · Factura electrónica: <?= !empty($ped["factura_electronica"]) ? "Sí" : "No" ?>
                <?php if (!empty($ped["observaciones"])): ?><br>Obs.: <?= nl2br(e($ped["observaciones"])) ?><?php endif; ?>
            </div>
            <?php if ($ped["items"]): ?>
                <table class="p-items">
                    <?php foreach ($ped["items"] as $it): ?>
                        <tr>
                            <td><b><?= e($it["referencia"]) ?></b><br><?= e($it["nombre"]) ?></td>
                            <td class="n"><?= (int) $it["cantidad"] ?> × <?= formatoCOP($it["precio_unitario"]) ?><br><b><?= formatoCOP($it["subtotal"]) ?></b></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>

            <div class="acciones-ped">
                <?php if ($esBorrador): ?>
                    <a class="btn btn-azul" href="vendedor.php?pedido=<?= (int) $ped["id"] ?>">✏️ Continuar</a>
                    <form method="POST" action="vendedor.php" onsubmit="return pfConfirmarForm(this, 'Se eliminará este borrador y no se podrá recuperar.', '¿Eliminar borrador?')">
                        <input type="hidden" name="accion" value="eliminar_borrador">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="pedido_id" value="<?= (int) $ped["id"] ?>">
                        <button type="submit" class="btn btn-rojo">🗑️ Eliminar</button>
                    </form>
                <?php elseif ($ped["estado"] === "pendiente"): ?>
                    <a class="btn btn-azul" href="vendedor.php?pedido=<?= (int) $ped["id"] ?>">✏️ Editar pedido</a>
                <?php else: ?>
                    <p class="bloqueado">🔒 <?= $ped["estado"] === "procesado" ? "Ya fue subido a Syscafe: no se puede editar." : "Pedido anulado." ?></p>
                <?php endif; ?>
            </div>
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
            <button type="button" class="tab-btn activo" data-panel="nuevo"><?= $pedidoEditar ? "✏️ Pedido" : "🛒 Nuevo pedido" ?></button>
            <button type="button" class="tab-btn" data-panel="mis-pedidos">📋 Mis pedidos<?= $borradores ? " (" . count($borradores) . " borrador" . (count($borradores) > 1 ? "es" : "") . ")" : "" ?></button>
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

            <?php if ($enviados): ?>
                <div class="ped-grupo">Pedidos enviados</div>
                <?php foreach ($enviados as $ped) { tarjetaPedido($ped, $_SESSION["csrf_pedido"]); } ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
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

</body>

</html>
