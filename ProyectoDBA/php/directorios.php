<?php
// php/directorios.php
include("../config/conexion.php");
$conexion_activa = isset($conn) ? $conn : (isset($conexion) ? $conexion : null);
$mensaje = "";

// Solo se administran archivos de respaldo: volcados (.dmp) y bitácoras (.log) de Data Pump.
function archivo_respaldo_ok($nombre) {
    return is_string($nombre) && $nombre !== '' && $nombre === basename($nombre)
        && preg_match('/^[A-Za-z0-9_.\-$#]+\.(dmp|log)$/i', $nombre) === 1;
}

$dir_sel = '';
if (isset($_GET['dir'])) {
    $dir_sel = strtoupper(trim($_GET['dir']));
} elseif (isset($_POST['dir_sel'])) {
    $dir_sel = strtoupper(trim($_POST['dir_sel']));
}

// Descarga de un archivo de respaldo (debe ir antes de imprimir HTML)
if ($conexion_activa && isset($_GET['descargar']) && isset($_GET['archivo'])) {
    $info = dir_info($conexion_activa, $dir_sel);
    $archivo = $_GET['archivo'];
    if ($info && archivo_respaldo_ok($archivo)) {
        $ruta = ruta_archivo($info['DIRECTORY_PATH'], $archivo);
        if (is_file($ruta) && is_readable($ruta)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $archivo . '"');
            header('Content-Length: ' . filesize($ruta));
            readfile($ruta);
            exit;
        }
    }
    $mensaje = ora_msg(false, "No se pudo descargar el archivo solicitado.");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $conexion_activa) {
    if (isset($_POST['crear_dir'])) {
        $nombre_dir = strtoupper(trim($_POST['dir_nombre']));
        $ruta_dir = trim($_POST['dir_ruta']);

        if (!nombre_ok($nombre_dir) || !ruta_ok($ruta_dir)) {
            $mensaje = ora_msg(false, "Nombre de directorio o ruta no válidos.");
        } else {
            // Si el servidor web está en la misma máquina que Oracle, se crea la carpeta física si no existe.
            $nota = '';
            if (!is_dir($ruta_dir)) {
                if (@mkdir($ruta_dir, 0777, true)) {
                    $nota = " La carpeta física fue creada.";
                } else {
                    $nota = " Verifique que la carpeta exista en el servidor Oracle y que el servicio tenga permisos de escritura.";
                }
            }
            $sql = "CREATE OR REPLACE DIRECTORY $nombre_dir AS '$ruta_dir'";
            if (ora_ddl($conexion_activa, $sql, $err)) {
                dir_asegurar($conexion_activa, $nombre_dir);
                $mensaje = ora_msg(true, "Directorio Oracle '" . h($nombre_dir) . "' creado y con permisos otorgados correctamente." . h($nota));
            } else {
                $mensaje = ora_msg(false, "Error: " . h($err));
            }
        }

    } elseif (isset($_POST['eliminar_dir'])) {
        $nombre_dir = strtoupper(trim($_POST['dir_eliminar']));
        if (!nombre_ok($nombre_dir) || $nombre_dir === 'DATA_PUMP_DIR' || !dir_info($conexion_activa, $nombre_dir)) {
            $mensaje = ora_msg(false, "Seleccione un directorio que se pueda eliminar (DATA_PUMP_DIR está protegido).");
        } elseif (ora_ddl($conexion_activa, "DROP DIRECTORY $nombre_dir", $err)) {
            $mensaje = ora_msg(true, "Directorio '" . h($nombre_dir) . "' eliminado de la base de datos (los archivos del disco no se tocan).");
            if ($dir_sel === $nombre_dir) {
                $dir_sel = '';
            }
        } else {
            $mensaje = ora_msg(false, "Error: " . h($err));
        }

    } elseif (isset($_POST['borrar_archivo'])) {
        $archivo = isset($_POST['archivo']) ? $_POST['archivo'] : '';
        $info = dir_info($conexion_activa, $dir_sel);
        if (!$info || !archivo_respaldo_ok($archivo)) {
            $mensaje = ora_msg(false, "Directorio o archivo no válido.");
        } else {
            dir_asegurar($conexion_activa, $info['DIRECTORY_NAME']);
            $stid = oci_parse($conexion_activa, "BEGIN UTL_FILE.FREMOVE(:d, :f); END;");
            $d = $info['DIRECTORY_NAME'];
            oci_bind_by_name($stid, ':d', $d);
            oci_bind_by_name($stid, ':f', $archivo);
            if (@oci_execute($stid)) {
                $mensaje = ora_msg(true, "Archivo '" . h($archivo) . "' eliminado correctamente.");
            } else {
                $e = oci_error($stid);
                $mensaje = ora_msg(false, "Error al eliminar el archivo: " . h($e['message']));
            }
            @oci_free_statement($stid);
        }
    }
}

$directorios = $conexion_activa ? lista_directorios($conexion_activa) : array();

