<?php
// php/seguridad.php
include("../config/conexion.php");
$conexion_activa = isset($conn) ? $conn : (isset($conexion) ? $conexion : null);
$mensaje = "";

// Roles y privilegios que ofrece la pantalla (lista blanca)
$roles_permitidos = array('CONNECT', 'RESOURCE', 'DBA');
$privs_permitidos = array('CREATE SESSION', 'CREATE TABLE', 'CREATE VIEW', 'CREATE SEQUENCE', 'CREATE PROCEDURE',
                          'CREATE TRIGGER', 'UNLIMITED TABLESPACE', 'SELECT ANY TABLE', 'CREATE ANY DIRECTORY');

// La contraseña va entre comillas dobles: se rechaza si las trae o si tiene caracteres de control.
function clave_ok($p) {
    return is_string($p) && strlen($p) >= 1 && strlen($p) <= 128 && preg_match('/^[^"\x00-\x1F]+$/', $p) === 1;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $conexion_activa) {
    // Se requiere habilitar _oracle_script para creaciones administrativas en XE
    ora_script($conexion_activa);

    if (isset($_POST['crear_profile'])) {
        $nombre_prof = strtoupper(trim($_POST['profile_name']));
        $idle = intval($_POST['idle_time']);
        $intentos = intval($_POST['failed_attempts']);

        if (!nombre_ok($nombre_prof)) {
            $mensaje = ora_msg(false, "Nombre de perfil no válido.");
        } elseif (ora_ddl($conexion_activa, "CREATE PROFILE $nombre_prof LIMIT IDLE_TIME $idle FAILED_LOGIN_ATTEMPTS $intentos", $err)) {
            $mensaje = ora_msg(true, "Perfil '" . h($nombre_prof) . "' creado exitosamente.");
        } else {
            $mensaje = ora_msg(false, "Error al crear perfil: " . h($err));
        }

    } elseif (isset($_POST['crear_usuario'])) {
        $usuario = strtoupper(trim($_POST['us_nombre']));
        $clave = $_POST['us_clave'];
        $ts = strtoupper(trim($_POST['us_ts']));
        $tmp = strtoupper(trim($_POST['us_tmp']));
        $perfil = strtoupper(trim($_POST['us_perfil']));

        if (!nombre_ok($usuario) || !clave_ok($clave) || !nombre_ok($ts) || !nombre_ok($tmp) || !nombre_ok($perfil)) {
            $mensaje = ora_msg(false, "Revise los datos: usuario, contraseña (sin comillas dobles), tablespaces y perfil.");
        } else {
            $sql = "CREATE USER $usuario IDENTIFIED BY \"$clave\" DEFAULT TABLESPACE $ts TEMPORARY TABLESPACE $tmp PROFILE $perfil QUOTA UNLIMITED ON $ts";
            $ok = ora_ddl($conexion_activa, $sql, $err);
            if ($ok && isset($_POST['us_sesion'])) {
                $ok = ora_ddl($conexion_activa, "GRANT CREATE SESSION TO $usuario", $err);
            }
            $mensaje = $ok ? ora_msg(true, "Usuario '" . h($usuario) . "' creado correctamente.")
                           : ora_msg(false, "Error al crear usuario: " . h($err));
        }

    } elseif (isset($_POST['admin_usuario'])) {
        $usuario = strtoupper(trim($_POST['adm_usuario']));
        $accion = isset($_POST['adm_accion']) ? $_POST['adm_accion'] : '';
        $clave = isset($_POST['adm_clave']) ? $_POST['adm_clave'] : '';
        $perfil = strtoupper(trim(isset($_POST['adm_perfil']) ? $_POST['adm_perfil'] : ''));
        $sql = '';
        $texto = '';

        if (!nombre_ok($usuario)) {
            $mensaje = ora_msg(false, "Seleccione un usuario.");
        } else {
            switch ($accion) {
                case 'LOCK':
                    $sql = "ALTER USER $usuario ACCOUNT LOCK";
                    $texto = 'bloqueado';
                    break;
                case 'UNLOCK':
                    $sql = "ALTER USER $usuario ACCOUNT UNLOCK";
                    $texto = 'desbloqueado';
                    break;
                case 'EXPIRE':
                    $sql = "ALTER USER $usuario PASSWORD EXPIRE";
                    $texto = 'con contraseña expirada';
                    break;
                case 'PASSWORD':
                    if (clave_ok($clave)) {
                        $sql = "ALTER USER $usuario IDENTIFIED BY \"$clave\"";
                        $texto = 'con contraseña cambiada';
                    }
                    break;
                case 'PROFILE':
                    if (nombre_ok($perfil)) {
                        $sql = "ALTER USER $usuario PROFILE $perfil";
                        $texto = "asignado al perfil " . $perfil;
                    }
                    break;
                case 'DROP':
                    $sql = "DROP USER $usuario CASCADE";
                    $texto = 'eliminado';
                    break;
            }
            if ($sql === '') {
                $mensaje = ora_msg(false, "Falta información para la acción elegida (contraseña nueva o perfil).");
            } elseif (ora_ddl($conexion_activa, $sql, $err)) {
                $mensaje = ora_msg(true, "Usuario '" . h($usuario) . "' " . h($texto) . " correctamente.");
            } else {
                $mensaje = ora_msg(false, "Error: " . h($err));
            }
        }

    } elseif (isset($_POST['privilegio'])) {
        $usuario = strtoupper(trim($_POST['pr_usuario']));
        $priv = isset($_POST['pr_priv']) ? $_POST['pr_priv'] : '';
        $accion = isset($_POST['pr_accion']) ? $_POST['pr_accion'] : '';
        $permitido = in_array($priv, $roles_permitidos, true) || in_array($priv, $privs_permitidos, true);

        if (!nombre_ok($usuario) || !$permitido || !in_array($accion, array('GRANT', 'REVOKE'), true)) {
            $mensaje = ora_msg(false, "Seleccione usuario, privilegio y acción.");
        } else {
            $sql = ($accion == 'GRANT') ? "GRANT $priv TO $usuario" : "REVOKE $priv FROM $usuario";
            if (ora_ddl($conexion_activa, $sql, $err)) {
                $mensaje = ora_msg(true, ($accion == 'GRANT' ? "Se otorgó " : "Se revocó ") . h($priv) . ($accion == 'GRANT' ? " a " : " de ") . h($usuario) . ".");
            } else {
                $mensaje = ora_msg(false, "Error: " . h($err));
            }
        }
    }
}

