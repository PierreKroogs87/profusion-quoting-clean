<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login_management/login.php");
    exit();
}
require '../db_connect.php';
require 'quote_commit_management/quote_calculator.php';

// Log POST data for debugging
error_log('POST Data: ' . print_r($_POST, true));

// Initialize error array
$errors = [];

// Define authorized roles for discount functionality
$authorized_roles = ['Profusion SuperAdmin', 'Profusion Manager', 'Profusion Consultant'];
$is_authorized = in_array($_SESSION['role_name'], $authorized_roles);

// Define valid car hire options
$valid_car_hire_options = [
    'None',
    'Group B Manual Hatchback',
    'Group C Manual Sedan',
    'Group D Automatic Hatchback',
    'Group H 1 Ton LDV',
    'Group M Luxury Hatchback'
];

// Validate and sanitize POST data
$quote_id = isset($_POST['quote_id']) && is_numeric($_POST['quote_id']) && $_POST['quote_id'] > 0 ? (int)$_POST['quote_id'] : null;
$quote_data = null;

// Validate existing quote only if quote_id is provided (editing)
if ($quote_id) {
    if (strpos($_SESSION['role_name'], 'Profusion') === 0) {
        $stmt = $conn->prepare("SELECT * FROM quotes WHERE quote_id = ?");
        $stmt->bind_param("i", $quote_id);
    } else {
        $stmt = $conn->prepare("SELECT * FROM quotes WHERE quote_id = ? AND (user_id = ? OR ? IN (SELECT user_id FROM users WHERE brokerage_id = quotes.brokerage_id))");
        $stmt->bind_param("iii", $quote_id, $_SESSION['user_id'], $_SESSION['user_id']);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        error_log("No quote found for quote_id=$quote_id for user_id={$_SESSION['user_id']} in save_quote.php");
        $errors[] = "Quote ID $quote_id not found or you do not have permission to edit it.";
        $_SESSION['errors'] = $errors;
        $_SESSION['form_data'] = $_POST;
        header("Location: ../dashboard.php");
        exit();
    }
    $quote_data = $result->fetch_assoc();
    $stmt->close();
}

$title = isset($_POST['title']) ? trim($_POST['title']) : '';
$initials = isset($_POST['initials']) ? trim($_POST['initials']) : '';
$surname = isset($_POST['surname']) ? trim($_POST['surname']) : '';
$marital_status = isset($_POST['marital_status']) ? trim($_POST['marital_status']) : '';
$client_id = isset($_POST['client_id']) ? trim($_POST['client_id']) : '';
$suburb_client = isset($_POST['suburb_client']) ? trim($_POST['suburb_client']) : '';
$brokerage_id = isset($_POST['brokerage_id']) && is_numeric($_POST['brokerage_id']) ? (int)$_POST['brokerage_id'] : ($_SESSION['brokerage_id'] ?? null);
$waive_broker_fee = isset($_POST['waive_broker_fee']) && $_POST['waive_broker_fee'] == '1' ? 1 : 0;
$vehicles = isset($_POST['vehicles']) && is_array($_POST['vehicles']) ? $_POST['vehicles'] : [];
$global_discount_percentage = $is_authorized && isset($_POST['global_discount_percentage']) && is_numeric($_POST['global_discount_percentage'])
    ? floatval($_POST['global_discount_percentage'])
    : 0.0;

// Validate required fields
if (empty($title)) $errors[] = "Title is required.";
if (empty($initials)) $errors[] = "Initials are required.";
if (empty($surname)) $errors[] = "Surname is required.";
if (empty($marital_status)) $errors[] = "Marital Status is required.";
if (empty($client_id) || !preg_match('/^\d{13}$/', $client_id)) $errors[] = "Valid 13-digit Client ID is required.";
if (empty($suburb_client)) $errors[] = "Suburb is required.";
if ($global_discount_percentage < 0 || $global_discount_percentage > 100) $errors[] = "Global discount percentage must be between 0 and 100.";

