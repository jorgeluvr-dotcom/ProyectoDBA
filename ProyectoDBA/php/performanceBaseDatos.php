<?php
// php/performanceBaseDatos.php
include("../config/conexion.php");
$conexion_activa = isset($conn) ? $conn : (isset($conexion) ? $conexion : null);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Performance de la Base de Datos</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="container">
        <h2>Performance de la Base de Datos (Oracle 21c XE)</h2>
        <a href="../index.php" class="btn-volver">← Volver al Menú Principal</a>
        <hr style="margin: 20px 0;">

        <h3>Métricas de la Instancia (V$INSTANCE)</h3>
        <table border="1" class="tabla-datos">
            <tr>
                <th>Nombre de Instancia</th>
                <th>Estado (Status)</th>
                <th>Modo (Database Status)</th>
                <th>Tiempo de Inicio</th>
            </tr>
            <?php
            if ($conexion_activa) {
                $query = "SELECT INSTANCE_NAME, STATUS, DATABASE_STATUS, TO_CHAR(STARTUP_TIME, 'DD/MM/YYYY HH24:MI:SS') AS STARTUP FROM V\$INSTANCE";
                $stid = oci_parse($conexion_activa, $query);
                @oci_execute($stid);
                while ($row = oci_fetch_assoc($stid)) {
                    echo "<tr>";
                    echo "<td>" . $row['INSTANCE_NAME'] . "</td>";
                    echo "<td>" . $row['STATUS'] . "</td>";
                    echo "<td>" . $row['DATABASE_STATUS'] . "</td>";
                    echo "<td>" . $row['STARTUP'] . "</td>";
                    echo "</tr>";
                }
            }
            ?>
        </table>

        <h3>Estadísticas del Sistema (V$SYSSTAT)</h3>
        <table border="1" class="tabla-datos">
            <tr>
                <th>Nombre de Estadística</th>
                <th>Valor Actual</th>
            </tr>
            <?php
            if ($conexion_activa) {
                $query_stats = "SELECT NAME, VALUE FROM V\$SYSSTAT WHERE ROWNUM <= 15";
                $stid_stats = oci_parse($conexion_activa, $query_stats);
                @oci_execute($stid_stats);
                while ($row = oci_fetch_assoc($stid_stats)) {
                    echo "<tr>";
                    echo "<td>" . $row['NAME'] . "</td>";
                    echo "<td>" . number_format($row['VALUE']) . "</td>";
                    echo "</tr>";
                }
            }
            ?>
        </table>
    </div>
</body>
</html>