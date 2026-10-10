<?php
/**
 * Bases de clientes (mayoristas y detal) con todos los campos del catálogo de terceros de Syscafe.
 * Guardado manual, subterceros, contactos e importación desde CSV (Excel > Guardar como CSV).
 */
require_once __DIR__ . "/pedidos_lib.php";

const CLIENTE_TEXTOS = [
    "tipo_documento" => 40, "identificacion" => 30, "dv" => 3, "codigo" => 30, "razon_social" => 200,
    "nombre_comercial" => 200, "nota" => 500,
    "direccion" => 255, "direccion2" => 255, "puntos_referencia" => 255,
    "telefono1" => 40, "telefono2" => 40, "telefono3" => 40, "movil" => 40, "codigo_postal" => 20,
    "email" => 150, "email_fe" => 150,
    "departamento" => 80, "codigo_municipio" => 12, "municipio" => 100, "codigo_pais" => 6, "pais" => 60,
    "codigo_barrio" => 20, "barrio" => 120,
    "grupo" => 80, "subgrupo" => 80, "encargado" => 150, "representante_legal" => 150,
    "zona" => 80, "codigo_vendedor" => 20, "vendedor_asignado" => 100, "codigo_cobrador" => 20, "cobrador" => 100,
    "codigo_agente" => 20, "agente_comercial" => 100, "codigo_transporta" => 20, "transportadora" => 100,
    "lista_precios" => 60, "calificacion" => 40,
];

const CLIENTE_ENTEROS = ["dias_credito", "no_facturas", "dias_mora", "inactivo"];
const SUBTERCERO_CAMPOS = ["tipo" => 60, "codigo" => 40, "nombre" => 200];
const CONTACTO_CAMPOS = ["nombre" => 150, "cargo" => 100, "telefono" => 40, "movil" => 40, "email" => 150, "observaciones" => 255];

const TIPOS_DOCUMENTO = [
    "NIT", "Cédula de ciudadanía", "Cédula de extranjería", "Pasaporte", "Tarjeta de identidad",
    "Registro civil", "Tarjeta de extranjería", "Documento de identificación extranjero", "NIT de otro país", "NUIP",
];

function esVerdadero($v): bool
{
    $v = strtolower(trim((string) $v));
    return in_array($v, ["1", "si", "sí", "s", "x", "true", "yes", "y", "verdadero"], true);
}

function sinTildes(string $t): string
{
    return strtr($t, ["á" => "a", "é" => "e", "í" => "i", "ó" => "o", "ú" => "u", "ü" => "u", "ñ" => "n",
                      "Á" => "a", "É" => "e", "Í" => "i", "Ó" => "o", "Ú" => "u", "Ü" => "u", "Ñ" => "n"]);
}

/** Limpia y normaliza los datos de un cliente. */
function normalizarCliente(array $in): array
{
    $d = [];
    foreach (CLIENTE_TEXTOS as $campo => $max) {
        $d[$campo] = textoCorto($in[$campo] ?? "", $max);
    }
    $d["observaciones"] = textoCorto($in["observaciones"] ?? "", 4000, false);

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
        $td = strtolower(sinTildes($d["tipo_documento"]));
        $tp = ($td === "" || strpos($td, "nit") === 0) ? "juridica" : "natural";
    }
    $d["tipo_persona"] = $tp;
    if ($d["tipo_documento"] === "") {
        $d["tipo_documento"] = $tp === "natural" ? "Cédula de ciudadanía" : "NIT";
    }

    $d["identificacion_norm"] = soloDigitos($d["identificacion"]);
    $d["dv"] = soloDigitos($d["dv"]);

    $cond = strtolower(trim((string) ($in["condicion_pago"] ?? "")));
    $dias = (int) soloDigitos((string) ($in["dias_credito"] ?? ""));
    $d["condicion_pago"] = (strpos($cond, "cr") === 0 || ($cond === "" && $dias > 0)) ? "credito" : "contado";
    $d["dias_credito"] = $d["condicion_pago"] === "credito" && $dias > 0 ? min($dias, 365) : null;
    $d["cupo_cartera"] = parsearPrecio((string) ($in["cupo_cartera"] ?? "0"));
    $d["no_facturas"] = (int) soloDigitos((string) ($in["no_facturas"] ?? "0"));
    $d["dias_mora"] = (int) soloDigitos((string) ($in["dias_mora"] ?? "0"));
    $d["inactivo"] = esVerdadero($in["inactivo"] ?? "") ? 1 : 0;
    if ($d["pais"] === "") {
        $d["pais"] = "Colombia";
    }
    if ($d["codigo_pais"] === "") {
        $d["codigo_pais"] = "169";
    }
    return $d;
}

