<?php
/**
 * Acciones POST de subadministradores: subadm_crear | subadm_editar | subadm_estado | subadm_eliminar
 * Se incluye desde admin.php dentro del bloque POST (usa $conexion, $accion y redireccionar()).
 * Solo las puede hacer el administrador principal (adminGuardia ya lo exige, y aquí se vuelve a comprobar).
 */

if (strpos((string) $accion, "subadm_") !== 0) {
    return;
}

require_once __DIR__ . "/admin_permisos.php";

if (!adminEsPrincipal()) {
    redireccionar("Solo el administrador principal puede gestionar subadministradores.", "error");
}

adminAsegurarColumnas($conexion);

$subId = (int) ($_POST["id"] ?? 0);
$largoSub = fn(string $t): int => function_exists("mb_strlen") ? mb_strlen($t, "UTF-8") : strlen($t);

try {
    if ($accion === "subadm_crear" || $accion === "subadm_editar") {
        $esNuevo = ($accion === "subadm_crear");
        $nombre = trim((string) ($_POST["nombre"] ?? ""));
        $correo = trim((string) ($_POST["usuario"] ?? ""));
        $clave = (string) ($_POST["password"] ?? "");
        $modulos = adminModulosDePost();

        if ($nombre === "" || $largoSub($nombre) > 120) {
            redireccionar("Escriba el nombre del subadministrador (máximo 120 caracteres).", "error", "subadmins");
        }
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || $largoSub($correo) > 100) {
            redireccionar("Escriba un correo válido para el ingreso.", "error", "subadmins");
        }
        if ($esNuevo && strlen($clave) < 6) {
            redireccionar("La contraseña debe tener al menos 6 caracteres.", "error", "subadmins");
        }
        if (!$esNuevo && $clave !== "" && strlen($clave) < 6) {
            redireccionar("La nueva contraseña debe tener al menos 6 caracteres (o déjela vacía para no cambiarla).", "error", "subadmins");
        }
        if (!$modulos) {
            redireccionar("Marque al menos un módulo al que pueda entrar.", "error", "subadmins");
        }

        // El correo no puede repetirse (ni con otro subadministrador ni con un administrador)
        $st = $conexion->prepare("SELECT id FROM administradores WHERE usuario = ? AND id <> ? LIMIT 1");
        $idComparar = $esNuevo ? 0 : $subId;
        $st->bind_param("si", $correo, $idComparar);
        $st->execute();
        $repetido = $st->get_result()->fetch_assoc();
        $st->close();
        if ($repetido) {
            redireccionar("Ya existe un usuario con ese correo.", "error", "subadmins");
        }

        $permisosJson = json_encode($modulos);

        if ($esNuevo) {
            $hash = password_hash($clave, PASSWORD_DEFAULT);
            $st = $conexion->prepare("INSERT INTO administradores (usuario, `contraseña`, activo, rol, permisos, nombre) VALUES (?, ?, 1, 'sub', ?, ?)");
            $st->bind_param("ssss", $correo, $hash, $permisosJson, $nombre);
            $st->execute();
            $st->close();
            redireccionar("Subadministrador creado. Ya puede ingresar con su correo y contraseña.", "exito", "subadmins");
        }

        // Editar: solo se puede tocar a un subadministrador, nunca a un administrador principal
        $st = $conexion->prepare("SELECT id FROM administradores WHERE id = ? AND rol = 'sub' LIMIT 1");
        $st->bind_param("i", $subId);
        $st->execute();
        $existe = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$existe) {
            redireccionar("No se encontró el subadministrador.", "error", "subadmins");
        }

        if ($clave !== "") {
            $hash = password_hash($clave, PASSWORD_DEFAULT);
            $st = $conexion->prepare("UPDATE administradores SET usuario = ?, nombre = ?, permisos = ?, `contraseña` = ? WHERE id = ? AND rol = 'sub'");
            $st->bind_param("ssssi", $correo, $nombre, $permisosJson, $hash, $subId);
        } else {
            $st = $conexion->prepare("UPDATE administradores SET usuario = ?, nombre = ?, permisos = ? WHERE id = ? AND rol = 'sub'");
            $st->bind_param("sssi", $correo, $nombre, $permisosJson, $subId);
        }
        $st->execute();
        $st->close();
        redireccionar("Subadministrador actualizado. Los cambios aplican en su próximo clic.", "exito", "subadmins");
    }

    if ($accion === "subadm_estado") {
        $activo = ((int) ($_POST["activo"] ?? 0)) === 1 ? 1 : 0;
        $st = $conexion->prepare("UPDATE administradores SET activo = ? WHERE id = ? AND rol = 'sub'");
        $st->bind_param("ii", $activo, $subId);
        $st->execute();
        $st->close();
        redireccionar($activo ? "Subadministrador activado." : "Subadministrador desactivado: ya no puede ingresar.", "exito", "subadmins");
    }

    if ($accion === "subadm_eliminar") {
        $st = $conexion->prepare("DELETE FROM administradores WHERE id = ? AND rol = 'sub'");
        $st->bind_param("i", $subId);
        $st->execute();
        $st->close();
        redireccionar("Subadministrador eliminado.", "exito", "subadmins");
    }
} catch (Throwable $e) {
    redireccionar("No se pudo guardar: " . $e->getMessage(), "error", "subadmins");
}

redireccionar("Acción no válida.", "error", "subadmins");
