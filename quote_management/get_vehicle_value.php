<?php
require '../db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['year'], $_POST['make'], $_POST['model'])) {
    $year = intval($_POST['year']);
    $make = $_POST['make'];
    $model = $_POST['model'];
    
    // Query to fetch the value for the selected year, make, and model
    $stmt = $conn->prepare("SELECT value FROM vehicles WHERE year = ? AND make = ? AND model = ? LIMIT 1");
    $stmt->bind_param("iss", $year, $make, $model);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Return the value as JSON
        header('Content-Type: application/json');
        echo json_encode(['value' => $row['value']]);
    } else {
        // Return error if no value found
        header('Content-Type: application/json');
        echo json_encode(['error' => 'No value found for the selected vehicle']);
    }
    
    $stmt->close();
    $conn->close();
} else {
    // Return error if invalid request
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid request']);
}
?>