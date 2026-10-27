<?php
// php/tablespaces.php
include("../config/conexion.php");
$conexion_activa = isset($conn) ? $conn : (isset($conexion) ? $conexion : null);
$mensaje = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['crear_ts'])) {
    $nombre = strtoupper(trim($_POST['ts_nombre']));
    $fichero = trim($_POST['ts_fichero']);
    $tamanio = trim($_POST['ts_tamanio']);
    $tipo = $_POST['ts_tipo'];

    if ($tipo == 'PERMANENT') {
        $sql = "CREATE TABLESPACE $nombre DATAFILE '$fichero' SIZE {$tamanio}M AUTOEXTEND ON NEXT 5M MAXSIZE UNLIMITED";
    } elseif ($tipo == 'TEMPORARY') {
        $sql = "CREATE TEMPORARY TABLESPACE $nombre TEMPFILE '$fichero' SIZE {$tamanio}M AUTOEXTEND ON";
    } elseif ($tipo == 'UNDO') {
        $sql = "CREATE UNDO TABLESPACE $nombre DATAFILE '$fichero' SIZE {$tamanio}M";
    }

    $stid = oci_parse($conexion_activa, $sql);
    if (@oci_execute($stid)) {
        $mensaje = "<div class='alert success'>Tablespace '$nombre' creado correctamente.</div>";
    } else {
        $e = oci_error($stid);
        $mensaje = "<div class='alert error'>Error al crear tablespace: " . htmlentities($e['message']) . "</div>";
    }
    @oci_free_statement($stid);
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
            <input type="number" name="ts_tamanio" value="10" required>

            <button type="submit" name="crear_ts" style="margin-top:15px;">Crear Tablespace</button>
        </form>

        <h3 style="margin-top:30px;">Tablespaces Actuales en la Base de Datos</h3>
        <table border="1" class="tabla-datos">
            <tr>
                <th>Nombre</th>
                <th>Estado</th>
                <th>Contenido</th>
            </tr>
            <?php
            if ($conexion_activa) {
                $q = "SELECT TABLESPACE_NAME, STATUS, CONTENTS FROM DBA_TABLESPACES ORDER BY TABLESPACE_NAME";
                $st = oci_parse($conexion_activa, $q);
                
                if (@oci_execute($st)) {
                    while ($r = oci_fetch_assoc($st)) {
                        echo "<tr>";
                        echo "<td>" . htmlspecialchars($r['TABLESPACE_NAME']) . "</td>";
                        echo "<td>" . htmlspecialchars($r['STATUS']) . "</td>";
                        echo "<td>" . htmlspecialchars($r['CONTENTS']) . "</td>";
                        echo "</tr>";
                    }
                }
                @oci_free_statement($st);
            }
            ?>
        </table>
    </div>
</body>
</html>