<?php
function getBrokerFee($conn, $broker_id) {
    if ($broker_id <= 0) {
        return 0.00;
    }
    $stmt = $conn->prepare("SELECT broker_fee FROM brokerages WHERE brokerage_id = ?");
    $stmt->bind_param("i", $broker_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result['broker_fee'] ?? 0.00;
}

function clamp($value, $min, $max) {
    return min(max($value, $min), $max);
}

function getAgeRating($age) {
    if (18 <= $age && $age <= 19) return 0.35;
    if (19 < $age && $age <= 20) return 0.30;
    if (20 < $age && $age <= 21) return 0.30;
    if (21 < $age && $age <= 23) return 0.25;
    if (23 < $age && $age <= 25) return 0.20;
    if (25 < $age && $age <= 28) return 0.10;
    if (28 < $age && $age <= 30) return 0.0;
    if (30 < $age && $age <= 35) return -0.05;
    if (35 < $age && $age <= 38) return -0.15;
    if (38 < $age && $age <= 40) return -0.20;
    if (40 < $age && $age <= 45) return -0.25;
    if (45 < $age && $age <= 55) return -0.30;
    if (55 < $age && $age <= 60) return -0.35;
    if (60 < $age && $age <= 65) return -0.35;
    if ($age > 65) return -0.35;
    return 0.0;
}

function getLicenseTypeRating($license_type) {
    if ($license_type == "B") return -0.15;
    if ($license_type == "EB") return -0.15;
    if ($license_type == "C1") return 0.2;
    if ($license_type == "EC") return 0.2;
    if ($license_type == "EC1") return 0.2;
    return 0.0;
}

function getYearOfIssueRating($license_held) {
    if (0 <= $license_held && $license_held <= 1) return 0.4;
    if (1 < $license_held && $license_held <= 2) return 0.35;
    if (2 < $license_held && $license_held <= 3) return 0.3;
    if (3 < $license_held && $license_held <= 4) return 0.25;
    if (4 < $license_held && $license_held <= 5) return 0.2;
    if (5 < $license_held && $license_held <= 6) return 0.0;
    if (6 < $license_held && $license_held <= 7) return -0.2;
    if (7 < $license_held && $license_held <= 8) return -0.25;
    if (8 < $license_held && $license_held <= 9) return -0.30;
    if (9 < $license_held && $license_held <= 10) return -0.35;
    return -0.30;
}

function getMaritalStatusRating($marital_status) {
    if ($marital_status == "Single") return 0.075;
    if ($marital_status == "Married") return -0.1;
    if ($marital_status == "Divorced") return 0.1;
    if ($marital_status == "Cohabiting") return -0.075;
    if ($marital_status == "Widowed") return 0.075;
    return 0.0;
}

function getNCBRating($ncb) {
    if ($ncb == "0") return 0.0;
    if ($ncb == "1") return -0.15;
    if ($ncb == "2") return -0.175;
    if ($ncb == "3") return -0.25;
    if ($ncb == "4") return -0.35;
    if ($ncb == "5") return -0.40;
    if ($ncb == "6") return -0.45;
    if ($ncb == "7") return -0.50;
    return 0.0;
}

function getParkingRating($parking) {
    if ($parking === "Behind_Locked_Gates") return 0.0;
    if ($parking === "LockedUp_Garage") return -0.1;
    if ($parking === "in_street") return 0.2;
    return 0.2;
}

function getTitleRating($title) {
    if ($title === "Mr") return 0.075;
    if ($title === "Mrs") return -0.1;
    if ($title === "Miss") return 0.1;
    if ($title === "Dr") return -0.075;
    if ($title === "Prof") return 0.075;
    return 0.0;
}

function getUseRating($usage) {
    if ($usage === "Private") return 0.0;
    if ($usage === "Business") return 0.3;
    return 0.1;
}

function getValueRating($value) {
    if (0 <= $value && $value <= 50000) return 0.12;
    if (50000 < $value && $value <= 100000) return 0.11;
    if (100000 < $value && $value <= 150000) return 0.10;
    if (150000 < $value && $value <= 200000) return 0.09;
    if (200000 < $value && $value <= 250000) return 0.08;
    if (250000 < $value && $value <= 300000) return 0.075;
    if (300000 < $value && $value <= 350000) return 0.07;
    if (350000 < $value && $value <= 400000) return 0.065;
    if (400000 < $value && $value <= 450000) return 0.06;
    if (450000 < $value && $value <= 500000) return 0.055;
    if (500000 < $value && $value <= 550000) return 0.05;
    if (550000 < $value && $value <= 600000) return 0.045;
    if (600000 < $value && $value <= 650000) return 0.04;
    if (650000 < $value && $value <= 700000) return 0.035;
    if (700000 < $value && $value <= 750000) return 0.03;
    return 0.0;
}

function calculateBaseRate($value, $discount_percentage = 0.0) {
    $base_rate = getValueRating($value);
    return $base_rate * (1 - ($discount_percentage / 100));
}

function calculateBaseRatePremium($value, $base_rate) {
    return ($value * $base_rate) / 12;
}

function calculatePremiumAdjustment($base_rate_premium, $matrix_disc_loading_rating) {
    return $base_rate_premium * $matrix_disc_loading_rating;
}

function calculateMotorRiskPremium($base_rate_premium, $adjustment, $vehicle_value) {
    $adjusted_premium = $base_rate_premium + $adjustment;
    // Set to fixed minimum premium of R200.00
    $minimum_premium = 200.00;
    // Log the calculation details
    error_log("Motor Risk Premium Calculation: base_rate_premium=$base_rate_premium, adjustment=$adjustment, adjusted_premium=$adjusted_premium, minimum_premium=$minimum_premium");
    return max($adjusted_premium, $minimum_premium);
}

function calculatePremium6($motor_risk_premium, $total_fees) {
    return $motor_risk_premium + $total_fees;
}

function calculatePremium5($premium6, $total_fees) {
    return ($premium6 * 1.10) + $total_fees;
}

function calculatePremium4($premium5, $total_fees) {
    return ($premium5 * 1.10) + $total_fees;
}

function calculatePremiumFlat($premium4, $total_fees) {
    return ($premium4 * 1.05) + 195 + $total_fees;
}

function calculateQuote($conn, $broker_id, $vehicles, $waive_broker_fee) {
    $total_premiums = [
        'premium6' => 0.00,
        'premium5' => 0.00,
        'premium4' => 0.00,
        'premium_flat' => 0.00
    ];
    $breakdown = [];
    $total_per_vehicle_fees = 0.00;

    // Define car hire costs
    $car_hire_costs = [
        'None' => 0.00,
        'Group B Manual Hatchback' => 85.00,
        'Group C Manual Sedan' => 95.00,
        'Group D Automatic Hatchback' => 110.00,
        'Group H 1 Ton LDV' => 130.00,
        'Group M Luxury Hatchback' => 320.00
    ];

    // Initialize policy fees (applied once per quote)
    $broker_fee = $waive_broker_fee ? 0.00 : getBrokerFee($conn, $broker_id);
    $policy_fees = [
        'broker_fee' => $broker_fee,
        'convenience_drive' => 70.00,
        'claims_assist' => 70.00,
        'legal_assist' => 70.00,
        'personal_liability' => 5.04,
        'sasria_motor' => 2.02 * count($vehicles) // Sasria motor fee per vehicle, summed for policy
    ];
    $total_policy_fees = array_sum($policy_fees);

    foreach ($vehicles as $index => $vehicle_data) {
        $vehicle = $vehicle_data['vehicle'];
        $driver = $vehicle_data['driver'];

        // Validate vehicle data
        $value = floatval($vehicle['value'] ?? 0);
        $make = $vehicle['make'] ?? '';
        $model = $vehicle['model'] ?? '';
        $car_hire = $vehicle['car_hire'] ?? '';
        $discount_percentage = floatval($vehicle['discount_percentage'] ?? 0.0);
        if ($value <= 0 || empty($make) || empty($model)) {
            error_log("Skipping vehicle $index due to invalid data: Value=$value, Make=$make, Model=$model");
            continue; // Skip invalid vehicles
        }

        // Get car hire cost
        $car_hire_cost = isset($car_hire_costs[$car_hire]) ? $car_hire_costs[$car_hire] : 0.00;
        $policy_fees['car_hire'] = $car_hire_cost; // Add car hire as a separate fee
        $total_policy_fees += $car_hire_cost; // Add car hire cost to total fees

        // Extract vehicle and driver details
        $usage = $vehicle['use'] ?? 'Private';
        $parking = $vehicle['parking'] ?? 'Behind_Locked_Gates';
        $age = intval($driver['age'] ?? 30);
        $license_type = $driver['licence_type'] ?? 'B';
        $license_held = intval($driver['licence_held'] ?? 0);
        $marital_status = $driver['marital_status'] ?? 'Single';
        $ncb = $driver['ncb'] ?? '0';
        $title = $driver['title'] ?? 'Mr';

        // Calculate ratings
        $age_rating = getAgeRating($age);
        $license_type_rating = getLicenseTypeRating($license_type);
        $year_of_issue_rating = getYearOfIssueRating($license_held);
        $marital_status_rating = getMaritalStatusRating($marital_status);
        $ncb_rating = getNCBRating($ncb);
        $parking_rating = getParkingRating($parking);
        $title_rating = getTitleRating($title);
        $vehicle_use_rating = getUseRating($usage);

        $matrix_disc_loading_rating = $age_rating + $license_type_rating + $year_of_issue_rating + 
                                     $marital_status_rating + $ncb_rating + $parking_rating + 
                                     $title_rating + $vehicle_use_rating;
        $matrix_disc_loading_rating = clamp($matrix_disc_loading_rating, -0.50, 2);

        // Calculate base motor risk premium with discount
        $base_rate_value = calculateBaseRate($value, $discount_percentage);
        $base_rate_premium = calculateBaseRatePremium($value, $base_rate_value);
        $adjustment = calculatePremiumAdjustment($base_rate_premium, $matrix_disc_loading_rating);
        $motor_risk_premium6 = calculateMotorRiskPremium($base_rate_premium, $adjustment, $value);

        // Calculate derived premiums per vehicle
        $motor_risk_premium5 = $motor_risk_premium6 * 1.10;
        $motor_risk_premium4 = $motor_risk_premium5 * 1.10;
        $motor_risk_premium_flat = $motor_risk_premium4 * 1.05 + (195 / count($vehicles)); // Distribute flat fee across vehicles

        // Add to total premiums
        $total_premiums['premium6'] += round($motor_risk_premium6, 2);
        $total_premiums['premium5'] += round($motor_risk_premium5, 2);
        $total_premiums['premium4'] += round($motor_risk_premium4, 2);
        $total_premiums['premium_flat'] += round($motor_risk_premium_flat, 2);

        // Debug: Log premium calculation details
        error_log("Vehicle $index: Value=$value, Make=$make, Model=$model, CarHire=$car_hire, CarHireCost=$car_hire_cost, ConvenienceDrive=70.00, BaseRate=$base_rate_value, BasePremium=$base_rate_premium, Adjustment=$adjustment, MotorRiskPremium6=$motor_risk_premium6, MotorRiskPremium5=$motor_risk_premium5, MotorRiskPremium4=$motor_risk_premium4, MotorRiskPremiumFlat=$motor_risk_premium_flat, DiscountPercentage=$discount_percentage");

        // Store per-vehicle breakdown
        $breakdown[$index] = [
            'motor_risk_premium6' => round($motor_risk_premium6, 2),
            'motor_risk_premium5' => round($motor_risk_premium5, 2),
            'motor_risk_premium4' => round($motor_risk_premium4, 2),
            'motor_risk_premium_flat' => round($motor_risk_premium_flat, 2),
            'excess_buyback' => 195.00,
            'convenience_drive' => 70.00,
            'car_hire' => $car_hire_cost,
            'security' => getSecurityRequirements($make, $model, $value)
        ];
    }

    // Calculate final total premiums by adding policy-wide fees once
    $total_premiums['premium6'] = round($total_premiums['premium6'] + $total_policy_fees, 2);
    $total_premiums['premium5'] = round($total_premiums['premium5'] + $total_policy_fees, 2);
    $total_premiums['premium4'] = round($total_premiums['premium4'] + $total_policy_fees, 2);
    $total_premiums['premium_flat'] = round($total_premiums['premium_flat'] + $total_policy_fees, 2);

    // Add policy-wide fees to breakdown
    $breakdown['policy_fees'] = $policy_fees;
    $breakdown['total_policy_fees'] = round($total_policy_fees, 2);

    // Calculate total risk premiums for each option
    $total_risk_premium6 = 0.00;
    $total_risk_premium5 = 0.00;
    $total_risk_premium4 = 0.00;
    $total_risk_premium_flat = 0.00;
    foreach ($breakdown as $index => $vehicle_breakdown) {
        if (is_numeric($index)) {
            $total_risk_premium6 += $vehicle_breakdown['motor_risk_premium6'];
            $total_risk_premium5 += $vehicle_breakdown['motor_risk_premium5'];
            $total_risk_premium4 += $vehicle_breakdown['motor_risk_premium4'];
            $total_risk_premium_flat += $vehicle_breakdown['motor_risk_premium_flat'];
        }
    }
    $breakdown['total_risk_premium6'] = round($total_risk_premium6, 2);
    $breakdown['total_risk_premium5'] = round($total_risk_premium5, 2);
    $breakdown['total_risk_premium4'] = round($total_risk_premium4, 2);
    $breakdown['total_risk_premium_flat'] = round($total_risk_premium_flat, 2);

    error_log("Total Premiums: " . print_r($total_premiums, true));
    error_log("Breakdown: " . print_r($breakdown, true));

    return [
        'premiums' => $total_premiums,
        'breakdown' => $breakdown,
        'total_fees' => round($total_policy_fees, 2),
        'per_policy_fees' => round($total_policy_fees, 2),
        'per_vehicle_fees' => 0.00 // No per-vehicle fees, all fees are policy-wide
    ];
}

function getSecurityRequirements(string $vehicle_make, string $vehicle_model, float $vehicle_value): string {
    $make = strtolower($vehicle_make);
    $model = strtolower($vehicle_model);
    $value = $vehicle_value;

    if ($make === 'volkswagen') {
        return 'Reactive Tracking';
    }

    if ($make === 'toyota') {
        $proactiveModels = ['hilux', 'fortuner', 'prado', 'land cruiser'];
        foreach ($proactiveModels as $pattern) {
            if (str_contains($model, strtolower($pattern))) {
                return 'Proactive Tracking';
            }
        }
    }

    if ($value >= 350000) {
        return 'Proactive Tracking';
    } elseif ($value >= 250000) {
        return 'Reactive Tracking';
    }

    return 'None';
}
?>