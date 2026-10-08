<?php
/** Base de datos de clientes: guardado manual e importación desde CSV (Excel > Guardar como CSV). */
require_once __DIR__ . "/pedidos_lib.php";

const CLIENTE_TEXTOS = [
    "tipo_documento" => 20, "identificacion" => 30, "dv" => 3, "codigo" => 30, "razon_social" => 200,
    "nombre_comercial" => 200, "direccion" => 255, "direccion2" => 255, "puntos_referencia" => 255,
    "telefono1" => 40, "telefono2" => 40, "telefono3" => 40, "movil" => 40, "codigo_postal" => 20,
    "email" => 150, "email_fe" => 150, "departamento" => 80, "municipio" => 100, "codigo_municipio" => 12,
    "pais" => 60, "barrio" => 120, "grupo" => 80, "subgrupo" => 80, "encargado" => 150,
    "representante_legal" => 150, "zona" => 80, "vendedor_asignado" => 100, "cobrador" => 100, "lista_precios" => 40,
];

function esVerdadero($v): bool
{
    $v = strtolower(trim((string) $v));
    return in_array($v, ["1", "si", "sí", "s", "x", "true", "yes", "y", "verdadero"], true);
}

/** Limpia y normaliza los datos de un cliente. */
function normalizarCliente(array $in): array
{
    $d = [];
    foreach (CLIENTE_TEXTOS as $campo => $max) {
        $d[$campo] = textoCorto($in[$campo] ?? "", $max);
    }
    $d["observaciones"] = textoCorto($in["observaciones"] ?? "", 2000, false);

    // Persona natural o jurídica
    $tp = strtolower(trim((string) ($in["tipo_persona"] ?? "")));
    if (isset($in["es_juridica"]) && trim((string) $in["es_juridica"]) !== "") {
        $tp = esVerdadero($in["es_juridica"]) ? "juridica" : "natural";
    }
    if (in_array($tp, ["natural", "n", "persona natural"], true)) {
        $tp = "natural";
    } elseif (in_array($tp, ["juridica", "jurídica", "j", "persona juridica", "persona jurídica"], true)) {
        $tp = "juridica";
    } else {
        $td = strtolower($d["tipo_documento"]);
        $tp = in_array($td, ["cc", "ce", "cedula", "cédula", "pasaporte", "ti"], true) ? "natural" : "juridica";
    }
    $d["tipo_persona"] = $tp;
    if ($d["tipo_documento"] === "") {
        $d["tipo_documento"] = $tp === "natural" ? "CC" : "NIT";
    }

    $d["identificacion_norm"] = soloDigitos($d["identificacion"]);
    $d["dv"] = soloDigitos($d["dv"]);

    $cond = strtolower(trim((string) ($in["condicion_pago"] ?? "")));
    $dias = (int) soloDigitos((string) ($in["dias_credito"] ?? ""));
    $d["condicion_pago"] = (strpos($cond, "cr") === 0 || ($cond === "" && $dias > 0)) ? "credito" : "contado";
    $d["dias_credito"] = $d["condicion_pago"] === "credito" && $dias > 0 ? min($dias, 365) : null;
    $d["cupo_cartera"] = parsearPrecio((string) ($in["cupo_cartera"] ?? "0"));
    $d["inactivo"] = esVerdadero($in["inactivo"] ?? "") ? 1 : 0;
    if ($d["pais"] === "") {
        $d["pais"] = "Colombia";
    }
    return $d;
}

function tiposCliente(array $d): array
{
    $cols = array_merge(["tipo_persona", "identificacion_norm", "dias_credito", "cupo_cartera", "inactivo", "condicion_pago", "observaciones"], array_keys(CLIENTE_TEXTOS));
    $tipos = "";
    $vals = [];
    foreach ($cols as $c) {
        $tipos .= $c === "dias_credito" || $c === "inactivo" ? "i" : ($c === "cupo_cartera" ? "d" : "s");
        $vals[] = $d[$c];
    }
    return [$cols, $tipos, $vals];
}