function tiposCliente(array $d): array
{
    $cols = array_merge(
        ["tipo_persona", "identificacion_norm", "dias_credito", "cupo_cartera", "no_facturas", "dias_mora", "inactivo", "condicion_pago", "observaciones"],
        array_keys(CLIENTE_TEXTOS)
    );
    $tipos = "";
    $vals = [];
    foreach ($cols as $col) {
        $tipos .= in_array($col, CLIENTE_ENTEROS, true) ? "i" : ($col === "cupo_cartera" ? "d" : "s");
        $vals[] = $d[$col];
    }
    return [$cols, $tipos, $vals];
}

/** Convierte el JSON de filas (subterceros / contactos) en filas limpias; ignora las vacías. */
function filasHijas($json, array $campos, int $max = 100): array
{
    $arr = is_array($json) ? $json : json_decode((string) $json, true);
    if (!is_array($arr)) {
        return [];
    }
    $out = [];
    foreach ($arr as $f) {
        if (!is_array($f)) {
            continue;
        }
        $fila = [];
        $vacia = true;
        foreach ($campos as $campo => $len) {
            $v = textoCorto($f[$campo] ?? "", $len);
            if ($v !== "") {
                $vacia = false;
            }
            $fila[$campo] = $v;
        }
        if (!$vacia) {
            $out[] = $fila;
        }
        if (count($out) >= $max) {
            break;
        }
    }
    return $out;
}

function guardarHijosCliente(mysqli $c, string $lista, int $clienteId, array $subterceros, array $contactos): void
{
    $lista = listaValida($lista);

    $st = $c->prepare("DELETE FROM cliente_subterceros WHERE lista = ? AND cliente_id = ?");
    $st->bind_param("si", $lista, $clienteId);
    $st->execute();
    $st->close();
    $st = $c->prepare("DELETE FROM cliente_contactos WHERE lista = ? AND cliente_id = ?");
    $st->bind_param("si", $lista, $clienteId);
    $st->execute();
    $st->close();

    $ins = $c->prepare("INSERT INTO cliente_subterceros (lista, cliente_id, tipo, codigo, nombre) VALUES (?, ?, ?, ?, ?)");
    foreach ($subterceros as $f) {
        $ins->bind_param("sisss", $lista, $clienteId, $f["tipo"], $f["codigo"], $f["nombre"]);
        $ins->execute();
    }
    $ins->close();

    $ins = $c->prepare("INSERT INTO cliente_contactos (lista, cliente_id, nombre, cargo, telefono, movil, email, observaciones) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($contactos as $f) {
        $ins->bind_param("sissssss", $lista, $clienteId, $f["nombre"], $f["cargo"], $f["telefono"], $f["movil"], $f["email"], $f["observaciones"]);
        $ins->execute();
    }
    $ins->close();
}

