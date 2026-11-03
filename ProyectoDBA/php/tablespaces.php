<?php
// php/tablespaces.php
include("../config/conexion.php");
$conexion_activa = isset($conn) ? $conn : (isset($conexion) ? $conexion : null);
$mensaje = "";

// Archivos de datos y temporales (para redimensionar y para listar).
function lista_datafiles($c) {
    $r = ora_rows($c, "SELECT FILE_NAME, TABLESPACE_NAME, BYTES, AUTOEXTENSIBLE, 'DATAFILE' AS TIPO FROM DBA_DATA_FILES
                       UNION ALL
                       SELECT FILE_NAME, TABLESPACE_NAME, BYTES, AUTOEXTENSIBLE, 'TEMPFILE' AS TIPO FROM DBA_TEMP_FILES
                       ORDER BY 2, 1");
    return $r ? $r : array();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $conexion_activa) {

    if (isset($_POST['crear_ts'])) {
        $nombre = strtoupper(trim($_POST['ts_nombre']));
        $fichero = trim($_POST['ts_fichero']);
        $tamanio = intval(trim($_POST['ts_tamanio']));
        $tipo = $_POST['ts_tipo'];
        $sql = '';

        if (!nombre_ok($nombre) || !ruta_ok($fichero) || $tamanio < 1) {
            $mensaje = ora_msg(false, "Datos no válidos: revise el nombre, la ruta del fichero y el tamaño.");
        } else {
            if ($tipo == 'PERMANENT') {
                $sql = "CREATE TABLESPACE $nombre DATAFILE '$fichero' SIZE {$tamanio}M AUTOEXTEND ON NEXT 5M MAXSIZE UNLIMITED";
            } elseif ($tipo == 'TEMPORARY') {
                $sql = "CREATE TEMPORARY TABLESPACE $nombre TEMPFILE '$fichero' SIZE {$tamanio}M AUTOEXTEND ON";
            } elseif ($tipo == 'UNDO') {
                $sql = "CREATE UNDO TABLESPACE $nombre DATAFILE '$fichero' SIZE {$tamanio}M";
            }
            if ($sql === '') {
                $mensaje = ora_msg(false, "Tipo de tablespace no válido.");
            } elseif (ora_ddl($conexion_activa, $sql, $err)) {
                $mensaje = ora_msg(true, "Tablespace '" . h($nombre) . "' creado correctamente.");
            } else {
                $mensaje = ora_msg(false, "Error al crear tablespace: " . h($err));
            }
        }

    } elseif (isset($_POST['agregar_df'])) {
        $nombre = strtoupper(trim($_POST['df_ts']));
        $fichero = trim($_POST['df_fichero']);
        $tamanio = intval(trim($_POST['df_tamanio']));
        $r = nombre_ok($nombre) ? ora_rows($conexion_activa, "SELECT CONTENTS FROM DBA_TABLESPACES WHERE TABLESPACE_NAME = :t", array(':t' => $nombre)) : false;

        if (!$r || !ruta_ok($fichero) || $tamanio < 1) {
            $mensaje = ora_msg(false, "Datos no válidos: seleccione el tablespace e indique ruta y tamaño.");
        } else {
            $palabra = ($r[0]['CONTENTS'] == 'TEMPORARY') ? 'TEMPFILE' : 'DATAFILE';
            $sql = "ALTER TABLESPACE $nombre ADD $palabra '$fichero' SIZE {$tamanio}M AUTOEXTEND ON";
            if (ora_ddl($conexion_activa, $sql, $err)) {
                $mensaje = ora_msg(true, "Se agregó el archivo al tablespace '" . h($nombre) . "'.");
            } else {
                $mensaje = ora_msg(false, "Error al agregar el archivo: " . h($err));
            }
        }

    } elseif (isset($_POST['redimensionar_df'])) {
        $fichero = trim($_POST['rs_fichero']);
        $tamanio = intval(trim($_POST['rs_tamanio']));
        $encontrado = null;
        foreach (lista_datafiles($conexion_activa) as $f) {
            if ($f['FILE_NAME'] === $fichero) {
                $encontrado = $f;
            }
        }
        if (!$encontrado || $tamanio < 1) {
            $mensaje = ora_msg(false, "Seleccione un archivo de la lista e indique un tamaño válido.");
        } else {
            $objeto = ($encontrado['TIPO'] == 'TEMPFILE') ? 'TEMPFILE' : 'DATAFILE';
            $sql = "ALTER DATABASE $objeto '" . str_replace("'", "''", $encontrado['FILE_NAME']) . "' RESIZE {$tamanio}M";
            if (ora_ddl($conexion_activa, $sql, $err)) {
                $mensaje = ora_msg(true, "Archivo redimensionado a {$tamanio} MB.");
            } else {
                $mensaje = ora_msg(false, "Error al redimensionar: " . h($err));
            }
        }

    } elseif (isset($_POST['estado_ts'])) {
        $nombre = strtoupper(trim($_POST['es_ts']));
        $acciones = array('ONLINE' => 'ONLINE', 'OFFLINE' => 'OFFLINE', 'READ_ONLY' => 'READ ONLY', 'READ_WRITE' => 'READ WRITE');
        $accion = isset($_POST['es_accion']) ? $_POST['es_accion'] : '';
        if (!nombre_ok($nombre) || !isset($acciones[$accion])) {
            $mensaje = ora_msg(false, "Seleccione el tablespace y la acción.");
        } elseif (ora_ddl($conexion_activa, "ALTER TABLESPACE $nombre " . $acciones[$accion], $err)) {
            $mensaje = ora_msg(true, "Tablespace '" . h($nombre) . "' ahora en estado " . h($acciones[$accion]) . ".");
        } else {
            $mensaje = ora_msg(false, "Error al cambiar el estado: " . h($err));
        }

    } elseif (isset($_POST['eliminar_ts'])) {
        $nombre = strtoupper(trim($_POST['del_ts']));
        if (!nombre_ok($nombre) || in_array($nombre, array('SYSTEM', 'SYSAUX'), true)) {
            $mensaje = ora_msg(false, "Seleccione un tablespace que se pueda eliminar (SYSTEM y SYSAUX están protegidos).");
        } elseif (ora_ddl($conexion_activa, "DROP TABLESPACE $nombre INCLUDING CONTENTS AND DATAFILES", $err)) {
            $mensaje = ora_msg(true, "Tablespace '" . h($nombre) . "' eliminado junto con sus archivos.");
        } else {
            $mensaje = ora_msg(false, "Error al eliminar el tablespace: " . h($err));
        }
    }
}

// Datos para listas y tablas
$tablespaces = $conexion_activa ? ora_rows($conexion_activa, "SELECT TABLESPACE_NAME, STATUS, CONTENTS FROM DBA_TABLESPACES ORDER BY TABLESPACE_NAME") : array();
$tablespaces = $tablespaces ? $tablespaces : array();
$datafiles = $conexion_activa ? lista_datafiles($conexion_activa) : array();

// Uso de espacio por tablespace (permanentes/undo + temporales)
$uso = array();
if ($conexion_activa) {
    $q1 = ora_rows($conexion_activa, "SELECT d.TABLESPACE_NAME, ROUND(d.MB, 2) AS TOTAL_MB, ROUND(d.MB - NVL(f.MB, 0), 2) AS USADO_MB,
                                             ROUND(NVL(f.MB, 0), 2) AS LIBRE_MB,
                                             ROUND((d.MB - NVL(f.MB, 0)) / d.MB * 100, 1) AS PCT
                                      FROM (SELECT TABLESPACE_NAME, SUM(BYTES) / 1048576 AS MB FROM DBA_DATA_FILES GROUP BY TABLESPACE_NAME) d
                                      LEFT JOIN (SELECT TABLESPACE_NAME, SUM(BYTES) / 1048576 AS MB FROM DBA_FREE_SPACE GROUP BY TABLESPACE_NAME) f
                                             ON d.TABLESPACE_NAME = f.TABLESPACE_NAME
                                      ORDER BY d.TABLESPACE_NAME");
    $q2 = ora_rows($conexion_activa, "SELECT TABLESPACE_NAME, ROUND(TABLESPACE_SIZE / 1048576, 2) AS TOTAL_MB,
                                             ROUND((TABLESPACE_SIZE - FREE_SPACE) / 1048576, 2) AS USADO_MB,
                                             ROUND(FREE_SPACE / 1048576, 2) AS LIBRE_MB,
                                             ROUND((TABLESPACE_SIZE - FREE_SPACE) / TABLESPACE_SIZE * 100, 1) AS PCT
                                      FROM DBA_TEMP_FREE_SPACE ORDER BY TABLESPACE_NAME");
    $uso = array_merge($q1 ? $q1 : array(), $q2 ? $q2 : array());
}
foreach ($datafiles as $k => $f) {
    $datafiles[$k]['MB'] = round($f['BYTES'] / 1048576, 2);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Administración de Tablespaces</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="container">
        <h2>Administración de Tablespaces y Datafiles</h2>
        <a href="../index.php" class="btn-volver">← Volver al Menú Principal</a>
        <?php echo $mensaje; ?>

        <form method="POST" action="" class="form-card" style="margin-top:20px;">
            <h3>Crear Nuevo Tablespace</h3>
            <label>Nombre del Tablespace:</label>
            <input type="text" name="ts_nombre" required placeholder="Ej: DATACURSOXY">

            <label>Tipo de Tablespace:</label>
            <select name="ts_tipo" required>
                <option value="PERMANENT">Permanente (Datos)</option>
                <option value="TEMPORARY">Temporal (TEMP)</option>
                <option value="UNDO">Deshacer (UNDO)</option>
            </select>

            <label>Ruta completa y nombre del Fichero (.dbf):</label>
            <input type="text" name="ts_fichero" required placeholder="C:\app\oracle\product\21c\oradata\XE\datacurso01.dbf">

            <label>Tamaño Inicial (MB):</label>
            <input type="number" name="ts_tamanio" value="10" min="1" required>

            <button type="submit" name="crear_ts" style="margin-top:15px;">Crear Tablespace</button>
        </form>

        <form method="POST" action="" class="form-card">
            <h3>Agregar Datafile a un Tablespace</h3>
            <label>Tablespace:</label>
            <select name="df_ts" required>
                <option value="">-- Seleccione --</option>
                <?php echo opciones($tablespaces, 'TABLESPACE_NAME'); ?>
            </select>
            <label>Ruta completa y nombre del nuevo fichero:</label>
            <input type="text" name="df_fichero" required placeholder="C:\app\oracle\product\21c\oradata\XE\datacurso02.dbf">
            <label>Tamaño (MB):</label>
            <input type="number" name="df_tamanio" value="10" min="1" required>
            <button type="submit" name="agregar_df" style="margin-top:15px;">Agregar Datafile</button>
        </form>

        <form method="POST" action="" class="form-card">
            <h3>Redimensionar Datafile</h3>
            <label>Archivo:</label>
            <select name="rs_fichero" required>
                <option value="">-- Seleccione un archivo --</option>
                <?php
                foreach ($datafiles as $f) {
                    echo "<option value='" . h($f['FILE_NAME']) . "'>" . h($f['TABLESPACE_NAME']) . " - " . h($f['FILE_NAME']) . " (" . h($f['MB']) . " MB)</option>";
                }
                ?>
            </select>
            <label>Nuevo tamaño (MB):</label>
            <input type="number" name="rs_tamanio" min="1" required>
            <button type="submit" name="redimensionar_df" style="margin-top:15px;">Redimensionar</button>
        </form>

        <form method="POST" action="" class="form-card">
            <h3>Cambiar Estado de un Tablespace</h3>
            <label>Tablespace:</label>
            <select name="es_ts" required>
                <option value="">-- Seleccione --</option>
                <?php echo opciones($tablespaces, 'TABLESPACE_NAME'); ?>
            </select>
            <label>Acción:</label>
            <select name="es_accion" required>
                <option value="ONLINE">ONLINE</option>
                <option value="OFFLINE">OFFLINE</option>
                <option value="READ_ONLY">READ ONLY</option>
                <option value="READ_WRITE">READ WRITE</option>
            </select>
            <button type="submit" name="estado_ts" style="margin-top:15px;">Aplicar</button>
        </form>

        <form method="POST" action="" class="form-card" onsubmit="return confirm('Se eliminará el tablespace con su contenido y sus archivos. ¿Continuar?');">
            <h3>Eliminar Tablespace</h3>
            <label>Tablespace:</label>
            <select name="del_ts" required>
                <option value="">-- Seleccione --</option>
                <?php echo opciones($tablespaces, 'TABLESPACE_NAME'); ?>
            </select>
            <button type="submit" name="eliminar_ts" style="margin-top:15px;background:#dc3545;">Eliminar Tablespace</button>
        </form>

        <h3 style="margin-top:30px;">Tablespaces Actuales en la Base de Datos</h3>
        <?php render_tabla($tablespaces, array('Nombre' => 'TABLESPACE_NAME', 'Estado' => 'STATUS', 'Contenido' => 'CONTENTS')); ?>

        <h3 style="margin-top:30px;">Uso de Espacio por Tablespace</h3>
        <?php render_tabla($uso, array('Tablespace' => 'TABLESPACE_NAME', 'Total (MB)' => 'TOTAL_MB', 'Usado (MB)' => 'USADO_MB', 'Libre (MB)' => 'LIBRE_MB', '% Usado' => 'PCT')); ?>

        <h3 style="margin-top:30px;">Datafiles y Tempfiles</h3>
        <?php render_tabla($datafiles, array('Archivo' => 'FILE_NAME', 'Tablespace' => 'TABLESPACE_NAME', 'Tipo' => 'TIPO', 'Tamaño (MB)' => 'MB', 'Autoextensible' => 'AUTOEXTENSIBLE')); ?>
    </div>
</body>
</html>