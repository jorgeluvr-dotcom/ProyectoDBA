<?php
// index.php
include("config/conexion.php");

// Detectar si la conexión está activa usando las variables estándar ($conn o $conexion)
$conexion_activa = isset($conn) ? $conn : (isset($conexion) ? $conexion : null);

// Obtener fecha, hora y usuario conectado directamente desde Oracle si hay conexión
$fecha_db = "";
$usuario_db = "";
if ($conexion_activa) {
    $query_info = "SELECT TO_CHAR(SYSDATE, 'DD/MM/YYYY HH24:MI:SS') AS FECHA_DB, USER AS USUARIO FROM DUAL";
    $stid_info = oci_parse($conexion_activa, $query_info);
    if (@oci_execute($stid_info)) {
        if ($row_info = oci_fetch_assoc($stid_info)) {
            $fecha_db = $row_info['FECHA_DB'];
            $usuario_db = $row_info['USUARIO'];
        }
    }
    @oci_free_statement($stid_info);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Sistema DBA - Oracle 21c XE</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* Estilos para el panel de estado, usuario y hora */
        .info-panel {
            margin: 15px 0;
            padding: 10px;
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 13px;
            color: #444;
            display: flex;
            justify-content: space-around;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .db-status {
            font-weight: bold;
            padding: 4px 10px;
            border-radius: 4px;
        }
        .db-status.connected {
            background-color: #d4edda;
            color: #155724;
        }
        .db-status.disconnected {
            background-color: #f8d7da;
            color: #721c24;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Sistema de Administración de Bases de Datos (DBA)</h2>
        <p>Proyecto Final - EIF-402 Administración de Bases de Datos (UNA)</p>

        <!-- Panel de Estado, Usuario y Hora de Oracle -->
        <div class="info-panel">
            <div>
                <?php if ($conexion_activa): ?>
                    <span class="db-status connected">🟢 Conectado a Oracle 21c XE</span>
                <?php else: ?>
                    <span class="db-status disconnected">🔴 Sin conexión a la Base de Datos</span>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($usuario_db)): ?>
                <div>
                    <strong>Usuario Oracle:</strong> <?php echo htmlspecialchars($usuario_db); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($fecha_db)): ?>
                <div>
                    <strong>Fecha / Hora DB:</strong> <?php echo $fecha_db; ?>
                </div>
            <?php endif; ?>
        </div>

        <hr>

        <div class="menu-grid">
            <a href="php/performanceBaseDatos.php" class="card-link">📈 Performance de la BD</a>
            <a href="php/tablespaces.php" class="card-link">💾 Gestión de Tablespaces</a>
            <a href="php/respaldos.php" class="card-link">🛡️ Respaldos Data Pump</a>
            <a href="php/tunning.php" class="card-link">⚡ Tuning y Estadísticas</a>
            <a href="php/seguridad.php" class="card-link">🔒 Seguridad y Perfiles</a>
            <a href="php/directorios.php" class="card-link">📁 Directorios Oracle</a>
            <a href="php/recuperacion.php" class="card-link">🔄 Recuperación (IMPDP)</a>
            <a href="php/auditoria.php" class="card-link">👁️ Auditoría de la BD</a>
        </div>
    </div>
</body>
</html>