<?php
// php/respaldos.php
include("../config/conexion.php");
$conexion_activa = isset($conn) ? $conn : (isset($conexion) ? $conexion : null);

// Respuesta AJAX (tablas de un esquema). Debe ir antes de imprimir cualquier HTML.
if (isset($_GET['ajax_schema']) && $conexion_activa) {
    $schema_Ajax = strtoupper(trim($_GET['ajax_schema']));
    $options = '<option value="">-- Seleccione una Tabla específica --</option>';
    if (nombre_ok($schema_Ajax)) {
        $tablas = ora_rows($conexion_activa, "SELECT TABLE_NAME FROM DBA_TABLES WHERE OWNER = :schema ORDER BY TABLE_NAME",
                           array(':schema' => $schema_Ajax));
        if ($tablas) {
            foreach ($tablas as $rt) {
                $options .= "<option value='" . h($rt['TABLE_NAME']) . "'>" . h($rt['TABLE_NAME']) . "</option>";
            }
        }
    }
    echo $options;
    exit;
}

$mensaje = "";
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generar_respaldo']) && $conexion_activa) {
    $tipo = isset($_POST['tipo_respaldo']) ? $_POST['tipo_respaldo'] : '';
    $directorio = isset($_POST['directorio']) ? trim($_POST['directorio']) : '';
    $esquema = '';
    $tabla = '';
    $sello = date('Ymd_His');
    $error_form = '';

    if (!dir_info($conexion_activa, $directorio)) {
        $error_form = 'Seleccione un directorio Oracle válido.';
    } elseif ($tipo == 'FULL') {
        $base = "full_$sello";
    } elseif ($tipo == 'SCHEMA') {
        $esquema = strtoupper(trim(isset($_POST['schema_param']) ? $_POST['schema_param'] : ''));
        if (!nombre_ok($esquema)) {
            $error_form = 'Seleccione el esquema a respaldar.';
        }
        $base = "schema_{$esquema}_$sello";
    } elseif ($tipo == 'TABLE') {
        $esquema = strtoupper(trim(isset($_POST['schema_tabla_param']) ? $_POST['schema_tabla_param'] : ''));
        $tabla = trim(isset($_POST['tabla_param']) ? $_POST['tabla_param'] : '');
        if (!nombre_ok($esquema) || !nombre_ok($tabla)) {
            $error_form = 'Seleccione el esquema propietario y la tabla a respaldar.';
        }
        $base = "table_{$esquema}_{$tabla}_$sello";
    } else {
        $error_form = 'Seleccione el tipo de respaldo.';
    }

    if ($error_form !== '') {
        $mensaje = ora_msg(false, h($error_form));
    } else {
        $dump = $base . '.dmp';
        $log = $base . '.log';
        dir_asegurar($conexion_activa, $directorio);
        $ok = datapump($conexion_activa, 'EXPORT', $tipo, $directorio, $dump, $log, $esquema, $tabla, $estado, $err);

        if ($ok) {
            $equiv = "expdp DIRECTORY=" . h(strtoupper($directorio)) . " DUMPFILE=" . h($dump) . " LOGFILE=" . h($log) .
                     ($tipo == 'FULL' ? " FULL=y" : ($tipo == 'SCHEMA' ? " SCHEMAS=" . h($esquema) : " TABLES=" . h($esquema) . "." . h($tabla)));
            $mensaje = ora_msg(true, "Respaldo <strong>" . h($tipo) . "</strong> ejecutado con éxito (estado: " . h($estado) . ").<br>" .
                       "Archivo generado: <code>" . h($dump) . "</code> en el directorio <code>" . h(strtoupper($directorio)) . "</code><br>" .
                       "Equivalente en línea de comandos: <code>" . $equiv . "</code>");
        } else {
            $mensaje = ora_msg(false, "Error al ejecutar el respaldo: " . h($err !== '' ? $err : 'estado ' . $estado));
        }
        $texto_log = leer_log($conexion_activa, $directorio, $log);
        if ($texto_log !== '') {
            $mensaje .= "<pre style='text-align:left;max-width:900px;width:100%;overflow:auto;background:#f8f9fa;border:1px solid #ddd;padding:10px;font-size:12px;box-sizing:border-box;'>" .
                        h($texto_log) . "</pre>";
        }
    }
}

