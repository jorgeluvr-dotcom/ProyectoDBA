<?php
// php/seguridad.php
include("../config/conexion.php");
$conexion_activa = isset($conn) ? $conn : (isset($conexion) ? $conexion : null);
$mensaje = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['crear_profile'])) {
    $nombre_prof = strtoupper(trim($_POST['profile_name']));
    $idle = intval($_POST['idle_time']);
    $intentos = intval($_POST['failed_attempts']);

    // Se requiere habilitar _oracle_script para creaciones administrativas en XE
    $stid_script = oci_parse($conexion_activa, "ALTER SESSION SET \"_ORACLE_SCRIPT\"=TRUE");
    @oci_execute($stid_script);

    $sql = "CREATE PROFILE $nombre_prof LIMIT IDLE_TIME $idle FAILED_LOGIN_ATTEMPTS $intentos";
    $stid = oci_parse($conexion_activa, $sql);
    if (@oci_execute($stid)) {
        $mensaje = "<div class='alert success'>Perfil '$nombre_prof' creado exitosamente.</div>";
    } else {
        $e = oci_error($stid);
        $mensaje = "<div class='alert error'>Error al crear perfil: " . htmlentities($e['message']) . "</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Seguridad y Perfiles</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="container">
        <h2>Gestión de Seguridad y Perfiles (Profiles)</h2>
        <a href="../index.php" class="btn-volver">← Volver al Menú Principal</a>
        <?php echo $mensaje; ?>

        <form method="POST" action="" class="form-card" style="margin-top:20px;">
            <h3>Crear Perfil de Usuario</h3>
            <label>Nombre del Perfil:</label>
            <input type="text" name="profile_name" required placeholder="APP_USER_PROFILE">

            <label>Tiempo Inactivo Máximo (Minutos - IDLE_TIME):</label>
            <input type="number" name="idle_time" value="30" required>

            <label>Intentos Fallidos de Inicio de Sesión:</label>
            <input type="number" name="failed_attempts" value="3" required>

            <button type="submit" name="crear_profile" style="margin-top:15px;">Crear Perfil</button>
        </form>

        <h3 style="margin-top:30px;">Perfiles Registrados en el Sistema</h3>
        <table border="1" class="tabla-datos">
            <tr>
                <th>Perfil</th>
                <th>Recurso / Parámetro</th>
                <th>Valor Limite</th>
            </tr>
            <?php
            if ($conexion_activa) {
                $q = "SELECT PROFILE, RESOURCE_NAME, LIMIT FROM DBA_PROFILES WHERE RESOURCE_NAME IN ('IDLE_TIME', 'FAILED_LOGIN_ATTEMPTS') ORDER BY PROFILE";
                $st = oci_parse($conexion_activa, $q);
                @oci_execute($st);
                while ($r = oci_fetch_assoc($st)) {
                    echo "<tr>";
                    echo "<td>" . $r['PROFILE'] . "</td>";
                    echo "<td>" . $r['RESOURCE_NAME'] . "</td>";
                    echo "<td>" . $r['LIMIT'] . "</td>";
                    echo "</tr>";
                }
            }
            ?>
        </table>
    </div>
</body>
</html>