<?php
require_once '../db_connect.php';

function logDiscountChange($conn, $quote_id, $vehicle_id, $action, $discount_percentage, $user_id) {
    $stmt = $conn->prepare("
        INSERT INTO quote_discount_logs (quote_id, vehicle_id, action, discount_percentage, user_id, timestamp)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    if (!$stmt) {
        throw new Exception("Failed to prepare INSERT statement for discount log: " . $conn->error);
    }

    $stmt->bind_param("iisdi", $quote_id, $vehicle_id, $action, $discount_percentage, $user_id);
    if (!$stmt->execute()) {
        throw new Exception("Failed to log discount change for vehicle_id=$vehicle_id, action=$action: " . $stmt->error);
    }

    $stmt->close();
}
?>