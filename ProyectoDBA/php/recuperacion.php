<?php
// php/recuperacion.php
include("../config/conexion.php");
$conexion_activa = isset($conn) ? $conn : (isset($conexion) ? $conexion : null);

$mensaje = "";
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ejecutar_recuperacion'])) {
    $archivo_dmp = trim($_POST['archivo_dmp']);
    $directorio = trim($_POST['directorio']);
    
    // Comando Data Pump para importación y recuperación (impdp)
    $comando_import = "impdp system/password@XE DIRECTORY=$directorio DUMPFILE=$archivo_dmp LOGFILE=import.log";
    
    $mensaje = "<div class='alert success'>Instrucción de recuperación lista. Comando Data Pump (impdp) recomendado para ejecutar en servidor: <br><code>$comando_import</code></div>";
}
?>
<!DOCTYPE html>
<?php
// php/recuperacion.php
include("../config/conexion.php");
$conexion_activa = isset($conn) ? $conn : (isset($conexion) ? $conexion : null);
$mensaje = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ejecutar_import'])) {
    $directorio = trim($_POST['directorio']);
    $dumpfile = trim($_POST['dumpfile']);
    $esquema = trim($_POST['esquema_destino']);

    $comando_impdp = "impdp system/password@XE DIRECTORY=$directorio DUMPFILE=$dumpfile SCHEMAS=$esquema TABLE_EXISTS_ACTION=REPLACE";
    $mensaje = "<div class='alert success'>Instrucción de Recuperación Data Pump generada con éxito: <br><code>$comando_impdp</code></div>";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Recuperación de Respaldos</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="container">
        <h2>Recuperación de Respaldos (Data Pump IMPDP)</h2>
        <a href="../index.php" class="btn-volver">← Volver al Menú Principal</a>
        <?php echo $mensaje; ?>

        <form method="POST" action="" class="form-card" style="margin-top:20px;">
            <h3>Asistente de Importación / Restauración</h3>
            <label>Directorio Oracle (DIRECTORY):</label>
            <select name="directorio" required>
                <option value="">-- Seleccione un Directorio --</option>
                <?php
                if ($conexion_activa) {
                    $q_dir = "SELECT DIRECTORY_NAME FROM DBA_DIRECTORIES";
                    $st_dir = oci_parse($conexion_activa, $q_dir);
                    @oci_execute($st_dir);
                    while ($r_dir = oci_fetch_assoc($st_dir)) {
                        echo "<option value='".$r_dir['DIRECTORY_NAME']."'>".$r_dir['DIRECTORY_NAME']."</option>";
                    }
                }
                ?>
            </select>

            <label style="margin-top:15px;">Nombre del archivo de volcado (.dmp):</label>
            <input type="text" name="dumpfile" required placeholder="schema_coop.dmp">

            <label style="margin-top:15px;">Esquema Destino:</label>
            <input type="text" name="esquema_destino" required placeholder="COOPERATIVA">

            <button type="submit" name="ejecutar_import" style="margin-top:20px;">Generar Comando de Recuperación</button>
        </form>
    </div>
</body>
</html>