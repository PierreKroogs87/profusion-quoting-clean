<?php
require_once '../db_connect.php';

function updateDriver($conn, $driver, $quote_id, $vehicle_id) {
    $stmt = $conn->prepare("
        UPDATE quote_drivers SET
            driver_title = ?, driver_initials = ?, driver_surname = ?, driver_id_number = ?,
            dob = ?, age = ?, licence_type = ?, year_of_issue = ?, licence_held = ?,
            driver_marital_status = ?, ncb = ?
        WHERE vehicle_id = ? AND quote_id = ?
    ");
    if (!$stmt) {
        throw new Exception("Failed to prepare UPDATE statement for driver with vehicle_id $vehicle_id: " . $conn->error);
    }

    $stmt->bind_param(
        "sssssisiisiii",
        $driver['title'],
        $driver['initials'],
        $driver['surname'],
        $driver['id_number'],
        $driver['dob'],
        $driver['age'],
        $driver['licence_type'],
        $driver['year_of_issue'],
        $driver['licence_held'],
        $driver['marital_status'],
        $driver['ncb'],
        $vehicle_id,
        $quote_id
    );

    if (!$stmt->execute()) {
        throw new Exception("Failed to update driver for vehicle_id $vehicle_id: " . $stmt->error);
    }

    $affected_rows = $stmt->affected_rows;
    $stmt->close();
    return $affected_rows;
}

function insertDriver($conn, $driver, $quote_id, $vehicle_id) {
    $stmt = $conn->prepare("
        INSERT INTO quote_drivers (
            vehicle_id, quote_id, driver_title, driver_initials, driver_surname, driver_id_number,
            dob, age, licence_type, year_of_issue, licence_held, driver_marital_status, ncb
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        throw new Exception("Failed to prepare INSERT statement for driver: " . $conn->error);
    }

    $stmt->bind_param(
        "iissssiisiisi",
        $vehicle_id,
        $quote_id,
        $driver['title'],
        $driver['initials'],
        $driver['surname'],
        $driver['id_number'],
        $driver['dob'],
        $driver['age'],
        $driver['licence_type'],
        $driver['year_of_issue'],
        $driver['licence_held'],
        $driver['marital_status'],
        $driver['ncb']
    );

    if (!$stmt->execute()) {
        throw new Exception("Failed to insert driver for vehicle_id $vehicle_id: " . $stmt->error);
    }

    $driver_id = $conn->insert_id;
    $stmt->close();
    return $driver_id;
}
?>