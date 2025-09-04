<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login_management/login.php");
    exit();
}
require '../db_connect.php';

// Define authorized roles for editing
$authorized_roles = ['Profusion SuperAdmin', 'Profusion Manager', 'Profusion Consultant', 'Broker Manager'];
if (!in_array($_SESSION['role_name'], $authorized_roles)) {
    header("Location: ../dashboard.php");
    exit();
}

// Get policy_id from URL
$policy_id = $_GET['policy_id'] ?? null;
if (!$policy_id || !is_numeric($policy_id)) {
    $_SESSION['errors'] = ["Invalid or missing policy ID."];
    header("Location: amendments.php");
    exit();
}

// Fetch policy and quote data
$stmt = $conn->prepare("
    SELECT p.*, q.quote_id, q.brokerage_id FROM policies p
    JOIN quotes q ON p.quote_id = q.quote_id
    WHERE p.policy_id = ?
");
$stmt->bind_param("i", $policy_id);
$stmt->execute();
$policy_result = $stmt->get_result();
if ($policy_result->num_rows === 0) {
    $_SESSION['errors'] = ["No policy found for this ID."];
    header("Location: amendments.php");
    exit();
}
$policy_data = $policy_result->fetch_assoc();
$quote_id = $policy_data['quote_id'];
$brokerage_id = $policy_data['brokerage_id'];
$stmt->close();

// Fetch broker fee
$stmt = $conn->prepare("SELECT broker_fee FROM brokerages WHERE brokerage_id = ?");
$stmt->bind_param("i", $brokerage_id);
$stmt->execute();
$result = $stmt->get_result();
$broker_fee = $result->num_rows > 0 ? $result->fetch_assoc()['broker_fee'] : 0.00;
$stmt->close();

// Fetch policy holder from quotes
$stmt = $conn->prepare("
    SELECT initials, surname, title, marital_status, client_id
    FROM quotes WHERE quote_id = ?
");
$stmt->bind_param("i", $quote_id);
$stmt->execute();
$result = $stmt->get_result();
$policy_holder = $result->num_rows > 0 ? $result->fetch_assoc() : [];
$stmt->close();

// Fetch policy holder responses from underwriting data
$policy_holder_responses = [];
$stmt = $conn->prepare("
    SELECT question_key, response
    FROM policy_underwriting_data
    WHERE policy_id = ? AND section = 'policy_holder_information'
");
$stmt->bind_param("i", $policy_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $policy_holder_responses[$row['question_key']] = $row['response'];
}
$stmt->close();

// Fetch vehicle and driver data
$vehicles = [];
$vehicle_stmt = $conn->prepare("
    SELECT qv.*, qd.driver_initials, qd.driver_surname, qd.driver_id_number, qd.dob, qd.licence_type, qd.year_of_issue
    FROM quote_vehicles qv
    LEFT JOIN quote_drivers qd ON qv.vehicle_id = qd.vehicle_id AND qv.quote_id = qd.quote_id
    WHERE qv.quote_id = ? AND qv.deleted_at IS NULL
    ORDER BY qv.vehicle_id
");
$vehicle_stmt->bind_param("i", $quote_id);
$vehicle_stmt->execute();
$vehicle_result = $vehicle_stmt->get_result();
$index = 0;
while ($vehicle = $vehicle_result->fetch_assoc()) {
    $vehicle_responses = [];
    $stmt = $conn->prepare("
        SELECT question_key, response
        FROM policy_underwriting_data
        WHERE policy_id = ? AND section = 'motor_section' AND question_key LIKE ?
    ");
    $like_pattern = "3.%_$index";
    $stmt->bind_param("is", $policy_id, $like_pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $vehicle_responses[$row['question_key']] = $row['response'];
    }
    $stmt->close();

    // Fetch additional drivers
    $additional_drivers = [];
    $add_stmt = $conn->prepare("
        SELECT * FROM quote_additional_drivers
        WHERE vehicle_id = ? AND quote_id = ? AND deleted_at IS NULL
    ");
    $add_stmt->bind_param("ii", $vehicle['vehicle_id'], $quote_id);
    $add_stmt->execute();
    $add_result = $add_stmt->get_result();
    while ($add_driver = $add_result->fetch_assoc()) {
        $additional_drivers[] = $add_driver;
    }
    $add_stmt->close();

    $vehicles[] = [
        'vehicle' => $vehicle,
        'driver' => [
            'driver_initials' => $vehicle['driver_initials'],
            'driver_surname' => $vehicle['driver_surname'],
            'driver_id_number' => $vehicle['driver_id_number'],
            'dob' => $vehicle['dob'],
            'licence_type' => $vehicle['licence_type'],
            'year_of_issue' => $vehicle['year_of_issue']
        ],
        'responses' => $vehicle_responses,
        'additional_drivers' => $additional_drivers
    ];
    $index++;
}
$vehicle_stmt->close();

// Fetch banking details from underwriting data
$banking_responses = [];
$stmt = $conn->prepare("
    SELECT question_key, response
    FROM policy_underwriting_data
    WHERE policy_id = ? AND section = 'bank_details_mandate'
");
$stmt->bind_param("i", $policy_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $banking_responses[$row['question_key']] = $row['response'];
}
$stmt->close();

// Handle form submission (update all sections)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();
    try {
        // Update policy table (status, premium_type, premium_amount, etc.)
        $new_status = $_POST['status'] ?? $policy_data['status'];
        $new_premium_type = $_POST['premium_type'] ?? $policy_data['premium_type'];
        $new_premium_amount = $_POST['premium_amount'] ?? $policy_data['premium_amount'];
        $new_policy_start_date = $_POST['policy_start_date'] ?? $policy_data['policy_start_date'];
        $new_account_holder = $_POST['account_holder'] ?? $policy_data['account_holder'];
        $new_bank_name = $_POST['bank_name'] ?? $policy_data['bank_name'];
        $new_account_number = $_POST['account_number'] ?? $policy_data['account_number'];
        $new_branch_code = $_POST['branch_code'] ?? $policy_data['branch_code'];
        $new_account_type = $_POST['account_type'] ?? $policy_data['account_type'];
        $new_debit_date = $_POST['debit_date'] ?? $policy_data['debit_date'];

        $update_policy = $conn->prepare("
            UPDATE policies SET status = ?, premium_type = ?, premium_amount = ?, policy_start_date = ?,
            account_holder = ?, bank_name = ?, account_number = ?, branch_code = ?, account_type = ?, debit_date = ?, updated_at = NOW()
            WHERE policy_id = ?
        ");
        $update_policy->bind_param("ssds ssssss i", $new_status, $new_premium_type, $new_premium_amount, $new_policy_start_date,
            $new_account_holder, $new_bank_name, $new_account_number, $new_branch_code, $new_account_type, $new_debit_date, $policy_id);
        if (!$update_policy->execute()) {
            throw new Exception("Failed to update policy: " . $update_policy->error);
        }
        $update_policy->close();

        // Update quotes table (client info)
        $new_initials = $_POST['initials'] ?? $policy_holder['initials'];
        $new_surname = $_POST['surname'] ?? $policy_holder['surname'];
        $new_title = $_POST['title'] ?? $policy_holder['title'];
        $new_marital_status = $_POST['marital_status'] ?? $policy_holder['marital_status'];
        $new_client_id = $_POST['client_id'] ?? $policy_holder['client_id'];

        $update_quote = $conn->prepare("
            UPDATE quotes SET initials = ?, surname = ?, title = ?, marital_status = ?, client_id = ?
            WHERE quote_id = ?
        ");
        $update_quote->bind_param("sssssi", $new_initials, $new_surname, $new_title, $new_marital_status, $new_client_id, $quote_id);
        if (!$update_quote->execute()) {
            throw new Exception("Failed to update quote: " . $update_quote->error);
        }
        $update_quote->close();

        // Update policy holder underwriting data (e.g., cell, email, addresses)
        $policy_holder_keys = [
            '2.5_cell_number' => $_POST['cell_number'] ?? $policy_holder_responses['2.5_cell_number'],
            '2.8_email' => $_POST['email'] ?? $policy_holder_responses['2.8_email'],
            '2.10_physical_address' => $_POST['physical_address'] ?? $policy_holder_responses['2.10_physical_address'],
            '2.10_physical_suburb' => $_POST['physical_suburb'] ?? $policy_holder_responses['2.10_physical_suburb'],
            '2.10_physical_postal_code' => $_POST['physical_postal_code'] ?? $policy_holder_responses['2.10_physical_postal_code'],
            '2.12_postal_address' => $_POST['postal_address'] ?? $policy_holder_responses['2.12_postal_address'],
            '2.12_postal_suburb' => $_POST['postal_suburb'] ?? $policy_holder_responses['2.12_postal_suburb'],
            '2.12_postal_postal_code' => $_POST['postal_postal_code'] ?? $policy_holder_responses['2.12_postal_postal_code']
        ];
        $update_uw = $conn->prepare("
            INSERT INTO policy_underwriting_data (policy_id, section, question_key, response)
            VALUES (?, 'policy_holder_information', ?, ?)
            ON DUPLICATE KEY UPDATE response = ?
        ");
        foreach ($policy_holder_keys as $key => $value) {
            $update_uw->bind_param("isss", $policy_id, $key, $value, $value);
            if (!$update_uw->execute()) {
                throw new Exception("Failed to update policy holder data for $key: " . $update_uw->error);
            }
        }
        $update_uw->close();

        // Update vehicles (loop over posted vehicles)
        $vehicles_post = $_POST['vehicles'] ?? [];
        foreach ($vehicles_post as $index => $vehicle_data) {
            if (!isset($vehicles[$index])) continue;

            $vehicle_id = $vehicles[$index]['vehicle']['vehicle_id'];

            // Update quote_vehicles
            $new_year = $vehicle_data['year'] ?? $vehicles[$index]['vehicle']['vehicle_year'];
            $new_make = $vehicle_data['make'] ?? $vehicles[$index]['vehicle']['vehicle_make'];
            $new_model = $vehicle_data['model'] ?? $vehicles[$index]['vehicle']['vehicle_model'];
            $new_value = $vehicle_data['value_sum_insured'] ?? $vehicles[$index]['vehicle']['vehicle_value'];
            $new_street = $vehicle_data['risk_street'] ?? $vehicles[$index]['vehicle']['street'];
            $new_suburb_vehicle = $vehicle_data['risk_suburb'] ?? $vehicles[$index]['vehicle']['suburb_vehicle'];
            $new_postal_code = $vehicle_data['risk_postal_code'] ?? $vehicles[$index]['vehicle']['postal_code'];
            $new_car_hire = $vehicle_data['car_hire_option'] ?? $vehicles[$index]['vehicle']['car_hire'];

            $update_vehicle = $conn->prepare("
                UPDATE quote_vehicles SET vehicle_year = ?, vehicle_make = ?, vehicle_model = ?, vehicle_value = ?,
                street = ?, suburb_vehicle = ?, postal_code = ?, car_hire = ?
                WHERE vehicle_id = ? AND quote_id = ?
            ");
            $update_vehicle->bind_param("issdsssii", $new_year, $new_make, $new_model, $new_value,
                $new_street, $new_suburb_vehicle, $new_postal_code, $new_car_hire, $vehicle_id, $quote_id);
            if (!$update_vehicle->execute()) {
                throw new Exception("Failed to update vehicle $index: " . $update_vehicle->error);
            }
            $update_vehicle->close();

            // Update quote_drivers
            $new_driver_initials = $vehicle_data['regular_driver_name'] ?? $vehicles[$index]['driver']['driver_initials'];
            $new_driver_surname = $vehicle_data['regular_driver_name'] ?? $vehicles[$index]['driver']['driver_surname'];
            $new_driver_id_number = $vehicle_data['driver_id_number'] ?? $vehicles[$index]['driver']['driver_id_number'];
            $new_dob = $vehicle_data['driver_dob'] ?? $vehicles[$index]['driver']['dob'];
            $new_licence_type = $vehicle_data['licence_type'] ?? $vehicles[$index]['driver']['licence_type'];
            $new_year_of_issue = $vehicle_data['licence_issue_year'] ?? $vehicles[$index]['driver']['year_of_issue'];

            $update_driver = $conn->prepare("
                UPDATE quote_drivers SET driver_initials = ?, driver_surname = ?, driver_id_number = ?, dob = ?, licence_type = ?, year_of_issue = ?
                WHERE vehicle_id = ? AND quote_id = ?
            ");
            $update_driver->bind_param("ssssssi i", $new_driver_initials, $new_driver_surname, $new_driver_id_number, $new_dob, $new_licence_type, $new_year_of_issue, $vehicle_id, $quote_id);
            if (!$update_driver->execute()) {
                throw new Exception("Failed to update driver for vehicle $index: " . $update_driver->error);
            }
            $update_driver->close();

            // Update vehicle underwriting data
            $vehicle_keys = [
                '3.3_sum_insured_' . $index => $vehicle_data['value_sum_insured'] ?? $vehicles[$index]['responses']['3.3_sum_insured_' . $index],
                '3.4_engine_number_' . $index => $vehicle_data['engine_number'] ?? $vehicles[$index]['responses']['3.4_engine_number_' . $index],
                '3.4_chassis_number_' . $index => $vehicle_data['chassis_number'] ?? $vehicles[$index]['responses']['3.4_chassis_number_' . $index],
                '3.5_finance_house_' . $index => $vehicle_data['finance_institution'] ?? $vehicles[$index]['responses']['3.5_finance_house_' . $index],
                '3.6_registered_in_client_name_' . $index => $vehicle_data['registered_in_client_name'] ?? $vehicles[$index]['responses']['3.6_registered_in_client_name_' . $index],
                '3.6_registered_owner_name_' . $index => $vehicle_data['registered_owner_name'] ?? $vehicles[$index]['responses']['3.6_registered_owner_name_' . $index],
                '3.7_coverage_type_' . $index => $vehicle_data['coverage_type'] ?? $vehicles[$index]['responses']['3.7_coverage_type_' . $index],
                '3.8_vehicle_condition_' . $index => $vehicle_data['vehicle_condition'] ?? $vehicles[$index]['responses']['3.8_vehicle_condition_' . $index],
                '3.9_vehicle_use_' . $index => $vehicle_data['vehicle_use'] ?? $vehicles[$index]['responses']['3.9_vehicle_use_' . $index],
                '3.11_regular_driver_' . $index => $vehicle_data['regular_driver_name'] ?? $vehicles[$index]['responses']['3.11_regular_driver_' . $index],
                '3.11_driver_id_number_' . $index => $vehicle_data['driver_id_number'] ?? $vehicles[$index]['responses']['3.11_driver_id_number_' . $index],
                '3.11_driver_dob_' . $index => $vehicle_data['driver_dob'] ?? $vehicles[$index]['responses']['3.11_driver_dob_' . $index],
                '3.12_licence_type_' . $index => $vehicle_data['licence_type'] ?? $vehicles[$index]['responses']['3.12_licence_type_' . $index],
                '3.13_year_of_issue_' . $index => $vehicle_data['licence_issue_year'] ?? $vehicles[$index]['responses']['3.13_year_of_issue_' . $index],
                '3.20_add_additional_drivers_' . $index => $vehicle_data['add_additional_driver'] ?? $vehicles[$index]['responses']['3.20_add_additional_drivers_' . $index],
                '3.29_security_device_' . $index => $vehicle_data['security_device'] ?? $vehicles[$index]['responses']['3.29_security_device_' . $index],
                '3.30_car_hire_' . $index => $vehicle_data['car_hire_option'] ?? $vehicles[$index]['responses']['3.30_car_hire_' . $index]
            ];
            $update_uw = $conn->prepare("
                INSERT INTO policy_underwriting_data (policy_id, section, question_key, response)
                VALUES (?, 'motor_section', ?, ?)
                ON DUPLICATE KEY UPDATE response = ?
            ");
            foreach ($vehicle_keys as $key => $value) {
                $update_uw->bind_param("isss", $policy_id, $key, $value, $value);
                if (!$update_uw->execute()) {
                    throw new Exception("Failed to update vehicle data for $key: " . $update_uw->error);
                }
            }
            $update_uw->close();

            // Update additional drivers (delete old, insert new)
            $delete_add = $conn->prepare("UPDATE quote_additional_drivers SET deleted_at = NOW() WHERE vehicle_id = ? AND quote_id = ?");
            $delete_add->bind_param("ii", $vehicle_id, $quote_id);
            $delete_add->execute();
            $delete_add->close();

            $add_drivers_post = $vehicle_data['additional_drivers'] ?? [];
            $insert_add = $conn->prepare("
                INSERT INTO quote_additional_drivers (vehicle_id, quote_id, name, id_number, licence_type)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($add_drivers_post as $add_driver) {
                $name = $add_driver['name'] ?? '';
                $id_number = $add_driver['id_number'] ?? '';
                $licence_type = $add_driver['licence_type'] ?? '';
                $insert_add->bind_param("iisss", $vehicle_id, $quote_id, $name, $id_number, $licence_type);
                if (!$insert_add->execute()) {
                    throw new Exception("Failed to insert additional driver: " . $insert_add->error);
                }
            }
            $insert_add->close();
        }

        $conn->commit();
        $_SESSION['success'] = ["Policy amended successfully."];
        header("Location: amendments.php");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['errors'] = [$e->getMessage()];
        header("Location: amend_policy.php?policy_id=$policy_id");
        exit();
    }
}

// Calculate pro-rata premium (if needed, but not used in new layout)
$today = new DateTime();
$year = $today->format('Y');
$month = $today->format('m');
$days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$current_day = (int)$today->format('d');
$days_remaining = $days_in_month - $current_day + 1;
$pro_rata_premium = ($policy_data['premium_amount'] / $days_in_month) * $days_remaining;
$pro_rata_premium = round($pro_rata_premium, 2);

// HTML Structure
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Amend Policy - Policy ID: <?php echo htmlspecialchars($policy_id); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --purple: #6A0DAD;
            --white: #fff;
            --font-scale: 1;
            --base-font: 14px;
            --base-padding: calc(0.375rem * var(--font-scale));
        }
        body {
            font-size: var(--base-font);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        main {
            flex: 1 0 auto;
        }
        footer {
            background-color: var(--white);
            color: var(--purple);
            font-size: var(--base-font);
            padding: calc(1rem * var(--font-scale)) 0;
            text-align: center;
            width: 100%;
            flex-shrink: 0;
        }
        header {
            padding: calc(1rem * var(--font-scale)) 0;
            text-align: left;
        }
        header img {
            height: calc(110px * var(--font-scale));
        }
        .navbar {
            background-color: var(--white);
            padding: calc(0.5rem * var(--font-scale)) 1rem;
            justify-content: flex-start;
        }
        .navbtn-purple {
            background-color: var(--purple);
            border-color: var(--purple);
            color: var(--white);
            font-size: 14px;
            padding: calc(0.5rem * var(--font-scale));
            text-decoration: none;
        }
        .navbtn-purple:hover {
            background-color: #4B0082;
            border-color: #4B0082;
            color: var(--white);
        }
        .btn-purple {
            background-color: var(--purple);
            border-color: var(--purple);
            color: var(--white);
            font-size: var(--base-font);
            padding: var(--base-padding) calc(0.75rem * var(--font-scale));
        }
        .btn-purple:hover {
            background-color: #4B0082;
            border-color: #4B0082;
            color: var(--white);
        }
        .form-label,
        .form-control,
        .form-select {
            font-size: var(--base-font);
            padding: var(--base-padding) calc(0.75rem * var(--font-scale));
        }
        .section-heading {
            font-size: 16px;
            font-weight: bold;
            color: var(--purple);
            margin-bottom: 10px;
        }
        .progress-bar {
            background-color: var(--purple);
        }
        .additional-driver-section {
            position: relative;
            padding: 10px;
            border: 1px solid #ccc;
            margin-bottom: 10px;
        }
        .remove-driver {
            position: absolute;
            top: 5px;
            right: 5px;
        }
    </style>
</head>
<body>
    <main>
        <div class="container mt-3">
            <header>
                <a href="../dashboard.php"><img src="../images/logo.png" alt="Profusion Insurance Logo"></a>
            </header>
            <nav class="navbar navbar-expand-lg navbar-dark">
                <div class="container-fluid">
                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <div class="collapse navbar-collapse" id="navbarNav">
                        <ul class="navbar-nav">
                            <li class="nav-item"><a class="nav-link btn navbtn-purple" href="../dashboard.php">Dashboard</a></li>
                            <?php if (strpos($_SESSION['role_name'], 'Profusion') === 0) { ?>
                                <li class="nav-item"><a class="nav-link btn navbtn-purple" href="../user_management/manage_users.php">Manage Users</a></li>
                                <li class="nav-item"><a class="nav-link btn navbtn-purple" href="../broker_management/manage_brokers.php">Manage Brokers</a></li>
                            <?php } ?>
                            <li class="nav-item"><a class="nav-link btn navbtn-purple" href="../login_management/logout.php">Logout</a></li>
                        </ul>
                    </div>
                </div>
            </nav>
            <div class="container mt-4">
                <h2 class="mb-4">Amend Policy (Policy ID: <?php echo htmlspecialchars($policy_id); ?>)</h2>
                <?php if (!empty($_SESSION['errors'])): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($_SESSION['errors'] as $error): ?>
                            <p><?php echo htmlspecialchars($error); ?></p>
                        <?php endforeach; ?>
                        <?php unset($_SESSION['errors']); ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($_SESSION['success'])): ?>
                    <div class="alert alert-success">
                        <?php foreach ($_SESSION['success'] as $success): ?>
                            <p><?php echo htmlspecialchars($success); ?></p>
                        <?php endforeach; ?>
                        <?php unset($_SESSION['success']); ?>
                    </div>
                <?php endif; ?>
                <form method="post" action="amend_policy.php?policy_id=<?php echo htmlspecialchars($policy_id); ?>" class="row g-3" id="amendForm">
                    <div class="col-12">
                        <div class="accordion" id="amendAccordion">
                            <!-- Client Personal Information -->
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="headingPersonal">
                                    <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapsePersonal" aria-expanded="true" aria-controls="collapsePersonal">
                                        Client Personal Information
                                    </button>
                                </h2>
                                <div id="collapsePersonal" class="accordion-collapse collapse show" aria-labelledby="headingPersonal" data-bs-parent="#amendAccordion">
                                    <div class="accordion-body">
                                        <div class="row mb-2">
                                            <div class="col-md-6">
                                                <div class="row">
                                                    <label class="col-md-4 col-form-label">Initials:</label>
                                                    <div class="col-md-8">
                                                        <input type="text" name="initials" value="<?php echo htmlspecialchars($policy_holder['initials'] ?? ''); ?>" class="form-control" required>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="row">
                                                    <label class="col-md-4 col-form-label">Surname:</label>
                                                    <div class="col-md-8">
                                                        <input type="text" name="surname" value="<?php echo htmlspecialchars($policy_holder['surname'] ?? ''); ?>" class="form-control" required>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row mb-2">
                                            <div class="col-md-6">
                                                <div class="row">
                                                    <label class="col-md-4 col-form-label">Title:</label>
                                                    <div class="col-md-8">
                                                        <select name="title" class="form-select" required>
                                                            <option value="">Choose...</option>
                                                            <option value="Mr." <?php echo ($policy_holder['title'] ?? '') === 'Mr.' ? 'selected' : ''; ?>>Mr.</option>
                                                            <option value="Mrs." <?php echo ($policy_holder['title'] ?? '') === 'Mrs.' ? 'selected' : ''; ?>>Mrs.</option>
                                                            <option value="Miss" <?php echo ($policy_holder['title'] ?? '') === 'Miss' ? 'selected' : ''; ?>>Miss</option>
                                                            <option value="Dr." <?php echo ($policy_holder['title'] ?? '') === 'Dr.' ? 'selected' : ''; ?>>Dr.</option>
                                                            <option value="Prof" <?php echo ($policy_holder['title'] ?? '') === 'Prof' ? 'selected' : ''; ?>>Prof</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="row">
                                                    <label class="col-md-4 col-form-label">Marital Status:</label>
                                                    <div class="col-md-8">
                                                        <select name="marital_status" class="form-select" required>
                                                            <option value="">Choose...</option>
                                                            <option value="Single" <?php echo ($policy_holder['marital_status'] ?? '') === 'Single' ? 'selected' : ''; ?>>Single</option>
                                                            <option value="Married" <?php echo ($policy_holder['marital_status'] ?? '') === 'Married' ? 'selected' : ''; ?>>Married</option>
                                                            <option value="Divorced" <?php echo ($policy_holder['marital_status'] ?? '') === 'Divorced' ? 'selected' : ''; ?>>Divorced</option>
                                                            <option value="Cohabiting" <?php echo ($policy_holder['marital_status'] ?? '') === 'Cohabiting' ? 'selected' : ''; ?>>Cohabiting</option>
                                                            <option value="Widowed" <?php echo ($policy_holder['marital_status'] ?? '') === 'Widowed' ? 'selected' : ''; ?>>Widowed</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row mb-2">
                                            <div class="col-md-6">
                                                <div class="row">
                                                    <label class="col-md-4 col-form-label">Client ID:</label>
                                                    <div class="col-md-8">
                                                        <input type="text" name="client_id" value="<?php echo htmlspecialchars($policy_holder['client_id'] ?? ''); ?>" class="form-control" required pattern="\d{13}">
                                                    </div>
                                                </div>
                                            </div>
                                            <!-- Empty second column for odd number -->
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- Client Contact Information -->
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="headingContact">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseContact" aria-expanded="false" aria-controls="collapseContact">
                                        Client Contact Information
                                    </button>
                                </h2>
                                <div id="collapseContact" class="accordion-collapse collapse" aria-labelledby="headingContact" data-bs-parent="#amendAccordion">
                                    <div class="accordion-body">
                                        <div class="row mb-2">
                                            <div class="col-md-6">
                                                <div class="row">
                                                    <label class="col-md-4 col-form-label">Cell Number:</label>
                                                    <div class="col-md-8">
                                                        <input type="text" name="cell_number" value="<?php echo htmlspecialchars($policy_holder_responses['2.5_cell_number'] ?? ''); ?>" class="form-control" required pattern="\d{10}">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="row">
                                                    <label class="col-md-4 col-form-label">Email:</label>
                                                    <div class="col-md-8">
                                                        <input type="email" name="email" value="<?php echo htmlspecialchars($policy_holder_responses['2.8_email'] ?? ''); ?>" class="form-control">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row mb-2">
                                            <div class="col-md-6">
                                                <div class="row">
                                                    <label class="col-md-4 col-form-label">Physical Address:</label>
                                                    <div class="col-md-8">
                                                        <input type="text" name="physical_address" value="<?php echo htmlspecialchars($policy_holder_responses['2.10_physical_address'] ?? ''); ?>" class="form-control" required>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="row">
                                                    <label class="col-md-4 col-form-label">Physical Suburb:</label>
                                                    <div class="col-md-8">
                                                        <input type="text" name="physical_suburb" value="<?php echo htmlspecialchars($policy_holder_responses['2.10_physical_suburb'] ?? ''); ?>" class="form-control">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row mb-2">
                                            <div class="col-md-6">
                                                <div class="row">
                                                    <label class="col-md-4 col-form-label">Physical Postal Code:</label>
                                                    <div class="col-md-8">
                                                        <input type="text" name="physical_postal_code" value="<?php echo htmlspecialchars($policy_holder_responses['2.10_physical_postal_code'] ?? ''); ?>" class="form-control" pattern="\d{4}">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="row">
                                                    <label class="col-md-4 col-form-label">Postal Address:</label>
                                                    <div class="col-md-8">
                                                        <input type="text" name="postal_address" value="<?php echo htmlspecialchars($policy_holder_responses['2.12_postal_address'] ?? ''); ?>" class="form-control" required>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row mb-2">
                                            <div class="col-md-6">
                                                <div class="row">
                                                    <label class="col-md-4 col-form-label">Postal Suburb:</label>
                                                    <div class="col-md-8">
                                                        <input type="text" name="postal_suburb" value="<?php echo htmlspecialchars($policy_holder_responses['2.12_postal_suburb'] ?? ''); ?>" class="form-control">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="row">
                                                    <label class="col-md-4 col-form-label">Postal Postal Code:</label>
                                                    <div class="col-md-8">
                                                        <input type="text" name="postal_postal_code" value="<?php echo htmlspecialchars($policy_holder_responses['2.12_postal_postal_code'] ?? ''); ?>" class="form-control" pattern="\d{4}">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- Vehicle Information (per vehicle) -->
                            <?php foreach ($vehicles as $index => $data) { ?>
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="headingVehicle<?php echo $index; ?>">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseVehicle<?php echo $index; ?>" aria-expanded="false" aria-controls="collapseVehicle<?php echo $index; ?>">
                                            Vehicle Information (Vehicle <?php echo $index + 1; ?>)
                                        </button>
                                    </h2>
                                    <div id="collapseVehicle<?php echo $index; ?>" class="accordion-collapse collapse" aria-labelledby="headingVehicle<?php echo $index; ?>" data-bs-parent="#amendAccordion">
                                        <div class="accordion-body">
                                            <div class="row mb-2">
                                                <div class="col-md-6">
                                                    <div class="row">
                                                        <label class="col-md-4 col-form-label">Year:</label>
                                                        <div class="col-md-8">
                                                            <input type="number" name="vehicles[<?php echo $index; ?>][year]" value="<?php echo htmlspecialchars($data['vehicle']['vehicle_year'] ?? ''); ?>" class="form-control" min="1900" max="2025" required>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="row">
                                                        <label class="col-md-4 col-form-label">Make:</label>
                                                        <div class="col-md-8">
                                                            <input type="text" name="vehicles[<?php echo $index; ?>][make]" value="<?php echo htmlspecialchars($data['vehicle']['vehicle_make'] ?? ''); ?>" class="form-control" required>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <div class="col-md-6">
                                                    <div class="row">
                                                        <label class="col-md-4 col-form-label">Model:</label>
                                                        <div class="col-md-8">
                                                            <input type="text" name="vehicles[<?php echo $index; ?>][model]" value="<?php echo htmlspecialchars($data['vehicle']['vehicle_model'] ?? ''); ?>" class="form-control" required>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="row">
                                                        <label class="col-md-4 col-form-label">Value/Sum Insured:</label>
                                                        <div class="col-md-8">
                                                            <input type="number" step="0.01" name="vehicles[<?php echo $index; ?>][value_sum_insured]" value="<?php echo htmlspecialchars($data['responses']['3.3_sum_insured_' . $index] ?? $data['vehicle']['vehicle_value'] ?? ''); ?>" class="form-control" required>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <div class="col-md-6">
                                                    <div class="row">
                                                        <label class="col-md-4 col-form-label">Engine Number:</label>
                                                        <div class="col-md-8">
                                                            <input type="text" name="vehicles[<?php echo $index; ?>][engine_number]" value="<?php echo htmlspecialchars($data['responses']['3.4_engine_number_' . $index] ?? ''); ?>" class="form-control" required>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="row">
                                                        <label class="col-md-4 col-form-label">Chassis Number:</label>
                                                        <div class="col-md-8">
                                                            <input type="text" name="vehicles[<?php echo $index; ?>][chassis_number]" value="<?php echo htmlspecialchars($data['responses']['3.4_chassis_number_' . $index] ?? ''); ?>" class="form-control" required>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <div class="col-md-6">
                                                    <div class="row">
                                                        <label class="col-md-4 col-form-label">Finance Institution:</label>
                                                        <div class="col-md-8">
                                                            <input type="text" name="vehicles[<?php echo $index; ?>][finance_institution]" value="<?php echo htmlspecialchars($data['responses']['3.5_finance_house_' . $index] ?? ''); ?>" class="form-control">
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="row">
                                                        <label class="col-md-4 col-form-label">Registered in Client’s Name:</label>
                                                        <div class="col-md-8">
                                                            <select name="vehicles[<?php echo $index; ?>][registered_in_client_name]" id="registered_in_client_name_<?php echo $index; ?>" class="form-select" required>
                                                                <option value="">Choose...</option>
                                                                <option value="yes" <?php echo ($data['responses']['3.6_registered_in_client_name_' . $index] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                                                <option value="no" <?php echo ($data['responses']['3.6_registered_in_client_name_' . $index] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row mb-2" id="registered_owner_name_details_<?php echo $index; ?>" style="display: <?php echo ($data['responses']['3.6_registered_in_client_name_' . $index] ?? '') === 'no' ? 'block' : 'none'; ?>;">
                                                <div class="col-md-6">
                                                    <div class="row">
                                                        <label class="col-md-4 col-form-label">Registered Owner Name:</label>
                                                        <div class="col-md-8">
                                                            <input type="text" name="vehicles[<?php echo $index; ?>][registered_owner_name]" value="<?php echo htmlspecialchars($data['responses']['3.6_registered_owner_name_' . $index] ?? ''); ?>" class="form-control">
                                                        </div>
                                                    </div>
                                                </div>
                                                <!-- Empty second column -->
                                            </div>
                                            <div class="row mb-2">
                                                <div class="col-md-6">
                                                    <div class="row">
                                                        <label class="col-md-4 col-form-label">Coverage Type:</label>
                                                        <div class="col-md-8">
                                                            <select name="vehicles[<?php echo $index; ?>][coverage_type]" class="form-select" required>
                                                                <option value="">Choose...</option>
                                                                <option value="comprehensive" <?php echo ($data['responses']['3.7_coverage_type_' . $index] ?? '') === 'comprehensive' ? 'selected' : ''; ?>>Comprehensive</option>
                                                                <!-- Add more options as needed -->
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="row">
                                                        <label class="col-md-4 col-form-label">Vehicle Condition:</label>
                                                        <div class="col-md-8">
                                                            <select name="vehicles[<?php echo $index; ?>][vehicle_condition]" class="form-select" required>
                                                                <option value="">Choose...</option>
                                                                <option value="new" <?php echo ($data['responses']['3.8_vehicle_condition_' . $index] ?? '') === 'new' ? 'selected' : ''; ?>>New</option>
                                                                <option value="used" <?php echo ($data['responses']['3.8_vehicle_condition_' . $index] ?? '') === 'used' ? 'selected' : ''; ?>>Used</option>
                                                                <!-- Add more -->
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <div class="col-md-6">
                                                    <div class="row">
                                                        <label class="col-md-4 col-form-label">Vehicle Use:</label>
                                                        <div class="col-md-8">
                                                            <select name="vehicles[<?php echo $index; ?>][vehicle_use]" class="form-select" required>
                                                                <option value="">Choose...</option>
                                                                <option value="private" <?php echo ($data['responses']['3.9_vehicle_use_' . $index] ?? '') === 'private' ? 'selected' : ''; ?>>Private</option>
                                                                <!-- Add more -->
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="row">
                                                        <label class="col-md-4 col-form-label">Security Device:</label>
                                                        <div class="col-md-8">
                                                            <select name="vehicles[<?php echo $index; ?>][security_device]" class="form-select" required>
                                                                <option value="">Choose...</option>
                                                                <option value="reactive_tracking" <?php echo ($data['responses']['3.29_security_device_' . $index] ?? '') === 'reactive_tracking' ? 'selected' : ''; ?>>Reactive Tracking</option>
                                                                <!-- Add more options -->
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <div class="col-md-6">
                                                    <div class="row">
                                                        <label class="col-md-4 col-form-label">Car Hire Option:</label>
                                                        <div class="col-md-8">
                                                            <select name="vehicles[<?php echo $index; ?>][car_hire_option]" class="form-select" required>
                                                                <option value="">Choose...</option>
                                                                <option value="Group B Manual Hatchback" <?php echo ($data['responses']['3.30_car_hire_' . $index] ?? $data['vehicle']['car_hire'] ?? '') === 'Group B Manual Hatchback' ? 'selected' : ''; ?>>Group B Manual Hatchback</option>
                                                                <option value="None" <?php echo ($data['responses']['3.30_car_hire_' . $index] ?? $data['vehicle']['car_hire'] ?? '') === 'None' ? 'selected' : ''; ?>>None</option>
                                                                <!-- Add more -->
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                                <!-- Empty second column -->
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <!-- Regular Driver Details -->
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="headingDriver<?php echo $index; ?>">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseDriver<?php echo $index; ?>" aria-expanded="false" aria-controls="collapseDriver<?php echo $index; ?>">
                                            Regular Driver Details (Vehicle <?php echo $index + 1; ?>)
                                        </button>
                                    </h2>
                                    <div id="collapseDriver<?php echo $index; ?>" class="accordion-collapse collapse" aria-labelledby="headingDriver<?php echo $index; ?>" data-bs-parent="#amendAccordion">
                                        <div class="accordion-body">
                                            <div class="row mb-2">
                                                <div class="col-md-6">
                                                    <div class="row">
                                                        <label class="col-md-4 col-form-label">Regular Driver Name:</label>
                                                        <div class="col-md-8">
                                                            <input type="text" name="vehicles[<?php echo $index; ?>][regular_driver_name]" value="<?php echo htmlspecialchars($data['responses']['3.11_regular_driver_' . $index] ?? ($data['driver']['driver_initials'] . ' ' . $data['driver']['driver_surname'])); ?>" class="form-control" required>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="row">
                                                        <label class="col-md-4 col-form-label">Driver ID Number:</label>
                                                        <div class="col-md-8">
                                                            <input type="text" name="vehicles[<?php echo $index; ?>][driver_id_number]" value="<?php echo htmlspecialchars($data['responses']['3.11_driver_id_number_' . $index] ?? $data['driver']['driver_id_number'] ?? ''); ?>" class="form-control" required pattern="\d{13}">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <div class="col-md-6">
                                                    <div class="row">
                                                        <label class="col-md-4 col-form-label">Driver DOB:</label>
                                                        <div class="col-md-8">
                                                            <input type="date" name="vehicles[<?php echo $index; ?>][driver_dob]" value="<?php echo htmlspecialchars($data['responses']['3.11_driver_dob_' . $index] ?? $data['driver']['dob'] ?? ''); ?>" class="form-control" required>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="row">
                                                        <label class="col-md-4 col-form-label">Licence Type:</label>
                                                        <div class="col-md-8">
                                                            <select name="vehicles[<?php echo $index; ?>][licence_type]" class="form-select" required>
                                                                <option value="">Choose...</option>
                                                                <option value="B" <?php echo ($data['responses']['3.12_licence_type_' . $index] ?? $data['driver']['licence_type'] ?? '') === 'B' ? 'selected' : ''; ?>>B</option>
                                                                <option value="EB" <?php echo ($data['responses']['3.12_licence_type_' . $index] ?? $data['driver']['licence_type'] ?? '') === 'EB' ? 'selected' : ''; ?>>EB</option>
                                                                <option value="C1" <?php echo ($data['responses']['3.12_licence_type_' . $index] ?? $data['driver']['licence_type'] ?? '') === 'C1' ? 'selected' : ''; ?>>C1</option>
                                                                <option value="EC" <?php echo ($data['responses']['3.12_licence_type_' . $index] ?? $data['driver']['licence_type'] ?? '') === 'EC' ? 'selected' : ''; ?>>EC</option>
                                                                <option value="EC1" <?php echo ($data['responses']['3.12_licence_type_' . $index] ?? $data['driver']['licence_type'] ?? '') === 'EC1' ? 'selected' : ''; ?>>EC1</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <div class="col-md-6">
                                                    <div class="row">
                                                        <label class="col-md-4 col-form-label">Licence Issue Year:</label>
                                                        <div class="col-md-8">
                                                            <input type="number" name="vehicles[<?php echo $index; ?>][licence_issue_year]" value="<?php echo htmlspecialchars($data['responses']['3.13_year_of_issue_' . $index] ?? $data['driver']['year_of_issue'] ?? ''); ?>" class="form-control" min="1900" max="2025" required>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="row">
                                                        <label class="col-md-4 col-form-label">Risk Street:</label>
                                                        <div class="col-md-8">
                                                            <input type="text" name="vehicles[<?php echo $index; ?>][risk_street]" value="<?php echo htmlspecialchars($data['responses']['3.28_street_' . $index] ?? $data['vehicle']['street'] ?? ''); ?>" class="form-control" required>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <div class="col-md-6">
                                                    <div class="row">
                                                        <label class="col-md-4 col-form-label">Risk Suburb:</label>
                                                        <div class="col-md-8">
                                                            <input type="text" name="vehicles[<?php echo $index; ?>][risk_suburb]" value="<?php echo htmlspecialchars($data['responses']['3.28_suburb_vehicle_' . $index] ?? $data['vehicle']['suburb_vehicle'] ?? ''); ?>" class="form-control" required>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="row">
                                                        <label class="col-md-4 col-form-label">Risk Postal Code:</label>
                                                        <div class="col-md-8">
                                                            <input type="text" name="vehicles[<?php echo $index; ?>][risk_postal_code]" value="<?php echo htmlspecialchars($data['responses']['3.28_postal_code_' . $index] ?? $data['vehicle']['postal_code'] ?? ''); ?>" class="form-control" pattern="\d{4}" required>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <div class="col-md-6">
                                                    <div class="row">
                                                        <label class="col-md-4 col-form-label">Add Additional Driver:</label>
                                                        <div class="col-md-8">
                                                            <select name="vehicles[<?php echo $index; ?>][add_additional_driver]" id="add_additional_driver_<?php echo $index; ?>" class="form-select" required>
                                                                <option value="">Choose...</option>
                                                                <option value="yes" <?php echo ($data['responses']['3.20_add_additional_drivers_' . $index] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                                                <option value="no" <?php echo ($data['responses']['3.20_add_additional_drivers_' . $index] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                                <!-- Empty second column -->
                                            </div>
                                            <div id="additional_drivers_<?php echo $index; ?>" style="display: <?php echo ($data['responses']['3.20_add_additional_drivers_' . $index] ?? '') === 'yes' ? 'block' : 'none'; ?>;">
                                                <?php foreach ($additional_drivers as $driver_index => $add_driver) { ?>
                                                    <div class="additional-driver-section">
                                                        <div class="row mb-2">
                                                            <div class="col-md-6">
                                                                <div class="row">
                                                                    <label class="col-md-4 col-form-label">Driver Name:</label>
                                                                    <div class="col-md-8">
                                                                        <input type="text" name="vehicles[<?php echo $index; ?>][additional_drivers][<?php echo $driver_index; ?>][name]" value="<?php echo htmlspecialchars($add_driver['name'] ?? ''); ?>" class="form-control">
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <div class="row">
                                                                    <label class="col-md-4 col-form-label">Driver ID Number:</label>
                                                                    <div class="col-md-8">
                                                                        <input type="text" name="vehicles[<?php echo $index; ?>][additional_drivers][<?php echo $driver_index; ?>][id_number]" value="<?php echo htmlspecialchars($add_driver['id_number'] ?? ''); ?>" class="form-control" pattern="\d{13}">
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="row mb-2">
                                                            <div class="col-md-6">
                                                                <div class="row">
                                                                    <label class="col-md-4 col-form-label">Licence Type:</label>
                                                                    <div class="col-md-8">
                                                                        <select name="vehicles[<?php echo $index; ?>][additional_drivers][<?php echo $driver_index; ?>][licence_type]" class="form-select">
                                                                            <option value="">Choose...</option>
                                                                            <option value="B" <?php echo ($add_driver['licence_type'] ?? '') === 'B' ? 'selected' : ''; ?>>B</option>
                                                                            <option value="EB" <?php echo ($add_driver['licence_type'] ?? '') === 'EB' ? 'selected' : ''; ?>>EB</option>
                                                                            <option value="C1" <?php echo ($add_driver['licence_type'] ?? '') === 'C1' ? 'selected' : ''; ?>>C1</option>
                                                                            <option value="EC" <?php echo ($add_driver['licence_type'] ?? '') === 'EC' ? 'selected' : ''; ?>>EC</option>
                                                                            <option value="EC1" <?php echo ($add_driver['licence_type'] ?? '') === 'EC1' ? 'selected' : ''; ?>>EC1</option>
                                                                        </select>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <!-- Empty second column -->
                                                        </div>
                                                        <button type="button" class="btn btn-danger remove-driver">Remove Driver</button>
                                                    </div>
                                                <?php } ?>
                                                <button type="button" class="btn btn-secondary add-another-driver" data-index="<?php echo $index; ?>">Add Another Driver</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php } ?>
                            <!-- Banking Details -->
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="headingBanking">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseBanking" aria-expanded="false" aria-controls="collapseBanking">
                                        Banking Details
                                    </button>
                                </h2>
                                <div id="collapseBanking" class="accordion-collapse collapse" aria-labelledby="headingBanking" data-bs-parent="#amendAccordion">
                                    <div class="accordion-body">
                                        <div class="row mb-2">
                                            <div class="col-md-6">
                                                <div class="row">
                                                    <label class="col-md-4 col-form-label">Bank Name:</label>
                                                    <div class="col-md-8">
                                                        <input type="text" name="bank_name" value="<?php echo htmlspecialchars($policy_data['bank_name'] ?? ''); ?>" class="form-control" required>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="row">
                                                    <label class="col-md-4 col-form-label">Account Holder:</label>
                                                    <div class="col-md-8">
                                                        <input type="text" name="account_holder" value="<?php echo htmlspecialchars($policy_data['account_holder'] ?? ''); ?>" class="form-control" required>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row mb-2">
                                            <div class="col-md-6">
                                                <div class="row">
                                                    <label class="col-md-4 col-form-label">Account Number:</label>
                                                    <div class="col-md-8">
                                                        <input type="text" name="account_number" value="<?php echo htmlspecialchars($policy_data['account_number'] ?? ''); ?>" class="form-control" required>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="row">
                                                    <label class="col-md-4 col-form-label">Account Type:</label>
                                                    <div class="col-md-8">
                                                        <select name="account_type" class="form-select" required>
                                                            <option value="">Choose...</option>
                                                            <option value="cheque" <?php echo ($policy_data['account_type'] ?? '') === 'cheque' ? 'selected' : ''; ?>>Cheque</option>
                                                            <option value="savings" <?php echo ($policy_data['account_type'] ?? '') === 'savings' ? 'selected' : ''; ?>>Savings</option>
                                                            <!-- Add more options -->
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row mb-2">
                                            <div class="col-md-6">
                                                <div class="row">
                                                    <label class="col-md-4 col-form-label">Branch Code:</label>
                                                    <div class="col-md-8">
                                                        <input type="text" name="branch_code" value="<?php echo htmlspecialchars($policy_data['branch_code'] ?? ''); ?>" class="form-control" required>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="row">
                                                    <label class="col-md-4 col-form-label">Debit Order Day:</label>
                                                    <div class="col-md-8">
                                                        <input type="number" name="debit_date" value="<?php echo htmlspecialchars($policy_data['debit_date'] ?? ''); ?>" class="form-control" min="1" max="31" required>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- Policy Details -->
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="headingPolicy">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapsePolicy" aria-expanded="false" aria-controls="collapsePolicy">
                                        Policy Details
                                    </button>
                                </h2>
                                <div id="collapsePolicy" class="accordion-collapse collapse" aria-labelledby="headingPolicy" data-bs-parent="#amendAccordion">
                                    <div class="accordion-body">
                                        <div class="row mb-2">
                                            <div class="col-md-6">
                                                <div class="row">
                                                    <label class="col-md-4 col-form-label">Policy Start Date:</label>
                                                    <div class="col-md-8">
                                                        <input type="date" name="policy_start_date" value="<?php echo htmlspecialchars($policy_data['policy_start_date'] ?? ''); ?>" class="form-control" required>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="row">
                                                    <label class="col-md-4 col-form-label">Premium Amount:</label>
                                                    <div class="col-md-8">
                                                        <input type="number" step="0.01" name="premium_amount" value="<?php echo htmlspecialchars($policy_data['premium_amount'] ?? ''); ?>" class="form-control" required>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row mb-2">
                                            <div class="col-md-6">
                                                <div class="row">
                                                    <label class="col-md-4 col-form-label">Broker Fee:</label>
                                                    <div class="col-md-8">
                                                        <input type="number" step="0.01" name="broker_fee" value="<?php echo htmlspecialchars($broker_fee); ?>" class="form-control" required>
                                                    </div>
                                                </div>
                                            </div>
                                            <!-- Empty second column -->
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-purple">Save Changes</button>
                        <a href="amendments.php" class="btn btn-link ms-3">Back to Search</a>
                    </div>
                </form>
            </div>
        </div>
    </main>
    <footer class="text-center py-3 mt-4">
        <p>© 2025 Profusion Insurance</p>
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // For each vehicle, add JS for additional drivers and other conditionals
            <?php foreach ($vehicles as $index => $data) { ?>
                const registeredSelect<?php echo $index; ?> = document.getElementById('registered_in_client_name_<?php echo $index; ?>');
                const registeredDetails<?php echo $index; ?> = document.getElementById('registered_owner_name_details_<?php echo $index; ?>');
                if (registeredSelect<?php echo $index; ?>) {
                    registeredSelect<?php echo $index; ?>.addEventListener('change', function() {
                        registeredDetails<?php echo $index; ?>.style.display = this.value === 'no' ? 'block' : 'none';
                    });
                }

                const addDriversSelect<?php echo $index; ?> = document.getElementById('add_additional_driver_<?php echo $index; ?>');
                const addDriversContainer<?php echo $index; ?> = document.getElementById('additional_drivers_<?php echo $index; ?>');
                if (addDriversSelect<?php echo $index; ?>) {
                    addDriversSelect<?php echo $index; ?>.addEventListener('change', function() {
                        addDriversContainer<?php echo $index; ?>.style.display = this.value === 'yes' ? 'block' : 'none';
                    });
                }

                // Add Another Driver
                const addDriverButton<?php echo $index; ?> = document.querySelector('.add-another-driver[data-index="<?php echo $index; ?>"]');
                if (addDriverButton<?php echo $index; ?>) {
                    addDriverButton<?php echo $index; ?>.addEventListener('click', function() {
                        const container = document.getElementById('additional_drivers_<?php echo $index; ?>');
                        const sections = container.querySelectorAll('.additional-driver-section');
                        const newIndex = sections.length;
                        const newSection = document.createElement('div');
                        newSection.className = 'additional-driver-section';
                        newSection.innerHTML = `
                            <div class="row mb-2">
                                <div class="col-md-6">
                                    <div class="row">
                                        <label class="col-md-4 col-form-label">Driver Name:</label>
                                        <div class="col-md-8">
                                            <input type="text" name="vehicles[<?php echo $index; ?>][additional_drivers][${newIndex}][name]" class="form-control">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="row">
                                        <label class="col-md-4 col-form-label">Driver ID Number:</label>
                                        <div class="col-md-8">
                                            <input type="text" name="vehicles[<?php echo $index; ?>][additional_drivers][${newIndex}][id_number]" class="form-control" pattern="\\d{13}">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-md-6">
                                    <div class="row">
                                        <label class="col-md-4 col-form-label">Licence Type:</label>
                                        <div class="col-md-8">
                                            <select name="vehicles[<?php echo $index; ?>][additional_drivers][${newIndex}][licence_type]" class="form-select">
                                                <option value="">Choose...</option>
                                                <option value="B">B</option>
                                                <option value="EB">EB</option>
                                                <option value="C1">C1</option>
                                                <option value="EC">EC</option>
                                                <option value="EC1">EC1</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <!-- Empty second column -->
                            </div>
                            <button type="button" class="btn btn-danger remove-driver">Remove Driver</button>
                        `;
                        container.appendChild(newSection);
                        newSection.querySelector('.remove-driver').addEventListener('click', function() {
                            newSection.remove();
                        });
                    });
                }

                // Remove Driver buttons
                const removeDriverButtons<?php echo $index; ?> = document.querySelectorAll('#additional_drivers_<?php echo $index; ?> .remove-driver');
                removeDriverButtons<?php echo $index; ?>.forEach(button => {
                    button.addEventListener('click', function() {
                        this.parentElement.remove();
                    });
                });
            <?php } ?>
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>