// Validate vehicles and drivers
$valid_coverage_types = ['Comprehensive', 'Third Party', 'Fire and Theft'];
if (empty($vehicles)) {
    $errors[] = "At least one vehicle is required.";
} else {
    foreach ($vehicles as $index => $vehicle) {
        if (!isset($vehicle['year']) || !is_numeric($vehicle['year']) || $vehicle['year'] < 1900 || $vehicle['year'] > 2025) {
            $errors[] = "Valid vehicle year is required for Vehicle " . ($index + 1) . ".";
        }
        if (empty($vehicle['make']) || $vehicle['make'] === '0') {
            $errors[] = "Valid vehicle make is required for Vehicle " . ($index + 1) . ".";
        }
        if (empty($vehicle['model'])) {
            $errors[] = "Vehicle model is required for Vehicle " . ($index + 1) . ".";
        }
        if (!isset($vehicle['value']) || !is_numeric($vehicle['value']) || $vehicle['value'] < 1000 || $vehicle['value'] > 750000) {
            $errors[] = "Valid sum insured (R1000-R750000) is required for Vehicle " . ($index + 1) . ".";
        }
        if (empty($vehicle['coverage_type']) || !in_array($vehicle['coverage_type'], $valid_coverage_types)) {
            $errors[] = "Valid coverage type is required for Vehicle " . ($index + 1) . ".";
        }
        if (empty($vehicle['use'])) $errors[] = "Vehicle use is required for Vehicle " . ($index + 1) . ".";
        if (empty($vehicle['parking'])) $errors[] = "Parking is required for Vehicle " . ($index + 1) . ".";
        if (empty($vehicle['car_hire']) || !in_array($vehicle['car_hire'], $valid_car_hire_options)) {
            $errors[] = "Valid car hire option is required for Vehicle " . ($index + 1) . ".";
        }
        if (empty($vehicle['street'])) $errors[] = "Street is required for Vehicle " . ($index + 1) . ".";
        if (empty($vehicle['suburb_vehicle'])) $errors[] = "Suburb is required for Vehicle " . ($index + 1) . ".";
        if (empty($vehicle['postal_code']) || !is_numeric($vehicle['postal_code'])) $errors[] = "Valid postal code is required for Vehicle " . ($index + 1) . ".";
        if (!isset($vehicle['driver']) || !is_array($vehicle['driver'])) {
            $errors[] = "Driver details are required for Vehicle " . ($index + 1) . ".";
        } else {
            $driver = $vehicle['driver'];
            if (empty($driver['title'])) $errors[] = "Driver title is required for Driver " . ($index + 1) . ".";
            if (empty($driver['initials'])) $errors[] = "Driver initials are required for Driver " . ($index + 1) . ".";
            if (empty($driver['surname'])) $errors[] = "Driver surname is required for Driver " . ($index + 1) . ".";
            if (empty($driver['id_number']) || !preg_match('/^\d{13}$/', $driver['id_number'])) $errors[] = "Valid 13-digit Driver ID is required for Driver " . ($index + 1) . ".";
            if (empty($driver['dob']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $driver['dob'])) $errors[] = "Valid date of birth is required for Driver " . ($index + 1) . ".";
            if (!isset($driver['age']) || !is_numeric($driver['age']) || $driver['age'] < 18 || $driver['age'] > 120) $errors[] = "Valid age (18-120) is required for Driver " . ($index + 1) . ".";
            if (empty($driver['licence_type'])) $errors[] = "Licence type is required for Driver " . ($index + 1) . ".";
            if (!isset($driver['year_of_issue']) || !is_numeric($driver['year_of_issue']) || $driver['year_of_issue'] < 1900 || $driver['year_of_issue'] > 2025) {
                $errors[] = "Valid year of issue is required for Driver " . ($index + 1) . ".";
            }
            if (!isset($driver['licence_held']) || !is_numeric($driver['licence_held']) || $driver['licence_held'] < 0) $errors[] = "Valid licence held years are required for Driver " . ($index + 1) . ".";
            if (empty($driver['marital_status'])) $errors[] = "Driver marital status is required for Driver " . ($index + 1) . ".";
            if (!isset($driver['ncb']) || !is_numeric($driver['ncb']) || $driver['ncb'] < 0 || $driver['ncb'] > 7) $errors[] = "Valid NCB (0-7) is required for Driver " . ($index + 1) . ".";
        }
    }
}

