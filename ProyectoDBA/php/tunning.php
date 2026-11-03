<?php
// php/tunning.php
include("../config/conexion.php");
$conexion_activa = isset($conn) ? $conn : (isset($conexion) ? $conexion : null);
$mensaje = "";
$plan_texto = "";
$sql_plan_enviado = "";
$esquema_consulta = isset($_REQUEST['esquema_consulta']) ? strtoupper(trim($_REQUEST['esquema_consulta'])) : '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $conexion_activa) {
    if (isset($_POST['recalcular_stats'])) {
        $esquema = strtoupper(trim($_POST['esquema_stats']));
        $tabla = strtoupper(trim(isset($_POST['tabla_stats']) ? $_POST['tabla_stats'] : ''));

        if (!nombre_ok($esquema) || ($tabla !== '' && !nombre_ok($tabla))) {
            $mensaje = ora_msg(false, "Esquema o tabla no válidos.");
        } else {
            if ($tabla === '') {
                $sql = "BEGIN DBMS_STATS.GATHER_SCHEMA_STATS(ownname => :esq, options => 'GATHER AUTO'); END;";
            } else {
                $sql = "BEGIN DBMS_STATS.GATHER_TABLE_STATS(ownname => :esq, tabname => :tab); END;";
            }
            $stid = oci_parse($conexion_activa, $sql);
            oci_bind_by_name($stid, ':esq', $esquema);
            if ($tabla !== '') {
                oci_bind_by_name($stid, ':tab', $tabla);
            }
            if (@oci_execute($stid)) {
                $mensaje = ora_msg(true, "Estadísticas recalculadas con éxito para " . ($tabla === '' ? "el esquema '" . h($esquema) . "'" : "la tabla '" . h($esquema) . "." . h($tabla) . "'") . ".");
                $esquema_consulta = $esquema;
            } else {
                $e = oci_error($stid);
                $mensaje = ora_msg(false, "Error: " . h($e['message']));
            }
            @oci_free_statement($stid);
        }
    } elseif (isset($_POST['crear_indice'])) {
        $esquema = strtoupper(trim($_POST['esquema_idx']));
        $tabla = strtoupper(trim($_POST['tabla_idx']));
        $columna = strtoupper(trim($_POST['columna_idx']));
        $nombre_idx = "IDX_" . $tabla . "_" . $columna;

        if (!nombre_ok($esquema) || !nombre_ok($tabla) || !nombre_ok($columna)) {
            $mensaje = ora_msg(false, "Esquema, tabla o columna no válidos.");
        } else {
            $sql = "CREATE INDEX $esquema.$nombre_idx ON $esquema.$tabla($columna)";
            if (ora_ddl($conexion_activa, $sql, $err)) {
                $mensaje = ora_msg(true, "Índice '" . h($nombre_idx) . "' creado correctamente en " . h("$esquema.$tabla($columna)") . ".");
            } else {
                $mensaje = ora_msg(false, "Error al crear índice: " . h($err));
            }
        }
    } elseif (isset($_POST['admin_indice'])) {
        $esquema = strtoupper(trim($_POST['esquema_adm']));
        $indice = strtoupper(trim($_POST['indice_adm']));
        $accion = isset($_POST['accion_adm']) ? $_POST['accion_adm'] : '';

        if (!nombre_ok($esquema) || !nombre_ok($indice) || !in_array($accion, array('REBUILD', 'DROP'), true)) {
            $mensaje = ora_msg(false, "Indique esquema, índice y acción válidos.");
        } else {
            $sql = ($accion == 'DROP') ? "DROP INDEX $esquema.$indice" : "ALTER INDEX $esquema.$indice REBUILD";
            if (ora_ddl($conexion_activa, $sql, $err)) {
                $mensaje = ora_msg(true, "Índice '" . h("$esquema.$indice") . "' " . ($accion == 'DROP' ? "eliminado" : "reconstruido") . " correctamente.");
            } else {
                $mensaje = ora_msg(false, "Error: " . h($err));
            }
        }
    } elseif (isset($_POST['ver_plan'])) {
        // EXPLAIN PLAN no ejecuta la sentencia: solo calcula el plan.
        $sql_plan_enviado = trim($_POST['sql_plan']);
        $consulta = rtrim($sql_plan_enviado, "; \t\r\n");
        if (!preg_match('/^\s*(SELECT|WITH|INSERT|UPDATE|DELETE|MERGE)\b/i', $consulta)) {
            $mensaje = ora_msg(false, "Escriba una sentencia SQL (SELECT, INSERT, UPDATE, DELETE...) para analizar.");
        } else {
            $sid = 'PHP' . date('His') . mt_rand(100, 999);
            if (!ora_ddl($conexion_activa, "EXPLAIN PLAN SET STATEMENT_ID = '$sid' FOR $consulta", $err)) {
                $mensaje = ora_msg(false, "No se pudo generar el plan: " . h($err));
            } else {
                $filas = ora_rows($conexion_activa, "SELECT PLAN_TABLE_OUTPUT FROM TABLE(DBMS_XPLAN.DISPLAY('PLAN_TABLE', :sid, 'TYPICAL'))", array(':sid' => $sid), $err);
                if ($filas === false) {
                    $mensaje = ora_msg(false, "No se pudo leer el plan: " . h($err));
                } else {
                    $lineas = array();
                    foreach ($filas as $f) {
                        $lineas[] = $f['PLAN_TABLE_OUTPUT'];
                    }
                    $plan_texto = implode("\n", $lineas);
                    $mensaje = ora_msg(true, "Plan de ejecución generado correctamente.");
                }
                $del = oci_parse($conexion_activa, "DELETE FROM PLAN_TABLE WHERE STATEMENT_ID = :sid");
                oci_bind_by_name($del, ':sid', $sid);
                @oci_execute($del);
            }
        }
    }
}