// Datos para listas y tablas
$usuarios = $conexion_activa ? ora_rows($conexion_activa, "SELECT USERNAME, ACCOUNT_STATUS, PROFILE, DEFAULT_TABLESPACE, TEMPORARY_TABLESPACE,
                                                                 TO_CHAR(CREATED, 'DD/MM/YYYY') AS CREADO
                                                          FROM DBA_USERS WHERE ORACLE_MAINTAINED = 'N' ORDER BY USERNAME") : array();
$usuarios = $usuarios ? $usuarios : array();
$ts_datos = $conexion_activa ? ora_rows($conexion_activa, "SELECT TABLESPACE_NAME FROM DBA_TABLESPACES WHERE CONTENTS = 'PERMANENT' ORDER BY TABLESPACE_NAME") : array();
$ts_temp = $conexion_activa ? ora_rows($conexion_activa, "SELECT TABLESPACE_NAME FROM DBA_TABLESPACES WHERE CONTENTS = 'TEMPORARY' ORDER BY TABLESPACE_NAME") : array();
$perfiles = $conexion_activa ? ora_rows($conexion_activa, "SELECT DISTINCT PROFILE FROM DBA_PROFILES ORDER BY PROFILE") : array();
$otorgados = $conexion_activa ? ora_rows($conexion_activa, "SELECT GRANTEE, GRANTED_ROLE AS OTORGADO, 'ROL' AS TIPO FROM DBA_ROLE_PRIVS
                                                           WHERE GRANTEE IN (SELECT USERNAME FROM DBA_USERS WHERE ORACLE_MAINTAINED = 'N')
                                                           UNION ALL
                                                           SELECT GRANTEE, PRIVILEGE, 'PRIVILEGIO' FROM DBA_SYS_PRIVS
                                                           WHERE GRANTEE IN (SELECT USERNAME FROM DBA_USERS WHERE ORACLE_MAINTAINED = 'N')
                                                           ORDER BY 1, 3, 2") : array();
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

        <form method="POST" action="" class="form-card">
            <h3>Crear Usuario</h3>
            <label>Nombre de usuario:</label>
            <input type="text" name="us_nombre" required placeholder="USUARIO1">
            <label>Contraseña:</label>
            <input type="password" name="us_clave" required>
            <label>Tablespace por defecto:</label>
            <select name="us_ts" required><?php echo opciones($ts_datos ? $ts_datos : array(), 'TABLESPACE_NAME', 'USERS'); ?></select>
            <label>Tablespace temporal:</label>
            <select name="us_tmp" required><?php echo opciones($ts_temp ? $ts_temp : array(), 'TABLESPACE_NAME', 'TEMP'); ?></select>
            <label>Perfil:</label>
            <select name="us_perfil" required><?php echo opciones($perfiles ? $perfiles : array(), 'PROFILE', 'DEFAULT'); ?></select>
            <label style="font-weight:normal;"><input type="checkbox" name="us_sesion" value="1" checked style="display:inline;width:auto;margin:0 6px 0 0;">Otorgar CREATE SESSION</label>
            <button type="submit" name="crear_usuario" style="margin-top:15px;">Crear Usuario</button>
        </form>

        <form method="POST" action="" class="form-card"
              onsubmit="if (this.adm_accion.value === 'DROP') { return confirm('Se eliminará el usuario y todos sus objetos. ¿Continuar?'); } return true;">
            <h3>Administrar Usuario</h3>
            <label>Usuario:</label>
            <select name="adm_usuario" required>
                <option value="">-- Seleccione --</option>
                <?php echo opciones($usuarios, 'USERNAME'); ?>
            </select>
            <label>Acción:</label>
            <select name="adm_accion">
                <option value="LOCK">Bloquear cuenta</option>
                <option value="UNLOCK">Desbloquear cuenta</option>
                <option value="EXPIRE">Expirar contraseña</option>
                <option value="PASSWORD">Cambiar contraseña</option>
                <option value="PROFILE">Asignar perfil</option>
                <option value="DROP">Eliminar usuario</option>
            </select>
            <label>Nueva contraseña (solo para "Cambiar contraseña"):</label>
            <input type="password" name="adm_clave">
            <label>Perfil (solo para "Asignar perfil"):</label>
            <select name="adm_perfil">
                <option value="">-- Seleccione --</option>
                <?php echo opciones($perfiles ? $perfiles : array(), 'PROFILE'); ?>
            </select>
            <button type="submit" name="admin_usuario" style="margin-top:15px;">Aplicar</button>
        </form>

        <form method="POST" action="" class="form-card">
            <h3>Otorgar o Revocar Privilegios / Roles</h3>
            <label>Usuario:</label>
            <select name="pr_usuario" required>
                <option value="">-- Seleccione --</option>
                <?php echo opciones($usuarios, 'USERNAME'); ?>
            </select>
            <label>Privilegio o Rol:</label>
            <select name="pr_priv" required>
                <optgroup label="Roles">
                    <?php foreach ($roles_permitidos as $p) { echo "<option value='" . h($p) . "'>" . h($p) . "</option>"; } ?>
                </optgroup>
                <optgroup label="Privilegios de sistema">
                    <?php foreach ($privs_permitidos as $p) { echo "<option value='" . h($p) . "'>" . h($p) . "</option>"; } ?>
                </optgroup>
            </select>
            <label>Acción:</label>
            <select name="pr_accion">
                <option value="GRANT">Otorgar (GRANT)</option>
                <option value="REVOKE">Revocar (REVOKE)</option>
            </select>
            <button type="submit" name="privilegio" style="margin-top:15px;">Aplicar</button>
        </form>

        <h3 style="margin-top:30px;">Usuarios de la Base de Datos</h3>
        <?php render_tabla($usuarios, array('Usuario' => 'USERNAME', 'Estado' => 'ACCOUNT_STATUS', 'Perfil' => 'PROFILE', 'Tablespace' => 'DEFAULT_TABLESPACE', 'Temporal' => 'TEMPORARY_TABLESPACE', 'Creado' => 'CREADO')); ?>

        <h3 style="margin-top:30px;">Roles y Privilegios Otorgados</h3>
        <?php render_tabla($otorgados ? $otorgados : array(), array('Usuario' => 'GRANTEE', 'Tipo' => 'TIPO', 'Otorgado' => 'OTORGADO')); ?>

        <h3 style="margin-top:30px;">Perfiles Registrados en el Sistema</h3>
        <table border="1" class="tabla-datos">
            <tr>
                <th>Perfil</th>
                <th>Recurso / Parámetro</th>
                <th>Valor Limite</th>
            </tr>
            <?php
            if ($conexion_activa) {
                $q = ora_rows($conexion_activa, "SELECT PROFILE, RESOURCE_NAME, LIMIT FROM DBA_PROFILES WHERE RESOURCE_NAME IN ('IDLE_TIME', 'FAILED_LOGIN_ATTEMPTS') ORDER BY PROFILE");
                if ($q) {
                    foreach ($q as $r) {
                        echo "<tr>";
                        echo "<td>" . h($r['PROFILE']) . "</td>";
                        echo "<td>" . h($r['RESOURCE_NAME']) . "</td>";
                        echo "<td>" . h($r['LIMIT']) . "</td>";
                        echo "</tr>";
                    }
                }
            }
            ?>
        </table>
    </div>
</body>
</html>