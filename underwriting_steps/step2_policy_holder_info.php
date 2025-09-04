<?php
require 'underwriting_common.php'; // Same directory: /home/profusi3/public_html/quoting/underwriting_steps/

// Check if we're on the correct step (handled by underwriting_common.php)
// $current_step is already validated in underwriting_common.php

// Initialize variables for edit mode
$edit_mode = isset($_GET['edit_mode']) && $_GET['edit_mode'] === 'true';
$responses = [];

// Fetch or create policy_id
$stmt = $conn->prepare("SELECT policy_id FROM policies WHERE quote_id = ?");
$stmt->bind_param("i", $quote_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $policy_id = $result->fetch_assoc()['policy_id'];
} else if (!$edit_mode) {
    // Create draft policy in normal mode
    if (!isset($_SESSION['underwriting_product'][$quote_id]) || !isset($_SESSION['underwriting_product'][$quote_id]['premium_amount'])) {
        error_log("No product_type or premium_amount in session for quote_id=$quote_id");
        $_SESSION['errors'] = ["Product type or premium amount not set. Please start over from the dashboard."];
        header("Location: ../dashboard.php");
        exit();
    }
    $product_type = $_SESSION['underwriting_product'][$quote_id]['product_type'];
    $premium_amount = $_SESSION['underwriting_product'][$quote_id]['premium_amount'];
    $stmt = $conn->prepare("
        INSERT INTO policies (quote_id, user_id, brokerage_id, status, premium_type, premium_amount)
        VALUES (?, ?, ?, 'draft', ?, ?)
    ");
    $stmt->bind_param("iiisd", $quote_id, $_SESSION['user_id'], $quote_data['brokerage_id'], $product_type, $premium_amount);
    if (!$stmt->execute()) {
        throw new Exception("Failed to create draft policy: " . $stmt->error);
    }
    $policy_id = $conn->insert_id;
    error_log("Created draft policy with policy_id=$policy_id for quote_id=$quote_id");
} else {
    error_log("No policy found for quote_id=$quote_id in edit mode");
    $_SESSION['errors'] = ["No policy found for this quote ID in edit mode."];
    header("Location: ../dashboard.php");
    exit();
}
$stmt->close();

// Load existing responses from policy_underwriting_data if in edit_mode
if ($edit_mode && $policy_id) {
    $stmt = $conn->prepare("
        SELECT question_key, response
        FROM policy_underwriting_data
        WHERE policy_id = ? AND section = 'policy_holder_information'
    ");
    $stmt->bind_param("i", $policy_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $responses[$row['question_key']] = $row['response'];
    }
    $stmt->close();
    error_log("Edit mode: Loaded responses for step 2: " . print_r($responses, true));
}

// Merge quote_data and responses for form population
$form_data = [
    '2.1_initials' => $responses['2.1_initials'] ?? $quote_data['initials'] ?? '',
    '2.1_surname' => $responses['2.1_surname'] ?? $quote_data['surname'] ?? '',
    '2.2_title' => $responses['2.2_title'] ?? $quote_data['title'] ?? '',
    '2.3_marital_status' => $responses['2.3_marital_status'] ?? $quote_data['marital_status'] ?? '',
    '2.4_client_id' => $responses['2.4_client_id'] ?? $quote_data['client_id'] ?? '',
    '2.5_cell_number' => $responses['2.5_cell_number'] ?? $quote_data['cell_number'] ?? '',
    '2.6_personal_cell' => $responses['2.6_personal_cell'] ?? '',
    '2.7_sms_consent' => $responses['2.7_sms_consent'] ?? '',
    '2.8_email' => $responses['2.8_email'] ?? $quote_data['email'] ?? '',
    '2.10_physical_address' => $responses['2.10_physical_address'] ?? $quote_data['physical_address'] ?? '',
    '2.10_physical_suburb' => $responses['2.10_physical_suburb'] ?? $quote_data['suburb_client'] ?? '',
    '2.10_physical_postal_code' => $responses['2.10_physical_postal_code'] ?? $quote_data['postal_code_client'] ?? '',
    '2.11_address_update_understanding' => $responses['2.11_address_update_understanding'] ?? '',
    '2.12_postal_address' => $responses['2.12_postal_address'] ?? $quote_data['postal_address'] ?? '',
    '2.12_postal_suburb' => $responses['2.12_postal_suburb'] ?? $quote_data['postal_suburb'] ?? '',
    '2.12_postal_postal_code' => $responses['2.12_postal_postal_code'] ?? $quote_data['postal_postal_code'] ?? '',
    '2.13_dual_insurance' => $responses['2.13_dual_insurance'] ?? '',
    '2.13_dual_insurance_cancellation_date' => $responses['2.13_dual_insurance_cancellation_date'] ?? ''
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();
    try {
        // Debug: Log POST data
        error_log("Step 2 POST Data: " . print_r($_POST, true));

        // Determine navigation direction
        $direction = $_POST['direction'] ?? 'next';

        // Process Step 2 responses
        $responses = [
            '2.1_initials' => $_POST['initials'] ?? null,
            '2.1_surname' => $_POST['surname'] ?? null,
            '2.2_title' => $_POST['title'] ?? null,
            '2.3_marital_status' => $_POST['marital_status'] ?? null,
            '2.4_client_id' => $_POST['client_id'] ?? null,
            '2.5_cell_number' => $_POST['cell_number'] ?? null,
            '2.6_personal_cell' => $_POST['personal_cell'] ?? null,
            '2.7_sms_consent' => $_POST['sms_consent'] ?? null,
            '2.8_email' => $_POST['email'] ?? '',
            '2.10_physical_address' => $_POST['physical_address'] ?? null,
            '2.10_physical_suburb' => $_POST['physical_suburb'] ?? '',
            '2.10_physical_postal_code' => $_POST['physical_postal_code'] ?? '',
            '2.11_address_update_understanding' => $_POST['address_update_understanding'] ?? null,
            '2.12_postal_address' => $_POST['postal_address'] ?? null,
            '2.12_postal_suburb' => $_POST['postal_suburb'] ?? '',
            '2.12_postal_postal_code' => $_POST['postal_postal_code'] ?? '',
            '2.13_dual_insurance' => $_POST['dual_insurance'] ?? null,
            '2.13_dual_insurance_cancellation_date' => $_POST['dual_insurance_cancellation_date'] ?? ''
        ];

        // Validate responses
        if (empty($responses['2.1_initials']) || !preg_match('/^[A-Za-z. ]+$/', $responses['2.1_initials'])) {
            throw new Exception("Invalid or missing initials");
        }
        if (empty($responses['2.1_surname']) || !preg_match('/^[A-Za-z ]+$/', $responses['2.1_surname'])) {
            throw new Exception("Invalid or missing surname");
        }
        if (!in_array($responses['2.2_title'], ['Mr.', 'Mrs.', 'Miss', 'Dr.', 'Prof'])) {
            throw new Exception("Invalid or missing title");
        }
        if (!in_array($responses['2.3_marital_status'], ['Single', 'Married', 'Divorced', 'Cohabiting', 'Widowed'])) {
            throw new Exception("Invalid or missing marital status");
        }
        if (empty($responses['2.4_client_id']) || !preg_match('/^\d{13}$/', $responses['2.4_client_id'])) {
            throw new Exception("Invalid or missing client ID (must be 13 digits)");
        }
        if (empty($responses['2.5_cell_number']) || !preg_match('/^\d{10}$/', $responses['2.5_cell_number'])) {
            throw new Exception("Invalid or missing cell number (must be 10 digits)");
        }
        if (!in_array($responses['2.6_personal_cell'], ['yes', 'no'])) {
            throw new Exception("Invalid or missing personal cell confirmation");
        }
        if (!in_array($responses['2.7_sms_consent'], ['yes', 'no'])) {
            throw new Exception("Invalid or missing SMS consent");
        }
        if (!empty($responses['2.8_email']) && !filter_var($responses['2.8_email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email address");
        }
        if (empty($responses['2.10_physical_address'])) {
            throw new Exception("Invalid or missing physical address");
        }
        if (!empty($responses['2.10_physical_suburb']) && !preg_match('/^[A-Za-z ]+$/', $responses['2.10_physical_suburb'])) {
            throw new Exception("Invalid physical suburb");
        }
        if (!empty($responses['2.10_physical_postal_code']) && !preg_match('/^\d{4}$/', $responses['2.10_physical_postal_code'])) {
            throw new Exception("Invalid physical postal code (must be 4 digits)");
        }
        if (!in_array($responses['2.11_address_update_understanding'], ['yes', 'no'])) {
            throw new Exception("Invalid or missing address update understanding");
        }
        if ($responses['2.11_address_update_understanding'] === 'no') {
            throw new Exception("Client did not understand address update responsibility. Underwriting cannot proceed.");
        }
        if (empty($responses['2.12_postal_address'])) {
            throw new Exception("Invalid or missing postal address");
        }
        if (!empty($responses['2.12_postal_suburb']) && !preg_match('/^[A-Za-z ]+$/', $responses['2.12_postal_suburb'])) {
            throw new Exception("Invalid postal suburb");
        }
        if (!empty($responses['2.12_postal_postal_code']) && !preg_match('/^\d{4}$/', $responses['2.12_postal_postal_code'])) {
            throw new Exception("Invalid postal postal code (must be 4 digits)");
        }
        if (!in_array($responses['2.13_dual_insurance'], ['yes', 'no'])) {
            throw new Exception("Invalid or missing dual insurance response");
        }
        if ($responses['2.13_dual_insurance'] === 'yes' && empty($responses['2.13_dual_insurance_cancellation_date'])) {
            throw new Exception("Cancellation date required for dual insurance");
        }
        if ($responses['2.13_dual_insurance'] === 'yes') {
            $cancellation_date = DateTime::createFromFormat('Y-m-d', $responses['2.13_dual_insurance_cancellation_date']);
            if (!$cancellation_date || $cancellation_date < new DateTime()) {
                throw new Exception("Invalid or past cancellation date for dual insurance");
            }
        }

        // Update quotes table
        $physical_suburb = $responses['2.10_physical_suburb'] ?: ($quote_data['suburb_client'] ?? null);
        $physical_postal_code = $responses['2.10_physical_postal_code'] ?: ($quote_data['postal_code_client'] ?? null);
        $stmt = $conn->prepare("
            UPDATE quotes SET
                initials = ?, surname = ?, title = ?, marital_status = ?, client_id = ?, 
                suburb_client = ?, postal_code_client = ?
            WHERE quote_id = ?
        ");
        $stmt->bind_param(
            "sssssssi",
            $responses['2.1_initials'],
            $responses['2.1_surname'],
            $responses['2.2_title'],
            $responses['2.3_marital_status'],
            $responses['2.4_client_id'],
            $physical_suburb,
            $physical_postal_code,
            $quote_id
        );
        if (!$stmt->execute()) {
            throw new Exception("Failed to update quote details: " . $stmt->error);
        }
        $stmt->close();

        // Save responses to policy_underwriting_data
        $stmt = $conn->prepare("
            INSERT INTO policy_underwriting_data (policy_id, section, question_key, response)
            VALUES (?, 'policy_holder_information', ?, ?)
            ON DUPLICATE KEY UPDATE response = ?
        ");
        foreach ($responses as $key => $value) {
            $stmt->bind_param("isss", $policy_id, $key, $value, $value);
            if (!$stmt->execute()) {
                throw new Exception("Failed to save response for $key: " . $stmt->error);
            }
        }
        $stmt->close();

        // If in edit_mode, redirect to dashboard after save; else advance to next step
        if ($edit_mode) {
            $conn->commit();
            error_log("Step 2 Edit Mode: Changes saved, redirecting to dashboard");
            header("Location: ../dashboard.php");
            exit();
        } else {
            // Advance to next step in normal mode
            $_SESSION['underwriting_step'][$quote_id] = 3;
            $conn->commit();
            error_log("Step 2: Navigating to next step (Step 3)");
            header("Location: step3_vehicle_driver_details.php?quote_id=$quote_id");
            exit();
        }

    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $errors[] = $e->getMessage();
        error_log("Step 2 Transaction failed: " . $e->getMessage());
        $_SESSION['errors'] = $errors;
        header("Location: step2_policy_holder_info.php?quote_id=$quote_id" . ($edit_mode ? '&edit_mode=true' : ''));
        exit();
    }
}

// Start HTML
start_html($script_sections[2]['name']);
?>

<div class="container mt-4">
    <h2 class="mb-4">Underwrite Policy (Quote ID: <?php echo htmlspecialchars($quote_id ?? 'N/A'); ?>)</h2>
    <?php if (isset($_SESSION['errors'])) { ?>
        <div class="alert alert-danger">
            <?php foreach ($_SESSION['errors'] as $error) { ?>
                <p><?php echo htmlspecialchars($error); ?></p>
            <?php } ?>
            <?php unset($_SESSION['errors']); ?>
        </div>
    <?php } ?>
    <!-- Progress Bar -->
    <div class="progress mb-4">
        <div class="progress-bar" role="progressbar" style="width: <?php echo (100 / count($script_sections)) * $current_step; ?>%;" aria-valuenow="<?php echo $current_step; ?>" aria-valuemin="1" aria-valuemax="<?php echo count($script_sections); ?>">
            Step <?php echo $current_step; ?> of <?php echo count($script_sections); ?>
        </div>
    </div>
    <!-- Navigation Bar -->
    <div class="mb-4">
        <h5>Navigate to Step:</h5>
        <div class="btn-group" role="group" aria-label="Underwriting Steps">
            <?php foreach ($script_sections as $step => $section) { ?>
                <?php
                $is_current_step = $step === $current_step;
                $disabled = !$edit_mode && $is_current_step ? 'disabled' : '';
                $btn_class = $is_current_step ? 'btn btn-purple' : 'btn btn-outline-purple';
                $file_path = $section['file'];
                ?>
                <a href="<?php echo htmlspecialchars($file_path . '?quote_id=' . $quote_id . ($edit_mode ? '&edit_mode=true' : '')); ?>" 
                   class="<?php echo $btn_class; ?>" 
                   <?php echo $disabled; ?>>
                    <?php echo htmlspecialchars($section['name']); ?> (Step <?php echo $step; ?>)
                </a>
            <?php } ?>
        </div>
    </div>
    <div class="row">
        <div class="col-md-8">
            <form method="post" action="step2_policy_holder_info.php?quote_id=<?php echo htmlspecialchars($quote_id); ?><?php echo $edit_mode ? '&edit_mode=true' : ''; ?>" class="row g-3" id="underwritingForm">
                <div class="col-12">
                    <div class="card mb-3">
                        <div class="card-header section-heading"><?php echo htmlspecialchars($script_sections[2]['name']); ?></div>
                        <div class="card-body">
                            <?php if (!empty($_SESSION['errors'])): ?>
                                <div class="alert alert-danger">
                                    <?php foreach ($_SESSION['errors'] as $error): ?>
                                        <p><?php echo htmlspecialchars($error); ?></p>
                                    <?php endforeach; ?>
                                    <?php unset($_SESSION['errors']); ?>
                                </div>
                            <?php endif; ?>
                            <p><strong>2.1</strong> Please confirm your full initials and surname.</p>
                            <div class="row mb-2">
                                <label for="initials" class="col-md-4 col-form-label">Initials:</label>
                                <div class="col-md-8">
                                    <input type="text" name="initials" id="initials" value="<?php echo htmlspecialchars($form_data['2.1_initials']); ?>" class="form-control" required pattern="[A-Za-z. ]+">
                                </div>
                            </div>
                            <div class="row mb-2">
                                <label for="surname" class="col-md-4 col-form-label">Surname:</label>
                                <div class="col-md-8">
                                    <input type="text" name="surname" id="surname" value="<?php echo htmlspecialchars($form_data['2.1_surname']); ?>" class="form-control" required pattern="[A-Za-z ]+">
                                </div>
                            </div>
                            <p><strong>2.2</strong> Are you Mr. / Mrs. / Dr. / Prof?</p>
                            <div class="row mb-2">
                                <label for="title" class="col-md-4 col-form-label">Title:</label>
                                <div class="col-md-8">
                                    <select name="title" id="title" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="Mr." <?php echo ($form_data['2.2_title'] === 'Mr.') ? 'selected' : ''; ?>>Mr</option>
                                        <option value="Mrs." <?php echo ($form_data['2.2_title'] === 'Mrs.') ? 'selected' : ''; ?>>Mrs</option>
                                        <option value="Miss" <?php echo ($form_data['2.2_title'] === 'Miss') ? 'selected' : ''; ?>>Miss</option>
                                        <option value="Dr." <?php echo ($form_data['2.2_title'] === 'Dr.') ? 'selected' : ''; ?>>Dr</option>
                                        <option value="Prof" <?php echo ($form_data['2.2_title'] === 'Prof') ? 'selected' : ''; ?>>Prof</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>2.3</strong> What is your marital status?</p>
                            <div class="row mb-2">
                                <label for="marital_status" class="col-md-4 col-form-label">Marital Status:</label>
                                <div class="col-md-8">
                                    <select name="marital_status" id="marital_status" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <?php
                                        $marital_statuses = ['Single', 'Married', 'Divorced', 'Cohabiting', 'Widowed'];
                                        foreach ($marital_statuses as $ms) {
                                            $selected = ($form_data['2.3_marital_status'] === $ms) ? 'selected' : '';
                                            echo "<option value=\"$ms\" $selected>$ms</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                            <p><strong>2.4</strong> May I please have your ID number? (Client must read out in full)</p>
                            <div class="row mb-2">
                                <label for="client_id" class="col-md-4 col-form-label">Client ID:</label>
                                <div class="col-md-8">
                                    <input type="text" name="client_id" id="client_id" maxlength="13" value="<?php echo htmlspecialchars($form_data['2.4_client_id']); ?>" class="form-control" pattern="\d{13}" required>
                                </div>
                            </div>
                            <p><strong>2.5</strong> Please confirm your cell number?</p>
                            <div class="row mb-2">
                                <label for="cell_number" class="col-md-4 col-form-label">Cell Number:</label>
                                <div class="col-md-8">
                                    <input type="text" name="cell_number" id="cell_number" value="<?php echo htmlspecialchars($form_data['2.5_cell_number']); ?>" class="form-control" pattern="\d{10}" required>
                                </div>
                            </div>
                            <p><strong>2.6</strong> Is this your personal cell number?</p>
                            <div class="row mb-2">
                                <label class="col-md-4 col-form-label">Personal Cell Number:</label>
                                <div class="col-md-8">
                                    <select name="personal_cell" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($form_data['2.6_personal_cell'] === 'yes') ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($form_data['2.6_personal_cell'] === 'no') ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>2.7</strong> Please note that we will SMS you relevant and important information pertaining to your policy from time to time, is that alright?</p>
                            <div class="row mb-2">
                                <label class="col-md-4 col-form-label">Consent for SMS:</label>
                                <div class="col-md-8">
                                    <select name="sms_consent" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($form_data['2.7_sms_consent'] === 'yes') ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($form_data['2.7_sms_consent'] === 'no') ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>2.8</strong> May I please have your email address? (Client must spell it out)</p>
                            <div class="row mb-2">
                                <label for="email" class="col-md-4 col-form-label">Email Address:</label>
                                <div class="col-md-8">
                                    <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($form_data['2.8_email']); ?>" class="form-control">
                                    <small class="form-text text-muted">Leave blank if no email address is provided. Policy documents will be posted.</small>
                                </div>
                            </div>
                            <p><strong>2.9</strong> Your physical address is used to calculate your premium, it is very important that this is the address where the insured items are kept.</p>
                            <p><strong>2.10</strong> Please confirm your full address.</p>
                            <div class="row mb-2">
                                <label for="physical_address" class="col-md-4 col-form-label">Physical Address (Street):</label>
                                <div class="col-md-8">
                                    <input type="text" name="physical_address" id="physical_address" value="<?php echo htmlspecialchars($form_data['2.10_physical_address']); ?>" class="form-control" required>
                                </div>
                            </div>
                            <div class="row mb-2">
                                <label for="physical_suburb" class="col-md-4 col-form-label">Physical Suburb:</label>
                                <div class="col-md-8">
                                    <input type="text" name="physical_suburb" id="physical_suburb" value="<?php echo htmlspecialchars($form_data['2.10_physical_suburb']); ?>" class="form-control" pattern="[A-Za-z ]*">
                                </div>
                            </div>
                            <div class="row mb-2">
                                <label for="physical_postal_code" class="col-md-4 col-form-label">Physical Postal Code:</label>
                                <div class="col-md-8">
                                    <input type="text" name="physical_postal_code" id="physical_postal_code" value="<?php echo htmlspecialchars($form_data['2.10_physical_postal_code']); ?>" class="form-control" pattern="\d{4}">
                                </div>
                            </div>
                            <p><strong>2.11</strong> Please note that should this change and you do not notify us this could negatively affect the outcome of your claim. It is your responsibility to ensure that you always keep us up to date.</p>
                            <div class="row mb-2">
                                <label class="col-md-4 col-form-label">Client understands address update responsibility:</label>
                                <div class="col-md-8">
                                    <select name="address_update_understanding" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($form_data['2.11_address_update_understanding'] === 'yes') ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($form_data['2.11_address_update_understanding'] === 'no') ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>2.12</strong> Please confirm your full postal address.</p>
                            <div class="row mb-2">
                                <label for="postal_address" class="col-md-4 col-form-label">Postal Address (Street):</label>
                                <div class="col-md-8">
                                    <input type="text" name="postal_address" id="postal_address" value="<?php echo htmlspecialchars($form_data['2.12_postal_address']); ?>" class="form-control" required>
                                </div>
                            </div>
                            <div class="row mb-2">
                                <label for="postal_suburb" class="col-md-4 col-form-label">Postal Suburb:</label>
                                <div class="col-md-8">
                                    <input type="text" name="postal_suburb" id="postal_suburb" value="<?php echo htmlspecialchars($form_data['2.12_postal_suburb']); ?>" class="form-control" pattern="[A-Za-z ]*">
                                </div>
                            </div>
                            <div class="row mb-2">
                                <label for="postal_postal_code" class="col-md-4 col-form-label">Postal Postal Code:</label>
                                <div class="col-md-8">
                                    <input type="text" name="postal_postal_code" id="postal_postal_code" value="<?php echo htmlspecialchars($form_data['2.12_postal_postal_code']); ?>" class="form-control" pattern="\d{4}">
                                </div>
                            </div>
                            <p><strong>2.13</strong> Are any of the items insured under this policy insured with any other insurer?</p>
                            <div class="row mb-2">
                                <label class="col-md-4 col-form-label">Dual Insurance:</label>
                                <div class="col-md-8">
                                    <select name="dual_insurance" id="dual_insurance" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="no" <?php echo ($form_data['2.13_dual_insurance'] === 'no') ? 'selected' : ''; ?>>No</option>
                                        <option value="yes" <?php echo ($form_data['2.13_dual_insurance'] === 'yes') ? 'selected' : ''; ?>>Yes (Specify cancellation date)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row mb-2" id="dual_insurance_details" style="display: <?php echo ($form_data['2.13_dual_insurance'] === 'yes') ? 'block' : 'none'; ?>;">
                                <label for="dual_insurance_cancellation_date" class="col-md-4 col-form-label">Cancellation Date of Other Policy:</label>
                                <div class="col-md-8">
                                    <input type="date" name="dual_insurance_cancellation_date" id="dual_insurance_cancellation_date" value="<?php echo htmlspecialchars($form_data['2.13_dual_insurance_cancellation_date']); ?>" class="form-control">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <button type="submit" name="direction" value="previous" class="btn btn-secondary">Previous Step</button>
                    <button type="submit" name="direction" value="next" class="btn btn-purple"><?php echo $edit_mode ? 'Save Changes' : 'Next Step'; ?></button>
                    <a href="../dashboard.php" class="btn btn-link ms-3">Back to Dashboard</a>
                </div>
            </form>
        </div>
    </div>
</div>
</main>

<footer class="text-center py-3 mt-4">
    <p>© 2025 Profusion Insurance</p>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const dualInsuranceSelect = document.querySelector('select[name="dual_insurance"]');
        const dualInsuranceDetails = document.getElementById('dual_insurance_details');
        if (dualInsuranceSelect) {
            dualInsuranceSelect.addEventListener('change', function() {
                dualInsuranceDetails.style.display = this.value === 'yes' ? 'block' : 'none';
                const cancellationInput = document.getElementById('dual_insurance_cancellation_date');
                cancellationInput.required = this.value === 'yes';
            });
            dualInsuranceDetails.style.display = dualInsuranceSelect.value === 'yes' ? 'block' : 'none';
            const cancellationInput = document.getElementById('dual_insurance_cancellation_date');
            cancellationInput.required = dualInsuranceSelect.value === 'yes';
        }

        // Client-side validation
        document.getElementById('underwritingForm').addEventListener('submit', function(event) {
            const requiredInputs = document.querySelectorAll('input[required], select[required]');
            let valid = true;
            requiredInputs.forEach(input => {
                if (!input.value) {
                    valid = false;
                    input.classList.add('is-invalid');
                } else {
                    input.classList.remove('is-invalid');
                }
            });
            if (!valid) {
                event.preventDefault();
                alert('Please fill out all required fields.');
            }
        });
    });
</script>
</body>
</html>
<?php
$conn->close();
?>