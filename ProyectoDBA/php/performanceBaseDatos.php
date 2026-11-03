<?php
// php/performanceBaseDatos.php
include("../config/conexion.php");
$conexion_activa = isset($conn) ? $conn : (isset($conexion) ? $conexion : null);

$instancia = array();
$stats = array();
$sga = array();
$sesiones = array();
$topsql = array();
$esperas = array();
$ratios = array();

if ($conexion_activa) {
    $r = ora_rows($conexion_activa, 'SELECT INSTANCE_NAME, STATUS, DATABASE_STATUS, TO_CHAR(STARTUP_TIME, \'DD/MM/YYYY HH24:MI:SS\') AS STARTUP FROM V$INSTANCE');
    $instancia = $r ? $r : array();

    // Estadísticas más relevantes del sistema
    $r = ora_rows($conexion_activa, "SELECT NAME, VALUE FROM V\$SYSSTAT
        WHERE NAME IN ('user calls','user commits','user rollbacks','execute count','parse count (total)','parse count (hard)',
                       'session logical reads','physical reads','physical writes','db block gets','consistent gets',
                       'redo size','sorts (memory)','sorts (disk)','logons current','logons cumulative')
        ORDER BY NAME");
    $stats = $r ? $r : array();

    // Indicadores calculados a partir de V$SYSSTAT / V$LIBRARYCACHE
    $v = array();
    foreach ($stats as $s) {
        $v[$s['NAME']] = (float)$s['VALUE'];
    }
    $lecturas = (isset($v['db block gets']) ? $v['db block gets'] : 0) + (isset($v['consistent gets']) ? $v['consistent gets'] : 0);
    if ($lecturas > 0 && isset($v['physical reads'])) {
        $ratios[] = array('IND' => 'Buffer Cache Hit Ratio', 'VAL' => round((1 - $v['physical reads'] / $lecturas) * 100, 2) . ' %');
    }
    $lib = ora_rows($conexion_activa, 'SELECT ROUND(SUM(PINHITS) / NULLIF(SUM(PINS), 0) * 100, 2) AS V FROM V$LIBRARYCACHE');
    if ($lib && $lib[0]['V'] !== null) {
        $ratios[] = array('IND' => 'Library Cache Hit Ratio', 'VAL' => $lib[0]['V'] . ' %');
    }
    if (isset($v['parse count (total)']) && $v['parse count (total)'] > 0 && isset($v['parse count (hard)'])) {
        $ratios[] = array('IND' => 'Parseos duros sobre el total', 'VAL' => round($v['parse count (hard)'] / $v['parse count (total)'] * 100, 2) . ' %');
    }
    if (isset($v['sorts (memory)']) && isset($v['sorts (disk)']) && ($v['sorts (memory)'] + $v['sorts (disk)']) > 0) {
        $ratios[] = array('IND' => 'Ordenamientos en memoria', 'VAL' => round($v['sorts (memory)'] / ($v['sorts (memory)'] + $v['sorts (disk)']) * 100, 2) . ' %');
    }

    $r = ora_rows($conexion_activa, 'SELECT NAME, ROUND(BYTES / 1048576, 2) AS MB FROM V$SGAINFO WHERE BYTES > 0 ORDER BY BYTES DESC');
    $sga = $r ? $r : array();

    $r = ora_rows($conexion_activa, 'SELECT NVL(USERNAME, \'(proceso de fondo)\') AS USUARIO, STATUS, COUNT(*) AS TOTAL
                                     FROM V$SESSION GROUP BY USERNAME, STATUS ORDER BY USERNAME, STATUS');
    $sesiones = $r ? $r : array();

    $r = ora_rows($conexion_activa, 'SELECT * FROM (
                                        SELECT SQL_ID, ROUND(ELAPSED_TIME / 1000000, 2) AS SEGUNDOS, EXECUTIONS, BUFFER_GETS, DISK_READS,
                                               SUBSTR(SQL_TEXT, 1, 80) AS TEXTO
                                        FROM V$SQL ORDER BY ELAPSED_TIME DESC
                                     ) WHERE ROWNUM <= 10');
    $topsql = $r ? $r : array();

    $r = ora_rows($conexion_activa, 'SELECT * FROM (
                                        SELECT EVENT, WAIT_CLASS, TOTAL_WAITS, ROUND(TIME_WAITED_MICRO / 1000000, 2) AS SEGUNDOS
                                        FROM V$SYSTEM_EVENT WHERE WAIT_CLASS <> \'Idle\' ORDER BY TIME_WAITED_MICRO DESC
                                     ) WHERE ROWNUM <= 10');
    $esperas = $r ? $r : array();
}

// Formatea el valor numérico de V$SYSSTAT
foreach ($stats as $k => $s) {
    $stats[$k]['VALOR'] = number_format($s['VALUE']);
}
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
        <a href="" class="btn-volver" style="background:#007bff;">↻ Actualizar métricas</a>
        <hr style="margin: 20px 0;">

        <h3>Métricas de la Instancia (V$INSTANCE)</h3>
        <?php render_tabla($instancia, array('Nombre de Instancia' => 'INSTANCE_NAME', 'Estado (Status)' => 'STATUS', 'Modo (Database Status)' => 'DATABASE_STATUS', 'Tiempo de Inicio' => 'STARTUP')); ?>

        <h3 style="margin-top:30px;">Indicadores de Rendimiento</h3>
        <?php render_tabla($ratios, array('Indicador' => 'IND', 'Valor' => 'VAL'), 'No hay datos suficientes para calcular indicadores.'); ?>

        <h3 style="margin-top:30px;">Estadísticas del Sistema (V$SYSSTAT)</h3>
        <?php render_tabla($stats, array('Nombre de Estadística' => 'NAME', 'Valor Actual' => 'VALOR')); ?>

        <h3 style="margin-top:30px;">Memoria SGA (V$SGAINFO)</h3>
        <?php render_tabla($sga, array('Componente' => 'NAME', 'Tamaño (MB)' => 'MB')); ?>

        <h3 style="margin-top:30px;">Sesiones por Usuario (V$SESSION)</h3>
        <?php render_tabla($sesiones, array('Usuario' => 'USUARIO', 'Estado' => 'STATUS', 'Sesiones' => 'TOTAL')); ?>

        <h3 style="margin-top:30px;">Top 10 Consultas por Tiempo (V$SQL)</h3>
        <?php render_tabla($topsql, array('SQL_ID' => 'SQL_ID', 'Segundos' => 'SEGUNDOS', 'Ejecuciones' => 'EXECUTIONS', 'Buffer Gets' => 'BUFFER_GETS', 'Lecturas a Disco' => 'DISK_READS', 'Texto' => 'TEXTO')); ?>

        <h3 style="margin-top:30px;">Top 10 Eventos de Espera (V$SYSTEM_EVENT)</h3>
        <?php render_tabla($esperas, array('Evento' => 'EVENT', 'Clase' => 'WAIT_CLASS', 'Total Esperas' => 'TOTAL_WAITS', 'Segundos' => 'SEGUNDOS')); ?>
    </div>
</body>
</html>