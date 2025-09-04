<?php
require 'underwriting_common.php';
use Ramsey\Uuid\Uuid;

// Check if we're on the correct step (handled by underwriting_common.php)
// $current_step is already validated in underwriting_common.php

// Initialize variables for edit mode
$edit_mode = isset($_GET['edit_mode']) && $_GET['edit_mode'] === 'true';
$responses = [];

// Fetch policy_id and banking details
$stmt = $conn->prepare("SELECT policy_id, account_holder, bank_name, account_number, branch_code, account_type, debit_date, policy_start_date FROM policies WHERE quote_id = ?");
$stmt->bind_param("i", $quote_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $policy_data = $result->fetch_assoc();
    $policy_id = $policy_data['policy_id'];
    // Pre-load banking details from policies table
    $responses['4.2_bank_name'] = $policy_data['bank_name'] ?? '';
    $responses['4.3_branch_code'] = $policy_data['branch_code'] ?? '';
    $responses['4.4_account_type'] = $policy_data['account_type'] ?? '';
    $responses['4.5_account_holder'] = $policy_data['account_holder'] ?? '';
    $responses['4.6_account_number'] = $policy_data['account_number'] ?? '';
    $responses['4.7_debit_date'] = $policy_data['debit_date'] ?? '';
    $responses['4.9_policy_start_date'] = $policy_data['policy_start_date'] ?? date('Y-m-d');
} else {
    $policy_id = null; // Will create draft in POST if needed (normal mode only)
}
$stmt->close();

