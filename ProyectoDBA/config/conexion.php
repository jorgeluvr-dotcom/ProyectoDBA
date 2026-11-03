<?php
$conn = @oci_connect('SYSTEM', 'una2009', 'localhost/XE', 'AL32UTF8');

if (!$conn) {
    $e = oci_error();
    die('ERROR DE CONEXION A ORACLE: ' . htmlentities($e['message'], ENT_QUOTES, 'UTF-8'));
}

/* =====================================================================
 * Funciones auxiliares compartidas por todos los módulos.
 * (No alteran la conexión ni la interfaz: solo evitan repetir código,
 *  validan entradas y ejecutan Data Pump de verdad.)
 * ===================================================================== */

// Escapa texto para mostrarlo en HTML.
function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Identificador Oracle válido (esquema, tabla, tablespace, usuario...).
function nombre_ok($s) {
    return is_string($s) && preg_match('/^[A-Za-z][A-Za-z0-9_$#]{0,127}$/', $s) === 1;
}

// Ruta de archivo/directorio sin comillas, punto y coma ni caracteres de control.
function ruta_ok($s) {
    return is_string($s) && $s !== '' && preg_match('/^[^\'"\x00-\x1F;]+$/', $s) === 1;
}

// Ejecuta una sentencia (DDL/DML/PLSQL) sin binds. Devuelve true/false y el error.
function ora_ddl($conn, $sql, &$err = '') {
    $st = oci_parse($conn, $sql);
    if (!$st) {
        $e = oci_error($conn);
        $err = $e ? $e['message'] : 'No se pudo preparar la sentencia.';
        return false;
    }
    if (@oci_execute($st)) {
        oci_free_statement($st);
        $err = '';
        return true;
    }
    $e = oci_error($st);
    $err = $e ? $e['message'] : 'Error desconocido.';
    oci_free_statement($st);
    return false;
}

// Ejecuta un SELECT con binds opcionales. Devuelve arreglo de filas (asociativas) o false.
function ora_rows($conn, $sql, $binds = array(), &$err = '') {
    $st = oci_parse($conn, $sql);
    if (!$st) {
        $e = oci_error($conn);
        $err = $e ? $e['message'] : 'No se pudo preparar la consulta.';
        return false;
    }
    foreach ($binds as $k => $v) {
        oci_bind_by_name($st, $k, $binds[$k]);
    }
    if (!@oci_execute($st)) {
        $e = oci_error($st);
        $err = $e ? $e['message'] : 'Error desconocido.';
        oci_free_statement($st);
        return false;
    }
    $rows = array();
    oci_fetch_all($st, $rows, 0, -1, OCI_FETCHSTATEMENT_BY_ROW + OCI_ASSOC);
    oci_free_statement($st);
    $err = '';
    return $rows;
}

// Mensaje con el mismo estilo (.alert) que ya usa el proyecto.
function ora_msg($ok, $html) {
    return "<div class='alert " . ($ok ? 'success' : 'error') . "'>" . $html . "</div>";
}

// Necesario en XE (contenedor raíz) para crear usuarios/perfiles/etc. sin prefijo C##.
function ora_script($conn) {
    $st = oci_parse($conn, 'ALTER SESSION SET "_ORACLE_SCRIPT"=TRUE');
    @oci_execute($st);
}

// Dibuja una tabla con el estilo .tabla-datos. $cols = array('Encabezado' => 'COLUMNA').
function render_tabla($rows, $cols, $vacio = 'Sin registros para mostrar.') {
    echo '<table border="1" class="tabla-datos"><tr>';
    foreach ($cols as $titulo => $col) {
        echo '<th>' . h($titulo) . '</th>';
    }
    echo '</tr>';
    if (!$rows) {
        echo '<tr><td colspan="' . count($cols) . '">' . h($vacio) . '</td></tr>';
    } else {
        foreach ($rows as $r) {
            echo '<tr>';
            foreach ($cols as $col) {
                echo '<td>' . h(isset($r[$col]) ? $r[$col] : '') . '</td>';
            }
            echo '</tr>';
        }
    }
    echo '</table>';
}

