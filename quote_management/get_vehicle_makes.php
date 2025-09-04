<?php
require '../db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['year'])) {
    $year = intval($_POST['year']);
    
    // Query to fetch unique makes for the selected year
    $stmt = $conn->prepare("SELECT DISTINCT make FROM vehicles WHERE year = ? ORDER BY make ASC");
    $stmt->bind_param("i", $year);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $makes = [];
    while ($row = $result->fetch_assoc()) {
        $makes[] = $row['make'];
    }
    
    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode($makes);
    
    $stmt->close();
    $conn->close();
} else {
    // Return error if invalid request
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid request']);
}
?>