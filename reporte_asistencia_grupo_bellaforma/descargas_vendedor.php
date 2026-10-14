<?php
/**
 * Permiso de descarga para ejecutivos (lo usan pedido_pdf.php y pedido_excel.php cuando NO entra el administrador).
 *  - Solo se puede descargar UN pedido por vez (?id=) y debe ser del propio ejecutivo (y no un borrador).
 *  - PDF: las cotizaciones las puede descargar cualquier ejecutivo; los demás documentos, solo los autorizados.
 *  - Excel: solo los ejecutivos que el administrador autorizó (vendedores.puede_descargar = 1).
 */
function autorizarDescargaVendedor(mysqli $c, string $formato): void
{
    $negar = function (string $mensaje, int $codigo = 403): void {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($codigo);
        header("Content-Type: text/plain; charset=utf-8");
        exit($mensaje);
    };

    $vendedorId = (int) ($_SESSION["vendedor_id"] ?? 0);
    $pedidoId = (int) ($_GET["id"] ?? 0);
    if ($vendedorId <= 0 || $pedidoId <= 0) {
        $negar("No autorizado.");
    }

    $st = $c->prepare("SELECT puede_descargar FROM vendedores WHERE id = ? AND activo = 1 LIMIT 1");
    $st->bind_param("i", $vendedorId);
    $st->execute();
    $res = $st->get_result();
    $vendedor = $res ? $res->fetch_assoc() : null;
    $st->close();
    if (!$vendedor) {
        $negar("No autorizado.");
    }

    $lista = obtenerPedidos($c, ["con_borradores" => true], null, $pedidoId);
    $pedido = $lista[0] ?? null;
    if (!$pedido || (int) $pedido["vendedor_id"] !== $vendedorId || $pedido["estado"] === "borrador") {
        $negar("Documento no disponible.", 404);
    }

    $autorizado = (int) ($vendedor["puede_descargar"] ?? 0) === 1;
    $esCotizacion = ($pedido["tipo_pedido"] ?? "pedido") === "cotizacion";
    $permitido = $formato === "pdf" ? ($esCotizacion || $autorizado) : $autorizado;
    if (!$permitido) {
        $negar("No tiene permiso para descargar este archivo. Pídale autorización al administrador.");
    }
}
