<?php
// php/auditoria.php
include("../config/conexion.php");
$conexion_activa = isset($conn) ? $conn : (isset($conexion) ? $conexion : null);
$mensaje = "";

// Acciones auditables que ofrece la pantalla (lista blanca)
$acciones_permitidas = array('LOGON', 'LOGOFF', 'CREATE TABLE', 'ALTER TABLE', 'DROP TABLE', 'CREATE USER', 'ALTER USER',
                             'DROP USER', 'CREATE ROLE', 'DROP ROLE', 'GRANT', 'REVOKE');

// Texto de ayuda cuando el usuario conectado no tiene permisos de auditoría
function ayuda_permisos($err) {
    if (strpos($err, 'ORA-01031') !== false || strpos($err, 'ORA-00942') !== false) {
        return "<br><small>Falta el rol de auditoría. Ejecute como SYS: <code>GRANT AUDIT_ADMIN, AUDIT_VIEWER TO SYSTEM;</code></small>";
    }
    return '';
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $conexion_activa) {
    if (isset($_POST['crear_politica'])) {
        $nombre = strtoupper(trim($_POST['pol_nombre']));
        $usuario = strtoupper(trim($_POST['pol_usuario']));
        $elegidas = array();
        if (isset($_POST['pol_acciones']) && is_array($_POST['pol_acciones'])) {
            foreach ($_POST['pol_acciones'] as $a) {
                if (in_array($a, $acciones_permitidas, true)) {
                    $elegidas[] = $a;
                }
            }
        }
        if (!nombre_ok($nombre) || strpos($nombre, 'ORA_') === 0 || !$elegidas || ($usuario !== '' && !nombre_ok($usuario))) {
            $mensaje = ora_msg(false, "Indique un nombre de política válido (sin prefijo ORA_), al menos una acción y, si lo desea, un usuario válido.");
        } else {
            ora_script($conexion_activa);
            $ok = ora_ddl($conexion_activa, "CREATE AUDIT POLICY $nombre ACTIONS " . implode(', ', $elegidas), $err);
            if ($ok) {
                $ok = ora_ddl($conexion_activa, "AUDIT POLICY $nombre" . ($usuario !== '' ? " BY $usuario" : ""), $err);
            }
            if ($ok) {
                $mensaje = ora_msg(true, "Política de auditoría '" . h($nombre) . "' creada y habilitada" . ($usuario !== '' ? " para el usuario " . h($usuario) : " para todos los usuarios") . ".");
            } else {
                $mensaje = ora_msg(false, "Error al crear la política: " . h($err) . ayuda_permisos($err));
            }
        }
    } elseif (isset($_POST['admin_politica'])) {
        $nombre = strtoupper(trim($_POST['adm_politica']));
        $accion = isset($_POST['adm_accion']) ? $_POST['adm_accion'] : '';
        if (!nombre_ok($nombre) || strpos($nombre, 'ORA_') === 0 || !in_array($accion, array('HABILITAR', 'DESHABILITAR', 'ELIMINAR'), true)) {
            $mensaje = ora_msg(false, "Seleccione una política propia (las ORA_ de Oracle no se modifican) y una acción.");
        } else {
            ora_script($conexion_activa);
            if ($accion == 'HABILITAR') {
                $ok = ora_ddl($conexion_activa, "AUDIT POLICY $nombre", $err);
            } elseif ($accion == 'DESHABILITAR') {
                $ok = ora_ddl($conexion_activa, "NOAUDIT POLICY $nombre", $err);
            } else {
                ora_ddl($conexion_activa, "NOAUDIT POLICY $nombre", $e0);
                $ok = ora_ddl($conexion_activa, "DROP AUDIT POLICY $nombre", $err);
            }
            if ($ok) {
                $mensaje = ora_msg(true, "Política '" . h($nombre) . "': acción " . h(strtolower($accion)) . " aplicada.");
            } else {
                $mensaje = ora_msg(false, "Error: " . h($err) . ayuda_permisos($err));
            }
        }
    }
}

$errPol = '';
$politicas_propias = $conexion_activa ? ora_rows($conexion_activa, "SELECT DISTINCT POLICY_NAME FROM AUDIT_UNIFIED_POLICIES WHERE POLICY_NAME NOT LIKE 'ORA\\_%' ESCAPE '\\' ORDER BY POLICY_NAME", array(), $errPol) : array();
$politicas_propias = $politicas_propias ? $politicas_propias : array();

