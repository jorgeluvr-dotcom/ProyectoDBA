<?php
$conn = oci_connect('SYSTEM', 'una2009', 'localhost/XE', 'AL32UTF8');

if (!$conn) {
    $e = oci_error();
    die('ERROR DE CONEXION A ORACLE: ' . htmlentities($e['message'], ENT_QUOTES, 'UTF-8'));
}