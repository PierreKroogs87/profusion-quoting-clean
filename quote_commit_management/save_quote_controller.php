<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');

// Check if user is logged in as staff
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'staff') {
    $_SESSION['errors'] = ['Unauthorized: Staff login required'];
    error_log("Unauthorized access: user_id=" . ($_SESSION['user_id'] ?? 'none') . ", user_type=" . ($_SESSION['user_type'] ?? 'none'));
    header("Location: ../login_management/login.php");
    exit();
}

require_once '../db_connect.php';
require_once 'validate_quote.php';
require_once 'quote_repository.php';
require_once 'vehicle_repository.php';
require_once 'driver_repository.php';
require_once 'discount_logger.php';
require_once 'quote_calculator.php';

// Log POST data for debugging
error_log('POST Data: ' . print_r($_POST, true));

// Validate input data
$validation_result = validateQuoteData($_POST, $_SESSION);
if (!empty($validation_result['errors'])) {
    $_SESSION['errors'] = $validation_result['errors'];
    $_SESSION['form_data'] = $_POST;
    error_log("Validation errors: " . implode(', ', $validation_result['errors']));
    header("Location: ../quote_management/new_quote.php");
    exit();
}

// Extract validated data
$quote_data = [
    'quote_id' => $validation_result['quote_id'],
    'title' => $validation_result['title'],
    'initials' => $validation_result['initials'],
    'surname' => $validation_result['surname'],
    'marital_status' => $validation_result['marital_status'],
    'client_id' => $validation_result['client_id'],
    'suburb_client' => $validation_result['suburb_client'],
    'brokerage_id' => $validation_result['brokerage_id'],
    'waive_broker_fee' => $validation_result['waive_broker_fee']
];
$vehicles = $validation_result['vehicles'];
$global_discount_percentage = $validation_result['global_discount_percentage'];
$is_authorized = $validation_result['is_authorized'];

// Log validated data
error_log("Validated quote data: " . print_r($quote_data, true));
error_log("Validated vehicles: " . print_r($vehicles, true));

// Handle reset discounts
if (isset($_POST['reset_discounts']) && $is_authorized) {
    $global_discount_percentage = 0;
    error_log("Reset discounts applied: global_discount_percentage=0");
}

// Begin transaction
$conn->begin_transaction();

