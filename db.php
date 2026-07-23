<?php
$host = "localhost";
$db_user = "u239040674_xmstockexchang";
$db_pass = "Xmstockexchange01";
$db_name = "u239040674_xmstockexchang";

$conn = new mysqli($host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>