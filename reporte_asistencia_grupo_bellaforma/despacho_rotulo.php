<?php
/**
 * Rótulos del Control de despachos (solo administrador).
 *   GET  despacho_rotulo.php?id=12                      -> la imagen del rótulo (con &descargar=1 se baja como archivo)
 *   GET  despacho_rotulo.php?pdf=1&fecha=2026-10-23&por=4 -> PDF con TODOS los rótulos de ese día (por = rótulos por hoja: 1, 2 o 4)
 *   POST despacho_rotulo.php  op=subir  id=12 imagen=<archivo>   -> anexa / reemplaza el rótulo (responde JSON)
 *   POST despacho_rotulo.php  op=quitar id=12                    -> quita el rótulo (responde JSON)
 */
session_start();

if (empty($_SESSION["admin_id"])) {
    http_response_code(403);
    exit("Sin permiso.");
}
session_write_close();

ini_set("display_errors", 0);
ob_start();

require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/vendedores_db.php";
date_default_timezone_set("America/Bogota");
asegurarTablasVendedores($conexion);
asegurarExtrasDespachos($conexion);

function rotuloJson(array $r, int $codigo = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($codigo);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

function rotuloTexto(string $t, int $codigo): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($codigo);
    header("Content-Type: text/plain; charset=utf-8");
    exit($t);
}

