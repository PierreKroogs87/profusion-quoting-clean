<?php
require '../db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['year'], $_POST['make'])) {
    $year = intval($_POST['year']);
    $make = $_POST['make'];
    
    // Query to fetch unique models for the selected year and make
    $stmt = $conn->prepare("SELECT DISTINCT model FROM vehicles WHERE year = ? AND make = ? ORDER BY model ASC");
    $stmt->bind_param("is", $year, $make);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $models = [];
    while ($row = $result->fetch_assoc()) {
        $models[] = $row['model'];
    }
    
    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode($models);
    
    $stmt->close();
    $conn->close();
} else {
    // Return error if invalid request
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid request']);
}
?>