// Archivos de respaldo del directorio seleccionado
$archivos = array();
$aviso_archivos = '';
if ($conexion_activa && $dir_sel !== '') {
    $info = dir_info($conexion_activa, $dir_sel);
    if (!$info) {
        $aviso_archivos = 'El directorio seleccionado no existe.';
    } elseif (!is_dir($info['DIRECTORY_PATH']) || !is_readable($info['DIRECTORY_PATH'])) {
        $aviso_archivos = 'El servidor web no puede leer la ruta ' . $info['DIRECTORY_PATH'] . ' (¿la carpeta no existe o Oracle está en otra máquina?).';
    } else {
        foreach ((array)@scandir($info['DIRECTORY_PATH']) as $f) {
            $ruta = ruta_archivo($info['DIRECTORY_PATH'], $f);
            if (archivo_respaldo_ok($f) && is_file($ruta)) {
                $archivos[] = array('NOMBRE' => $f, 'KB' => round(filesize($ruta) / 1024, 1), 'FECHA' => date('d/m/Y H:i:s', filemtime($ruta)), 'TS' => filemtime($ruta));
            }
        }
        usort($archivos, function ($a, $b) { return $b['TS'] - $a['TS']; });
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Directorios Oracle y Archivos</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="container">
        <h2>Administración de Directorios Oracle (CREATE DIRECTORY)</h2>
        <a href="../index.php" class="btn-volver">← Volver al Menú Principal</a>
        <?php echo $mensaje; ?>

        <form method="POST" action="" class="form-card" style="margin-top:20px;">
            <h3>Crear o Reemplazar Directorio para Data Pump</h3>
            <label>Nombre del Directorio (Ej: RESPALDO_DIR):</label>
            <input type="text" name="dir_nombre" required placeholder="RESPALDO_DIR">

            <label>Ruta Física en el Servidor (Ej: C:\oraclexe\app\oracle\Respaldos):</label>
            <input type="text" name="dir_ruta" required placeholder="C:\oraclexe\app\oracle\Respaldos">

            <button type="submit" name="crear_dir" style="margin-top:15px;">Crear Directorio</button>
        </form>

        <form method="POST" action="" class="form-card" onsubmit="return confirm('¿Eliminar este directorio de la base de datos?');">
            <h3>Eliminar un Directorio Oracle</h3>
            <label>Directorio:</label>
            <select name="dir_eliminar" required>
                <option value="">-- Seleccione --</option>
                <?php echo opciones($directorios, 'DIRECTORY_NAME'); ?>
            </select>
            <button type="submit" name="eliminar_dir" style="margin-top:15px;background:#dc3545;">Eliminar Directorio</button>
        </form>

        <h3 style="margin-top:30px;">Directorios Registrados en la Base de Datos</h3>
        <?php render_tabla($directorios, array('Nombre del Directorio' => 'DIRECTORY_NAME', 'Ruta Física (Directory Path)' => 'DIRECTORY_PATH')); ?>

        <h3 style="margin-top:30px;">Archivos de Respaldo (.dmp y .log)</h3>
        <form method="GET" action="" class="form-card">
            <label>Directorio a explorar:</label>
            <select name="dir" required>
                <option value="">-- Seleccione --</option>
                <?php echo opciones($directorios, 'DIRECTORY_NAME', $dir_sel); ?>
            </select>
            <button type="submit" style="margin-top:15px;">Ver Archivos</button>
        </form>

        <?php if ($dir_sel !== ''): ?>
            <?php if ($aviso_archivos !== ''): ?>
                <?php echo ora_msg(false, h($aviso_archivos)); ?>
            <?php else: ?>
                <table border="1" class="tabla-datos">
                    <tr>
                        <th>Archivo</th>
                        <th>Tamaño (KB)</th>
                        <th>Fecha de Modificación</th>
                        <th>Acciones</th>
                    </tr>
                    <?php if (!$archivos): ?>
                        <tr><td colspan="4">No hay archivos .dmp ni .log en este directorio.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($archivos as $a): ?>
                        <tr>
                            <td><?php echo h($a['NOMBRE']); ?></td>
                            <td><?php echo h($a['KB']); ?></td>
                            <td><?php echo h($a['FECHA']); ?></td>
                            <td>
                                <a href="?dir=<?php echo urlencode($dir_sel); ?>&descargar=1&archivo=<?php echo urlencode($a['NOMBRE']); ?>"
                                   class="btn-volver" style="margin:0 4px;padding:5px 10px;background:#007bff;">Descargar</a>
                                <form method="POST" action="?dir=<?php echo urlencode($dir_sel); ?>" style="display:inline;"
                                      onsubmit="return confirm('¿Eliminar el archivo <?php echo h(addslashes($a['NOMBRE'])); ?>?');">
                                    <input type="hidden" name="dir_sel" value="<?php echo h($dir_sel); ?>">
                                    <input type="hidden" name="archivo" value="<?php echo h($a['NOMBRE']); ?>">
                                    <button type="submit" name="borrar_archivo" style="margin:0 4px;padding:5px 10px;font-size:14px;background:#dc3545;">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>