$habilitadas = $conexion_activa ? ora_rows($conexion_activa, "SELECT POLICY_NAME, ENABLED_OPT, ENTITY_NAME, ENTITY_TYPE, SUCCESS, FAILURE
                                                            FROM AUDIT_UNIFIED_ENABLED_POLICIES ORDER BY POLICY_NAME") : array();
$habilitadas = $habilitadas ? $habilitadas : array();

$errUni = '';
$unificada = $conexion_activa ? ora_rows($conexion_activa, "SELECT * FROM (
                    SELECT DBUSERNAME, ACTION_NAME, OBJECT_NAME, TO_CHAR(EVENT_TIMESTAMP, 'DD/MM/YYYY HH24:MI:SS') AS FECHA,
                           RETURN_CODE, UNIFIED_AUDIT_POLICIES
                    FROM UNIFIED_AUDIT_TRAIL ORDER BY EVENT_TIMESTAMP DESC
                  ) WHERE ROWNUM <= 50", array(), $errUni) : false;

$errTrad = '';
$tradicional = $conexion_activa ? ora_rows($conexion_activa, "SELECT * FROM (
                    SELECT USERNAME, ACTION_NAME, OBJ_NAME, TO_CHAR(TIMESTAMP, 'DD/MM/YYYY HH24:MI:SS') AS FECHA, RETURNCODE
                    FROM DBA_AUDIT_TRAIL ORDER BY TIMESTAMP DESC
                  ) WHERE ROWNUM <= 50", array(), $errTrad) : false;
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
        <?php echo $mensaje; ?>
        <hr style="margin: 20px 0;">

        <form method="POST" action="" class="form-card">
            <h3>Crear Política de Auditoría</h3>
            <label>Nombre de la política:</label>
            <input type="text" name="pol_nombre" required placeholder="AUD_CAMBIOS_DDL">

            <label>Acciones a auditar:</label>
            <div style="text-align:left;max-width:450px;margin:6px auto 0;display:grid;grid-template-columns:1fr 1fr;gap:4px 12px;font-size:14px;">
                <?php foreach ($acciones_permitidas as $a): ?>
                    <label style="display:block;margin:0;font-weight:normal;text-align:left;">
                        <input type="checkbox" name="pol_acciones[]" value="<?php echo h($a); ?>" style="display:inline;width:auto;margin:0 6px 0 0;"> <?php echo h($a); ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <label>Usuario a auditar (vacío = todos):</label>
            <input type="text" name="pol_usuario" placeholder="HR">
            <button type="submit" name="crear_politica" style="margin-top:15px;">Crear y Habilitar</button>
        </form>

        <form method="POST" action="" class="form-card">
            <h3>Habilitar, Deshabilitar o Eliminar una Política</h3>
            <label>Política:</label>
            <select name="adm_politica" required>
                <option value="">-- Seleccione --</option>
                <?php echo opciones($politicas_propias, 'POLICY_NAME'); ?>
            </select>
            <label>Acción:</label>
            <select name="adm_accion">
                <option value="HABILITAR">Habilitar</option>
                <option value="DESHABILITAR">Deshabilitar</option>
                <option value="ELIMINAR">Eliminar</option>
            </select>
            <button type="submit" name="admin_politica" style="margin-top:15px;">Aplicar</button>
        </form>

        <h3>Políticas de Auditoría Habilitadas</h3>
        <?php render_tabla($habilitadas, array('Política' => 'POLICY_NAME', 'Opción' => 'ENABLED_OPT', 'Entidad' => 'ENTITY_NAME', 'Tipo' => 'ENTITY_TYPE', 'Éxito' => 'SUCCESS', 'Fallo' => 'FAILURE')); ?>

        <h3 style="margin-top:30px;">Registro de Auditoría Unificada (últimos 50 eventos)</h3>
        <table border="1" class="tabla-datos">
            <tr>
                <th>Usuario</th>
                <th>Acción</th>
                <th>Objeto</th>
                <th>Fecha</th>
                <th>Código Retorno</th>
                <th>Políticas</th>
            </tr>
            <?php
            if ($unificada === false) {
                echo "<tr><td colspan='6'>No se pudo leer UNIFIED_AUDIT_TRAIL: " . h($errUni) . "</td></tr>";
            } elseif (!$unificada) {
                echo "<tr><td colspan='6'>Aún no hay eventos auditados.</td></tr>";
            } else {
                foreach ($unificada as $r) {
                    echo "<tr><td>" . h($r['DBUSERNAME']) . "</td><td>" . h($r['ACTION_NAME']) . "</td><td>" . h($r['OBJECT_NAME']) .
                         "</td><td>" . h($r['FECHA']) . "</td><td>" . h($r['RETURN_CODE']) . "</td><td>" . h($r['UNIFIED_AUDIT_POLICIES']) . "</td></tr>";
                }
            }
            ?>
        </table>

        <h3 style="margin-top:30px;">Registro de Actividad y Auditoría de Conexiones/Acciones</h3>
        <table border="1" class="tabla-datos">
            <tr>
                <th>Usuario (USERNAME)</th>
                <th>Acción (ACTION_NAME)</th>
                <th>Objeto (OBJ_NAME)</th>
                <th>Fecha (TIMESTAMP)</th>
                <th>Código Retorno (RETURNCODE)</th>
            </tr>
            <?php
            if ($tradicional === false) {
                echo "<tr><td colspan='5'>No hay registros de auditoría activos o se requiere validar el parámetro AUDIT_TRAIL.</td></tr>";
            } elseif (!$tradicional) {
                echo "<tr><td colspan='5'>Sin registros en la auditoría tradicional (se usa la auditoría unificada de arriba).</td></tr>";
            } else {
                foreach ($tradicional as $r) {
                    echo "<tr><td>" . h($r['USERNAME']) . "</td><td>" . h($r['ACTION_NAME']) . "</td><td>" . h($r['OBJ_NAME']) .
                         "</td><td>" . h($r['FECHA']) . "</td><td>" . h($r['RETURNCODE']) . "</td></tr>";
                }
            }
            ?>
        </table>
    </div>
</body>
</html>