/** Subterceros y contactos de varios clientes: [cliente_id => ['subterceros'=>[], 'contactos'=>[]]] */
function cargarHijosClientes(mysqli $c, string $lista, array $ids): array
{
    $lista = listaValida($lista);
    $salida = [];
    $ids = array_values(array_filter(array_map("intval", $ids)));
    foreach ($ids as $id) {
        $salida[$id] = ["subterceros" => [], "contactos" => []];
    }
    if (!$ids) {
        return $salida;
    }
    $in = implode(",", $ids);
    $l = $c->real_escape_string($lista);
    $r = $c->query("SELECT cliente_id, tipo, codigo, nombre FROM cliente_subterceros WHERE lista = '$l' AND cliente_id IN ($in) ORDER BY id");
    while ($r && ($f = $r->fetch_assoc())) {
        $salida[(int) $f["cliente_id"]]["subterceros"][] = ["tipo" => $f["tipo"], "codigo" => $f["codigo"], "nombre" => $f["nombre"]];
    }
    $r = $c->query("SELECT cliente_id, nombre, cargo, telefono, movil, email, observaciones FROM cliente_contactos WHERE lista = '$l' AND cliente_id IN ($in) ORDER BY id");
    while ($r && ($f = $r->fetch_assoc())) {
        $id = (int) $f["cliente_id"];
        unset($f["cliente_id"]);
        $salida[$id]["contactos"][] = $f;
    }
    return $salida;
}

/** Crea o edita un cliente (en la base mayorista o detal). */
function guardarCliente(mysqli $c, string $lista, array $in, ?int $id): array
{
    $tabla = tablaClientes($lista);
    $lista = listaValida($lista);
    $d = normalizarCliente($in);
    if (strlen($d["identificacion_norm"]) < 5) {
        return ["ok" => false, "error" => "Ingrese un NIT o cédula válido."];
    }
    if ($d["razon_social"] === "") {
        return ["ok" => false, "error" => $d["tipo_persona"] === "natural" ? "Ingrese el nombre completo." : "Ingrese la razón social."];
    }
    [$cols, $tipos, $vals] = tiposCliente($d);

    try {
        $c->begin_transaction();
        if ($id) {
            $sets = implode(", ", array_map(fn($k) => "`$k` = ?", $cols));
            $st = $c->prepare("UPDATE `$tabla` SET $sets WHERE id = ?");
            $vals[] = $id;
            $st->bind_param($tipos . "i", ...$vals);
            $st->execute();
            $st->close();
            $clienteId = $id;
        } else {
            $lista_cols = implode(", ", array_map(fn($k) => "`$k`", $cols));
            $marcas = implode(", ", array_fill(0, count($cols), "?"));
            $st = $c->prepare("INSERT INTO `$tabla` ($lista_cols) VALUES ($marcas)");
            $st->bind_param($tipos, ...$vals);
            $st->execute();
            $st->close();
            $clienteId = (int) $c->insert_id;
        }

        if (isset($in["subterceros_json"]) || isset($in["contactos_json"])) {
            guardarHijosCliente(
                $c, $lista, $clienteId,
                filasHijas($in["subterceros_json"] ?? "[]", SUBTERCERO_CAMPOS),
                filasHijas($in["contactos_json"] ?? "[]", CONTACTO_CAMPOS)
            );
        }
        $c->commit();
    } catch (mysqli_sql_exception $e) {
        $c->rollback();
        if ((int) $e->getCode() === 1062) {
            return ["ok" => false, "error" => "Ya existe un cliente con ese NIT / cédula en esta base."];
        }
        return ["ok" => false, "error" => "No se pudo guardar el cliente: " . substr($e->getMessage(), 0, 160)];
    }
    return ["ok" => true, "id" => $clienteId];
}