// Load additional responses from policy_underwriting_data if in edit_mode
if ($edit_mode && $policy_id) {
    $stmt = $conn->prepare("
        SELECT question_key, response
        FROM policy_underwriting_data
        WHERE policy_id = ? AND section = 'bank_details_mandate'
    ");
    $stmt->bind_param("i", $policy_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $responses[$row['question_key']] = $row['response'];
    }
    $stmt->close();
    error_log("Edit mode: Loaded responses for step 5: " . print_r($responses, true));
}

// Use premium amount from session
if (!isset($_SESSION['underwriting_product'][$quote_id]) || !isset($_SESSION['underwriting_product'][$quote_id]['premium_amount'])) {
    error_log("No premium amount found in session for quote_id=$quote_id");
    $_SESSION['errors'] = ["Premium amount not set. Please start over from the dashboard."];
    header("Location: ../dashboard.php");
    exit();
}
$premium_amount = $_SESSION['underwriting_product'][$quote_id]['premium_amount'];

// Fetch brokerage_id for broker fee
$stmt = $conn->prepare("SELECT brokerage_id FROM quotes WHERE quote_id = ?");
$stmt->bind_param("i", $quote_id);
$stmt->execute();
$result = $stmt->get_result();
$brokerage_id = $result->num_rows > 0 ? $result->fetch_assoc()['brokerage_id'] : null;
$stmt->close();

// Fetch broker fee from brokerages table
$broker_fee = 0.00; // Default if not found
if ($brokerage_id) {
    $stmt = $conn->prepare("SELECT broker_fee FROM brokerages WHERE brokerage_id = ?");
    $stmt->bind_param("i", $brokerage_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $broker_fee = $result->fetch_assoc()['broker_fee'] ?? 0.00;
    }
    $stmt->close();
}

// Calculate pro-rata premium
$today = new DateTime();
$year = $today->format('Y');
$month = $today->format('m');
$days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$current_day = (int)$today->format('d');
$days_remaining = $days_in_month - $current_day + 1; // Include today
$pro_rata_premium = ($premium_amount / $days_in_month) * $days_remaining;
$pro_rata_premium = round($pro_rata_premium, 2);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();
    try {
        // Debug: Log POST data
        error_log("Step 5 POST Data: " . print_r($_POST, true));

        // Determine navigation direction
        $direction = $_POST['direction'] ?? 'next';

        // Check if policy exists, else create a draft (only in normal mode)
        $stmt = $conn->prepare("SELECT policy_id FROM policies WHERE quote_id = ?");
        $stmt->bind_param("i", $quote_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $policy_id = $result->fetch_assoc()['policy_id'];
        } else if (!$edit_mode) {
            // Create draft in normal mode
            if (!isset($_SESSION['underwriting_product'][$quote_id]) || !isset($_SESSION['underwriting_product'][$quote_id]['premium_amount'])) {
                error_log("No product_type or premium_amount in session for quote_id=$quote_id");
                throw new Exception("Product type or premium amount not set. Please start over from the dashboard.");
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
            throw new Exception("No policy found for editing in edit mode.");
        }
        $stmt->close();

        if ($direction === 'previous') {
            if (!$edit_mode) {
                $_SESSION['underwriting_step'][$quote_id] = 4;
            }
            $conn->commit();
            error_log("Step 5: Navigating to previous step (Step 4)");
            header("Location: step4_excess_disclosures.php?quote_id=$quote_id" . ($edit_mode ? '&edit_mode=true' : ''));
            exit();
        }

        // Process Step 5 responses
        $responses = [
            '4.1_premium_amount' => $premium_amount,
            '4.1_product_type' => $_SESSION['underwriting_product'][$quote_id]['product_type'],
            '4.1_broker_fee' => $broker_fee,
            '4.2_bank_name' => $_POST['bank_name'] ?? null,
            '4.3_branch_code' => $_POST['branch_code'] ?? null,
            '4.4_account_type' => $_POST['account_type'] ?? null,
            '4.5_account_holder' => $_POST['account_holder'] ?? null,
            '4.6_account_number' => $_POST['account_number'] ?? null,
            '4.7_debit_date' => $_POST['debit_date'] ?? null,
            '4.9_policy_start_date' => $_POST['policy_start_date'] ?? null,
            '4.10.1_payment_instruction' => $_POST['payment_instruction'] ?? null,
            '4.10.2_qsure_debit' => $_POST['qsure_debit'] ?? null,
            '4.10.3_cancellation_notice' => $_POST['cancellation_notice'] ?? null,
            '4.10.4_holiday_debit' => $_POST['holiday_debit'] ?? null,
            '4.10.5_computerized_debit' => $_POST['computerized_debit'] ?? null,
            '4.10.6_unpaid_premium' => $_POST['unpaid_premium'] ?? null,
            '4.11_mandate_understanding' => $_POST['mandate_understanding'] ?? null,
            '4.12_mandate_authorization' => $_POST['mandate_authorization'] ?? null,
            '4.13_cancellation_refund' => $_POST['cancellation_refund'] ?? null,
            '4.14_consecutive_unpaid' => $_POST['consecutive_unpaid'] ?? null,
            '4.15_premium_variation' => $_POST['premium_variation'] ?? null,
            '4.16_debit_method' => $_POST['debit_method'] ?? null,
            '4.16_pro_rata_amount' => $pro_rata_premium,
            '4.16_debit_confirmation' => $_POST['debit_confirmation'] ?? null
        ];

        // Validate responses
        if (empty($responses['4.2_bank_name']) || !preg_match('/^[A-Za-z0-9 ]+$/', $responses['4.2_bank_name'])) {
            throw new Exception("Invalid or missing bank name");
        }
        if (empty($responses['4.3_branch_code']) || !preg_match('/^\d{6}$/', $responses['4.3_branch_code'])) {
            throw new Exception("Invalid or missing branch code (must be 6 digits)");
        }
        if (!in_array($responses['4.4_account_type'], ['Savings', 'Cheque', 'Current'])) {
            throw new Exception("Invalid or missing account type");
        }
        if (empty($responses['4.5_account_holder']) || !preg_match('/^[A-Za-z ]+$/', $responses['4.5_account_holder'])) {
            throw new Exception("Invalid or missing account holder name");
        }
        if (empty($responses['4.6_account_number']) || !preg_match('/^\d{8,12}$/', $responses['4.6_account_number'])) {
            throw new Exception("Invalid or missing account number (must be 8-12 digits)");
        }
        if (empty($responses['4.7_debit_date']) || !in_array((int)$responses['4.7_debit_date'], range(1, 31))) {
            throw new Exception("Invalid or missing debit date (must be 1-31)");
        }
        if (empty($responses['4.9_policy_start_date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $responses['4.9_policy_start_date'])) {
            throw new Exception("Invalid or missing policy start date");
        }
        $start_date = DateTime::createFromFormat('Y-m-d', $responses['4.9_policy_start_date']);
        $today = new DateTime();
        $today->setTime(0, 0, 0); // Normalize to midnight
        $start_date->setTime(0, 0, 0); // Normalize to midnight
        if (!$start_date || $start_date < $today) {
            throw new Exception("Policy start date must be today or in the future");
        }
        if (!in_array($responses['4.16_debit_method'], ['Qsure Debit', 'Softi Comp Debit'])) {
            throw new Exception("Invalid or missing debit method");
        }
        if (empty($responses['4.16_debit_confirmation']) || !in_array($responses['4.16_debit_confirmation'], ['yes', 'no'])) {
            throw new Exception("Invalid or missing debit confirmation for " . $responses['4.16_debit_method']);
        }
        if ($responses['4.16_debit_confirmation'] === 'no') {
            throw new Exception("Client did not confirm " . $responses['4.16_debit_method'] . ". Underwriting cannot proceed.");
        }
        foreach (['4.10.1_payment_instruction', '4.10.2_qsure_debit', '4.10.3_cancellation_notice', '4.10.4_holiday_debit', '4.10.5_computerized_debit', '4.10.6_unpaid_premium', '4.11_mandate_understanding', '4.12_mandate_authorization', '4.13_cancellation_refund', '4.14_consecutive_unpaid', '4.15_premium_variation'] as $key) {
            if (!in_array($responses[$key], ['yes', 'no'])) {
                throw new Exception("Invalid or missing response for $key");
            }
            if ($responses[$key] === 'no') {
                throw new Exception("Client did not confirm $key. Underwriting cannot proceed.");
            }
        }

        // Update policies table with banking details and premium type
        $stmt = $conn->prepare("
            UPDATE policies SET
                account_holder = ?, bank_name = ?, account_number = ?,
                branch_code = ?, account_type = ?, debit_date = ?, 
                policy_start_date = ?, premium_type = ?, premium_amount = ?
            WHERE policy_id = ? AND quote_id = ?
        ");
        if (!$stmt) {
            throw new Exception("Failed to prepare UPDATE query: " . $conn->error);
        }
        $stmt->bind_param(
            "ssiisissdii",
            $responses['4.5_account_holder'],
            $responses['4.2_bank_name'],
            $responses['4.6_account_number'],
            $responses['4.3_branch_code'],
            $responses['4.4_account_type'],
            $responses['4.7_debit_date'],
            $responses['4.9_policy_start_date'],
            $_SESSION['underwriting_product'][$quote_id]['product_type'],
            $premium_amount,
            $policy_id,
            $quote_id
        );
        if (!$stmt->execute()) {
            error_log("Failed to update policies: " . $stmt->error);
            throw new Exception("Failed to update banking details: " . $stmt->error);
        }
        $stmt->close();
        error_log("Step 5: Successfully updated policies table");

        // Save responses to policy_underwriting_data
        $stmt = $conn->prepare("
            INSERT INTO policy_underwriting_data (policy_id, section, question_key, response)
            VALUES (?, 'bank_details_mandate', ?, ?)
            ON DUPLICATE KEY UPDATE response = ?
        ");
        if (!$stmt) {
            throw new Exception("Failed to prepare INSERT query: " . $conn->error);
        }
        foreach ($responses as $key => $value) {
            $value = $value ?? ''; // Convert null to empty string to avoid SQL errors
            $stmt->bind_param("isss", $policy_id, $key, $value, $value);
            if (!$stmt->execute()) {
                error_log("Failed to save response for $key: " . $stmt->error);
                throw new Exception("Failed to save response for $key: " . $stmt->error);
            }
        }
        $stmt->close();
        error_log("Step 5: Successfully saved responses to policy_underwriting_data");

        // If in edit_mode, redirect to dashboard after save; else advance to next step
        if ($edit_mode) {
            $conn->commit();
            error_log("Step 5 Edit Mode: Changes saved, redirecting to dashboard");
            header("Location: ../dashboard.php");
            exit();
        } else {
            // Advance to next step in normal mode
            $_SESSION['underwriting_step'][$quote_id] = 6;
            $conn->commit();
            error_log("Step 5: Navigating to next step (Step 6)");
            header("Location: step6_declarations.php?quote_id=$quote_id" . ($edit_mode ? '&edit_mode=true' : ''));
            exit();
        }

    } catch (Exception $e) {
        $conn->rollback();
        $errors[] = $e->getMessage();
        error_log("Step 5 Transaction failed: " . $e->getMessage());
        $_SESSION['errors'] = $errors;
        header("Location: step5_banking_details_mandate.php?quote_id=$quote_id" . ($edit_mode ? '&edit_mode=true' : ''));
        exit();
    }
}

// Start HTML
start_html($script_sections[5]['name']);
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
                ?>
                <a href="<?php echo htmlspecialchars($section['file'] . '?quote_id=' . $quote_id . ($edit_mode ? '&edit_mode=true' : '')); ?>" 
                   class="<?php echo $btn_class; ?>" 
                   <?php echo $disabled; ?>>
                    <?php echo htmlspecialchars($section['name']); ?> (Step <?php echo $step; ?>)
                </a>
            <?php } ?>
        </div>
    </div>
    <div class="row">
        <div class="col-md-8">
            <form method="post" action="step5_banking_details_mandate.php?quote_id=<?php echo htmlspecialchars($quote_id); ?><?php echo $edit_mode ? '&edit_mode=true' : ''; ?>" class="row g-3" id="underwritingForm">
                <div class="col-12">
                    <div class="card mb-3">
                        <div class="card-header section-heading"><?php echo htmlspecialchars($script_sections[5]['name']); ?></div>
                        <div class="card-body">
                            <?php if (!empty($_SESSION['errors'])): ?>
                                <div class="alert alert-danger">
                                    <?php foreach ($_SESSION['errors'] as $error): ?>
                                        <p><?php echo htmlspecialchars($error); ?></p>
                                    <?php endforeach; ?>
                                    <?php unset($_SESSION['errors']); ?>
                                </div>
                            <?php endif; ?>
                            <p><strong>4.1</strong> This authority and mandate for your monthly premium of R<?php echo number_format($premium_amount, 2); ?> (<?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $_SESSION['underwriting_product'][$quote_id]['product_type']))); ?>) will be debited from your bank account on a monthly basis by means of a debit order. This includes a Broker fee of R<?php echo number_format($broker_fee, 2); ?> which will cover administrative costs incurred by your broker on your policy. More specifically, the broker fee will cover the following services which will be performed by our broker:<br>
                                1. Facilitation of non-insurance value added products<br>
                                2. Arrange and assist with valuations with suitable professionals<br>
                                3. Follow up with motor repairers and insurers</p>
                            <p><strong>4.2</strong> With which bank do you bank?</p>
                            <div class="row mb-2">
                                <label for="bank_name" class="col-md-4 col-form-label">Bank Name:</label>
                                <div class="col-md-8">
                                    <select name="bank_name" id="bank_name" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="Absa Bank Ltd" <?php echo ($responses['4.2_bank_name'] ?? '') === 'Absa Bank Ltd' ? 'selected' : ''; ?>>Absa Bank Ltd</option>
                                        <option value="African Bank Ltd" <?php echo ($responses['4.2_bank_name'] ?? '') === 'African Bank Ltd' ? 'selected' : ''; ?>>African Bank Ltd</option>
                                        <option value="Bidvest Bank Ltd" <?php echo ($responses['4.2_bank_name'] ?? '') === 'Bidvest Bank Ltd' ? 'selected' : ''; ?>>Bidvest Bank Ltd</option>
                                        <option value="Capitec Bank Ltd" <?php echo ($responses['4.2_bank_name'] ?? '') === 'Capitec Bank Ltd' ? 'selected' : ''; ?>>Capitec Bank Ltd</option>
                                        <option value="Discovery Bank Ltd" <?php echo ($responses['4.2_bank_name'] ?? '') === 'Discovery Bank Ltd' ? 'selected' : ''; ?>>Discovery Bank Ltd</option>
                                        <option value="FNB" <?php echo ($responses['4.2_bank_name'] ?? '') === 'FNB' ? 'selected' : ''; ?>>FNB</option>
                                        <option value="Grindrod Bank Ltd" <?php echo ($responses['4.2_bank_name'] ?? '') === 'Grindrod Bank Ltd' ? 'selected' : ''; ?>>Grindrod Bank Ltd</option>
                                        <option value="Investec Bank Ltd" <?php echo ($responses['4.2_bank_name'] ?? '') === 'Investec Bank Ltd' ? 'selected' : ''; ?>>Investec Bank Ltd</option>
                                        <option value="Imperial Bank SA" <?php echo ($responses['4.2_bank_name'] ?? '') === 'Imperial Bank SA' ? 'selected' : ''; ?>>Imperial Bank SA</option>
                                        <option value="Mercantile Bank Ltd" <?php echo ($responses['4.2_bank_name'] ?? '') === 'Mercantile Bank Ltd' ? 'selected' : ''; ?>>Mercantile Bank Ltd</option>
                                        <option value="Nedbank Ltd" <?php echo ($responses['4.2_bank_name'] ?? '') === 'Nedbank Ltd' ? 'selected' : ''; ?>>Nedbank Ltd</option>
                                        <option value="Sasfin Bank Ltd" <?php echo ($responses['4.2_bank_name'] ?? '') === 'Sasfin Bank Ltd' ? 'selected' : ''; ?>>Sasfin Bank Ltd</option>
                                        <option value="Standard Bank of South Africa Ltd" <?php echo ($responses['4.2_bank_name'] ?? '') === 'Standard Bank of South Africa Ltd' ? 'selected' : ''; ?>>Standard Bank of South Africa Ltd</option>
                                        <option value="Ubank Ltd" <?php echo ($responses['4.2_bank_name'] ?? '') === 'Ubank Ltd' ? 'selected' : ''; ?>>Ubank Ltd</option>
                                        <option value="TymeBank Ltd" <?php echo ($responses['4.2_bank_name'] ?? '') === 'TymeBank Ltd' ? 'selected' : ''; ?>>TymeBank Ltd</option>
                                        <option value="Bank Zero" <?php echo ($responses['4.2_bank_name'] ?? '') === 'Bank Zero' ? 'selected' : ''; ?>>Bank Zero</option>
                                        <option value="Finbond Mutual Bank" <?php echo ($responses['4.2_bank_name'] ?? '') === 'Finbond Mutual Bank' ? 'selected' : ''; ?>>Finbond Mutual Bank</option>
                                        <option value="GBS Mutual Bank" <?php echo ($responses['4.2_bank_name'] ?? '') === 'GBS Mutual Bank' ? 'selected' : ''; ?>>GBS Mutual Bank</option>
                                        <option value="VBS Mutual Bank" <?php echo ($responses['4.2_bank_name'] ?? '') === 'VBS Mutual Bank' ? 'selected' : ''; ?>>VBS Mutual Bank</option>
                                        <option value="Access Bank South Africa" <?php echo ($responses['4.2_bank_name'] ?? '') === 'Access Bank South Africa' ? 'selected' : ''; ?>>Access Bank South Africa</option>
                                        <option value="Albaraka Bank Ltd" <?php echo ($responses['4.2_bank_name'] ?? '') === 'Albaraka Bank Ltd' ? 'selected' : ''; ?>>Albaraka Bank Ltd</option>
                                        <option value="Habib Bank AG Zurich" <?php echo ($responses['4.2_bank_name'] ?? '') === 'Habib Bank AG Zurich' ? 'selected' : ''; ?>>Habib Bank AG Zurich</option>
                                        <option value="Habib Overseas Bank Ltd" <?php echo ($responses['4.2_bank_name'] ?? '') === 'Habib Overseas Bank Ltd' ? 'selected' : ''; ?>>Habib Overseas Bank Ltd</option>
                                        <option value="South African Bank of Athens" <?php echo ($responses['4.2_bank_name'] ?? '') === 'South African Bank of Athens' ? 'selected' : ''; ?>>South African Bank of Athens</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>4.3</strong> Confirm Branch Code with the client.</p>
                            <div class="row mb-2">
                                <label for="branch_code" class="col-md-4 col-form-label">Branch Code:</label>
                                <div class="col-md-8">
                                    <input type="text" name="branch_code" id="branch_code" value="<?php echo htmlspecialchars($responses['4.3_branch_code'] ?? ''); ?>" class="form-control" pattern="\d{6}" required>
                                    <small class="form-text text-muted">Must be 6 digits.</small>
                                </div>
                            </div>
                            <p><strong>4.4</strong> What type of account is it?</p>
                            <div class="row mb-2">
                                <label for="account_type" class="col-md-4 col-form-label">Account Type:</label>
                                <div class="col-md-8">
                                    <select name="account_type" id="account_type" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="Savings" <?php echo ($responses['4.4_account_type'] ?? '') === 'Savings' ? 'selected' : ''; ?>>Savings</option>
                                        <option value="Cheque" <?php echo ($responses['4.4_account_type'] ?? '') === 'Cheque' ? 'selected' : ''; ?>>Cheque</option>
                                        <option value="Current" <?php echo ($responses['4.4_account_type'] ?? '') === 'Current' ? 'selected' : ''; ?>>Current</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>4.5</strong> Who is the account holder?</p>
                            <div class="row mb-2">
                                <label for="account_holder" class="col-md-4 col-form-label">Account Holder Name:</label>
                                <div class="col-md-8">
                                    <input type="text" name="account_holder" id="account_holder" value="<?php echo htmlspecialchars($responses['4.5_account_holder'] ?? ''); ?>" class="form-control" required pattern="[A-Za-z ]+">
                                </div>
                            </div>
                            <p><strong>4.6</strong> What is your account number?</p>
                            <div class="row mb-2">
                                <label for="account_number" class="col-md-4 col-form-label">Account Number:</label>
                                <div class="col-md-8">
                                    <input type="text" name="account_number" id="account_number" value="<?php echo htmlspecialchars($responses['4.6_account_number'] ?? ''); ?>" class="form-control" pattern="\d{8,12}" required>
                                    <small class="form-text text-muted">Must be 8-12 digits.</small>
                                </div>
                            </div>
                            <p><strong>4.7</strong> On which date of the month do you get paid? Please note that Profusion will debit your account on this date.</p>
                            <div class="row mb-2">
                                <label for="debit_date" class="col-md-4 col-form-label">Preferred Debit Date:</label>
                                <div class="col-md-8">
                                    <select name="debit_date" id="debit_date" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <?php
                                        for ($i = 1; $i <= 31; $i++) {
                                            $selected = ($responses['4.7_debit_date'] ?? '') == $i ? 'selected' : '';
                                            echo "<option value=\"$i\" $selected>$i</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                            <p><strong>4.9</strong> Please select the policy start date. Please remember if we do not receive premium there will be no cover, please check your bank to ensure that the premiums are being deducted.</p>
                            <div class="row mb-2">
                                <label for="policy_start_date" class="col-md-4 col-form-label">Policy Start Date:</label>
                                <div class="col-md-8">
                                    <input type="date" name="policy_start_date" id="policy_start_date" value="<?php echo htmlspecialchars($responses['4.9_policy_start_date'] ?? date('Y-m-d')); ?>" class="form-control" min="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                            <p><strong>4.10</strong> Please confirm the following declaration information by means of a yes or no:</p>
                            <p><strong>4.10.1</strong> Payment will not exceed agreed instruction amount as agreed to in terms of this agreement, unless otherwise communicated to you.</p>
                            <div class="row mb-2">
                                <label class="col-md-4 col-form-label">Payment Instruction:</label>
                                <div class="col-md-8">
                                    <select name="payment_instruction" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($responses['4.10.1_payment_instruction'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($responses['4.10.1_payment_instruction'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>4.10.2</strong> Please note that our debit order runs are managed by QSURE, should you notice a Profusion reference number on your statement, please kindly note that it relates to your insurance.</p>
                            <div class="row mb-2">
                                <label class="col-md-4 col-form-label">QSURE Debit:</label>
                                <div class="col-md-8">
                                    <select name="qsure_debit" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($responses['4.10.2_qsure_debit'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($responses['4.10.2_qsure_debit'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>4.10.3</strong> Should you wish to cancel with us, please kindly do so in writing - keeping in mind that a 30 day notice period is required.</p>
                            <div class="row mb-2">
                                <label class="col-md-4 col-form-label">Cancellation Notice:</label>
                                <div class="col-md-8">
                                    <select name="cancellation_notice" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($responses['4.10.3_cancellation_notice'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($responses['4.10.3_cancellation_notice'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>4.10.4</strong> If the payment falls on a Sunday or Public holiday the payment will be deducted on the next business day.</p>
                            <div class="row mb-2">
                                <label class="col-md-4 col-form-label">Holiday Debit:</label>
                                <div class="col-md-8">
                                    <select name="holiday_debit" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($responses['4.10.4_holiday_debit'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($responses['4.10.4_holiday_debit'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>4.10.5</strong> All debit orders hereby authorised will be processed through a computerized system provided by the banks, you also understand that each transaction will be printed on your bank statement. Your policy number will reflect on your statement which will enable you to identify these transactions.</p>
                            <div class="row mb-2">
                                <label class="col-md-4 col-form-label">Computerized Debit:</label>
                                <div class="col-md-8">
                                    <select name="computerized_debit" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($responses['4.10.5_computerized_debit'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($responses['4.10.5_computerized_debit'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>4.10.6</strong> If a premium is unpaid, the onus rests on the insured to make arrangements for a re-debit to take place within 15 days of the unpaid debit order date. Please note that if no arrangement is put in place and the premium remains unpaid, the cover for the given financial period will lapse and the inception date of your policy will be changed to the start of the following financial period. Do you understand?</p>
                            <div class="row mb-2">
                                <label class="col-md-4 col-form-label">Unpaid Premium Understanding:</label>
                                <div class="col-md-8">
                                    <select name="unpaid_premium" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($responses['4.10.6_unpaid_premium'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($responses['4.10.6_unpaid_premium'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>4.11</strong> Do you agree to and understand all that I have confirmed with you?</p>
                            <div class="row mb-2">
                                <label class="col-md-4 col-form-label">Mandate Understanding:</label>
                                <div class="col-md-8">
                                    <select name="mandate_understanding" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($responses['4.11_mandate_understanding'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($responses['4.11_mandate_understanding'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>4.12</strong> Mandate: Do you confirm that all payment instructions issued will be treated by your bank as if the instruction was given by you personally?</p>
                            <div class="row mb-2">
                                <label class="col-md-4 col-form-label">Mandate Authorization:</label>
                                <div class="col-md-8">
                                    <select name="mandate_authorization" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($responses['4.12_mandate_authorization'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($responses['4.12_mandate_authorization'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>4.13</strong> Cancellation: You will not be entitled to any refund of amounts which were debited while this authority was in force, if such amounts were legally owing to you.</p>
                            <div class="row mb-2">
                                <label class="col-md-4 col-form-label">Cancellation Refund Understanding:</label>
                                <div class="col-md-8">
                                    <select name="cancellation_refund" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($responses['4.13_cancellation_refund'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($responses['4.13_cancellation_refund'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>4.14</strong> Please note that should the premium be unpaid for a total of three (3) consecutive months, the policy will be cancelled back to the last date that cover was in place and there will be no option to reinstate the cover.</p>
                            <div class="row mb-2">
                                <label class="col-md-4 col-form-label">Consecutive Unpaid Premiums:</label>
                                <div class="col-md-8">
                                    <select name="consecutive_unpaid" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($responses['4.14_consecutive_unpaid'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($responses['4.14_consecutive_unpaid'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>4.15</strong> The monthly premium may vary according to changes that you make to the policy and is subject to review.</p>
                            <div class="row mb-2">
                                <label class="col-md-4 col-form-label">Premium Variation Understanding:</label>
                                <div class="col-md-8">
                                    <select name="premium_variation" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($responses['4.15_premium_variation'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($responses['4.15_premium_variation'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>4.16</strong> Please select the debit method for the pro-rata premium payment:</p>
                            <div class="row mb-2">
                                <label class="col-md-4 col-form-label">Debit Method:</label>
                                <div class="col-md-8">
                                    <div class="form-check">
                                        <input class="form-check-input debit-method" type="radio" name="debit_method" id="qsure_debit" value="Qsure Debit" <?php echo ($responses['4.16_debit_method'] ?? '') === 'Qsure Debit' ? 'checked' : ''; ?> required>
                                        <label class="form-check-label" for="qsure_debit">Qsure Debit</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input debit-method" type="radio" name="debit_method" id="softi_comp_debit" value="Softi Comp Debit" <?php echo ($responses['4.16_debit_method'] ?? '') === 'Softi Comp Debit' ? 'checked' : ''; ?> required>
                                        <label class="form-check-label" for="softi_comp_debit">Softi Comp Debit</label>
                                    </div>
                                </div>
                            </div>
                            <div id="debit_confirmation_section" style="display: <?php echo isset($responses['4.16_debit_method']) ? 'block' : 'none'; ?>;">
                                <p id="debit_confirmation_text">
                                    <?php if (($responses['4.16_debit_method'] ?? '') === 'Qsure Debit'): ?>
                                        <strong>4.16 Qsure Debit</strong> Please note that we will be debiting your account for the pro-rata amount for the rest of this month for <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $_SESSION['underwriting_product'][$quote_id]['product_type']))); ?>. The amount we will be debiting is R<?php echo number_format($pro_rata_premium, 2); ?> and this will be going off your account in the next 48 hours.
                                    <?php elseif (($responses['4.16_debit_method'] ?? '') === 'Softi Comp Debit'): ?>
                                        <strong>4.16 Softi Comp Debit</strong> Please note that we are sending a message to the cell number provided earlier. This message will contain a link that will take you to our secure payment portal where you will be required to capture your payment details. The amount of this payment will be for your pro-rata premium of R<?php echo number_format($pro_rata_premium, 2); ?> for <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $_SESSION['underwriting_product'][$quote_id]['product_type']))); ?>. Please complete this payment now as we need to process the payment before I am able to send you a confirmation of cover for your insurance.
                                    <?php endif; ?>
                                </p>
                                <button type="button" id="generate_mandate_link" class="btn btn-purple mt-2">Generate Mandate</button>
                                <div id="mandate_link_display" class="mt-2"></div>
                                <div class="row mb-2">
                                    <label class="col-md-4 col-form-label">Confirm Debit Method:</label>
                                    <div class="col-md-8">
                                        <select name="debit_confirmation" id="debit_confirmation" class="form-select" required>
                                            <option value="">Choose...</option>
                                            <option value="yes" <?php echo ($responses['4.16_debit_confirmation'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                            <option value="no" <?php echo ($responses['4.16_debit_confirmation'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                        </select>
                                    </div>
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
        const debitMethodRadios = document.querySelectorAll('.debit-method');
        const debitConfirmationSection = document.getElementById('debit_confirmation_section');
        const debitConfirmationText = document.getElementById('debit_confirmation_text');
        const debitConfirmationSelect = document.getElementById('debit_confirmation');

        function updateDebitConfirmation() {
            const selectedMethod = document.querySelector('.debit-method:checked')?.value || '';
            if (selectedMethod) {
                debitConfirmationSection.style.display = 'block';
                debitConfirmationSelect.required = true;
                if (selectedMethod === 'Qsure Debit') {
                    debitConfirmationText.innerHTML = `<strong>4.16 Qsure Debit</strong> Please note that we will be debiting your account for the pro-rata amount for the rest of this month for <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $_SESSION['underwriting_product'][$quote_id]['product_type']))); ?>. The amount we will be debiting is R<?php echo number_format($pro_rata_premium, 2); ?> and this will be going off your account in the next 48 hours.`;
                } else if (selectedMethod === 'Softi Comp Debit') {
                    debitConfirmationText.innerHTML = `<strong>4.16 Softi Comp Debit</strong> Please note that we are sending a message to the cell number provided earlier. This message will contain a link that will take you to our secure payment portal where you will be required to capture your payment details. The amount of this payment will be for your pro-rata premium of R<?php echo number_format($pro_rata_premium, 2); ?> for <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $_SESSION['underwriting_product'][$quote_id]['product_type']))); ?>. Please complete this payment now as we need to process the payment before I am able to send you a confirmation of cover for your insurance.`;
                }
            } else {
                debitConfirmationSection.style.display = 'none';
                debitConfirmationSelect.required = false;
            }
        }

        debitMethodRadios.forEach(radio => {
            radio.addEventListener('change', updateDebitConfirmation);
        });

        // Initial call to set visibility
        updateDebitConfirmation();

        // Validate form on submission
        document.getElementById('underwritingForm').addEventListener('submit', function(event) {
            const requiredInputs = document.querySelectorAll('input[required], select[required]');
            const selectedMethod = document.querySelector('.debit-method:checked')?.value || '';
            let valid = true;
            requiredInputs.forEach(input => {
                if (!input.value) {
                    valid = false;
                    input.classList.add('is-invalid');
                } else {
                    input.classList.remove('is-invalid');
                }
            });
            if (!selectedMethod) {
                valid = false;
                event.preventDefault();
                alert('Please select a debit method.');
            }
            if (!valid) {
                event.preventDefault();
                alert('Please fill out all required fields.');
            }
        });
    });
    document.getElementById('generate_mandate_link').addEventListener('click', function() {
    // AJAX to generate link
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'generate_mandate_link.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        if (xhr.status === 200) {
            document.getElementById('mandate_link_display').innerHTML = 'Link generated: ' + xhr.responseText;
        }
    };
    xhr.send('policy_id=<?php echo $policy_id; ?>');
    });
    var method = document.querySelector('.debit-method:checked')?.value === 'Softi Comp Debit' ? 'whatsapp' : 'sms'; // Example: Use WhatsApp for Softi
    xhr.send('policy_id=<?php echo $policy_id; ?>&method=' + method);
    
</script>
</body>
</html>
<?php
$conn->close();
?>