// Índices (se ordena primero y se limita después, para que el límite sea correcto)
$indices = $conexion_activa ? ora_rows($conexion_activa, "SELECT * FROM (
                    SELECT OWNER, INDEX_NAME, TABLE_NAME, INDEX_TYPE, STATUS
                    FROM DBA_INDEXES
                    WHERE OWNER IN (SELECT USERNAME FROM DBA_USERS WHERE ORACLE_MAINTAINED = 'N')
                    ORDER BY OWNER, TABLE_NAME, INDEX_NAME
                  ) WHERE ROWNUM <= 100") : array();

// Estadísticas de las tablas de un esquema
$stats = array();
if ($conexion_activa && nombre_ok($esquema_consulta)) {
    $stats = ora_rows($conexion_activa, "SELECT TABLE_NAME, NUM_ROWS, BLOCKS, AVG_ROW_LEN, TO_CHAR(LAST_ANALYZED, 'DD/MM/YYYY HH24:MI:SS') AS ANALIZADA
                                         FROM DBA_TABLES WHERE OWNER = :o ORDER BY TABLE_NAME",
                      array(':o' => $esquema_consulta));
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

        <form method="POST" action="" class="form-card" style="margin-top:20px;">
            <h3>Plan de Ejecución de una Consulta</h3>
            <label>Sentencia SQL a analizar (EXPLAIN PLAN):</label>
            <textarea name="sql_plan" rows="5" required placeholder="SELECT * FROM HR.EMPLOYEES WHERE DEPARTMENT_ID = 50"
                      style="width:100%;max-width:450px;padding:10px;margin-top:6px;box-sizing:border-box;font-family:monospace;font-size:13px;"><?php echo h($sql_plan_enviado); ?></textarea>
            <button type="submit" name="ver_plan" style="margin-top:15px;">Ver Plan de Ejecución</button>
        </form>
        <?php if ($plan_texto !== ''): ?>
            <pre style="text-align:left;max-width:100%;width:100%;overflow:auto;background:#f8f9fa;border:1px solid #ddd;padding:10px;font-size:12px;box-sizing:border-box;"><?php echo h($plan_texto); ?></pre>
        <?php endif; ?>

        <div style="display: flex; gap: 20px; margin-top: 20px;">
            <form method="POST" action="" class="form-card" style="flex: 1;">
                <h3>Recalcular Estadísticas (DBMS_STATS)</h3>
                <label>Nombre del Esquema:</label>
                <input type="text" name="esquema_stats" required placeholder="HR">
                <label>Tabla (opcional, vacío = todo el esquema):</label>
                <input type="text" name="tabla_stats" placeholder="EMPLOYEES">
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

        <form method="POST" action="" class="form-card" onsubmit="return confirm('¿Confirma la acción sobre el índice?');">
            <h3>Reconstruir o Eliminar un Índice</h3>
            <label>Esquema:</label>
            <input type="text" name="esquema_adm" required placeholder="HR">
            <label>Nombre del índice:</label>
            <input type="text" name="indice_adm" required placeholder="IDX_EMPLOYEES_DEPARTMENT_ID">
            <label>Acción:</label>
            <select name="accion_adm">
                <option value="REBUILD">Reconstruir (REBUILD)</option>
                <option value="DROP">Eliminar (DROP)</option>
            </select>
            <button type="submit" name="admin_indice" style="margin-top:15px;">Aplicar</button>
        </form>

        <h3 style="margin-top:30px;">Índices Personalizados en el Sistema</h3>
        <?php render_tabla($indices, array('Propietario' => 'OWNER', 'Nombre del Índice' => 'INDEX_NAME', 'Tabla Asociada' => 'TABLE_NAME', 'Tipo' => 'INDEX_TYPE', 'Estado' => 'STATUS')); ?>

        <h3 style="margin-top:30px;">Estadísticas de las Tablas de un Esquema</h3>
        <form method="GET" action="" class="form-card">
            <label>Esquema a consultar:</label>
            <input type="text" name="esquema_consulta" value="<?php echo h($esquema_consulta); ?>" placeholder="HR">
            <button type="submit" style="margin-top:15px;">Consultar Estadísticas</button>
        </form>
        <?php
        if ($esquema_consulta !== '') {
            render_tabla($stats, array('Tabla' => 'TABLE_NAME', 'Filas (NUM_ROWS)' => 'NUM_ROWS', 'Bloques' => 'BLOCKS', 'Long. Prom. Fila' => 'AVG_ROW_LEN', 'Último Análisis' => 'ANALIZADA'),
                         'El esquema no tiene tablas o no existe.');
        }
        ?>
    </div>
</body>
</html>