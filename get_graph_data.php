<?php
require 'db_connect.php';

$brokerage_id = $_GET['brokerage_id'] ?? '0';
$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('m'));

$days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$data = array_fill(1, $days_in_month, 0);

if ($brokerage_id === 'all') {
    $stmt = $conn->prepare("
        SELECT DAY(created_at) as day, COUNT(*) as count
        FROM quotes
        WHERE YEAR(created_at) = ? AND MONTH(created_at) = ?
        GROUP BY DAY(created_at)
    ");
    $stmt->bind_param("ii", $year, $month);
} else {
    $brokerage_id = (int)$brokerage_id;
    if (!$brokerage_id) {
        echo json_encode([]);
        exit;
    }
    $stmt = $conn->prepare("
        SELECT DAY(created_at) as day, COUNT(*) as count
        FROM quotes
        WHERE brokerage_id = ? AND YEAR(created_at) = ? AND MONTH(created_at) = ?
        GROUP BY DAY(created_at)
    ");
    $stmt->bind_param("iii", $brokerage_id, $year, $month);
}

$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $data[$row['day']] = (int)$row['count'];
}

$stmt->close();
$conn->close();

echo json_encode($data);
?>