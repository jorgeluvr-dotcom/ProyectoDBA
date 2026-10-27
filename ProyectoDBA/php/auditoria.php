<?php
// php/auditoria.php
include("../config/conexion.php");
$conexion_activa = isset($conn) ? $conn : (isset($conexion) ? $conexion : null);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Auditoría de la Base de Datos</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="container">
        <h2>Auditoría de la Base de Datos (DBA_AUDIT_TRAIL)</h2>
        <a href="../index.php" class="btn-volver">← Volver al Menú Principal</a>
        <hr style="margin: 20px 0;">

        <h3>Registro de Actividad y Auditoría de Conexiones/Acciones</h3>
        <table border="1" class="tabla-datos">
            <tr>
                <th>Usuario (USERNAME)</th>
                <th>Acción (ACTION_NAME)</th>
                <th>Objeto (OBJ_NAME)</th>
                <th>Fecha (TIMESTAMP)</th>
                <th>Código Retorno (RETURNCODE)</th>
            </tr>
            <?php
            if ($conexion_activa) {
                $q = "SELECT USERNAME, ACTION_NAME, OBJ_NAME, TO_CHAR(TIMESTAMP, 'DD/MM/YYYY HH24:MI:SS') AS FECHA, RETURNCODE 
                      FROM DBA_AUDIT_TRAIL 
                      WHERE ROWNUM <= 50 
                      ORDER BY TIMESTAMP DESC";
                $st = oci_parse($conexion_activa, $q);
                if (@oci_execute($st)) {
                    while ($r = oci_fetch_assoc($st)) {
                        echo "<tr>";
                        echo "<td>" . htmlspecialchars($r['USERNAME']) . "</td>";
                        echo "<td>" . htmlspecialchars($r['ACTION_NAME']) . "</td>";
                        echo "<td>" . htmlspecialchars($r['OBJ_NAME']) . "</td>";
                        echo "<td>" . htmlspecialchars($r['FECHA']) . "</td>";
                        echo "<td>" . htmlspecialchars($r['RETURNCODE']) . "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='5'>No hay registros de auditoría activos o se requiere validar el parámetro AUDIT_TRAIL.</td></tr>";
                }
                @oci_free_statement($st);
            }
            ?>
        </table>
    </div>
</body>
</html>