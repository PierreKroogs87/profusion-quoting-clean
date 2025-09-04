<?php
header('Content-Type: application/json');
require '../db_connect.php';

$years_query = "SELECT DISTINCT year FROM vehicles WHERE year BETWEEN 2005 AND 2025 ORDER BY year DESC";
$years_result = mysqli_query($conn, $years_query);
$years = [];
while ($year = mysqli_fetch_assoc($years_result)) {
    $years[] = $year['year'];
}
echo json_encode($years);
mysqli_close($conn);
?>