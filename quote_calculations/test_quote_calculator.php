<?php
require '../db_connect.php';
require 'quote_calculator.php';

// Define arrays for random field values
$marital_statuses = ['Single', 'Married', 'Divorced', 'Cohabiting', 'Widowed'];
$license_types = ['B', 'EB', 'C1', 'EC', 'EC1'];
$parking_options = ['Behind_Locked_Gates', 'LockedUp_Garage', 'in_street'];
$titles = ['Mr', 'Mrs', 'Miss', 'Dr', 'Prof'];
$uses = ['Private', 'Business'];
$makes = ['Toyota', 'Volkswagen', 'Ford', 'Honda', 'BMW'];
$models = ['Corolla', 'Polo', 'Focus', 'Civic', '3 Series'];
$vehicle_values = [50000, 100000, 150000, 200000, 250000, 300000, 350000, 400000, 450000, 500000];

// Define test cases for age and license held ranges
$test_cases = [
    ['age_min' => 18, 'age_max' => 20, 'license_held_min' => 1, 'license_held_max' => 2],
    ['age_min' => 21, 'age_max' => 25, 'license_held_min' => 3, 'license_held_max' => 7],
    ['age_min' => 26, 'age_max' => 30, 'license_held_min' => 8, 'license_held_max' => 10],
    ['age_min' => 31, 'age_max' => 35, 'license_held_min' => 10, 'license_held_max' => 15],
    ['age_min' => 36, 'age_max' => 40, 'license_held_min' => 10, 'license_held_max' => 20],
    ['age_min' => 41, 'age_max' => 45, 'license_held_min' => 10, 'license_held_max' => 25],
    ['age_min' => 46, 'age_max' => 50, 'license_held_min' => 10, 'license_held_max' => 30],
    ['age_min' => 55, 'age_max' => 65, 'license_held_min' => 10, 'license_held_max' => 40]
];

// Generate vehicles
$vehicles = [];
foreach ($test_cases as $index => $case) {
    $age = rand($case['age_min'], $case['age_max']);
    $license_held = rand($case['license_held_min'], $case['license_held_max']);
    // Ensure license_held is realistic (driver must be at least 16 when license issued)
    $max_license_held = $age - 16;
    $license_held = min($license_held, $max_license_held);
    $year_of_issue = 2025 - $license_held;

    $vehicles[] = [
        'vehicle' => [
            'value' => $vehicle_values[array_rand($vehicle_values)],
            'use' => $uses[array_rand($uses)],
            'parking' => $parking_options[array_rand($parking_options)],
            'make' => $makes[array_rand($makes)],
            'model' => $models[array_rand($models)],
            'year' => rand(2005, 2025),
            'coverage_type' => 'Comprehensive'
        ],
        'driver' => [
            'age' => $age,
            'licence_type' => $license_types[array_rand($license_types)],
            'licence_held' => $license_held,
            'year_of_issue' => $year_of_issue,
            'marital_status' => $marital_statuses[array_rand($marital_statuses)],
            'ncb' => '0',
            'title' => $titles[array_rand($titles)]
        ]
    ];
}

// Calculate quote
$broker_id = 1; // Assume getBrokerFee returns R100
$waive_broker_fee = 0;
$result = calculateQuote($conn, $broker_id, $vehicles, $waive_broker_fee);

// Display results
echo "<h2>Comprehensive Rating Check Results</h2>";
echo "<h3>Input Vehicles</h3>";
foreach ($vehicles as $index => $vehicle) {
    echo "<h4>Vehicle " . ($index + 1) . "</h4>";
    echo "<pre>";
    print_r($vehicle);
    echo "</pre>";
}

echo "<h3>Calculated Results</h3>";
foreach ($result['breakdown'] as $index => $breakdown) {
    echo "<h4>Vehicle " . ($index + 1) . "</h4>";
    echo "<p><strong>Age Rating</strong>: " . getAgeRating($vehicles[$index]['driver']['age']) . "</p>";
    echo "<p><strong>License Type Rating</strong>: " . getLicenseTypeRating($vehicles[$index]['driver']['licence_type']) . "</p>";
    echo "<p><strong>Years License Held Rating</strong>: " . getYearOfIssueRating($vehicles[$index]['driver']['licence_held']) . "</p>";
    echo "<p><strong>Marital Status Rating</strong>: " . getMaritalStatusRating($vehicles[$index]['driver']['marital_status']) . "</p>";
    echo "<p><strong>NCB Rating</strong>: " . getNCBRating($vehicles[$index]['driver']['ncb']) . "</p>";
    echo "<p><strong>Parking Rating</strong>: " . getParkingRating($vehicles[$index]['vehicle']['parking']) . "</p>";
    echo "<p><strong>Title Rating</strong>: " . getTitleRating($vehicles[$index]['driver']['title']) . "</p>";
    echo "<p><strong>Vehicle Use Rating</strong>: " . getUseRating($vehicles[$index]['vehicle']['use']) . "</p>";
    $matrix_disc_loading_rating = getAgeRating($vehicles[$index]['driver']['age']) +
                                 getLicenseTypeRating($vehicles[$index]['driver']['licence_type']) +
                                 getYearOfIssueRating($vehicles[$index]['driver']['licence_held']) +
                                 getMaritalStatusRating($vehicles[$index]['driver']['marital_status']) +
                                 getNCBRating($vehicles[$index]['driver']['ncb']) +
                                 getParkingRating($vehicles[$index]['vehicle']['parking']) +
                                 getTitleRating($vehicles[$index]['driver']['title']) +
                                 getUseRating($vehicles[$index]['vehicle']['use']);
    $matrix_disc_loading_rating = clamp($matrix_disc_loading_rating, -0.75, 2);
    echo "<p><strong>Matrix Discount/Loading Rating</strong>: $matrix_disc_loading_rating</p>";
    echo "<p><strong>Motor Risk Premium</strong>: R" . number_format($breakdown['motor_risk_premium'], 2) . "</p>";
    echo "<p><strong>Security Requirement</strong>: " . $breakdown['security'] . "</p>";
}

echo "<h3>Aggregated Premiums</h3>";
echo "<p><strong>Premium 6</strong>: R" . number_format($result['premiums']['premium6'], 2) . "</p>";
echo "<p><strong>Premium 5</strong>: R" . number_format($result['premiums']['premium5'], 2) . "</p>";
echo "<p><strong>Premium 4</strong>: R" . number_format($result['premiums']['premium4'], 2) . "</p>";
echo "<p><strong>Premium Flat</strong>: R" . number_format($result['premiums']['premium_flat'], 2) . "</p>";
echo "<p><strong>Total Fees</strong>: R" . number_format($result['total_fees'], 2) . "</p>";
echo "<p><strong>Per-Policy Fees</strong>: R" . number_format($result['per_policy_fees'], 2) . "</p>";
echo "<p><strong>Per-Vehicle Fees</strong>: R" . number_format($result['per_vehicle_fees'], 2) . "</p>";

mysqli_close($conn);
?>