/** Crea o edita un cliente desde el formulario del admin. */
function guardarCliente(mysqli $c, array $in, ?int $id): array
{
    $d = normalizarCliente($in);
    if (strlen($d["identificacion_norm"]) < 5) {
        return ["ok" => false, "error" => "Ingrese un NIT o cédula válido."];
    }
    if ($d["razon_social"] === "") {
        return ["ok" => false, "error" => $d["tipo_persona"] === "natural" ? "Ingrese el nombre completo." : "Ingrese la razón social."];
    }
    [$cols, $tipos, $vals] = tiposCliente($d);

    try {
        if ($id) {
            $sets = implode(", ", array_map(fn($k) => "`$k` = ?", $cols));
            $st = $c->prepare("UPDATE clientes SET $sets WHERE id = ?");
            $vals[] = $id;
            $st->bind_param($tipos . "i", ...$vals);
        } else {
            $lista = implode(", ", array_map(fn($k) => "`$k`", $cols));
            $marcas = implode(", ", array_fill(0, count($cols), "?"));
            $st = $c->prepare("INSERT INTO clientes ($lista) VALUES ($marcas)");
            $st->bind_param($tipos, ...$vals);
        }
        $st->execute();
        $st->close();
    } catch (mysqli_sql_exception $e) {
        if ((int) $e->getCode() === 1062) {
            return ["ok" => false, "error" => "Ya existe un cliente con ese NIT / cédula."];
        }
        return ["ok" => false, "error" => "No se pudo guardar el cliente."];
    }
    return ["ok" => true];
}

function sinTildes(string $t): string
{
    return strtr($t, ["á" => "a", "é" => "e", "í" => "i", "ó" => "o", "ú" => "u", "ü" => "u", "ñ" => "n",
                      "Á" => "a", "É" => "e", "Í" => "i", "Ó" => "o", "Ú" => "u", "Ü" => "u", "Ñ" => "n"]);
}

function aliasColumnasClientes(): array
{
    $a = [
        "tipo_persona" => ["tipo_persona", "tipo_de_persona", "persona"],
        "es_juridica" => ["persona_juridica", "juridica", "es_juridica"],
        "tipo_documento" => ["tipo_documento", "tipo_doc", "tipo_identificacion", "tipo_de_documento"],
        "identificacion" => ["identificacion", "nit", "cedula", "documento", "nit_cedula", "numero_documento", "id"],
        "dv" => ["dv", "digito_verificacion", "digito"],
        "codigo" => ["codigo", "cod"],
        "razon_social" => ["razon_social", "razon", "nombre", "nombre_completo", "nombre_o_razon_social", "tercero"],
        "nombre_comercial" => ["nombre_comercial", "nombre_c_cial", "comercial", "nombre_c_cial"],
        "direccion" => ["direccion", "dir", "direccion_1"],
        "direccion2" => ["direccion2", "2_dir", "2o_dir", "segunda_direccion", "direccion_2"],
        "puntos_referencia" => ["puntos_referencia", "punto_referencia", "referencia"],
        "telefono1" => ["telefono1", "telefono", "telefonos", "telefono_1", "tel1", "tel"],
        "telefono2" => ["telefono2", "telefono_2", "tel2"],
        "telefono3" => ["telefono3", "telefono_3", "tel3"],
        "movil" => ["movil", "tel_movil", "celular", "telefono_movil"],
        "codigo_postal" => ["codigo_postal", "cod_postal"],
        "email" => ["email", "correo", "correo_electronico", "e_mail"],
        "email_fe" => ["email_fe", "correo_fe", "email_factura", "email_factura_electronica", "correo_factura_electronica"],
        "departamento" => ["departamento", "depto", "dpto"],
        "municipio" => ["municipio", "ciudad"],
        "codigo_municipio" => ["codigo_municipio", "cod_municipio", "divipola"],
        "pais" => ["pais"],
        "barrio" => ["barrio"],
        "grupo" => ["grupo"],
        "subgrupo" => ["subgrupo"],
        "encargado" => ["encargado"],
        "representante_legal" => ["representante_legal", "rep_legal", "representante"],
        "observaciones" => ["observaciones", "observacion", "obs"],
        "zona" => ["zona"],
        "vendedor_asignado" => ["vendedor", "vendedor_asignado"],
        "cobrador" => ["cobrador"],
        "lista_precios" => ["lista_precios", "list_prec", "lista"],
        "condicion_pago" => ["condicion_pago", "condicion", "forma_pago"],
        "dias_credito" => ["dias_credito", "dias", "plazo"],
        "cupo_cartera" => ["cupo_cartera", "cupo"],
        "inactivo" => ["inactivo"],
    ];
    $mapa = [];
    foreach ($a as $campo => $lista) {
        foreach ($lista as $n) {
            $mapa[$n] = $campo;
        }
    }
    return $mapa;
}

