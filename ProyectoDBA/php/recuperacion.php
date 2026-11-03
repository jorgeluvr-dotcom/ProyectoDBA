<?php
// php/recuperacion.php
include("../config/conexion.php");
$conexion_activa = isset($conn) ? $conn : (isset($conexion) ? $conexion : null);
$mensaje = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ejecutar_import']) && $conexion_activa) {
    $directorio = isset($_POST['directorio']) ? trim($_POST['directorio']) : '';
    $dumpfile = isset($_POST['dumpfile']) ? trim($_POST['dumpfile']) : '';
    $tipo = isset($_POST['tipo_recuperacion']) ? $_POST['tipo_recuperacion'] : 'SCHEMA';
    $esquema = strtoupper(trim(isset($_POST['esquema_destino']) ? $_POST['esquema_destino'] : ''));
    $tabla = strtoupper(trim(isset($_POST['tabla_destino']) ? $_POST['tabla_destino'] : ''));
    $error_form = '';

    $info = dir_info($conexion_activa, $directorio);
    if (!$info) {
        $error_form = 'Seleccione un directorio Oracle válido.';
    } elseif (!ruta_ok($dumpfile) || preg_match('/[\\\\\/]/', $dumpfile)) {
        $error_form = 'Indique el nombre del archivo .dmp (sin rutas).';
    } elseif (!in_array($tipo, array('FULL', 'SCHEMA', 'TABLE'), true)) {
        $error_form = 'Seleccione el tipo de recuperación.';
    } elseif ($tipo != 'FULL' && !nombre_ok($esquema)) {
        $error_form = 'Indique el esquema a recuperar.';
    } elseif ($tipo == 'TABLE' && !nombre_ok($tabla)) {
        $error_form = 'Indique la tabla a recuperar.';
    } elseif (!is_file(ruta_archivo($info['DIRECTORY_PATH'], $dumpfile)) && is_dir($info['DIRECTORY_PATH'])) {
        $error_form = 'El archivo ' . $dumpfile . ' no existe en el directorio ' . $info['DIRECTORY_NAME'] . '.';
    }

    if ($error_form !== '') {
        $mensaje = ora_msg(false, h($error_form));
    } else {
        $log = 'import_' . date('Ymd_His') . '.log';
        dir_asegurar($conexion_activa, $directorio);
        $ok = datapump($conexion_activa, 'IMPORT', $tipo, $directorio, $dumpfile, $log, $esquema, $tabla, $estado, $err);

        if ($ok) {
            $equiv = "impdp DIRECTORY=" . h(strtoupper($directorio)) . " DUMPFILE=" . h($dumpfile) . " LOGFILE=" . h($log) .
                     ($tipo == 'FULL' ? " FULL=y" : ($tipo == 'SCHEMA' ? " SCHEMAS=" . h($esquema) : " TABLES=" . h($esquema) . "." . h($tabla))) .
                     " TABLE_EXISTS_ACTION=REPLACE";
            $mensaje = ora_msg(true, "Recuperación <strong>" . h($tipo) . "</strong> ejecutada con éxito (estado: " . h($estado) . ").<br>" .
                       "Equivalente en línea de comandos: <code>" . $equiv . "</code>");
        } else {
            $mensaje = ora_msg(false, "Error al ejecutar la recuperación: " . h($err !== '' ? $err : 'estado ' . $estado));
        }
        $texto_log = leer_log($conexion_activa, $directorio, $log);
        if ($texto_log !== '') {
            $mensaje .= "<pre style='text-align:left;max-width:900px;width:100%;overflow:auto;background:#f8f9fa;border:1px solid #ddd;padding:10px;font-size:12px;box-sizing:border-box;'>" .
                        h($texto_log) . "</pre>";
        }
    }
}

$directorios = $conexion_activa ? lista_directorios($conexion_activa) : array();

// Archivos .dmp visibles desde el servidor web (para sugerirlos en el campo de texto).
$sugeridos = array();
foreach ($directorios as $d) {
    $ruta = $d['DIRECTORY_PATH'];
    if (is_dir($ruta) && is_readable($ruta)) {
        foreach ((array)@scandir($ruta) as $f) {
            if (strtolower(substr($f, -4)) === '.dmp') {
                $sugeridos[$f] = true;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Recuperación de Respaldos</title>
    <link rel="stylesheet" href="../css/style.css">
    <script>
    function actualizarOpcionesRecuperacion() {
        var tipo = document.getElementById('tipo_recuperacion').value;
        document.getElementById('seccion_esquema').style.display = (tipo === 'FULL') ? 'none' : 'block';
        document.getElementById('seccion_tabla').style.display = (tipo === 'TABLE') ? 'block' : 'none';
    }
    function confirmarImport() {
        var b = document.getElementById('btn_import');
        setTimeout(function () {
            b.disabled = true;
            b.innerText = 'Recuperando... espere';
        }, 0);
        return true;
    }
    </script>
</head>
<body>
    <div class="container">
        <h2>Recuperación de Respaldos (Data Pump IMPDP)</h2>
        <a href="../index.php" class="btn-volver">← Volver al Menú Principal</a>
        <?php echo $mensaje; ?>

        <form method="POST" action="" class="form-card" style="margin-top:20px;" onsubmit="return confirmarImport();">
            <h3>Asistente de Importación / Restauración</h3>

            <label>Tipo de Recuperación:</label>
            <select name="tipo_recuperacion" id="tipo_recuperacion" onchange="actualizarOpcionesRecuperacion()">
                <option value="SCHEMA">Recuperar un Schema completo</option>
                <option value="TABLE">Recuperar una Tabla</option>
                <option value="FULL">Recuperar Full (toda la Base de Datos)</option>
            </select>

            <label style="margin-top:15px;">Directorio Oracle (DIRECTORY):</label>
            <select name="directorio" required>
                <option value="">-- Seleccione un Directorio --</option>
                <?php echo opciones($directorios, 'DIRECTORY_NAME'); ?>
            </select>

            <label style="margin-top:15px;">Nombre del archivo de volcado (.dmp):</label>
            <input type="text" name="dumpfile" required placeholder="schema_coop.dmp" list="lista_dmp">
            <datalist id="lista_dmp">
                <?php foreach (array_keys($sugeridos) as $f) { echo "<option value='" . h($f) . "'>"; } ?>
            </datalist>

            <div id="seccion_esquema">
                <label style="margin-top:15px;">Esquema Destino:</label>
                <input type="text" name="esquema_destino" placeholder="COOPERATIVA">
            </div>

            <div id="seccion_tabla" style="display:none;">
                <label style="margin-top:15px;">Tabla a recuperar:</label>
                <input type="text" name="tabla_destino" placeholder="SOCIOS">
            </div>

            <button type="submit" name="ejecutar_import" id="btn_import" style="margin-top:20px;">Ejecutar Recuperación</button>
        </form>
    </div>
</body>
</html>