function aliasColumnasClientes(): array
{
    $a = [
        "tipo_persona" => ["tipo_persona", "tipo_de_persona", "persona"],
        "es_juridica" => ["persona_juridica", "juridica", "es_juridica"],
        "tipo_documento" => ["tipo_documento", "tipo_doc", "tipo_identificacion", "tipo_de_documento", "identificacion_tipo"],
        "identificacion" => ["identificacion", "nit", "cedula", "documento", "nit_cedula", "numero_documento", "id", "numero"],
        "dv" => ["dv", "digito_verificacion", "digito"],
        "codigo" => ["codigo", "cod"],
        "razon_social" => ["razon_social", "razon", "nombre", "nombre_completo", "nombre_o_razon_social", "tercero"],
        "nombre_comercial" => ["nombre_comercial", "nombre_c_cial", "comercial", "nombre_ccial"],
        "nota" => ["nota", "nota_cliente"],
        "direccion" => ["direccion", "dir", "direccion_1"],
        "direccion2" => ["direccion2", "2_dir", "2o_dir", "segunda_direccion", "direccion_2"],
        "puntos_referencia" => ["puntos_referencia", "punto_referencia", "referencia"],
        "telefono1" => ["telefono1", "telefono", "telefonos", "telefono_1", "tel1", "tel", "telefonos_1"],
        "telefono2" => ["telefono2", "telefono_2", "tel2"],
        "telefono3" => ["telefono3", "telefono_3", "tel3"],
        "movil" => ["movil", "tel_movil", "celular", "telefono_movil"],
        "codigo_postal" => ["codigo_postal", "cod_postal"],
        "email" => ["email", "correo", "correo_electronico", "e_mail"],
        "email_fe" => ["email_fe", "correo_fe", "email_factura", "email_factura_electronica", "correo_factura_electronica"],
        "departamento" => ["departamento", "depto", "dpto"],
        "codigo_municipio" => ["codigo_municipio", "cod_municipio", "divipola"],
        "municipio" => ["municipio", "ciudad"],
        "codigo_pais" => ["codigo_pais", "cod_pais"],
        "pais" => ["pais"],
        "codigo_barrio" => ["codigo_barrio", "cod_barrio"],
        "barrio" => ["barrio"],
        "grupo" => ["grupo"],
        "subgrupo" => ["subgrupo"],
        "encargado" => ["encargado"],
        "representante_legal" => ["representante_legal", "rep_legal", "representante"],
        "observaciones" => ["observaciones", "observacion", "obs"],
        "zona" => ["zona"],
        "codigo_vendedor" => ["codigo_vendedor", "cod_vendedor"],
        "vendedor_asignado" => ["vendedor", "vendedor_asignado", "nombre_vendedor"],
        "codigo_cobrador" => ["codigo_cobrador", "cod_cobrador"],
        "cobrador" => ["cobrador", "nombre_cobrador"],
        "codigo_agente" => ["codigo_agente", "cod_agente"],
        "agente_comercial" => ["agente_comercial", "agente_cial", "agente"],
        "codigo_transporta" => ["codigo_transporta", "cod_transporta", "codigo_transportadora"],
        "transportadora" => ["transportadora", "transporta"],
        "lista_precios" => ["lista_precios", "list_prec", "lista"],
        "calificacion" => ["calificacion", "calific"],
        "cupo_cartera" => ["cupo_cartera", "cupo"],
        "no_facturas" => ["no_facturas", "no_fact", "facturas"],
        "dias_mora" => ["dias_mora", "no_dias_mora"],
        "condicion_pago" => ["condicion_pago", "condicion", "forma_pago"],
        "dias_credito" => ["dias_credito", "dias", "plazo"],
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
function importarClientesCSV(mysqli $c, string $lista, string $ruta): array
{
    $tabla = tablaClientes($lista);
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
    $r = $c->query("SELECT identificacion_norm FROM `$tabla`");
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
            $lista_cols = implode(", ", array_map(fn($k) => "`$k`", $cols));
            $marcas = implode(", ", array_fill(0, count($cols), "?"));
            $upd = implode(", ", array_map(fn($k) => "`$k` = VALUES(`$k`)", array_filter($cols, fn($k) => $k !== "identificacion_norm")));
            $st = $c->prepare("INSERT INTO `$tabla` ($lista_cols) VALUES ($marcas) ON DUPLICATE KEY UPDATE $upd");
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
        return ["ok" => false, "error" => "Falló la importación en la fila $linea. No se guardó nada. (" . substr($e->getMessage(), 0, 120) . ")"];
    }
    return ["ok" => true, "nuevos" => $nuevos, "actualizados" => $actualizados, "errores" => $errores];
}