/** Importa clientes desde un CSV (separado por coma, punto y coma o tabulador). */
function importarClientesCSV(mysqli $c, string $ruta): array
{
    $texto = file_get_contents($ruta);
    if ($texto === false || trim($texto) === "") {
        return ["ok" => false, "error" => "El archivo está vacío."];
    }
    if (substr($texto, 0, 3) === "\xEF\xBB\xBF") {
        $texto = substr($texto, 3);
    }
    if (!preg_match('//u', $texto)) {
        $conv = @iconv("Windows-1252", "UTF-8//TRANSLIT", $texto);
        $texto = $conv !== false ? $conv : $texto;
    }

    $primera = strtok($texto, "\n");
    $cuentas = [";" => substr_count($primera, ";"), "," => substr_count($primera, ","), "\t" => substr_count($primera, "\t")];
    arsort($cuentas);
    $delim = array_key_first($cuentas);

    $fh = fopen("php://memory", "r+");
    fwrite($fh, $texto);
    rewind($fh);

    $cab = fgetcsv($fh, 0, $delim);
    if (!$cab) {
        return ["ok" => false, "error" => "No se encontró la fila de encabezados."];
    }
    $alias = aliasColumnasClientes();
    $columnas = [];
    foreach ($cab as $i => $h) {
        $k = trim(preg_replace('/[^a-z0-9]+/', "_", strtolower(sinTildes(trim((string) $h)))), "_");
        if (isset($alias[$k])) {
            $columnas[$i] = $alias[$k];
        }
    }
    if (!in_array("identificacion", $columnas, true) || !in_array("razon_social", $columnas, true)) {
        return ["ok" => false, "error" => "El archivo debe tener al menos las columnas: identificacion (NIT/cédula) y razon_social (o nombre)."];
    }

    $existentes = [];
    $r = $c->query("SELECT identificacion_norm FROM clientes");
    while ($r && ($f = $r->fetch_row())) {
        $existentes[$f[0]] = true;
    }

    $nuevos = 0;
    $actualizados = 0;
    $errores = [];
    $linea = 1;
    $c->begin_transaction();
    try {
        while (($fila = fgetcsv($fh, 0, $delim)) !== false) {
            $linea++;
            if (!$fila || (count($fila) === 1 && trim((string) $fila[0]) === "")) {
                continue;
            }
            $in = [];
            foreach ($columnas as $i => $campo) {
                $in[$campo] = $fila[$i] ?? "";
            }
            $d = normalizarCliente($in);
            if (strlen($d["identificacion_norm"]) < 5 || $d["razon_social"] === "") {
                if (count($errores) < 8) {
                    $errores[] = "fila $linea";
                }
                continue;
            }
            [$cols, $tipos, $vals] = tiposCliente($d);
            $lista = implode(", ", array_map(fn($k) => "`$k`", $cols));
            $marcas = implode(", ", array_fill(0, count($cols), "?"));
            $upd = implode(", ", array_map(fn($k) => "`$k` = VALUES(`$k`)", array_filter($cols, fn($k) => $k !== "identificacion_norm")));
            $st = $c->prepare("INSERT INTO clientes ($lista) VALUES ($marcas) ON DUPLICATE KEY UPDATE $upd");
            $st->bind_param($tipos, ...$vals);
            $st->execute();
            $st->close();
            if (isset($existentes[$d["identificacion_norm"]])) {
                $actualizados++;
            } else {
                $nuevos++;
                $existentes[$d["identificacion_norm"]] = true;
            }
        }
        $c->commit();
    } catch (Throwable $e) {
        $c->rollback();
        return ["ok" => false, "error" => "Falló la importación en la fila $linea. No se guardó nada."];
    }
    return ["ok" => true, "nuevos" => $nuevos, "actualizados" => $actualizados, "errores" => $errores];
}
