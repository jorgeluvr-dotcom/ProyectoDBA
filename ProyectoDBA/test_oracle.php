
<?php

require_once __DIR__ . '/config/conexion.php';

$sql = "SELECT SYSDATE FROM DUAL";

$stmt = oci_parse($conn, $sql);

if (!oci_execute($stmt)) {
    $e = oci_error($stmt);
    die('ERROR SQL: ' .
        htmlentities($e['message'], ENT_QUOTES, 'UTF-8'));
}

$row = oci_fetch_assoc($stmt);

echo "¡Conexión exitosa con Oracle!<br>";
echo "Oracle respondió: " .
    htmlentities($row['SYSDATE'], ENT_QUOTES, 'UTF-8');

oci_free_statement($stmt);
oci_close($conn);