/* ---------------- POST: subir / quitar ---------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $op = (string) ($_POST["op"] ?? "");
    $id = (int) ($_POST["id"] ?? 0);

    try {
        $st = $conexion->prepare("SELECT id FROM despachos WHERE id = ?");
        $st->bind_param("i", $id);
        $st->execute();
        $existe = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$existe) {
            rotuloJson(["ok" => false, "error" => "Ese despacho ya no existe."]);
        }

        if ($op === "quitar") {
            borrarRotuloDeDespacho($conexion, $id);
            rotuloJson(["ok" => true]);
        }

        if ($op !== "subir") {
            rotuloJson(["ok" => false, "error" => "Operación no válida."]);
        }

        $f = $_FILES["imagen"] ?? null;
        if (!$f || ($f["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $msg = (($f["error"] ?? 0) === UPLOAD_ERR_INI_SIZE || ($f["error"] ?? 0) === UPLOAD_ERR_FORM_SIZE)
                ? "La imagen es muy pesada."
                : "No llegó la imagen. Intente de nuevo.";
            rotuloJson(["ok" => false, "error" => $msg]);
        }
        if ((int) $f["size"] > 6 * 1024 * 1024) {
            rotuloJson(["ok" => false, "error" => "La imagen es muy pesada (máximo 6 MB)."]);
        }

        $bin = file_get_contents($f["tmp_name"]);
        $info = $bin !== false ? @getimagesizefromstring($bin) : false;
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            rotuloJson(["ok" => false, "error" => "Solo se aceptan imágenes JPG o PNG."]);
        }
        $mime = $info[2] === IMAGETYPE_PNG ? "image/png" : "image/jpeg";

        // Si llegó un PNG y el servidor puede, se pasa a JPG (más liviano y compatible con el PDF)
        if ($mime === "image/png" && function_exists("imagecreatefromstring") && function_exists("imagejpeg")) {
            $img = @imagecreatefromstring($bin);
            if ($img) {
                $lienzo = imagecreatetruecolor(imagesx($img), imagesy($img));
                imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255));
                imagecopy($lienzo, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
                ob_start();
                imagejpeg($lienzo, null, 88);
                $jpg = ob_get_clean();
                if ($jpg !== false && $jpg !== "") {
                    $bin = $jpg;
                    $mime = "image/jpeg";
                }
            }
        }

        $st = $conexion->prepare("REPLACE INTO despacho_rotulos (despacho_id, mime, datos) VALUES (?, ?, ?)");
        $nulo = null;
        $st->bind_param("isb", $id, $mime, $nulo);
        $st->send_long_data(2, $bin);
        $st->execute();
        $st->close();
        rotuloJson(["ok" => true]);
    } catch (Throwable $e) {
        rotuloJson(["ok" => false, "error" => "No se pudo guardar el rótulo. Intente de nuevo."]);
    }
}

/* ---------------- GET: PDF con todos los rótulos del día ---------------- */
if (isset($_GET["pdf"])) {
    $fecha = (string) ($_GET["fecha"] ?? "");
    if (!fechaDespachoValida($fecha)) {
        rotuloTexto("Fecha no válida.", 400);
    }
    $por = (int) ($_GET["por"] ?? 4);
    if (!in_array($por, [1, 2, 4], true)) {
        $por = 4;
    }

    $autoload = __DIR__ . "/vendor/autoload.php";
    if (!file_exists($autoload)) {
        rotuloTexto("Falta instalar FPDF. Agregue \"setasign/fpdf\" en composer.json y vuelva a desplegar.", 500);
    }
    require_once $autoload;

    $st = $conexion->prepare(
        "SELECT r.despacho_id, r.mime, r.datos
         FROM despacho_rotulos r
         JOIN despachos d ON d.id = r.despacho_id
         LEFT JOIN pedidos_vendedores p ON p.id = d.pedido_id
         WHERE d.fecha = ? AND (p.id IS NULL OR p.estado <> 'anulado')
         ORDER BY d.id ASC"
    );
    $st->bind_param("s", $fecha);
    $st->execute();
    $res = $st->get_result();
    $rotulos = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $st->close();

    if (!$rotulos) {
        rotuloTexto("No hay rótulos anexados el " . $fecha . ".", 404);
    }

    // Cuadrícula de la hoja carta/A4: 4 por hoja (2x2), 2 por hoja (una columna) o 1 por hoja
    $columnas = $por === 4 ? 2 : 1;
    $filas = $por === 1 ? 1 : 2;
    $mx = 8;
    $my = 8;
    $gap = 6;
    $cw = (210 - 2 * $mx - ($columnas - 1) * $gap) / $columnas;
    $ch = (297 - 2 * $my - ($filas - 1) * $gap) / $filas;
    $porHoja = $columnas * $filas;

    $pdf = new FPDF("P", "mm", "A4");
    $pdf->SetAutoPageBreak(false);
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->SetLineWidth(0.2);

    foreach ($rotulos as $i => $r) {
        if ($i % $porHoja === 0) {
            $pdf->AddPage();
        }
        $pos = $i % $porHoja;
        $x0 = $mx + ($pos % $columnas) * ($cw + $gap);
        $y0 = $my + intdiv($pos, $columnas) * ($ch + $gap);

        $dim = @getimagesizefromstring($r["datos"]);
        if (!$dim || $dim[0] <= 0 || $dim[1] <= 0) {
            continue;
        }
        $w = $cw;
        $h = $w * $dim[1] / $dim[0];
        if ($h > $ch) {
            $h = $ch;
            $w = $h * $dim[0] / $dim[1];
        }
        $x = $x0 + ($cw - $w) / 2;

        $tmp = tempnam(sys_get_temp_dir(), "rot");
        file_put_contents($tmp, $r["datos"]);
        try {
            $pdf->Image($tmp, $x, $y0, $w, $h, $r["mime"] === "image/png" ? "PNG" : "JPG");
            $pdf->Rect($x0, $y0, $cw, $ch);   // guía de corte
        } catch (Throwable $e) {
            // una imagen que FPDF no pueda leer no daña el resto
        }
        @unlink($tmp);
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $pdf->Output("D", "rotulos_" . $fecha . ".pdf");
    exit;
}

/* ---------------- GET: una imagen ---------------- */
$id = (int) ($_GET["id"] ?? 0);
if ($id <= 0) {
    rotuloTexto("No encontrado.", 404);
}
$st = $conexion->prepare(
    "SELECT r.mime, r.datos, d.numero_guia FROM despacho_rotulos r JOIN despachos d ON d.id = r.despacho_id WHERE r.despacho_id = ?"
);
$st->bind_param("i", $id);
$st->execute();
$st->bind_result($mime, $datos, $guia);
$hay = $st->fetch();
$st->close();
if (!$hay) {
    rotuloTexto("No encontrado.", 404);
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
header("Content-Type: " . $mime);
header("Content-Length: " . strlen($datos));
header("Cache-Control: private, max-age=86400");
if (!empty($_GET["descargar"])) {
    $nombre = "rotulo_" . (preg_match('/^[A-Za-z0-9_-]+$/', (string) $guia) ? $guia : $id) . ($mime === "image/png" ? ".png" : ".jpg");
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
}
echo $datos;
