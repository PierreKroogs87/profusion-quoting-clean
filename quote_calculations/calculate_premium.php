<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}
require '../db_connect.php';
require '../quote_commit_management/quote_calculator.php';

// Log POST data for debugging
error_log('POST Data for calculate_premium: ' . print_r($_POST, true));

// Define authorized roles for discount functionality
$authorized_roles = ['Profusion SuperAdmin', 'Profusion Manager', 'Profusion Consultant'];
$is_authorized = in_array($_SESSION['role_name'], $authorized_roles);

// Validate POST data
$quote_id = isset($_POST['quote_id']) && is_numeric($_POST['quote_id']) ? (int)$_POST['quote_id'] : null;
$brokerage_id = isset($_POST['brokerage_id']) && is_numeric($_POST['brokerage_id']) ? (int)$_POST['brokerage_id'] : ($_SESSION['brokerage_id'] ?? null);
$waive_broker_fee = isset($_POST['waive_broker_fee']) && $_POST['waive_broker_fee'] == '1' ? 1 : 0;
$vehicles = isset($_POST['vehicles']) && is_array($_POST['vehicles']) ? $_POST['vehicles'] : [];
$global_discount_percentage = $is_authorized && isset($_POST['global_discount_percentage']) && is_numeric($_POST['global_discount_percentage'])
    ? floatval($_POST['global_discount_percentage'])
    : 0.0;

// Validate global discount
if ($global_discount_percentage < 0 || $global_discount_percentage > 100) {
    error_log("Invalid global discount percentage: $global_discount_percentage");
    echo json_encode(['error' => 'Global discount percentage must be between 0 and 100.']);
    exit();
}

if (empty($vehicles)) {
    error_log('No vehicles provided in calculate_premium');
    echo json_encode(['error' => 'No vehicles provided']);
    exit();
}

// Prepare vehicle data for calculation
$saved_vehicles = [];
$errors = [];
$valid_coverage_types = ['Comprehensive', 'Third Party', 'Fire and Theft'];
$valid_car_hire_options = [
    'None',
    'Group B Manual Hatchback',
    'Group C Manual Sedan',
    'Group D Automatic Hatchback',
    'Group H 1 Ton LDV',
    'Group M Luxury Hatchback'
];

// Log global discount percentage
error_log("Global Discount Percentage: $global_discount_percentage");

foreach ($vehicles as $index => $vehicle) {
    // Log vehicle data for debugging
    error_log("Processing vehicle $index: " . print_r($vehicle, true));

    // Essential fields with validation
    $value = isset($vehicle['value']) && is_numeric($vehicle['value']) && $vehicle['value'] >= 1000 && $vehicle['value'] <= 750000
        ? floatval($vehicle['value'])
        : null;
    $make = !empty($vehicle['make']) && $vehicle['make'] !== '0' ? trim($vehicle['make']) : null;
    $model = !empty($vehicle['model']) ? trim($vehicle['model']) : null;

    // Skip vehicle if essential fields are missing
    if ($value === null || $make === null || $model === null) {
        error_log("Skipping vehicle $index due to missing essential fields: Value=$value, Make=$make, Model=$model");
        $errors[] = "Vehicle " . ($index + 1) . " is missing required fields (value, make, or model).";
        continue;
    }

    // Optional vehicle fields with defaults
    $year = isset($vehicle['year']) && is_numeric($vehicle['year']) && $vehicle['year'] >= 1900 && $vehicle['year'] <= 2025
        ? (int)$vehicle['year']
        : 2020; // Default year
    $coverage_type = !empty($vehicle['coverage_type']) && in_array($vehicle['coverage_type'], $valid_coverage_types)
        ? $vehicle['coverage_type']
        : 'Comprehensive'; // Default coverage
    $use = !empty($vehicle['use']) ? $vehicle['use'] : 'Private'; // Default use
    $parking = !empty($vehicle['parking']) ? $vehicle['parking'] : 'Behind_Locked_Gates'; // Default parking
    $car_hire = !empty($vehicle['car_hire']) && in_array($vehicle['car_hire'], $valid_car_hire_options)
        ? $vehicle['car_hire']
        : 'Group B Manual Hatchback'; // Default car hire

    // Driver data with defaults
    $driver = $vehicle['driver'] ?? [];
    $age = isset($driver['age']) && is_numeric($driver['age']) && $driver['age'] >= 18 && $driver['age'] <= 120
        ? (int)$driver['age']
        : 30; // Default age
    $licence_type = !empty($driver['licence_type']) ? $driver['licence_type'] : 'B'; // Default licence type
    $licence_held = isset($driver['licence_held']) && is_numeric($driver['licence_held']) && $driver['licence_held'] >= 0
        ? (int)$driver['licence_held']
        : 5; // Default years held
    $ncb = isset($driver['ncb']) && is_numeric($driver['ncb']) && $driver['ncb'] >= 0 && $driver['ncb'] <= 7
        ? $driver['ncb']
        : '0'; // Default NCB
    $marital_status = !empty($driver['marital_status']) ? $driver['marital_status'] : 'Single'; // Default marital status
    $title = !empty($driver['title']) ? $driver['title'] : 'Mr'; // Default title

    // Apply global discount to this vehicle
    $discount_percentage = $global_discount_percentage;
    error_log("Vehicle $index assigned discount_percentage: $discount_percentage");

    // Prepare vehicle and driver data
    $saved_vehicles[] = [
        'vehicle' => [
            'value' => $value,
            'make' => $make,
            'model' => $model,
            'use' => $use,
            'parking' => $parking,
            'car_hire' => $car_hire,
            'discount_percentage' => $discount_percentage
        ],
        'driver' => [
            'age' => $age,
            'licence_type' => $licence_type,
            'licence_held' => $licence_held,
            'marital_status' => $marital_status,
            'ncb' => $ncb,
            'title' => $title
        ]
    ];
}

if (empty($saved_vehicles)) {
    error_log('No valid vehicles for premium calculation');
    echo json_encode(['error' => 'No valid vehicles for calculation: ' . implode(', ', $errors)]);
    exit();
}

// Calculate premiums
$calculation = calculateQuote($conn, $brokerage_id, $saved_vehicles, $waive_broker_fee);
$premiums = $calculation['premiums'];

// Calculate effective rating percentages for each vehicle
$rating_percentages = [];
foreach ($saved_vehicles as $index => $vehicle_data) {
    $value = $vehicle_data['vehicle']['value'];
    $discount_percentage = $vehicle_data['vehicle']['discount_percentage'];
    $base_rate = getValueRating($value); // From quote_calculator.php
    $effective_rate = $base_rate * (1 - ($discount_percentage / 100));
    $rating_percentages[$index] = round($effective_rate * 100, 2); // Convert to percentage
    error_log("Vehicle $index: Base Rate=$base_rate, Discount Percentage=$discount_percentage, Effective Rate=$effective_rate, Rating Percentage={$rating_percentages[$index]}%");
}

// Log calculated premiums and ratings
error_log('Calculated Premiums: ' . print_r($premiums, true));
error_log('Effective Rating Percentages: ' . print_r($rating_percentages, true));

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'premiums' => [
        'premium6' => round($premiums['premium6'], 2),
        'premium5' => round($premiums['premium5'], 2),
        'premium4' => round($premiums['premium4'], 2),
        'premium_flat' => round($premiums['premium_flat'], 2)
    ],
    'rating_percentages' => $rating_percentages
]);

$conn->close();
?>