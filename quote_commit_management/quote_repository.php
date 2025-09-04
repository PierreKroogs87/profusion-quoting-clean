<?php
require_once '../db_connect.php';

function updateQuote($conn, $quote_data, $user_id, $premiums, $original_premiums) {
    $stmt = $conn->prepare("
        UPDATE quotes SET
            user_id = ?, brokerage_id = ?, title = ?, initials = ?, surname = ?, marital_status = ?,
            client_id = ?, suburb_client = ?, waive_broker_fee = ?, fees = ?,
            premium6 = ?, premium5 = ?, premium4 = ?, premium_flat = ?,
            original_premium6 = ?, original_premium5 = ?, original_premium4 = ?, original_premium_flat = ?
        WHERE quote_id = ? AND user_id = ?
    ");
    if (!$stmt) {
        throw new Exception("Failed to prepare UPDATE statement for quote: " . $conn->error);
    }

    $fees = '{}'; // Placeholder for fees, as per original logic
    $stmt->bind_param(
        "iissssssisddddddddii",
        $user_id,
        $quote_data['brokerage_id'],
        $quote_data['title'],
        $quote_data['initials'],
        $quote_data['surname'],
        $quote_data['marital_status'],
        $quote_data['client_id'],
        $quote_data['suburb_client'],
        $quote_data['waive_broker_fee'],
        $fees,
        $premiums['premium6'],
        $premiums['premium5'],
        $premiums['premium4'],
        $premiums['premium_flat'],
        $original_premiums['premium6'],
        $original_premiums['premium5'],
        $original_premiums['premium4'],
        $original_premiums['premium_flat'],
        $quote_data['quote_id'],
        $user_id
    );

    if (!$stmt->execute()) {
        throw new Exception("Failed to update quote: " . $stmt->error);
    }

    $affected_rows = $stmt->affected_rows;
    $stmt->close();
    return $affected_rows;
}

function insertQuote($conn, $quote_data, $user_id) {
    $stmt = $conn->prepare("
        INSERT INTO quotes (
            user_id, brokerage_id, title, initials, surname, marital_status,
            client_id, suburb_client, waive_broker_fee, fees, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    if (!$stmt) {
        throw new Exception("Failed to prepare INSERT statement for quote: " . $conn->error);
    }

    $fees = '{}'; // Placeholder for fees
    $stmt->bind_param(
        "iissssssis",
        $user_id,
        $quote_data['brokerage_id'],
        $quote_data['title'],
        $quote_data['initials'],
        $quote_data['surname'],
        $quote_data['marital_status'],
        $quote_data['client_id'],
        $quote_data['suburb_client'],
        $quote_data['waive_broker_fee'],
        $fees
    );

    if (!$stmt->execute()) {
        throw new Exception("Failed to insert new quote: " . $stmt->error);
    }

    $quote_id = $conn->insert_id;
    $stmt->close();
    return $quote_id;
}
?>