// Genera <option> a partir de filas. $sel = valor preseleccionado.
function opciones($rows, $col, $sel = '') {
    $o = '';
    if ($rows) {
        foreach ($rows as $r) {
            $v = $r[$col];
            $o .= "<option value='" . h($v) . "'" . ($v === $sel ? ' selected' : '') . ">" . h($v) . "</option>";
        }
    }
    return $o;
}

// Esquemas/usuarios creados por el usuario (no los internos de Oracle).
function lista_esquemas($conn) {
    $r = ora_rows($conn, "SELECT USERNAME FROM DBA_USERS WHERE ORACLE_MAINTAINED = 'N' ORDER BY USERNAME");
    return $r ? $r : array();
}

// Directorios Oracle registrados.
function lista_directorios($conn) {
    $r = ora_rows($conn, "SELECT DIRECTORY_NAME, DIRECTORY_PATH FROM DBA_DIRECTORIES ORDER BY DIRECTORY_NAME");
    return $r ? $r : array();
}

function usuario_actual($conn) {
    $r = ora_rows($conn, 'SELECT USER AS U FROM DUAL');
    return $r ? $r[0]['U'] : 'SYSTEM';
}

// Devuelve la fila del directorio (DIRECTORY_NAME, DIRECTORY_PATH) o null si no existe.
function dir_info($conn, $dir) {
    if (!nombre_ok($dir)) {
        return null;
    }
    $r = ora_rows($conn, 'SELECT DIRECTORY_NAME, DIRECTORY_PATH FROM DBA_DIRECTORIES WHERE DIRECTORY_NAME = :d',
                  array(':d' => strtoupper($dir)));
    return $r ? $r[0] : null;
}

// Garantiza lectura/escritura del usuario conectado sobre el directorio (si ya la tiene, no pasa nada).
function dir_asegurar($conn, $dir) {
    $u = usuario_actual($conn);
    if (nombre_ok($dir) && nombre_ok($u)) {
        ora_ddl($conn, "GRANT READ, WRITE ON DIRECTORY $dir TO $u");
    }
}

function ruta_archivo($ruta, $archivo) {
    return rtrim($ruta, '\\/') . DIRECTORY_SEPARATOR . $archivo;
}

// Lee (últimos caracteres) del .log generado por Data Pump, si el servidor web puede leer la carpeta.
function leer_log($conn, $dir, $log, $max = 3500) {
    $i = dir_info($conn, $dir);
    if (!$i) {
        return '';
    }
    $f = ruta_archivo($i['DIRECTORY_PATH'], $log);
    if (!is_readable($f)) {
        return '';
    }
    $t = @file_get_contents($f);
    if ($t === false) {
        return '';
    }
    if (strlen($t) > $max) {
        $t = '...' . substr($t, -$max);
    }
    return $t;
}

/* Ejecuta Data Pump con el paquete DBMS_DATAPUMP (no requiere expdp/impdp en el PATH).
 *   $operacion : 'EXPORT' | 'IMPORT'
 *   $modo      : 'FULL' | 'SCHEMA' | 'TABLE'
 *   $dir       : nombre del DIRECTORY Oracle
 *   $dump/$log : nombres de archivo .dmp y .log
 *   $esquema   : requerido en SCHEMA y TABLE;  $tabla: requerido en TABLE
 * Devuelve true si el job terminó (COMPLETED); $estado trae el estado final y $err el error si falla. */
