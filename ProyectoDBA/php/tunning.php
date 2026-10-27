<?php
// php/tunning.php
include("../config/conexion.php");
$conexion_activa = isset($conn) ? $conn : (isset($conexion) ? $conexion : null);
$mensaje = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['recalcular_stats'])) {
        $esquema = strtoupper(trim($_POST['esquema_stats']));
        $sql = "BEGIN DBMS_STATS.GATHER_SCHEMA_STATS(ownname => '$esquema', options => 'GATHER AUTO'); END;";
        $stid = oci_parse($conexion_activa, $sql);
        if (@oci_execute($stid)) {
            $mensaje = "<div class='alert success'>Estadísticas recalculadas con éxito para el esquema '$esquema'.</div>";
        } else {
            $e = oci_error($stid);
            $mensaje = "<div class='alert error'>Error: " . htmlentities($e['message']) . "</div>";
        }
        @oci_free_statement($stid);
    } elseif (isset($_POST['crear_indice'])) {
        $esquema = strtoupper(trim($_POST['esquema_idx']));
        $tabla = strtoupper(trim($_POST['tabla_idx']));
        $columna = strtoupper(trim($_POST['columna_idx']));
        $nombre_idx = "IDX_" . $tabla . "_" . $columna;

        $sql = "CREATE INDEX $esquema.$nombre_idx ON $esquema.$tabla($columna)";
        $stid = oci_parse($conexion_activa, $sql);
        if (@oci_execute($stid)) {
            $mensaje = "<div class='alert success'>Índice '$nombre_idx' creado correctamente en $esquema.$tabla($columna).</div>";
        } else {
            $e = oci_error($stid);
            $mensaje = "<div class='alert error'>Error al crear índice: " . htmlentities($e['message']) . "</div>";
        }
        @oci_free_statement($stid);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Tuning, Estadísticas e Índices</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="container">
        <h2>Tuning de Consultas, Estadísticas e Índices</h2>
        <a href="../index.php" class="btn-volver">← Volver al Menú Principal</a>
        <?php echo $mensaje; ?>

        <div style="display: flex; gap: 20px; margin-top: 20px;">
            <form method="POST" action="" class="form-card" style="flex: 1;">
                <h3>Recalcular Estadísticas (DBMS_STATS)</h3>
                <label>Nombre del Esquema:</label>
                <input type="text" name="esquema_stats" required placeholder="HR">
                <button type="submit" name="recalcular_stats" style="margin-top:15px;">Ejecutar GATHER_SCHEMA_STATS</button>
            </form>

            <form method="POST" action="" class="form-card" style="flex: 1;">
                <h3>Crear Índice (Evitar Full Table Scan)</h3>
                <label>Esquema:</label>
                <input type="text" name="esquema_idx" required placeholder="HR">
                <label>Tabla:</label>
                <input type="text" name="tabla_idx" required placeholder="EMPLOYEES">
                <label>Columna (Foreign Key / Filtro):</label>
                <input type="text" name="columna_idx" required placeholder="DEPARTMENT_ID">
                <button type="submit" name="crear_indice" style="margin-top:15px;">Crear Índice</button>
            </form>
        </div>

        <h3 style="margin-top:30px;">Índices Personalizados en el Sistema</h3>
        <table border="1" class="tabla-datos">
            <tr>
                <th>Propietario</th>
                <th>Nombre del Índice</th>
                <th>Tabla Asociada</th>
                <th>Tipo</th>
            </tr>
            <?php
            if ($conexion_activa) {
                $q = "SELECT OWNER, INDEX_NAME, TABLE_NAME, INDEX_TYPE FROM DBA_INDEXES WHERE OWNER NOT IN ('SYS','SYSTEM') AND ROWNUM <= 30 ORDER BY TABLE_NAME";
                $st = oci_parse($conexion_activa, $q);
                if (@oci_execute($st)) {
                    while ($r = oci_fetch_assoc($st)) {
                        echo "<tr>";
                        echo "<td>" . htmlspecialchars($r['OWNER']) . "</td>";
                        echo "<td>" . htmlspecialchars($r['INDEX_NAME']) . "</td>";
                        echo "<td>" . htmlspecialchars($r['TABLE_NAME']) . "</td>";
                        echo "<td>" . htmlspecialchars($r['INDEX_TYPE']) . "</td>";
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