<?php
// php/respaldos.php
include("../config/conexion.php");
$conexion_activa = isset($conn) ? $conn : (isset($conexion) ? $conexion : null);

$mensaje = "";
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generar_respaldo'])) {
    $tipo = $_POST['tipo_respaldo'];
    $directorio = trim($_POST['directorio']);
    
    if ($tipo == 'FULL') {
        $comando_sugerido = "expdp system/password@XE DIRECTORY=$directorio DUMPFILE=full_%U.dmp LOGFILE=full.log FULL=y";
    } elseif ($tipo == 'SCHEMA') {
        $esquema = trim($_POST['schema_param']);
        $comando_sugerido = "expdp system/password@XE DIRECTORY=$directorio DUMPFILE=schema_$esquema.dmp LOGFILE=schema_$esquema.log SCHEMAS=$esquema";
    } elseif ($tipo == 'TABLE') {
        $esquema = trim($_POST['schema_tabla_param']);
        $tabla = trim($_POST['tabla_param']);
        $objeto_tabla = "$esquema.$tabla";
        $comando_sugerido = "expdp system/password@XE DIRECTORY=$directorio DUMPFILE=table_{$esquema}_{$tabla}.dmp LOGFILE=table_{$esquema}_{$tabla}.log TABLES=$objeto_tabla";
    }
    
    $mensaje = "<div class='alert success'>Comando Data Pump generado con éxito para ejecución en servidor: <br><code>$comando_sugerido</code></div>";
}
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
    </script>
</head>
<body>
<?php
if (isset($_GET['ajax_schema']) && $conexion_activa) {
    $schema_Ajax = strtoupper(trim($_GET['ajax_schema']));
    $q_tab = "SELECT TABLE_NAME FROM DBA_TABLES WHERE OWNER = :schema ORDER BY TABLE_NAME";
    $st_tab = oci_parse($conexion_activa, $q_tab);
    oci_bind_by_name($st_tab, ":schema", $schema_Ajax);
    @oci_execute($st_tab);
    
    $options = '<option value="">-- Seleccione una Tabla específica --</option>';
    while ($rt = oci_fetch_assoc($st_tab)) {
        $options .= "<option value='".$rt['TABLE_NAME']."'>".$rt['TABLE_NAME']."</option>";
    }
    echo $options;
    exit;
}
?>
    <div class="container">
        <h2>Creación de Respaldos (Data Pump EXPDP)</h2>
        <a href="../index.php" class="btn-volver">← Volver al Menú Principal</a>
        <?php echo $mensaje; ?>

        <form method="POST" action="" class="form-card">
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
                    <?php
                    if ($conexion_activa) {
                        $q_sch = "SELECT USERNAME FROM DBA_USERS WHERE USERNAME NOT IN ('SYS','SYSTEM','ANONYMOUS','CTXSYS','DBSNMP','MDSYS','OLAPSYS','ORDSYS','OUTLN','XDB') ORDER BY USERNAME";
                        $st_sch = oci_parse($conexion_activa, $q_sch);
                        @oci_execute($st_sch);
                        while ($r = oci_fetch_assoc($st_sch)) {
                            echo "<option value='".$r['USERNAME']."'>".$r['USERNAME']."</option>";
                        }
                    }
                    ?>
                </select>
            </div>

            <div id="seccion_tabla_esquema" style="display: none; margin-top: 15px;">
                <label>Esquema Propietario:</label>
                <select name="schema_tabla_param" id="schema_tabla_param" onchange="cargarTablasDelEsquema()">
                    <option value="">-- Seleccione el Esquema --</option>
                    <?php
                    if ($conexion_activa) {
                        @oci_execute($st_sch);
                        while ($r_t = oci_fetch_assoc($st_sch)) {
                            echo "<option value='".$r_t['USERNAME']."'>".$r_t['USERNAME']."</option>";
                        }
                    }
                    ?>
                </select>
                <label style="margin-top:10px;">Tabla Específica:</label>
                <select name="tabla_param" id="tabla_param">
                    <option value="">-- Primero seleccione un esquema --</option>
                </select>
            </div>

            <label style="margin-top: 15px;">Directorio Oracle (DIRECTORY):</label>
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
            <button type="submit" name="generar_respaldo" style="margin-top: 20px;">Generar Respaldo</button>
        </form>
    </div>
</body>
</html>