// Handle reset discounts
if (isset($_POST['reset_discounts']) && $is_authorized) {
    $global_discount_percentage = 0;
}

// If errors exist, redirect back with error message
error_log("Form Data in save_quote.php: " . print_r($_POST, true));
if (!empty($errors)) {
    error_log("Validation errors: " . implode(', ', $errors));
    $_SESSION['errors'] = $errors;
    $_SESSION['form_data'] = $_POST;
    header("Location: " . ($quote_id ? "quote_management/edit_quote.php?quote_id=$quote_id" : "quote_management/new_quote.php"));
    exit();
}

// Begin transaction
$conn->begin_transaction();

try {
    if ($quote_id) {
        // Update existing quote
        $stmt = $conn->prepare("
            UPDATE quotes SET
                user_id = ?, brokerage_id = ?, title = ?, initials = ?, surname = ?, marital_status = ?,
                client_id = ?, suburb_client = ?, waive_broker_fee = ?, fees = ?
            WHERE quote_id = ? AND user_id = ?
        ");
        $fees = '{}'; // Placeholder for fees
        $stmt->bind_param(
            "iissssssisii",
            $_SESSION['user_id'], $brokerage_id, $title, $initials, $surname, $marital_status,
            $client_id, $suburb_client, $waive_broker_fee, $fees, $quote_id, $_SESSION['user_id']
        );
        if (!$stmt->execute()) {
            throw new Exception("Failed to update quote: " . $stmt->error);
        }
        $stmt->close();
    } else {
        // Insert new quote
        $stmt = $conn->prepare("
            INSERT INTO quotes (
                user_id, brokerage_id, title, initials, surname, marital_status,
                client_id, suburb_client, waive_broker_fee, fees, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $fees = '{}'; // Placeholder for fees
        $stmt->bind_param(
            "iissssssis",
            $_SESSION['user_id'], $brokerage_id, $title, $initials, $surname, $marital_status,
            $client_id, $suburb_client, $waive_broker_fee, $fees
        );
        if (!$stmt->execute()) {
            throw new Exception("Failed to insert new quote: " . $stmt->error);
        }
        $quote_id = $conn->insert_id;
        error_log("New quote inserted with quote_id: $quote_id");
        $stmt->close();
    }

    // Fetch existing vehicle IDs for this quote
    $existing_vehicle_ids = [];
    if ($quote_id) {
        $stmt = $conn->prepare("SELECT vehicle_id FROM quote_vehicles WHERE quote_id = ? AND deleted_at IS NULL");
        $stmt->bind_param("i", $quote_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $existing_vehicle_ids[] = $row['vehicle_id'];
        }
        $stmt->close();
    }

    // Process vehicles and drivers
    $submitted_vehicle_ids = [];
    $saved_vehicles = [];
    foreach ($vehicles as $index => $vehicle) {
        $driver = $vehicle['driver'];
        $vehicle_id = isset($vehicle['vehicle_id']) && is_numeric($vehicle['vehicle_id']) && $vehicle['vehicle_id'] > 0 ? (int)$_POST['vehicle_id'] : null;
        $car_hire = $vehicle['car_hire'] ?? '';
        $discount_percentage = $global_discount_percentage;

        // Log vehicle data for debugging
        error_log("Processing vehicle $index: vehicle_id=$vehicle_id, make=" . ($vehicle['make'] ?? 'NULL') . ", coverage_type=" . ($vehicle['coverage_type'] ?? 'NULL') . ", car_hire=$car_hire, discount_percentage=$discount_percentage");

        if ($vehicle_id && in_array($vehicle_id, $existing_vehicle_ids)) {
            // Update existing vehicle
            $vehicle_stmt = $conn->prepare("
                UPDATE quote_vehicles SET
                    vehicle_year = ?, vehicle_make = ?, vehicle_model = ?, vehicle_value = ?,
                    coverage_type = ?, vehicle_use = ?, parking = ?, street = ?,
                    suburb_vehicle = ?, postal_code = ?, car_hire = ?,
                    discount_percentage = ?, discounted_by = ?, discounted_at = ?
                WHERE vehicle_id = ? AND quote_id = ?
            ");
            if (!$vehicle_stmt) {
                throw new Exception("Failed to prepare UPDATE statement for vehicle $index (ID: $vehicle_id): " . $conn->error);
            }
            $discounted_by = $discount_percentage > 0 && $is_authorized ? $_SESSION['user_id'] : null;
            $discounted_at = $discount_percentage > 0 && $is_authorized ? date('Y-m-d H:i:s') : null;
            $vehicle_stmt->bind_param(
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
                $car_hire,
                $discount_percentage,
                $discounted_by,
                $discounted_at,
                $vehicle_id,
                $quote_id
            );
            if (!$vehicle_stmt->execute()) {
                throw new Exception("Failed to update vehicle $index (ID: $vehicle_id): " . $vehicle_stmt->error);
            }
            $vehicle_stmt->close();

            // Update existing driver
            $driver_stmt = $conn->prepare("
                UPDATE quote_drivers SET
                    driver_title = ?, driver_initials = ?, driver_surname = ?, driver_id_number = ?,
                    dob = ?, age = ?, licence_type = ?, year_of_issue = ?, licence_held = ?,
                    driver_marital_status = ?, ncb = ?
                WHERE vehicle_id = ? AND quote_id = ?
            ");
            if (!$driver_stmt) {
                throw new Exception("Failed to prepare driver UPDATE statement for vehicle $index (ID: $vehicle_id): " . $conn->error);
            }
            $driver_stmt->bind_param(
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
            if (!$driver_stmt->execute()) {
                throw new Exception("Failed to update driver for vehicle $index (ID: $vehicle_id): " . $driver_stmt->error);
            }
            $driver_stmt->close();
        } else {
            // Insert new vehicle
            $vehicle_stmt = $conn->prepare("
                INSERT INTO quote_vehicles (
                    quote_id, vehicle_year, vehicle_make, vehicle_model, vehicle_value, coverage_type,
                    vehicle_use, parking, street, suburb_vehicle, postal_code, car_hire,
                    discount_percentage, discounted_by, discounted_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$vehicle_stmt) {
                throw new Exception("Failed to prepare INSERT statement for vehicle $index: " . $conn->error);
            }
            $discounted_by = $discount_percentage > 0 && $is_authorized ? $_SESSION['user_id'] : null;
            $discounted_at = $discount_percentage > 0 && $is_authorized ? date('Y-m-d H:i:s') : null;
            $vehicle_stmt->bind_param(
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
                $car_hire,
                $discount_percentage,
                $discounted_by,
                $discounted_at
            );
            if (!$vehicle_stmt->execute()) {
                throw new Exception("Failed to insert vehicle $index: " . $vehicle_stmt->error);
            }
            $vehicle_id = $conn->insert_id;
            $vehicle_stmt->close();

            // Insert new driver
            $driver_stmt = $conn->prepare("
                INSERT INTO quote_drivers (
                    vehicle_id, quote_id, driver_title, driver_initials, driver_surname, driver_id_number,
                    dob, age, licence_type, year_of_issue, licence_held, driver_marital_status, ncb
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$driver_stmt) {
                throw new Exception("Failed to prepare driver INSERT statement for vehicle $index: " . $conn->error);
            }
            $driver_stmt->bind_param(
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
            if (!$driver_stmt->execute()) {
                throw new Exception("Failed to insert driver for vehicle $index (ID: $vehicle_id): " . $driver_stmt->error);
            }
            $driver_stmt->close();
        }
        $submitted_vehicle_ids[] = $vehicle_id;

        // Log discount change if applicable
        if ($discount_percentage > 0 && $is_authorized) {
            logDiscountChange($conn, $quote_id, $vehicle_id, 'apply_discount', $discount_percentage, $_SESSION['user_id']);
        } elseif (isset($_POST['reset_discounts']) && $is_authorized) {
            logDiscountChange($conn, $quote_id, $vehicle_id, 'reset_discount', 0, $_SESSION['user_id']);
        }

        // Store vehicle and driver for premium calculation
        $saved_vehicles[] = [
            'vehicle' => [
                'value' => floatval($vehicle['value']),
                'make' => $vehicle['make'],
                'model' => $vehicle['model'],
                'use' => $vehicle['use'],
                'parking' => $vehicle['parking'],
                'car_hire' => $car_hire,
                'discount_percentage' => $discount_percentage
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
    if ($quote_id) {
        $vehicles_to_delete = array_diff($existing_vehicle_ids, array_filter($submitted_vehicle_ids, function($id) { return $id !== null; }));
        if (!empty($vehicles_to_delete)) {
            error_log("Soft-deleting vehicles: " . implode(',', $vehicles_to_delete));
            $placeholders = implode(',', array_fill(0, count($vehicles_to_delete), '?'));
            $stmt = $conn->prepare("UPDATE quote_vehicles SET deleted_at = NOW() WHERE vehicle_id IN ($placeholders) AND quote_id = ?");
            $types = str_repeat('i', count($vehicles_to_delete)) . 'i';
            $params = array_merge($vehicles_to_delete, [$quote_id]);
            $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) {
                throw new Exception("Failed to delete removed vehicles: " . $stmt->error);
            }
            $stmt->close();
        }
    }

    // Calculate original premiums (no discounts)
    $original_vehicles = array_map(function($v) {
        $v['vehicle']['discount_percentage'] = 0;
        return $v;
    }, $saved_vehicles);
    $original_calculation = calculateQuote($conn, $brokerage_id, $original_vehicles, $waive_broker_fee);
    $original_premiums = $original_calculation['premiums'];
    $original_breakdown = $original_calculation['breakdown'];

    // Calculate discounted premiums
    $calculation = calculateQuote($conn, $brokerage_id, $saved_vehicles, $waive_broker_fee);
    $premiums = $calculation['premiums'];
    $breakdown = $calculation['breakdown'];

    // Update quote with premiums
    $stmt = $conn->prepare("
        UPDATE quotes SET
            premium6 = ?, premium5 = ?, premium4 = ?, premium_flat = ?,
            original_premium6 = ?, original_premium5 = ?, original_premium4 = ?, original_premium_flat = ?
        WHERE quote_id = ?
    ");
    $stmt->bind_param(
        "ddddddddi",
        $premiums['premium6'],
        $premiums['premium5'],
        $premiums['premium4'],
        $premiums['premium_flat'],
        $original_premiums['premium6'],
        $original_premiums['premium5'],
        $original_premiums['premium4'],
        $original_premiums['premium_flat'],
        $quote_id
    );
    if (!$stmt->execute()) {
        throw new Exception("Failed to update quote premiums: " . $stmt->error);
    }
    $stmt->close();

    // Store breakdown in session for quote_results.php
    $_SESSION['quote_breakdown'] = $breakdown;

    // Commit transaction
    $conn->commit();
    
    // Redirect to quote_results.php
    $_SESSION['success'] = "Quote " . ($quote_id ? "updated" : "created") . " successfully!";
    header("Location: quote_management/quote_results.php?quote_id=$quote_id");
    exit();

} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    $errors[] = $e->getMessage();
    error_log("Transaction failed: " . $e->getMessage());
    $_SESSION['errors'] = $errors;
    $_SESSION['form_data'] = $_POST;
    header("Location: " . ($quote_id ? "quote_management/edit_quote.php?quote_id=$quote_id" : "quote_management/new_quote.php"));
    exit();
}

// Function to log discount changes
function logDiscountChange($conn, $quote_id, $vehicle_id, $action, $discount_percentage, $user_id) {
    $stmt = $conn->prepare("
        INSERT INTO quote_discount_logs (quote_id, vehicle_id, action, discount_percentage, user_id, timestamp)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param("iisdi", $quote_id, $vehicle_id, $action, $discount_percentage, $user_id);
    if (!$stmt->execute()) {
        error_log("Failed to log discount change for vehicle_id=$vehicle_id, action=$action");
    }
    $stmt->close();
}

$conn->close();
?>