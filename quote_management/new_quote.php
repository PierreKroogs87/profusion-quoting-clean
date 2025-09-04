<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login_management/login.php");
    exit();
}
require '../db_connect.php';

// Fetch brokerages
$brokerages_query = "SELECT brokerage_id, brokerage_name FROM brokerages";
$brokerages_result = mysqli_query($conn, $brokerages_query);

// Fetch unique years from vehicles table
$years_query = "SELECT DISTINCT year FROM vehicles WHERE year BETWEEN 2005 AND 2025 ORDER BY year DESC";
$years_result = mysqli_query($conn, $years_query);

// Handle form data (no quote_id needed for new quotes)
$form_data = [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Quote - Insurance App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --purple: #6A0DAD;
            --green: #28A745;
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
        .navbar-nav .nav-link {
            margin: 0 calc(0.25rem * var(--font-scale));
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
            text-decoration: none;
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
        .subheading {
            font-weight: bold;
            color: var(--purple);
        }
        .premium-card {
            margin-bottom: 15px;
            border: 1px solid var(--purple);
            background-color: #f9f9f9;
        }
        .premium-card .card-header {
            background-color: var(--purple);
            color: var(--white);
            font-size: 14px;
            text-align: center;
        }
        .premium-card .card-body {
            padding: calc(0.75rem * var(--font-scale));
        }
        .premium-card p {
            margin: 0;
            font-size: var(--base-font);
        }
        .form-select {
            font-size: 14px;
        }
        .card-header {
            text-align: center;
            background-color: #6A0DAD;
        }
        .card-body {
            white-space: nowrap;
        }
        .vehicle-section {
            border: 1px solid #ccc;
            padding: 15px;
            margin-bottom: 15px;
            position: relative;
        }
        .remove-vehicle {
            position: absolute;
            top: -10px;
            right: -10px;
            z-index: 10;
            border-radius: 50%;
            padding: 5px 10px;
            font-size: 12px;
        }
        .vehicle-heading, .driver-heading {
            font-size: 16px;
            font-weight: bold;
            color: var(--purple);
            margin-bottom: 10px;
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
                <h2 class="mb-4">New Quote</h2>
                <div class="row">
                    <!-- Left Side: Form -->
                    <div class="col-md-6">
                        <form method="post" action="../quote_commit_management/save_quote_controller.php" class="row g-3" id="quoteForm">
                            <div class="col-12">
                                <div class="card mb-3" style="color: white">
                                    <div class="card-header" style="background-color: #6A0DAD">Client Details</div>
                                    <div class="card-body" style="color: black">
                                        <div class="row mb-2">
                                            <label for="title" class="col-md-3 col-form-label text-md-end">Title:</label>
                                            <div class="col-md-9">
                                                <select name="title" id="title" class="form-select" required>
                                                    <option value="">Choose...</option>
                                                    <option value="Mr." <?php echo ($form_data['title'] ?? '') === 'Mr.' ? 'selected' : ''; ?>>Mr</option>
                                                    <option value="Mrs." <?php echo ($form_data['title'] ?? '') === 'Mrs.' ? 'selected' : ''; ?>>Mrs</option>
                                                    <option value="Miss" <?php echo ($form_data['title'] ?? '') === 'Miss' ? 'selected' : ''; ?>>Miss</option>
                                                    <option value="Dr." <?php echo ($form_data['title'] ?? '') === 'Dr.' ? 'selected' : ''; ?>>Dr</option>
                                                    <option value="Prof" <?php echo ($form_data['title'] ?? '') === 'Prof' ? 'selected' : ''; ?>>Prof</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="row mb-2">
                                            <label for="initials" class="col-md-3 col-form-label text-md-end">Initials:</label>
                                            <div class="col-md-9">
                                                <input type="text" name="initials" id="initials" value="<?php echo htmlspecialchars($form_data['initials'] ?? ''); ?>" class="form-control" required>
                                            </div>
                                        </div>
                                        <div class="row mb-2">
                                            <label for="surname" class="col-md-3 col-form-label text-md-end">Surname:</label>
                                            <div class="col-md-9">
                                                <input type="text" name="surname" id="surname" value="<?php echo htmlspecialchars($form_data['surname'] ?? ''); ?>" class="form-control" required>
                                            </div>
                                        </div>
                                        <div class="row mb-2">
                                            <label for="marital_status" class="col-md-3 col-form-label text-md-end">Marital Status:</label>
                                            <div class="col-md-9">
                                                <select name="marital_status" id="marital_status" class="form-select" required>
                                                    <option value="">Choose...</option>
                                                    <?php
                                                    $marital_statuses = ['Single', 'Married', 'Divorced', 'Cohabiting', 'Widowed'];
                                                    foreach ($marital_statuses as $ms) {
                                                        $selected = (isset($form_data['marital_status']) && trim($form_data['marital_status']) === $ms) ? 'selected' : '';
                                                        echo "<option value=\"$ms\" $selected>$ms</option>";
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="row mb-2">
                                            <label for="client_id" class="col-md-3 col-form-label text-md-end">Client ID:</label>
                                            <div class="col-md-9">
                                                <input type="text" name="client_id" id="client_id" maxlength="13" value="<?php echo htmlspecialchars($form_data['client_id'] ?? ''); ?>" class="form-control" required>
                                            </div>
                                        </div>
                                        <div class="row mb-2">
                                            <label for="suburb_client" class="col-md-3 col-form-label text-md-end">Suburb:</label>
                                            <div class="col-md-9">
                                                <input type="text" name="suburb_client" id="suburb_client" value="<?php echo htmlspecialchars($form_data['suburb_client'] ?? ''); ?>" class="form-control" required>
                                            </div>
                                        </div>
                                        <?php if (strpos($_SESSION['role_name'], 'Profusion') === 0) { ?>
                                            <div class="row mb-2">
                                                <label for="brokerage_id" class="col-md-3 col-form-label text-md-end">Broker:</label>
                                                <div class="col-md-9">
                                                    <select name="brokerage_id" id="brokerage_id" class="form-select" required>
                                                        <option value="">Select Broker...</option>
                                                        <?php while ($brokerage = mysqli_fetch_assoc($brokerages_result)) { ?>
                                                            <option value="<?php echo $brokerage['brokerage_id']; ?>" <?php echo ($form_data['brokerage_id'] ?? '') == $brokerage['brokerage_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($brokerage['brokerage_name']); ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                            </div>
                                        <?php } ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Vehicle and Driver Sections -->
                            <div class="col-12" id="vehicle-sections">
                                <div class="vehicle-section">
                                    <button type="button" class="btn btn-danger remove-vehicle" style="display: none;">Remove Vehicle</button>
                                    <input type="hidden" name="vehicles[0][vehicle_id]" value="">
                                    <div class="vehicle-heading">Vehicle</div>
                                    <div class="card mb-3" style="color: white">
                                        <div class="card-header" style="background-color: #6A0DAD">Vehicle Details</div>
                                        <div class="card-body" style="color: black">
                                            <div class="row mb-2">
                                                <label for="vehicles[0][year]" class="col-md-3 col-form-label text-md-end">Vehicle Year:</label>
                                                <div class="col-md-9">
                                                    <select name="vehicles[0][year]" class="form-select vehicle-year" required>
                                                        <option value="">Choose...</option>
                                                        <?php while ($year = mysqli_fetch_assoc($years_result)) { ?>
                                                            <option value="<?php echo htmlspecialchars($year['year']); ?>" <?php echo ($form_data['vehicle_year'] ?? '') == $year['year'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($year['year']); ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <label for="vehicles[0][make]" class="col-md-3 col-form-label text-md-end">Vehicle Make:</label>
                                                <div class="col-md-9">
                                                    <select name="vehicles[0][make]" class="form-select vehicle-make" required>
                                                        <option value="">Choose...</option>
                                                        <?php if (!empty($form_data['vehicle_make'])) { ?>
                                                            <option value="<?php echo htmlspecialchars($form_data['vehicle_make']); ?>" selected><?php echo htmlspecialchars($form_data['vehicle_make']); ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <label for="vehicles[0][model]" class="col-md-3 col-form-label text-md-end">Vehicle Model:</label>
                                                <div class="col-md-9">
                                                    <select name="vehicles[0][model]" class="form-select vehicle-model" required>
                                                        <option value="">Choose...</option>
                                                        <?php if (!empty($form_data['vehicle_model'])) { ?>
                                                            <option value="<?php echo htmlspecialchars($form_data['vehicle_model']); ?>" selected><?php echo htmlspecialchars($form_data['vehicle_model']); ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <label for="vehicles[0][value]" class="col-md-3 col-form-label text-md-end">Sum Insured (R):</label>
                                                <div class="col-md-9">
                                                    <input type="number" name="vehicles[0][value]" class="form-control vehicle-value" min="1000" max="750000" value="<?php echo htmlspecialchars($form_data['vehicle_value'] ?? ''); ?>" required>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <label for="vehicles[0][coverage_type]" class="col-md-3 col-form-label text-md-end">Coverage Type:</label>
                                                <div class="col-md-9">
                                                    <select name="vehicles[0][coverage_type]" class="form-select" required>
                                                        <option value="">Select</option>
                                                        <option value="Comprehensive" <?php echo ($form_data['coverage_type'] ?? '') === 'Comprehensive' ? 'selected' : ''; ?>>Comprehensive</option>
                                                        <option value="Third Party" <?php echo ($form_data['coverage_type'] ?? '') === 'Third Party' ? 'selected' : ''; ?>>Third Party</option>
                                                        <option value="Fire and Theft" <?php echo ($form_data['coverage_type'] ?? '') === 'Fire and Theft' ? 'selected' : ''; ?>>Fire and Theft</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <label for="vehicles[0][use]" class="col-md-3 col-form-label text-md-end">Vehicle Use:</label>
                                                <div class="col-md-9">
                                                    <select name="vehicles[0][use]" class="form-select" required>
                                                        <option value="">Choose...</option>
                                                        <option value="Private" <?php echo ($form_data['vehicle_use'] ?? '') === 'Private' ? 'selected' : ''; ?>>Private</option>
                                                        <option value="Business" <?php echo ($form_data['vehicle_use'] ?? '') === 'Business' ? 'selected' : ''; ?>>Business</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <label for="vehicles[0][parking]" class="col-md-3 col-form-label text-md-end">Parking:</label>
                                                <div class="col-md-9">
                                                    <select name="vehicles[0][parking]" class="form-select" required>
                                                        <option value="">Choose...</option>
                                                        <option value="Behind_Locked_Gates" <?php echo ($form_data['parking'] ?? '') === 'Behind_Locked_Gates' ? 'selected' : ''; ?>>Behind Locked Gates</option>
                                                        <option value="LockedUp_Garage" <?php echo ($form_data['parking'] ?? '') === 'LockedUp_Garage' ? 'selected' : ''; ?>>Locked Up Garage</option>
                                                        <option value="in_street" <?php echo ($form_data['parking'] ?? '') === 'in_street' ? 'selected' : ''; ?>>In Street</option>


                                                    </select>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <label for="vehicles[0][car_hire]" class="col-md-3 col-form-label text-md-end">Car Hire Option:</label>
                                                <div class="col-md-9">
                                                    <select name="vehicles[0][car_hire]" class="form-select" required>
                                                        <option value="">Choose...</option>
                                                        <option value="None" <?php echo ($form_data['car_hire'] ?? '') === 'None' ? 'selected' : ''; ?>>None</option>
                                                        <option value="Group B Manual Hatchback" <?php echo ($form_data['car_hire'] ?? '') === 'Group B Manual Hatchback' ? 'selected' : ''; ?>>Group B Manual Hatchback (R85.00)</option>
                                                        <option value="Group C Manual Sedan" <?php echo ($form_data['car_hire'] ?? '') === 'Group C Manual Sedan' ? 'selected' : ''; ?>>Group C Manual Sedan (R95.00)</option>
                                                        <option value="Group D Automatic Hatchback" <?php echo ($form_data['car_hire'] ?? '') === 'Group D Automatic Hatchback' ? 'selected' : ''; ?>>Group D Automatic Hatchback (R110.00)</option>
                                                        <option value="Group H 1 Ton LDV" <?php echo ($form_data['car_hire'] ?? '') === 'Group H 1 Ton LDV' ? 'selected' : ''; ?>>Group H 1 Ton LDV (R130.00)</option>
                                                        <option value="Group M Luxury Hatchback" <?php echo ($form_data['car_hire'] ?? '') === 'Group M Luxury Hatchback' ? 'selected' : ''; ?>>Group M Luxury Hatchback (R320.00)</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="subheading col-md-3 text-md-end">Risk Address:</div>
                                            <div class="row mb-2">
                                                <label for="vehicles[0][street]" class="col-md-3 col-form-label text-md-end">Street:</label>
                                                <div class="col-md-9">
                                                    <input type="text" name="vehicles[0][street]" class="form-control" value="<?php echo htmlspecialchars($form_data['street'] ?? ''); ?>" required>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <label for="vehicles[0][suburb_vehicle]" class="col-md-3 col-form-label text-md-end">Suburb:</label>
                                                <div class="col-md-9">
                                                    <input type="text" name="vehicles[0][suburb_vehicle]" class="form-control" value="<?php echo htmlspecialchars($form_data['suburb_vehicle'] ?? ''); ?>" required>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <label for="vehicles[0][postal_code]" class="col-md-3 col-form-label text-md-end">Postal Code:</label>
                                                <div class="col-md-9">
                                                    <input type="number" name="vehicles[0][postal_code]" class="form-control" value="<?php echo htmlspecialchars($form_data['postal_code'] ?? ''); ?>" required>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                            
                                    <div class="driver-heading">Driver</div>
                                    <div class="card mb-3" style="color: white">
                                        <div class="card-header" style="background-color: #6A0DAD">Driver Details</div>
                                        <div class="card-body" style="color: black">
                                            <div class="row mb-2">
                                                <label for="vehicles[0][driver][title]" class="col-md-3 col-form-label text-md-end">Driver Title:</label>
                                                <div class="col-md-9">
                                                    <select name="vehicles[0][driver][title]" class="form-select driver-title" required>
                                                        <option value="">Choose...</option>
                                                        <option value="Mr." <?php echo ($form_data['driver_title'] ?? '') === 'Mr.' ? 'selected' : ''; ?>>Mr</option>
                                                        <option value="Mrs." <?php echo ($form_data['driver_title'] ?? '') === 'Mrs.' ? 'selected' : ''; ?>>Mrs</option>
                                                        <option value="Miss" <?php echo ($form_data['driver_title'] ?? '') === 'Miss' ? 'selected' : ''; ?>>Miss</option>
                                                        <option value="Dr." <?php echo ($form_data['driver_title'] ?? '') === 'Dr.' ? 'selected' : ''; ?>>Dr</option>
                                                        <option value="Prof" <?php echo ($form_data['driver_title'] ?? '') === 'Prof' ? 'selected' : ''; ?>>Prof</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <label for="vehicles[0][driver][initials]" class="col-md-3 col-form-label text-md-end">Driver Initials:</label>
                                                <div class="col-md-9">
                                                    <input type="text" name="vehicles[0][driver][initials]" class="form-control driver-initials" value="<?php echo htmlspecialchars($form_data['driver_initials'] ?? ''); ?>" required>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <label for="vehicles[0][driver][surname]" class="col-md-3 col-form-label text-md-end">Driver Surname:</label>
                                                <div class="col-md-9">
                                                    <input type="text" name="vehicles[0][driver][surname]" class="form-control driver-surname" value="<?php echo htmlspecialchars($form_data['driver_surname'] ?? ''); ?>" required>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <label for="vehicles[0][driver][id_number]" class="col-md-3 col-form-label text-md-end">Driver ID:</label>
                                                <div class="col-md-9">
                                                    <input type="text" name="vehicles[0][driver][id_number]" class="form-control driver-id_number" maxlength="13" value="<?php echo htmlspecialchars($form_data['driver_id'] ?? ''); ?>" required>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <label for="vehicles[0][driver][dob]" class="col-md-3 col-form-label text-md-end">Date of Birth:</label>
                                                <div class="col-md-9">
                                                    <input type="date" name="vehicles[0][driver][dob]" class="form-control driver-dob" min="1940-01-01" value="<?php echo htmlspecialchars($form_data['dob'] ?? ''); ?>" required>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <label for="vehicles[0][driver][age]" class="col-md-3 col-form-label text-md-end">Age:</label>
                                                <div class="col-md-9">
                                                    <input type="number" name="vehicles[0][driver][age]" class="form-control driver-age" min="18" max="120" value="<?php echo htmlspecialchars($form_data['age'] ?? ''); ?>" readonly>
                                                    <input type="hidden" name="vehicles[0][driver][age_hidden]" class="driver-age-hidden" value="<?php echo htmlspecialchars($form_data['age'] ?? ''); ?>">
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <label for="vehicles[0][driver][licence_type]" class="col-md-3 col-form-label text-md-end">Licence Type:</label>
                                                <div class="col-md-9">
                                                    <select name="vehicles[0][driver][licence_type]" class="form-select" required>
                                                        <option value="">Choose...</option>
                                                        <option value="B" <?php echo ($form_data['licence_type'] ?? '') === 'B' ? 'selected' : ''; ?>>B</option>
                                                        <option value="EB" <?php echo ($form_data['licence_type'] ?? '') === 'EB' ? 'selected' : ''; ?>>EB</option>
                                                        <option value="C1" <?php echo ($form_data['licence_type'] ?? '') === 'C1' ? 'selected' : ''; ?>>C1</option>
                                                        <option value="EC" <?php echo ($form_data['licence_type'] ?? '') === 'EC' ? 'selected' : ''; ?>>EC</option>
                                                        <option value="EC1" <?php echo ($form_data['licence_type'] ?? '') === 'EC1' ? 'selected' : ''; ?>>EC1</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <label for="vehicles[0][driver][year_of_issue]" class="col-md-3 col-form-label text-md-end">Year of Issue:</label>
                                                <div class="col-md-9">
                                                    <input type="number" name="vehicles[0][driver][year_of_issue]" class="form-control driver-year-of-issue" min="1924" max="2025" value="<?php echo htmlspecialchars($form_data['year_of_issue'] ?? ''); ?>" required>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <label for="vehicles[0][driver][licence_held]" class="col-md-3 col-form-label text-md-end">Licence Held:</label>
                                                <div class="col-md-9">
                                                    <input type="number" name="vehicles[0][driver][licence_held]" class="form-control driver-licence-held" value="<?php echo htmlspecialchars($form_data['licence_held'] ?? ''); ?>" readonly>
                                                    <input type="hidden" name="vehicles[0][driver][licence_held_hidden]" class="driver-licence-held-hidden" value="<?php echo htmlspecialchars($form_data['licence_held'] ?? ''); ?>">
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <label for="vehicles[0][driver][marital_status]" class="col-md-3 col-form-label text-md-end">Driver Marital Status:</label>
                                                <div class="col-md-9">
                                                    <select name="vehicles[0][driver][marital_status]" class="form-select driver-marital-status" required>
                                                        <option value="">Choose...</option>
                                                        <?php
                                                        foreach ($marital_statuses as $ms) {
                                                            $selected = (isset($form_data['driver_marital_status']) && trim($form_data['driver_marital_status']) === $ms) ? 'selected' : '';
                                                            echo "<option value=\"$ms\" $selected>$ms</option>";
                                                        }
                                                        ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <label for="vehicles[0][driver][ncb]" class="col-md-3 col-form-label text-md-end">Driver NCB:</label>
                                                <div class="col-md-9">
                                                    <select name="vehicles[0][driver][ncb]" class="form-select" required>
                                                        <option value="">Choose...</option>
                                                        <option value="0" <?php echo ($form_data['ncb'] ?? '') === '0' ? 'selected' : ''; ?>>0</option>
                                                        <option value="1" <?php echo ($form_data['ncb'] ?? '') === '1' ? 'selected' : ''; ?>>1</option>
                                                        <option value="2" <?php echo ($form_data['ncb'] ?? '') === '2' ? 'selected' : ''; ?>>2</option>
                                                        <option value="3" <?php echo ($form_data['ncb'] ?? '') === '3' ? 'selected' : ''; ?>>3</option>
                                                        <option value="4" <?php echo ($form_data['ncb'] ?? '') === '4' ? 'selected' : ''; ?>>4</option>
                                                        <option value="5" <?php echo ($form_data['ncb'] ?? '') === '5' ? 'selected' : ''; ?>>5</option>
                                                        <option value="6" <?php echo ($form_data['ncb'] ?? '') === '6' ? 'selected' : ''; ?>>6</option>
                                                        <option value="7" <?php echo ($form_data['ncb'] ?? '') === '7' ? 'selected' : ''; ?>>7+</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            <div class="col-12 mb-3">
                                <button type="button" class="btn btn-purple" id="add-vehicle">Add Another Vehicle</button>
                            </div>

                            <div class="form-check">
                                <input type="checkbox" name="waive_broker_fee" id="waive_broker_fee" value="1" <?php if ($form_data['waive_broker_fee'] ?? 0) echo 'checked'; ?>>
                                <label for="waive_broker_fee" class="form-check-label">Waive Broker Fee</label>
                            </div>

                            <div class="col-12">
                                <button type="submit" class="btn btn-purple">Save Quote</button>
                                <a href="../dashboard.php" class="btn btn-link ms-3">Back to Dashboard</a>
                            </div>
                        </form>
                    </div>

                    <!-- Right Side: Premium Display -->
                    <div class="col-md-6">
                        <?php if (isset($_GET['premium6']) || isset($_GET['premium5']) || isset($_GET['premium4']) || isset($_GET['premium_flat'])) { ?>
                            <h3 class="mb-3" style="color: var(--purple);">Quote Preview</h3>
                            <?php if (isset($premiums['premium6']) && $premiums['premium6'] > 0) { ?>
                                <div class="card premium-card mb-3" style="max-width: 22rem;">
                                    <div class="card-header">Premium 6</div>
                                    <div class="card-body">
                                        <p><strong>Premium:</strong> R <?php echo number_format($premiums['premium6'], 2); ?></p>
                                        <p><strong>Basic Excess:</strong> 6% of the sum insured min R5000.00</p>
                                        <p><strong>Security:</strong> <?php echo !empty($security) ? htmlspecialchars(implode(', ', $security)) : 'None'; ?></p>
                                    </div>
                                </div>
                            <?php } ?>
                            <?php if (isset($premiums['premium5']) && $premiums['premium5'] > 0) { ?>
                                <div class="card premium-card mb-3" style="max-width: 22rem;">
                                    <div class="card-header">Premium 5</div>
                                    <div class="card-body">
                                        <p><strong>Premium:</strong> R <?php echo number_format($premiums['premium5'], 2); ?></p>
                                        <p><strong>Basic Excess:</strong> 5% of the sum insured min R5000.00</p>
                                        <p><strong>Security:</strong> <?php echo !empty($security) ? htmlspecialchars(implode(', ', $security)) : 'None'; ?></p>
                                    </div>
                                </div>
                            <?php } ?>
                            <?php if (isset($premiums['premium4']) && $premiums['premium4'] > 0) { ?>
                                <div class="card premium-card mb-3" style="max-width: 22rem;">
                                    <div class="card-header">Premium 4</div>
                                    <div class="card-body">
                                        <p><strong>Premium:</strong> R <?php echo number_format($premiums['premium4'], 2); ?></p>
                                        <p><strong>Basic Excess:</strong> 4% of the sum insured min R5000.00</p>
                                        <p><strong>Security:</strong> <?php echo !empty($security) ? htmlspecialchars(implode(', ', $security)) : 'None'; ?></p>
                                    </div>
                                </div>
                            <?php } ?>
                            <?php if (isset($premiums['premium_flat']) && $premiums['premium_flat'] > 0) { ?>
                                <div class="card premium-card mb-3" style="max-width: 22rem;">
                                    <div class="card-header">Premium Flat</div>
                                    <div class="card-body">
                                        <p><strong>Premium:</strong> R <?php echo number_format($premiums['premium_flat'], 2); ?></p>
                                        <p><strong>Basic Excess:</strong> Flat R3500.00 only</p>
                                        <p><strong>Security:</strong> <?php echo !empty($security) ? htmlspecialchars(implode(', ', $security)) : 'None'; ?></p>
                                    </div>
                                </div>
                            <?php } ?>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <footer class="text-center py-3 mt-4">
        <p>© 2025 Profusion Insurance</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Confirm form submission
    document.getElementById('quoteForm').onsubmit = function() {
        return confirm('Are you sure you want to save this quote?');
    };

    // Calculate DOB from South African ID number
    function calculateDOBFromID(idNumber, dobInput, ageInput, hiddenAgeInput) {
        if (!idNumber || !/^\d{13}$/.test(idNumber)) {
            dobInput.value = '';
            ageInput.value = '';
            hiddenAgeInput.value = '';
            return;
        }
        
        // Extract year, month, and day from ID number
        const yearPrefix = parseInt(idNumber.substring(0, 2)) <= 24 ? '20' : '19';
        const year = yearPrefix + idNumber.substring(0, 2);
        const month = idNumber.substring(2, 4);
        const day = idNumber.substring(4, 6);
        
        // Validate date components
        const date = new Date(`${year}-${month}-${day}`);
        if (isNaN(date.getTime()) || parseInt(month) < 1 || parseInt(month) > 12 || parseInt(day) < 1 || parseInt(day) > 31) {
            dobInput.value = '';
            ageInput.value = '';
            hiddenAgeInput.value = '';
            return;
        }
        
        // Format as yyyy-mm-dd for input type="date"
        dobInput.value = `${year}-${month.padStart(2, '0')}-${day.padStart(2, '0')}`;
        
        // Calculate age
        const today = new Date();
        let age = today.getFullYear() - date.getFullYear();
        if (today.getMonth() < date.getMonth() || (today.getMonth() === date.getMonth() && today.getDate() < date.getDate())) {
            age--;
        }
        ageInput.value = age;
        hiddenAgeInput.value = age;
    }

    // Sync client title to driver title
    function syncTitle(sectionIndex) {
        const clientTitle = document.getElementById('title').value;
        const driverTitle = document.querySelector(`[name="vehicles[${sectionIndex}][driver][title]"]`);
        const validTitles = ['Mr.', 'Mrs.', 'Miss', 'Dr.', 'Prof'];
        if (validTitles.includes(clientTitle)) {
            driverTitle.value = clientTitle;
        } else if (!validTitles.includes(driverTitle.value)) {
            driverTitle.value = '';
        }
    }

    // Sync client initials to driver initials
    function syncInitials(sectionIndex) {
        const clientInitials = document.getElementById('initials').value;
        const driverInitials = document.querySelector(`[name="vehicles[${sectionIndex}][driver][initials]"]`);
        driverInitials.value = clientInitials;
    }

    // Sync client surname to driver surname
    function syncSurname(sectionIndex) {
        const clientSurname = document.getElementById('surname').value;
        const driverSurname = document.querySelector(`[name="vehicles[${sectionIndex}][driver][surname]"]`);
        driverSurname.value = clientSurname;
    }

    // Sync client marital status to driver marital status
    function syncMaritalStatus(sectionIndex) {
        const clientMaritalStatus = document.getElementById('marital_status').value;
        const driverMaritalStatus = document.querySelector(`[name="vehicles[${sectionIndex}][driver][marital_status]"]`);
        const validStatuses = ['Single', 'Married', 'Divorced', 'Cohabiting', 'Widowed'];
        if (validStatuses.includes(clientMaritalStatus)) {
            driverMaritalStatus.value = clientMaritalStatus;
        } else if (!validStatuses.includes(driverMaritalStatus.value)) {
            driverMaritalStatus.value = '';
        }
    }

    // Sync client ID to driver ID and calculate DOB
    function syncClientID(sectionIndex) {
        const clientID = document.getElementById('client_id').value;
        const driverID = document.querySelector(`[name="vehicles[${sectionIndex}][driver][id_number]"]`);
        const dobInput = document.querySelector(`[name="vehicles[${sectionIndex}][driver][dob]"]`);
        const ageInput = document.querySelector(`[name="vehicles[${sectionIndex}][driver][age]"]`);
        const hiddenAgeInput = document.querySelector(`[name="vehicles[${sectionIndex}][driver][age_hidden]"]`);
        driverID.value = clientID;
        calculateDOBFromID(clientID, dobInput, ageInput, hiddenAgeInput);
    }

    // Calculate age from DOB
    function calculateAge(dob, ageInput, hiddenAgeInput) {
        const birthDate = new Date(dob);
        const today = new Date();
        let age = today.getFullYear() - birthDate.getFullYear();
        if (today.getMonth() < birthDate.getMonth() || (today.getMonth() === birthDate.getMonth() && today.getDate() < birthDate.getDate())) {
            age--;
        }
        ageInput.value = age;
        hiddenAgeInput.value = age;
    }

    // Calculate years license held
    function calculateLH(year, heldInput, hiddenHeldInput) {
        const held = 2025 - parseInt(year);
        const licenceHeld = held >= 0 ? held : 0;
        heldInput.value = licenceHeld;
        hiddenHeldInput.value = licenceHeld;
    }

    // Fetch vehicle makes
    function fetchMakes(year, makeSelect, modelSelect, valueInput, sectionIndex) {
        if (!year) {
            makeSelect.innerHTML = '<option value="">Choose...</option>';
            modelSelect.innerHTML = '<option value="">Choose...</option>';
            valueInput.value = '';
            return;
        }
        
        fetch('get_vehicle_makes.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'year=' + encodeURIComponent(year)
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! Status: ${response.status} ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            makeSelect.innerHTML = '<option value="">Choose...</option>';
            if (Array.isArray(data)) {
                data.forEach(make => {
                    const option = document.createElement('option');
                    option.value = make;
                    option.textContent = make;
                    makeSelect.appendChild(option);
                });
            } else {
                console.error('Error fetching makes:', data.error || 'Invalid response');
            }
            modelSelect.innerHTML = '<option value="">Choose...</option>';
            valueInput.value = '';
        })
        .catch(error => console.error('Fetch error for makes:', error));
    }

    // Fetch vehicle models
    function fetchModels(year, make, modelSelect, valueInput, sectionIndex) {
        if (!year || !make) {
            modelSelect.innerHTML = '<option value="">Choose...</option>';
            valueInput.value = '';
            return;
        }
        
        fetch('get_vehicle_models.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'year=' + encodeURIComponent(year) + '&make=' + encodeURIComponent(make)
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! Status: ${response.status} ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            modelSelect.innerHTML = '<option value="">Choose...</option>';
            if (Array.isArray(data)) {
                data.forEach(model => {
                    const option = document.createElement('option');
                    option.value = model;
                    option.textContent = model;
                    modelSelect.appendChild(option);
                });
            } else {
                console.error('Error fetching models:', data.error || 'Invalid response');
            }
            valueInput.value = '';
        })
        .catch(error => console.error('Fetch error for models:', error));
    }

    // Fetch vehicle value
    function fetchVehicleValue(year, make, model, valueInput, sectionIndex) {
        if (!year || !make || !model) {
            valueInput.value = '';
            return;
        }
        
        fetch('get_vehicle_value.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'year=' + encodeURIComponent(year) + '&make=' + encodeURIComponent(make) + '&model=' + encodeURIComponent(model)
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! Status: ${response.status} ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.value) {
                valueInput.value = data.value;
            } else {
                valueInput.value = '';
                console.error('Error fetching vehicle value:', data.error || 'No value returned');
            }
        })
        .catch(error => console.error('Fetch error for vehicle value:', error));
    }

    // Initialize event listeners for a vehicle section
    function initializeVehicleSection(section, index) {
        const yearSelect = section.querySelector('.vehicle-year');
        const makeSelect = section.querySelector('.vehicle-make');
        const modelSelect = section.querySelector('.vehicle-model');
        const valueInput = section.querySelector('.vehicle-value');
        const dobInput = section.querySelector('.driver-dob');
        const ageInput = section.querySelector('.driver-age');
        const hiddenAgeInput = section.querySelector('.driver-age-hidden');
        const yearOfIssueInput = section.querySelector('.driver-year-of-issue');
        const licenceHeldInput = section.querySelector('.driver-licence-held');
        const hiddenLicenceHeldInput = section.querySelector('.driver-licence-held-hidden');
        const idInput = section.querySelector('.driver-id_number');

        // Set dynamic headings
        const vehicleHeading = section.querySelector('.vehicle-heading');
        const driverHeading = section.querySelector('.driver-heading');
        vehicleHeading.textContent = index === 0 ? 'Vehicle' : `Vehicle ${index}`;
        driverHeading.textContent = index === 0 ? 'Driver' : `Driver ${index}`;

        // Vehicle lookups
        yearSelect.addEventListener('change', function() {
            fetchMakes(this.value, makeSelect, modelSelect, valueInput, index);
        });

        makeSelect.addEventListener('change', function() {
            fetchModels(yearSelect.value, this.value, modelSelect, valueInput, index);
        });

        modelSelect.addEventListener('change', function() {
            fetchVehicleValue(yearSelect.value, makeSelect.value, this.value, valueInput, index);
        });

        // Age calculation
        dobInput.addEventListener('change', function() {
            calculateAge(this.value, ageInput, hiddenAgeInput);
        });

        // License held calculation
        yearOfIssueInput.addEventListener('change', function() {
            calculateLH(this.value, licenceHeldInput, hiddenLicenceHeldInput);
        });

        // Driver ID change triggers DOB calculation
        if (idInput) {
            idInput.addEventListener('change', function() {
                calculateDOBFromID(this.value, dobInput, ageInput, hiddenAgeInput);
            });
        }

        // Sync client fields to driver fields
        document.getElementById('title').addEventListener('change', function() {
            syncTitle(index);
        });
        document.getElementById('initials').addEventListener('input', function() {
            syncInitials(index);
        });
        document.getElementById('surname').addEventListener('input', function() {
            syncSurname(index);
        });
        document.getElementById('marital_status').addEventListener('change', function() {
            syncMaritalStatus(index);
        });
        document.getElementById('client_id').addEventListener('input', function() {
            syncClientID(index);
        });

        // Initialize for existing data
        if (dobInput.value) calculateAge(dobInput.value, ageInput, hiddenAgeInput);
        if (yearOfIssueInput.value) calculateLH(yearOfIssueInput.value, licenceHeldInput, hiddenLicenceHeldInput);
        syncTitle(index);
        syncInitials(index);
        syncSurname(index);
        syncMaritalStatus(index);
        syncClientID(index);

        // Initialize vehicle lookups for first section if data exists
        if (yearSelect.value) {
            const firstYear = yearSelect.value;
            fetchMakes(firstYear, makeSelect, modelSelect, valueInput, index);
            const firstMake = makeSelect.value;
            if (firstMake) {
                fetchModels(firstYear, firstMake, modelSelect, valueInput, index);
                const firstModel = modelSelect.value;
                if (firstModel) {
                    fetchVehicleValue(firstYear, firstMake, firstModel, valueInput, index);
                }
            }
        }
    }

    // Add new vehicle section
    let vehicleIndex = 1;
    document.getElementById('add-vehicle').addEventListener('click', function() {
        const template = document.querySelector('.vehicle-section').cloneNode(true);
        template.querySelectorAll('input, select').forEach(input => {
            input.value = '';
            if (input.name) {
                input.name = input.name.replace(/vehicles\[0\]/g, `vehicles[${vehicleIndex}]`);
            }
        });
        template.querySelector('.remove-vehicle').style.display = 'block';
        document.getElementById('vehicle-sections').appendChild(template);
        initializeVehicleSection(template, vehicleIndex);
        vehicleIndex++;
    });

    // Remove vehicle section
    document.getElementById('vehicle-sections').addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-vehicle')) {
            const sections = document.querySelectorAll('.vehicle-section');
            if (sections.length > 1) {
                e.target.closest('.vehicle-section').remove();
            }
        }
    });

    // Validate form on submit
    document.getElementById('quoteForm').addEventListener('submit', function(e) {
        const sections = document.querySelectorAll('.vehicle-section');
        for (let i = 0; i < sections.length; i++) {
            const title = sections[i].querySelector(`[name="vehicles[${i}][driver][title]"]`).value;
            const maritalStatus = sections[i].querySelector(`[name="vehicles[${i}][driver][marital_status]"]`).value;
            const validTitles = ['Mr.', 'Mrs.', 'Miss', 'Dr.', 'Prof'];
            const validMaritalStatuses = ['Single', 'Married', 'Divorced', 'Cohabiting', 'Widowed'];
            if (!validTitles.includes(title) || !validMaritalStatuses.includes(maritalStatus)) {
                e.preventDefault();
                alert('Please select valid options for Driver Title and Driver Marital Status in all vehicle sections.');
                return;
            }
        }
    });

    // Initialize first section
    document.addEventListener('DOMContentLoaded', function() {
        const firstSection = document.querySelector('.vehicle-section');
        firstSection.querySelector('.remove-vehicle').style.display = 'none'; // Hide remove button for first section
        initializeVehicleSection(firstSection, 0);
    });
    </script>
</body>
</html>

<?php
mysqli_close($conn);
?>