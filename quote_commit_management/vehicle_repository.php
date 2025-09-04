<?php
require_once '../db_connect.php';

function getExistingVehicleIds($conn, $quote_id) {
    $existing_vehicle_ids = [];
    $stmt = $conn->prepare("SELECT vehicle_id FROM quote_vehicles WHERE quote_id = ? AND deleted_at IS NULL");
    if (!$stmt) {
        throw new Exception("Failed to prepare SELECT statement for existing vehicles: " . $conn->error);
    }
    $stmt->bind_param("i", $quote_id);
    if (!$stmt->execute()) {
        throw new Exception("Failed to fetch existing vehicle IDs: " . $stmt->error);
    }
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $existing_vehicle_ids[] = $row['vehicle_id'];
    }
    $stmt->close();
    return $existing_vehicle_ids;
}

function updateVehicle($conn, $vehicle, $quote_id, $vehicle_id, $discount_percentage, $user_id) {
    $stmt = $conn->prepare("
        UPDATE quote_vehicles SET
            vehicle_year = ?, vehicle_make = ?, vehicle_model = ?, vehicle_value = ?,
            coverage_type = ?, vehicle_use = ?, parking = ?, street = ?,
            suburb_vehicle = ?, postal_code = ?, car_hire = ?,
            discount_percentage = ?, discounted_by = ?, discounted_at = ?
        WHERE vehicle_id = ? AND quote_id = ?
    ");
    if (!$stmt) {
        throw new Exception("Failed to prepare UPDATE statement for vehicle ID $vehicle_id: " . $conn->error);
    }

    $discounted_by = $discount_percentage > 0 ? $user_id : null;
    $discounted_at = $discount_percentage > 0 ? date('Y-m-d H:i:s') : null;
    $stmt->bind_param(
        "issdsssssisdisii",
        $vehicle['year'],
        $vehicle['make'],
        $vehicle['model'],
        $vehicle['value'],
        $vehicle['coverage_type'],
        $vehicle['use'],
        $vehicle['parking'],
        $vehicle['street'],
        $vehicle['suburb_vehicle'],
        $vehicle['postal_code'],
        $vehicle['car_hire'],
        $discount_percentage,
        $discounted_by,
        $discounted_at,
        $vehicle_id,
        $quote_id
    );

    if (!$stmt->execute()) {
        throw new Exception("Failed to update vehicle ID $vehicle_id: " . $stmt->error);
    }

    $affected_rows = $stmt->affected_rows;
    $stmt->close();
    return $affected_rows;
}

function insertVehicle($conn, $vehicle, $quote_id, $discount_percentage, $user_id) {
    $stmt = $conn->prepare("
        INSERT INTO quote_vehicles (
            quote_id, vehicle_year, vehicle_make, vehicle_model, vehicle_value, coverage_type,
            vehicle_use, parking, street, suburb_vehicle, postal_code, car_hire,
            discount_percentage, discounted_by, discounted_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        throw new Exception("Failed to prepare INSERT statement for vehicle: " . $conn->error);
    }

    $discounted_by = $discount_percentage > 0 ? $user_id : null;
    $discounted_at = $discount_percentage > 0 ? date('Y-m-d H:i:s') : null;
    $stmt->bind_param(
        "iissdsssssssdis",
        $quote_id,
        $vehicle['year'],
        $vehicle['make'],
        $vehicle['model'],
        $vehicle['value'],
        $vehicle['coverage_type'],
        $vehicle['use'],
        $vehicle['parking'],
        $vehicle['street'],
        $vehicle['suburb_vehicle'],
        $vehicle['postal_code'],
        $vehicle['car_hire'],
        $discount_percentage,
        $discounted_by,
        $discounted_at
    );

    if (!$stmt->execute()) {
        throw new Exception("Failed to insert vehicle: " . $stmt->error);
    }

    $vehicle_id = $conn->insert_id;
    $stmt->close();
    return $vehicle_id;
}

function softDeleteVehicles($conn, $quote_id, $vehicles_to_delete) {
    if (empty($vehicles_to_delete)) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($vehicles_to_delete), '?'));
    $stmt = $conn->prepare("UPDATE quote_vehicles SET deleted_at = NOW() WHERE vehicle_id IN ($placeholders) AND quote_id = ?");
    if (!$stmt) {
        throw new Exception("Failed to prepare DELETE statement for vehicles: " . $conn->error);
    }

    $types = str_repeat('i', count($vehicles_to_delete)) . 'i';
    $params = array_merge($vehicles_to_delete, [$quote_id]);
    $stmt->bind_param($types, ...$params);

    if (!$stmt->execute()) {
        throw new Exception("Failed to soft delete vehicles: " . $stmt->error);
    }

    $affected_rows = $stmt->affected_rows;
    $stmt->close();
    return $affected_rows;
}
?>