function datapump($conn, $operacion, $modo, $dir, $dump, $log, $esquema, $tabla, &$estado, &$err) {
    $estado = '';
    $err = '';
    if (!in_array($operacion, array('EXPORT', 'IMPORT'), true) || !in_array($modo, array('FULL', 'SCHEMA', 'TABLE'), true)) {
        $err = 'Operación o modo de Data Pump no válido.';
        return false;
    }
    if (!nombre_ok($dir) || !ruta_ok($dump) || !ruta_ok($log)) {
        $err = 'Directorio o nombre de archivo no válido.';
        return false;
    }
    if (($modo !== 'FULL' && !nombre_ok($esquema)) || ($modo === 'TABLE' && !nombre_ok($tabla))) {
        $err = 'Esquema o tabla no válidos.';
        return false;
    }

    $filtros = '';
    if ($modo === 'SCHEMA') {
        $filtros = "DBMS_DATAPUMP.METADATA_FILTER(h, 'SCHEMA_LIST', :lst);";
    } elseif ($modo === 'TABLE') {
        $filtros = "DBMS_DATAPUMP.METADATA_FILTER(h, 'SCHEMA_EXPR', :sx);
                    DBMS_DATAPUMP.METADATA_FILTER(h, 'NAME_EXPR', :nx);";
    }
    $extra = ($operacion === 'IMPORT') ? "DBMS_DATAPUMP.SET_PARAMETER(h, 'TABLE_EXISTS_ACTION', 'REPLACE');" : '';

    $plsql = "DECLARE
                h   NUMBER;
                est VARCHAR2(30);
              BEGIN
                h := DBMS_DATAPUMP.OPEN(operation => :op, job_mode => :modo, job_name => :job);
                DBMS_DATAPUMP.ADD_FILE(handle => h, filename => :dump, directory => :dir1,
                                       filetype => DBMS_DATAPUMP.KU\$_FILE_TYPE_DUMP_FILE);
                DBMS_DATAPUMP.ADD_FILE(handle => h, filename => :log, directory => :dir2,
                                       filetype => DBMS_DATAPUMP.KU\$_FILE_TYPE_LOG_FILE);
                $filtros
                $extra
                DBMS_DATAPUMP.START_JOB(h);
                DBMS_DATAPUMP.WAIT_FOR_JOB(h, est);
                :estado := est;
              EXCEPTION
                WHEN OTHERS THEN
                  BEGIN DBMS_DATAPUMP.STOP_JOB(h, 1, 0); EXCEPTION WHEN OTHERS THEN NULL; END;
                  RAISE;
              END;";

    $job = 'PHP_DP_' . date('YmdHis') . mt_rand(10, 99);
    $dirU = strtoupper($dir);
    $st = oci_parse($conn, $plsql);
    if (!$st) {
        $e = oci_error($conn);
        $err = $e ? $e['message'] : 'No se pudo preparar Data Pump.';
        return false;
    }
    oci_bind_by_name($st, ':op', $operacion);
    oci_bind_by_name($st, ':modo', $modo);
    oci_bind_by_name($st, ':job', $job);
    oci_bind_by_name($st, ':dump', $dump);
    oci_bind_by_name($st, ':dir1', $dirU);
    oci_bind_by_name($st, ':log', $log);
    oci_bind_by_name($st, ':dir2', $dirU);
    oci_bind_by_name($st, ':estado', $estado, 30);
    if ($modo === 'SCHEMA') {
        $lst = "'" . strtoupper($esquema) . "'";
        oci_bind_by_name($st, ':lst', $lst);
    } elseif ($modo === 'TABLE') {
        $sx = "IN ('" . strtoupper($esquema) . "')";
        $nx = "IN ('" . $tabla . "')";
        oci_bind_by_name($st, ':sx', $sx);
        oci_bind_by_name($st, ':nx', $nx);
    }

    @set_time_limit(0);
    if (!@oci_execute($st)) {
        $e = oci_error($st);
        $err = $e ? $e['message'] : 'Error desconocido en Data Pump.';
        oci_free_statement($st);
        return false;
    }
    oci_free_statement($st);
    return ($estado === 'COMPLETED');
}