try {
    // Check if client_id exists in clients table
    $stmt = $conn->prepare("SELECT client_id FROM clients WHERE client_id = ?");
    $stmt->bind_param("s", $quote_data['client_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $client_exists = $result->num_rows > 0;
    $stmt->close();
    error_log("Client exists check: client_id={$quote_data['client_id']}, exists=" . ($client_exists ? 'yes' : 'no'));

    if (!$client_exists) {
        // Insert new client with minimal data
        $client_name = trim($quote_data['initials'] . ' ' . $quote_data['surname']);
        $stmt = $conn->prepare("INSERT INTO clients (client_id, name) VALUES (?, ?)");
        $stmt->bind_param("ss", $quote_data['client_id'], $client_name);
        $stmt->execute();
        $stmt->close();
        error_log("Inserted new client: client_id={$quote_data['client_id']}, name=$client_name");
    }

    // Insert or update quote
    if ($quote_data['quote_id']) {
        updateQuote($conn, $quote_data, $_SESSION['user_id'], [], []);
        error_log("Updated quote: quote_id={$quote_data['quote_id']}");
    } else {
        $quote_data['quote_id'] = insertQuote($conn, $quote_data, $_SESSION['user_id']);
        error_log("Inserted new quote: quote_id={$quote_data['quote_id']}");
    }

    // Fetch existing vehicle IDs
    $existing_vehicle_ids = getExistingVehicleIds($conn, $quote_data['quote_id']);
    error_log("Existing vehicle IDs: " . implode(',', $existing_vehicle_ids));
    $submitted_vehicle_ids = [];
    $saved_vehicles = [];

    // Process vehicles and drivers
    foreach ($vehicles as $index => $vehicle) {
        $driver = $vehicle['driver'];
        $vehicle_id = isset($vehicle['vehicle_id']) && is_numeric($vehicle['vehicle_id']) && $vehicle['vehicle_id'] > 0 ? (int)$vehicle['vehicle_id'] : null;

        // Log vehicle data for debugging
        error_log("Processing vehicle $index: vehicle_id=$vehicle_id, make=" . ($vehicle['make'] ?? 'NULL') . ", coverage_type=" . ($vehicle['coverage_type'] ?? 'NULL') . ", car_hire=" . ($vehicle['car_hire'] ?? 'NULL') . ", discount_percentage=$global_discount_percentage");

        if ($vehicle_id && in_array($vehicle_id, $existing_vehicle_ids)) {
            // Update existing vehicle and driver
            updateVehicle($conn, $vehicle, $quote_data['quote_id'], $vehicle_id, $global_discount_percentage, $_SESSION['user_id']);
            updateDriver($conn, $driver, $quote_data['quote_id'], $vehicle_id);
            error_log("Updated vehicle and driver: vehicle_id=$vehicle_id");
        } else {
            // Insert new vehicle and driver
            $vehicle_id = insertVehicle($conn, $vehicle, $quote_data['quote_id'], $global_discount_percentage, $_SESSION['user_id']);
            insertDriver($conn, $driver, $quote_data['quote_id'], $vehicle_id);
            error_log("Inserted vehicle and driver: vehicle_id=$vehicle_id");
        }
        $submitted_vehicle_ids[] = $vehicle_id;

        // Log discount change if applicable
        if ($global_discount_percentage > 0 && $is_authorized) {
            logDiscountChange($conn, $quote_data['quote_id'], $vehicle_id, 'apply_discount', $global_discount_percentage, $_SESSION['user_id']);
            error_log("Logged discount change: quote_id={$quote_data['quote_id']}, vehicle_id=$vehicle_id, discount=$global_discount_percentage");
        } elseif (isset($_POST['reset_discounts']) && $is_authorized) {
            logDiscountChange($conn, $quote_data['quote_id'], $vehicle_id, 'reset_discount', 0, $_SESSION['user_id']);
            error_log("Logged reset discount: quote_id={$quote_data['quote_id']}, vehicle_id=$vehicle_id");
        }

        // Store vehicle and driver for premium calculation
        $saved_vehicles[] = [
            'vehicle' => [
                'value' => floatval($vehicle['value']),
                'make' => $vehicle['make'],
                'model' => $vehicle['model'],
                'use' => $vehicle['use'],
                'parking' => $vehicle['parking'],
                'car_hire' => $vehicle['car_hire'],
                'discount_percentage' => $global_discount_percentage
            ],
            'driver' => [
                'age' => intval($driver['age']),
                'licence_type' => $driver['licence_type'],
                'licence_held' => intval($driver['licence_held']),
                'marital_status' => $driver['marital_status'],
                'ncb' => $driver['ncb'],
                'title' => $driver['title']
            ]
        ];
    }

    // Soft-delete removed vehicles
    $vehicles_to_delete = array_diff($existing_vehicle_ids, array_filter($submitted_vehicle_ids, function($id) { return $id !== null; }));
    if (!empty($vehicles_to_delete)) {
        error_log("Soft-deleting vehicles: " . implode(',', $vehicles_to_delete));
        softDeleteVehicles($conn, $quote_data['quote_id'], $vehicles_to_delete);
    }

    // Calculate original premiums (no discounts)
    $original_vehicles = array_map(function($v) {
        $v['vehicle']['discount_percentage'] = 0;
        return $v;
    }, $saved_vehicles);
    $original_calculation = calculateQuote($conn, $quote_data['brokerage_id'], $original_vehicles, $quote_data['waive_broker_fee']);
    $original_premiums = $original_calculation['premiums'];
    $original_breakdown = $original_calculation['breakdown'];
    error_log("Original premiums calculated: " . print_r($original_premiums, true));

    // Calculate discounted premiums
    $calculation = calculateQuote($conn, $quote_data['brokerage_id'], $saved_vehicles, $quote_data['waive_broker_fee']);
    $premiums = $calculation['premiums'];
    $breakdown = $calculation['breakdown'];
    error_log("Discounted premiums calculated: " . print_r($premiums, true));

    // Update quote with premiums
    updateQuote($conn, $quote_data, $_SESSION['user_id'], $premiums, $original_premiums);
    error_log("Updated quote with premiums: quote_id={$quote_data['quote_id']}");

    // Store breakdown in session for quote_results.php
    $_SESSION['quote_breakdown'] = $breakdown;

    // Commit transaction
    $conn->commit();
    error_log("Transaction committed: quote_id={$quote_data['quote_id']}");

    // Redirect to quote_results.php
    $_SESSION['success'] = "Quote " . ($quote_data['quote_id'] ? "updated" : "created") . " successfully!";
    header("Location: ../quote_management/quote_results.php?quote_id={$quote_data['quote_id']}");
    exit();

} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    $errors[] = $e->getMessage();
    error_log("Transaction failed: " . $e->getMessage());
    $_SESSION['errors'] = $errors;
    $_SESSION['form_data'] = $_POST;
    header("Location: ../quote_management/new_quote.php");
    exit();
}

$conn->close();
?>