$esquemas = $conexion_activa ? lista_esquemas($conexion_activa) : array();
$directorios = $conexion_activa ? lista_directorios($conexion_activa) : array();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Creación de Respaldos</title>
    <link rel="stylesheet" href="../css/style.css">
    <script>
    function actualizarOpcionesRespaldo() {
        var tipo = document.getElementById('tipo_respaldo').value;
        var seccionSchema = document.getElementById('seccion_schema_completo');
        var seccionTabla = document.getElementById('seccion_tabla_esquema');
        
        seccionSchema.style.display = (tipo === 'SCHEMA') ? 'block' : 'none';
        seccionTabla.style.display = (tipo === 'TABLE') ? 'block' : 'none';
    }

    function cargarTablasDelEsquema() {
        var esquemaSeleccionado = document.getElementById('schema_tabla_param').value;
        var selectTabla = document.getElementById('tabla_param');
        selectTabla.innerHTML = '<option value="">-- Cargando tablas... --</option>';

        if (esquemaSeleccionado) {
            var xhr = new XMLHttpRequest();
            xhr.open("GET", "respaldos.php?ajax_schema=" + encodeURIComponent(esquemaSeleccionado), true);
            xhr.onload = function () {
                if (xhr.status === 200) {
                    selectTabla.innerHTML = xhr.responseText;
                }
            };
            xhr.send();
        }
    }

    function confirmarRespaldo() {
        // Se deshabilita después del envío (un botón deshabilitado antes no manda su nombre en el POST).
        var b = document.getElementById('btn_respaldo');
        setTimeout(function () {
            b.disabled = true;
            b.innerText = 'Ejecutando respaldo... espere';
        }, 0);
        return true;
    }
    </script>
</head>
<body>
    <div class="container">
        <h2>Creación de Respaldos (Data Pump EXPDP)</h2>
        <a href="../index.php" class="btn-volver">← Volver al Menú Principal</a>
        <?php echo $mensaje; ?>

        <form method="POST" action="" class="form-card" onsubmit="return confirmarRespaldo();">
            <label>Tipo de Respaldo:</label>
            <select name="tipo_respaldo" id="tipo_respaldo" required onchange="actualizarOpcionesRespaldo()">
                <option value="">-- Seleccione una opción --</option>
                <option value="FULL">1. Respaldo Full (Toda la Base de Datos)</option>
                <option value="SCHEMA">2. Respaldo por Schema Completo</option>
                <option value="TABLE">3. Respaldo por una Tabla de un Schema</option>
            </select>
            
            <div id="seccion_schema_completo" style="display: none; margin-top: 15px;">
                <label>Esquema a respaldar:</label>
                <select name="schema_param" id="schema_param">
                    <option value="">-- Seleccione un Esquema --</option>
                    <?php echo opciones($esquemas, 'USERNAME'); ?>
                </select>
            </div>

            <div id="seccion_tabla_esquema" style="display: none; margin-top: 15px;">
                <label>Esquema Propietario:</label>
                <select name="schema_tabla_param" id="schema_tabla_param" onchange="cargarTablasDelEsquema()">
                    <option value="">-- Seleccione el Esquema --</option>
                    <?php echo opciones($esquemas, 'USERNAME'); ?>
                </select>
                <label style="margin-top:10px;">Tabla Específica:</label>
                <select name="tabla_param" id="tabla_param">
                    <option value="">-- Primero seleccione un esquema --</option>
                </select>
            </div>

            <label style="margin-top: 15px;">Directorio Oracle (DIRECTORY):</label>
            <select name="directorio" required>
                <option value="">-- Seleccione un Directorio --</option>
                <?php echo opciones($directorios, 'DIRECTORY_NAME'); ?>
            </select>
            <button type="submit" name="generar_respaldo" id="btn_respaldo" style="margin-top: 20px;">Generar Respaldo</button>
        </form>
    </div>
</body>
</html>