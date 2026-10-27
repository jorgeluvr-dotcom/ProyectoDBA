<?php
// php/directorios.php
include("../config/conexion.php");
$conexion_activa = isset($conn) ? $conn : (isset($conexion) ? $conexion : null);
$mensaje = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['crear_dir'])) {
    $nombre_dir = strtoupper(trim($_POST['dir_nombre']));
    $ruta_dir = trim($_POST['dir_ruta']);

    $sql = "CREATE OR REPLACE DIRECTORY $nombre_dir AS '$ruta_dir'";
    $stid = oci_parse($conexion_activa, $sql);
    if (@oci_execute($stid)) {
        $sql_grant = "GRANT READ, WRITE ON DIRECTORY $nombre_dir TO PUBLIC";
        $stid_g = oci_parse($conexion_activa, $sql_grant);
        @oci_execute($stid_g);
        
        $mensaje = "<div class='alert success'>Directorio Oracle '$nombre_dir' creado y con permisos otorgados correctamente.</div>";
    } else {
        $e = oci_error($stid);
        $mensaje = "<div class='alert error'>Error: " . htmlentities($e['message']) . "</div>";
    }
    @oci_free_statement($stid);
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

        <h3 style="margin-top:30px;">Directorios Registrados en la Base de Datos</h3>
        <table border="1" class="tabla-datos">
            <tr>
                <th>Nombre del Directorio</th>
                <th>Ruta Física (Directory Path)</th>
            </tr>
            <?php
            if ($conexion_activa) {
                $q = "SELECT DIRECTORY_NAME, DIRECTORY_PATH FROM DBA_DIRECTORIES ORDER BY DIRECTORY_NAME";
                $st = oci_parse($conexion_activa, $q);
                if (@oci_execute($st)) {
                    while ($r = oci_fetch_assoc($st)) {
                        echo "<tr>";
                        echo "<td>" . htmlspecialchars($r['DIRECTORY_NAME']) . "</td>";
                        echo "<td>" . htmlspecialchars($r['DIRECTORY_PATH']) . "</td>";
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