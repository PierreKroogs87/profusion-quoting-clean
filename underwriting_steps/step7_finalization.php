<?php
require 'underwriting_common.php'; // Same directory: /home/profusi3/public_html/quoting/underwriting_steps/

// Import PHPMailer classes at the top
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Check if we're on the correct step (handled by underwriting_common.php)
// $current_step is already validated in underwriting_common.php

// Initialize variables
$edit_mode = isset($_GET['edit_mode']) && $_GET['edit_mode'] === 'true';
$errors = [];
$summary_data = [
    'policy' => [],
    'policy_holder' => [],
    'vehicles' => [],
    'excess_disclosures' => [],
    'banking_details' => [],
    'declarations' => []
];

// Fetch policy_id and policy data
$stmt = $conn->prepare("
    SELECT policy_id, status, premium_type, premium_amount, policy_start_date, 
           account_holder, bank_name, account_number, branch_code, account_type, debit_date
    FROM policies WHERE quote_id = ?
");
$stmt->bind_param("i", $quote_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $policy_data = $result->fetch_assoc();
    $policy_id = $policy_data['policy_id'];
    $summary_data['policy']['status'] = $policy_data['status'];
    $summary_data['policy']['premium_type'] = $policy_data['premium_type'];
    $summary_data['policy']['premium_amount'] = $policy_data['premium_amount'];
    $summary_data['banking_details']['4.1_account_holder'] = $policy_data['account_holder'] ?? 'Not provided';
    $summary_data['banking_details']['4.1_bank_name'] = $policy_data['bank_name'] ?? 'Not provided';
    $summary_data['banking_details']['4.1_account_number'] = $policy_data['account_number'] ?? 'Not provided';
    $summary_data['banking_details']['4.1_branch_code'] = $policy_data['branch_code'] ?? 'Not provided';
    $summary_data['banking_details']['4.1_account_type'] = $policy_data['account_type'] ?? 'Not provided';
    $summary_data['banking_details']['4.2_debit_date'] = $policy_data['debit_date'] ?? 'Not provided';
    $summary_data['banking_details']['4.9_policy_start_date'] = $policy_data['policy_start_date'] ?? 'Not provided';
} else {
    error_log("No policy found for quote_id=$quote_id");
    $_SESSION['errors'] = ["No policy found for this quote ID."];
    header("Location: ../dashboard.php");
    exit();
}
$stmt->close();

// Fetch broker fee for display
$stmt = $conn->prepare("SELECT broker_fee FROM brokerages WHERE brokerage_id = ?");
$stmt->bind_param("i", $quote_data['brokerage_id']);
$stmt->execute();
$result = $stmt->get_result();
$broker_fee = $result->num_rows > 0 ? $result->fetch_assoc()['broker_fee'] : 0.00;
$stmt->close();
$summary_data['banking_details']['4.1_broker_fee'] = $broker_fee;

// Fetch policy holder information from quotes
$stmt = $conn->prepare("
    SELECT initials, surname, title, marital_status, client_id
    FROM quotes WHERE quote_id = ?
");
$stmt->bind_param("i", $quote_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $summary_data['policy_holder'] = $result->fetch_assoc();
}
$stmt->close();

// Fetch additional policy holder responses from policy_underwriting_data
$stmt = $conn->prepare("
    SELECT question_key, response
    FROM policy_underwriting_data
    WHERE policy_id = ? AND section = 'policy_holder_information'
");
$stmt->bind_param("i", $policy_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $summary_data['policy_holder'][$row['question_key']] = $row['response'] ?? 'Not provided';
}
$stmt->close();

// Fetch vehicle and driver data from quote_vehicles and quote_drivers
$summary_data['vehicles'] = $vehicles_data; // From underwriting_common.php
$vehicle_responses = [];
foreach ($vehicles_data as $index => $data) {
    $vehicle_responses[$index] = [];
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
        $vehicle_responses[$index][$row['question_key']] = $row['response'];
    }
    $stmt->close();

    // Fetch additional drivers
    $summary_data['vehicles'][$index]['additional_drivers'] = [];
    $stmt = $conn->prepare("
        SELECT * FROM quote_additional_drivers 
        WHERE vehicle_id = ? AND quote_id = ? AND deleted_at IS NULL
    ");
    $stmt->bind_param("ii", $data['vehicle']['vehicle_id'], $quote_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($add_driver = $result->fetch_assoc()) {
        $summary_data['vehicles'][$index]['additional_drivers'][] = $add_driver;
    }
    $stmt->close();
}

// Fetch excess disclosure responses
$stmt = $conn->prepare("
    SELECT question_key, response
    FROM policy_underwriting_data
    WHERE policy_id = ? AND section = 'motor_section'
    AND question_key IN (
        '3.18_understands_authorised_driver',
        '3.20_insurance_refused',
        '3.21_insolvency_status',
        '3.22_criminal_convictions',
        '3.23_disabilities',
        '3.33_excess_understanding'
    )
");
$stmt->bind_param("i", $policy_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $summary_data['excess_disclosures'][$row['question_key']] = $row['response'] ?? 'Not provided';
}
$stmt->close();

// Fetch additional banking details from policy_underwriting_data
$stmt = $conn->prepare("
    SELECT question_key, response
    FROM policy_underwriting_data
    WHERE policy_id = ? AND section = 'bank_details_mandate'
");
$stmt->bind_param("i", $policy_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $summary_data['banking_details'][$row['question_key']] = $row['response'] ?? 'Not provided';
}
$stmt->close();

// Fetch declarations from policy_underwriting_data
$stmt = $conn->prepare("
    SELECT question_key, response
    FROM policy_underwriting_data
    WHERE policy_id = ? AND section = 'declarations'
    AND question_key IN (
        '5.1_policy_details_confirmation',
        '5.2_terms_acceptance',
        '5.3_final_acknowledgement'
    )
");
$stmt->bind_param("i", $policy_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $summary_data['declarations'][$row['question_key']] = $row['response'] ?? 'Not provided';
}
$stmt->close();

// Debug: Log fetched data
error_log("Policy ID: $policy_id, Quote ID: $quote_id");
error_log("Policy Holder Data: " . print_r($summary_data['policy_holder'], true));
error_log("Vehicles Data: " . print_r($summary_data['vehicles'], true));
error_log("Excess Disclosures: " . print_r($summary_data['excess_disclosures'], true));
error_log("Banking Details: " . print_r($summary_data['banking_details'], true));
error_log("Declarations: " . print_r($summary_data['declarations'], true));
error_log("Vehicle Responses: " . print_r($vehicle_responses, true));

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();
    try {
        // Debug: Log POST data
        error_log("Step 7 POST Data: " . print_r($_POST, true));

        // Determine navigation direction
        $direction = $_POST['direction'] ?? 'finalize';

        if ($direction === 'previous') {
            if (!$edit_mode) {
                $_SESSION['underwriting_step'][$quote_id] = 6;
            }
            $conn->commit();
            error_log("Step 7: Navigating to previous step (Step 6)");
            header("Location: step6_declarations.php?quote_id=$quote_id" . ($edit_mode ? '&edit_mode=true' : ''));
            exit();
        }

        // Validate final confirmation
        if (!isset($_POST['confirm_details']) || $_POST['confirm_details'] !== 'yes') {
            throw new Exception("You must confirm that all details are correct to finalize the policy.");
        }

        // Update policy status to 'active'
        $stmt = $conn->prepare("UPDATE policies SET status = 'active', updated_at = NOW() WHERE policy_id = ? AND quote_id = ?");
        $stmt->bind_param("ii", $policy_id, $quote_id);
        if (!$stmt->execute()) {
            throw new Exception("Failed to update policy status: " . $stmt->error);
        }
        $stmt->close();

        // Update quote status to 'converted'
        $stmt = $conn->prepare("UPDATE quotes SET status = 'converted' WHERE quote_id = ?");
        $stmt->bind_param("i", $quote_id);
        if (!$stmt->execute()) {
            throw new Exception("Failed to update quote status: " . $stmt->error);
        }
        $stmt->close();

        // Send email notification
        require '../vendor/PHPMailer/PHPMailer.php';
        require '../vendor/PHPMailer/SMTP.php';
        require '../vendor/PHPMailer/Exception.php';
        require '../config.php';

        // Build comprehensive email content
        $email_body = "<h2>New Policy Activated</h2>";
        $email_body .= "<p><strong>Policy ID:</strong> " . htmlspecialchars($policy_id) . "</p>";
        $email_body .= "<p><strong>Quote ID:</strong> " . htmlspecialchars($quote_id) . "</p>";

        // Policy Holder Information
        $email_body .= "<h3>Policy Holder Information</h3>";
        $email_body .= "<p><strong>Name:</strong> " . htmlspecialchars(($summary_data['policy_holder']['title'] ?? '') . ' ' . ($summary_data['policy_holder']['initials'] ?? '') . ' ' . ($summary_data['policy_holder']['surname'] ?? '')) . "</p>";
        $email_body .= "<p><strong>Marital Status:</strong> " . htmlspecialchars($summary_data['policy_holder']['marital_status'] ?? 'Not provided') . "</p>";
        $email_body .= "<p><strong>Client ID:</strong> " . htmlspecialchars($summary_data['policy_holder']['client_id'] ?? 'Not provided') . "</p>";
        $email_body .= "<p><strong>Cell Number:</strong> " . htmlspecialchars($summary_data['policy_holder']['2.5_cell_number'] ?? 'Not provided') . "</p>";
        $email_body .= "<p><strong>Email:</strong> " . htmlspecialchars($summary_data['policy_holder']['2.8_email'] ?? 'Not provided') . "</p>";
        $email_body .= "<p><strong>Physical Address:</strong> " . htmlspecialchars($summary_data['policy_holder']['2.10_physical_address'] ?? 'Not provided') . ", " . 
                       htmlspecialchars($summary_data['policy_holder']['2.10_physical_suburb'] ?? $summary_data['policy_holder']['suburb_client'] ?? 'Not provided') . ", " . 
                       htmlspecialchars($summary_data['policy_holder']['2.10_physical_postal_code'] ?? $summary_data['policy_holder']['postal_code_client'] ?? 'Not provided') . "</p>";
        $email_body .= "<p><strong>Postal Address:</strong> " . htmlspecialchars($summary_data['policy_holder']['2.12_postal_address'] ?? 'Not provided') . ", " . 
                       htmlspecialchars($summary_data['policy_holder']['2.12_postal_suburb'] ?? 'Not provided') . ", " . 
                       htmlspecialchars($summary_data['policy_holder']['2.12_postal_postal_code'] ?? 'Not provided') . "</p>";
        $email_body .= "<p><strong>Dual Insurance:</strong> " . htmlspecialchars($summary_data['policy_holder']['2.13_dual_insurance'] ?? 'Not provided');
        if (($summary_data['policy_holder']['2.13_dual_insurance'] ?? '') === 'yes') {
            $email_body .= " (Cancellation Date: " . htmlspecialchars($summary_data['policy_holder']['2.13_dual_insurance_cancellation_date'] ?? 'Not provided') . ")";
        }
        $email_body .= "</p>";

        // Vehicle and Driver Details
        $email_body .= "<h3>Vehicle and Driver Details</h3>";
        foreach ($summary_data['vehicles'] as $index => $data) {
            $vehicle = $data['vehicle'];
            $driver = $data['driver'];
            $vehicle_description = htmlspecialchars(trim("{$vehicle['vehicle_year']} {$vehicle['vehicle_make']} {$vehicle['vehicle_model']}"));
            $email_body .= "<h4>Vehicle " . ($index + 1) . ": " . $vehicle_description . "</h4>";
            $email_body .= "<p><strong>Sum Insured:</strong> R" . number_format($vehicle_responses[$index]['3.3_sum_insured_' . $index] ?? $vehicle['vehicle_value'], 2) . "</p>";
            $email_body .= "<p><strong>Engine Number:</strong> " . htmlspecialchars($vehicle_responses[$index]['3.4_engine_number_' . $index] ?? 'Not provided') . "</p>";
            $email_body .= "<p><strong>Chassis Number:</strong> " . htmlspecialchars($vehicle_responses[$index]['3.4_chassis_number_' . $index] ?? 'Not provided') . "</p>";
            $email_body .= "<p><strong>Finance Institution:</strong> " . htmlspecialchars($vehicle_responses[$index]['3.5_finance_house_' . $index] ?? 'Not financed') . "</p>";
            $email_body .= "<p><strong>Registered in Client’s Name:</strong> " . htmlspecialchars($vehicle_responses[$index]['3.6_registered_in_client_name_' . $index] ?? 'Not provided');
            if (($vehicle_responses[$index]['3.6_registered_in_client_name_' . $index] ?? '') === 'no') {
                $email_body .= " (Owner: " . htmlspecialchars($vehicle_responses[$index]['3.6_registered_owner_name_' . $index] ?? 'Not provided') . ")";
            }
            $email_body .= "</p>";
            $email_body .= "<p><strong>Coverage Type:</strong> " . htmlspecialchars($vehicle_responses[$index]['3.7_coverage_type_' . $index] ?? 'Not provided') . "</p>";
            $email_body .= "<p><strong>Vehicle Condition:</strong> " . htmlspecialchars($vehicle_responses[$index]['3.8_vehicle_condition_' . $index] ?? 'Not provided') . "</p>";
            $email_body .= "<p><strong>Vehicle Use:</strong> " . htmlspecialchars($vehicle_responses[$index]['3.9_vehicle_use_' . $index] ?? 'Not provided') . "</p>";
            $email_body .= "<p><strong>Regular Driver:</strong> " . htmlspecialchars($vehicle_responses[$index]['3.11_regular_driver_' . $index] ?? ($driver['driver_initials'] . ' ' . $driver['driver_surname'])) . "</p>";
            $email_body .= "<p><strong>Driver ID Number:</strong> " . htmlspecialchars($vehicle_responses[$index]['3.11_driver_id_number_' . $index] ?? $driver['driver_id_number'] ?? 'Not provided') . "</p>";
            $email_body .= "<p><strong>Driver DOB:</strong> " . htmlspecialchars($vehicle_responses[$index]['3.11_driver_dob_' . $index] ?? $driver['dob'] ?? 'Not provided') . "</p>";
            $email_body .= "<p><strong>Licence Type:</strong> " . htmlspecialchars($vehicle_responses[$index]['3.12_licence_type_' . $index] ?? $driver['licence_type'] ?? 'Not provided') . "</p>";
            $email_body .= "<p><strong>Licence Issue Year:</strong> " . htmlspecialchars($vehicle_responses[$index]['3.13_year_of_issue_' . $index] ?? $driver['year_of_issue'] ?? 'Not provided') . "</p>";
            $email_body .= "<p><strong>Comprehensive Insurance:</strong> " . htmlspecialchars($vehicle_responses[$index]['3.16_has_comprehensive_insurance_' . $index] ?? 'Not provided');
            if (($vehicle_responses[$index]['3.16_has_comprehensive_insurance_' . $index] ?? '') === 'yes') {
                $email_body .= " (Duration: " . htmlspecialchars($vehicle_responses[$index]['3.16_insurance_duration_' . $index] ?? 'Not provided') . " years, ";
                $email_body .= "Gaps: " . htmlspecialchars($vehicle_responses[$index]['3.16_insurance_gaps_' . $index] ?? 'Not provided') . ", ";
                $email_body .= "Claims: " . htmlspecialchars($vehicle_responses[$index]['3.16_recent_claims_' . $index] ?? 'Not provided') . ")";
            }
            $email_body .= "</p>";
            if (($vehicle_responses[$index]['3.20_add_additional_drivers_' . $index] ?? 'no') === 'yes' && !empty($data['additional_drivers'])) {
                $email_body .= "<p><strong>Additional Drivers:</strong></p><ul>";
                foreach ($data['additional_drivers'] as $driver_index => $add_driver) {
                    $email_body .= "<li>";
                    $email_body .= "Name: " . htmlspecialchars($add_driver['name'] ?? 'Not provided') . ", ";
                    $email_body .= "ID: " . htmlspecialchars($add_driver['id_number'] ?? 'Not provided') . ", ";
                    $email_body .= "Licence: " . htmlspecialchars($add_driver['licence_type'] ?? 'Not provided');
                    $email_body .= "</li>";
                }
                $email_body .= "</ul>";
            }
            $email_body .= "<p><strong>Existing Damage:</strong> " . htmlspecialchars($vehicle_responses[$index]['3.27_existing_damage_' . $index] ?? 'Not provided');
            if (($vehicle_responses[$index]['3.27_existing_damage_' . $index] ?? '') === 'yes') {
                $email_body .= " (Description: " . htmlspecialchars($vehicle_responses[$index]['3.27_damage_description_' . $index] ?? 'Not provided') . ")";
            }
            $email_body .= "</p>";
            $email_body .= "<p><strong>Risk Address:</strong> " . htmlspecialchars($vehicle_responses[$index]['3.28_street_' . $index] ?? $vehicle['street'] ?? 'Not provided') . ", " . 
                           htmlspecialchars($vehicle_responses[$index]['3.28_suburb_vehicle_' . $index] ?? $vehicle['suburb_vehicle'] ?? 'Not provided') . ", " . 
                           htmlspecialchars($vehicle_responses[$index]['3.28_postal_code_' . $index] ?? $vehicle['postal_code'] ?? 'Not provided') . "</p>";
            $email_body .= "<p><strong>Security Device:</strong> " . htmlspecialchars($vehicle_responses[$index]['3.29_security_device_' . $index] ?? 'Not provided') . "</p>";
            $email_body .= "<p><strong>Car Hire Option:</strong> " . htmlspecialchars($vehicle_responses[$index]['3.30_car_hire_' . $index] ?? $vehicle['car_hire'] ?? 'Not provided') . "</p>";
        }

        // Excess Disclosures
        $email_body .= "<h3>Excess Disclosures</h3>";
        $email_body .= "<p><strong>Authorised Driver Policy Understanding:</strong> " . htmlspecialchars($summary_data['excess_disclosures']['3.18_understands_authorised_driver'] ?? 'Not provided') . "</p>";
        $email_body .= "<p><strong>Insurance Refused/Cancelled:</strong> " . htmlspecialchars($summary_data['excess_disclosures']['3.20_insurance_refused'] ?? 'Not provided') . "</p>";
        $email_body .= "<p><strong>Insolvency/Debt Administration:</strong> " . htmlspecialchars($summary_data['excess_disclosures']['3.21_insolvency_status'] ?? 'Not provided') . "</p>";
        $email_body .= "<p><strong>Criminal Convictions:</strong> " . htmlspecialchars($summary_data['excess_disclosures']['3.22_criminal_convictions'] ?? 'Not provided') . "</p>";
        $email_body .= "<p><strong>Disabilities/Illnesses:</strong> " . htmlspecialchars($summary_data['excess_disclosures']['3.23_disabilities'] ?? 'Not provided') . "</p>";
        $email_body .= "<p><strong>Excess Structure Understanding:</strong> " . htmlspecialchars($summary_data['excess_disclosures']['3.33_excess_understanding'] ?? 'Not provided') . "</p>";

        // Banking Details
        $email_body .= "<h3>Banking Details</h3>";
        $email_body .= "<p><strong>Bank Name:</strong> " . htmlspecialchars($summary_data['banking_details']['4.1_bank_name'] ?? 'Not provided') . "</p>";
        $email_body .= "<p><strong>Account Holder:</strong> " . htmlspecialchars($summary_data['banking_details']['4.1_account_holder'] ?? 'Not provided') . "</p>";
        $email_body .= "<p><strong>Account Number:</strong> " . htmlspecialchars($summary_data['banking_details']['4.1_account_number'] ?? 'Not provided') . "</p>";
        $email_body .= "<p><strong>Account Type:</strong> " . htmlspecialchars($summary_data['banking_details']['4.1_account_type'] ?? 'Not provided') . "</p>";
        $email_body .= "<p><strong>Branch Code:</strong> " . htmlspecialchars($summary_data['banking_details']['4.1_branch_code'] ?? 'Not provided') . "</p>";
        $email_body .= "<p><strong>Debit Order Day:</strong> " . htmlspecialchars($summary_data['banking_details']['4.2_debit_date'] ?? 'Not provided') . "</p>";
        $email_body .= "<p><strong>Policy Start Date:</strong> " . htmlspecialchars($summary_data['banking_details']['4.9_policy_start_date'] ?? 'Not provided') . "</p>";
        $email_body .= "<p><strong>Premium Amount:</strong> R" . number_format($summary_data['policy']['premium_amount'] ?? 0, 2) . "</p>";
        $email_body .= "<p><strong>Broker Fee:</strong> R" . number_format($summary_data['banking_details']['4.1_broker_fee'] ?? 0, 2) . "</p>";
        $email_body .= "<p><strong>Debit Method:</strong> " . htmlspecialchars($summary_data['banking_details']['4.16_debit_method'] ?? 'Not provided') . "</p>";
        $email_body .= "<p><strong>Pro-rata Premium:</strong> R" . number_format($summary_data['banking_details']['4.16_pro_rata_amount'] ?? 0, 2) . "</p>";

        // Declarations
        $email_body .= "<h3>Declarations</h3>";
        $email_body .= "<p><strong>Policy Details Confirmation:</strong> " . htmlspecialchars($summary_data['declarations']['5.1_policy_details_confirmation'] ?? 'Not provided') . "</p>";
        $email_body .= "<p><strong>Terms and Conditions Acceptance:</strong> " . htmlspecialchars($summary_data['declarations']['5.2_terms_acceptance'] ?? 'Not provided') . "</p>";
        $email_body .= "<p><strong>Final Acknowledgement:</strong> " . htmlspecialchars($summary_data['declarations']['5.3_final_acknowledgement'] ?? 'Not provided') . "</p>";

        // PDF Download Link
        $email_body .= "<p><strong>Download Confirmation:</strong> <a href=\"https://quoting.profusionum.co.za/underwriting_steps/generate_confirmation.php?policy_id=$policy_id&quote_id=$quote_id&excess_option=" . htmlspecialchars($summary_data['policy']['premium_type'] ?? 'standard') . "\">Download PDF</a></p>";

        // Send email
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = SMTP_PORT;

            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress('newbusiness@profusionum.com', 'Profusion Insurance New Business');

            $mail->isHTML(true);
            $mail->Subject = 'New Policy Activated - Policy ID: ' . $policy_id;
            $mail->Body = $email_body;
            $mail->AltBody = strip_tags($email_body);

            $mail->send();
            error_log("Step 7: Policy activation email sent to newbusiness@profusionum.com for policy_id=$policy_id");
        } catch (Exception $e) {
            error_log("Step 7: Failed to send policy activation email: {$mail->ErrorInfo}");
            $errors[] = "Failed to send policy activation email: {$mail->ErrorInfo}";
        }

        // Clear session data
        unset($_SESSION['underwriting_step'][$quote_id]);
        unset($_SESSION['underwriting_product'][$quote_id]);
        unset($_SESSION['errors']);

        $conn->commit();
        error_log("Step 7: Policy finalized and session data cleared");
        header("Location: ../dashboard.php");
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $errors[] = $e->getMessage();
        error_log("Step 7 Transaction failed: " . $e->getMessage());
        $_SESSION['errors'] = $errors;
        header("Location: step7_finalization.php?quote_id=$quote_id" . ($edit_mode ? '&edit_mode=true' : ''));
        exit();
    }
}

// Start HTML
start_html($script_sections[7]['name']);
?>

<div class="container mt-4">
    <h2 class="mb-4">Underwrite Policy (Quote ID: <?php echo htmlspecialchars($quote_id ?? 'N/A'); ?>)</h2>
    <?php if (!empty($errors)) { ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $error) { ?>
                <p><?php echo htmlspecialchars($error); ?></p>
            <?php } ?>
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
            <form method="post" action="step7_finalization.php?quote_id=<?php echo htmlspecialchars($quote_id); ?>" class="row g-3" id="underwritingForm">
                <div class="col-12">
                    <div class="card mb-3">
                        <div class="card-header section-heading"><?php echo htmlspecialchars($script_sections[7]['name']); ?></div>
                        <div class="card-body">
                            <h5>Policy Summary</h5>
                            <p>Please review the following details before finalizing your policy.</p>

                            <!-- Policy Holder Information -->
                            <div class="card mb-3">
                                <div class="card-header">Policy Holder Information</div>
                                <div class="card-body">
                                    <p><strong>Initials:</strong> <?php echo htmlspecialchars($summary_data['policy_holder']['initials'] ?? 'Not provided'); ?></p>
                                    <p><strong>Surname:</strong> <?php echo htmlspecialchars($summary_data['policy_holder']['surname'] ?? 'Not provided'); ?></p>
                                    <p><strong>Title:</strong> <?php echo htmlspecialchars($summary_data['policy_holder']['title'] ?? 'Not provided'); ?></p>
                                    <p><strong>Marital Status:</strong> <?php echo htmlspecialchars($summary_data['policy_holder']['marital_status'] ?? 'Not provided'); ?></p>
                                    <p><strong>Client ID:</strong> <?php echo htmlspecialchars($summary_data['policy_holder']['client_id'] ?? 'Not provided'); ?></p>
                                    <p><strong>Cell Number:</strong> <?php echo htmlspecialchars($summary_data['policy_holder']['2.5_cell_number'] ?? 'Not provided'); ?></p>
                                    <p><strong>Email:</strong> <?php echo htmlspecialchars($summary_data['policy_holder']['2.8_email'] ?? 'Not provided'); ?></p>
                                    <p><strong>Physical Address:</strong> <?php echo htmlspecialchars($summary_data['policy_holder']['2.10_physical_address'] ?? 'Not provided'); ?>, 
                                        <?php echo htmlspecialchars($summary_data['policy_holder']['2.10_physical_suburb'] ?? $summary_data['policy_holder']['suburb_client'] ?? 'Not provided'); ?>, 
                                        <?php echo htmlspecialchars($summary_data['policy_holder']['2.10_physical_postal_code'] ?? $summary_data['policy_holder']['postal_code_client'] ?? 'Not provided'); ?></p>
                                    <p><strong>Postal Address:</strong> <?php echo htmlspecialchars($summary_data['policy_holder']['2.12_postal_address'] ?? 'Not provided'); ?>, 
                                        <?php echo htmlspecialchars($summary_data['policy_holder']['2.12_postal_suburb'] ?? 'Not provided'); ?>, 
                                        <?php echo htmlspecialchars($summary_data['policy_holder']['2.12_postal_postal_code'] ?? 'Not provided'); ?></p>
                                    <p><strong>Dual Insurance:</strong> <?php echo htmlspecialchars($summary_data['policy_holder']['2.13_dual_insurance'] ?? 'Not provided'); ?>
                                        <?php if (($summary_data['policy_holder']['2.13_dual_insurance'] ?? '') === 'yes') { ?>
                                            (Cancellation Date: <?php echo htmlspecialchars($summary_data['policy_holder']['2.13_dual_insurance_cancellation_date'] ?? 'Not provided'); ?>)
                                        <?php } ?>
                                    </p>
                                </div>
                            </div>

                            <!-- Vehicle and Driver Details -->
                            <div class="card mb-3">
                                <div class="card-header">Vehicle and Driver Details</div>
                                <div class="card-body">
                                    <?php foreach ($summary_data['vehicles'] as $index => $data) {
                                        $vehicle = $data['vehicle'];
                                        $driver = $data['driver'];
                                        $vehicle_description = htmlspecialchars(trim("{$vehicle['vehicle_year']} {$vehicle['vehicle_make']} {$vehicle['vehicle_model']}"));
                                        ?>
                                        <h6>Vehicle <?php echo $index + 1; ?>: <?php echo $vehicle_description; ?></h6>
                                        <p><strong>Sum Insured:</strong> R <?php echo number_format($vehicle_responses[$index]['3.3_sum_insured_' . $index] ?? $vehicle['vehicle_value'], 2); ?></p>
                                        <p><strong>Engine Number:</strong> <?php echo htmlspecialchars($vehicle_responses[$index]['3.4_engine_number_' . $index] ?? 'Not provided'); ?></p>
                                        <p><strong>Chassis Number:</strong> <?php echo htmlspecialchars($vehicle_responses[$index]['3.4_chassis_number_' . $index] ?? 'Not provided'); ?></p>
                                        <p><strong>Finance Institution:</strong> <?php echo htmlspecialchars($vehicle_responses[$index]['3.5_finance_house_' . $index] ?? 'Not financed'); ?></p>
                                        <p><strong>Registered in Client’s Name:</strong> <?php echo htmlspecialchars($vehicle_responses[$index]['3.6_registered_in_client_name_' . $index] ?? 'Not provided'); ?>
                                            <?php if (($vehicle_responses[$index]['3.6_registered_in_client_name_' . $index] ?? '') === 'no') { ?>
                                                (Owner: <?php echo htmlspecialchars($vehicle_responses[$index]['3.6_registered_owner_name_' . $index] ?? 'Not provided'); ?>)
                                            <?php } ?>
                                        </p>
                                        <p><strong>Coverage Type:</strong> <?php echo htmlspecialchars($vehicle_responses[$index]['3.7_coverage_type_' . $index] ?? 'Not provided'); ?></p>
                                        <p><strong>Vehicle Condition:</strong> <?php echo htmlspecialchars($vehicle_responses[$index]['3.8_vehicle_condition_' . $index] ?? 'Not provided'); ?></p>
                                        <p><strong>Vehicle Use:</strong> <?php echo htmlspecialchars($vehicle_responses[$index]['3.9_vehicle_use_' . $index] ?? 'Not provided'); ?></p>
                                        <p><strong>Regular Driver:</strong> <?php echo htmlspecialchars($vehicle_responses[$index]['3.11_regular_driver_' . $index] ?? ($driver['driver_initials'] . ' ' . $driver['driver_surname'])); ?></p>
                                        <p><strong>Driver ID Number:</strong> <?php echo htmlspecialchars($vehicle_responses[$index]['3.11_driver_id_number_' . $index] ?? $driver['driver_id_number'] ?? 'Not provided'); ?></p>
                                        <p><strong>Driver DOB:</strong> <?php echo htmlspecialchars($vehicle_responses[$index]['3.11_driver_dob_' . $index] ?? $driver['dob'] ?? 'Not provided'); ?></p>
                                        <p><strong>Licence Type:</strong> <?php echo htmlspecialchars($vehicle_responses[$index]['3.12_licence_type_' . $index] ?? $driver['licence_type'] ?? 'Not provided'); ?></p>
                                        <p><strong>Licence Issue Year:</strong> <?php echo htmlspecialchars($vehicle_responses[$index]['3.13_year_of_issue_' . $index] ?? $driver['year_of_issue'] ?? 'Not provided'); ?></p>
                                        <p><strong>Comprehensive Insurance:</strong> <?php echo htmlspecialchars($vehicle_responses[$index]['3.16_has_comprehensive_insurance_' . $index] ?? 'Not provided'); ?>
                                            <?php if (($vehicle_responses[$index]['3.16_has_comprehensive_insurance_' . $index] ?? '') === 'yes') { ?>
                                                (Duration: <?php echo htmlspecialchars($vehicle_responses[$index]['3.16_insurance_duration_' . $index] ?? 'Not provided'); ?> years,
                                                Gaps: <?php echo htmlspecialchars($vehicle_responses[$index]['3.16_insurance_gaps_' . $index] ?? 'Not provided'); ?>,
                                                Claims: <?php echo htmlspecialchars($vehicle_responses[$index]['3.16_recent_claims_' . $index] ?? 'Not provided'); ?>)
                                            <?php } ?>
                                        </p>
                                        <p><strong>Additional Drivers:</strong> <?php echo ($vehicle_responses[$index]['3.20_add_additional_drivers_' . $index] ?? 'no') === 'yes' ? 'Yes' : 'No'; ?>
                                            <?php if (($vehicle_responses[$index]['3.20_add_additional_drivers_' . $index] ?? '') === 'yes' && !empty($data['additional_drivers'])) {
                                                echo '<ul>';
                                                foreach ($data['additional_drivers'] as $driver_index => $add_driver) {
                                                    echo '<li>';
                                                    echo 'Name: ' . htmlspecialchars($add_driver['name'] ?? 'Not provided') . ', ';
                                                    echo 'ID: ' . htmlspecialchars($add_driver['id_number'] ?? 'Not provided') . ', ';
                                                    echo 'Licence: ' . htmlspecialchars($add_driver['licence_type'] ?? 'Not provided');
                                                    echo '</li>';
                                                }
                                                echo '</ul>';
                                            } ?>
                                        </p>
                                        <p><strong>Existing Damage:</strong> <?php echo htmlspecialchars($vehicle_responses[$index]['3.27_existing_damage_' . $index] ?? 'Not provided'); ?>
                                            <?php if (($vehicle_responses[$index]['3.27_existing_damage_' . $index] ?? '') === 'yes') { ?>
                                                (Description: <?php echo htmlspecialchars($vehicle_responses[$index]['3.27_damage_description_' . $index] ?? 'Not provided'); ?>)
                                            <?php } ?>
                                        </p>
                                        <p><strong>Risk Address:</strong> <?php echo htmlspecialchars($vehicle_responses[$index]['3.28_street_' . $index] ?? $vehicle['street'] ?? 'Not provided'); ?>, 
                                            <?php echo htmlspecialchars($vehicle_responses[$index]['3.28_suburb_vehicle_' . $index] ?? $vehicle['suburb_vehicle'] ?? 'Not provided'); ?>, 
                                            <?php echo htmlspecialchars($vehicle_responses[$index]['3.28_postal_code_' . $index] ?? $vehicle['postal_code'] ?? 'Not provided'); ?></p>
                                        <p><strong>Security Device:</strong> <?php echo htmlspecialchars($vehicle_responses[$index]['3.29_security_device_' . $index] ?? 'Not provided'); ?></p>
                                        <p><strong>Car Hire Option:</strong> <?php echo htmlspecialchars($vehicle_responses[$index]['3.30_car_hire_' . $index] ?? $vehicle['car_hire'] ?? 'Not provided'); ?></p>
                                    <?php } ?>
                                </div>
                            </div>

                            <!-- Excess Disclosures -->
                            <div class="card mb-3">
                                <div class="card-header">Excess Disclosures</div>
                                <div class="card-body">
                                    <p><strong>Authorised Driver Policy Understanding:</strong> <?php echo htmlspecialchars($summary_data['excess_disclosures']['3.18_understands_authorised_driver'] ?? 'Not provided'); ?></p>
                                    <p><strong>Insurance Refused/Cancelled:</strong> <?php echo htmlspecialchars($summary_data['excess_disclosures']['3.20_insurance_refused'] ?? 'Not provided'); ?></p>
                                    <p><strong>Insolvency/Debt Administration:</strong> <?php echo htmlspecialchars($summary_data['excess_disclosures']['3.21_insolvency_status'] ?? 'Not provided'); ?></p>
                                    <p><strong>Criminal Convictions:</strong> <?php echo htmlspecialchars($summary_data['excess_disclosures']['3.22_criminal_convictions'] ?? 'Not provided'); ?></p>
                                    <p><strong>Disabilities/Illnesses:</strong> <?php echo htmlspecialchars($summary_data['excess_disclosures']['3.23_disabilities'] ?? 'Not provided'); ?></p>
                                    <p><strong>Excess Structure Understanding:</strong> <?php echo htmlspecialchars($summary_data['excess_disclosures']['3.33_excess_understanding'] ?? 'Not provided'); ?></p>
                                </div>
                            </div>

                            <!-- Banking Details -->
                            <div class="card mb-3">
                                <div class="card-header">Banking Details</div>
                                <div class="card-body">
                                    <p><strong>Bank Name:</strong> <?php echo htmlspecialchars($summary_data['banking_details']['4.1_bank_name'] ?? 'Not provided'); ?></p>
                                    <p><strong>Account Holder:</strong> <?php echo htmlspecialchars($summary_data['banking_details']['4.1_account_holder'] ?? 'Not provided'); ?></p>
                                    <p><strong>Account Number:</strong> <?php echo htmlspecialchars($summary_data['banking_details']['4.1_account_number'] ?? 'Not provided'); ?></p>
                                    <p><strong>Account Type:</strong> <?php echo htmlspecialchars($summary_data['banking_details']['4.1_account_type'] ?? 'Not provided'); ?></p>
                                    <p><strong>Branch Code:</strong> <?php echo htmlspecialchars($summary_data['banking_details']['4.1_branch_code'] ?? 'Not provided'); ?></p>
                                    <p><strong>Debit Order Day:</strong> <?php echo htmlspecialchars($summary_data['banking_details']['4.2_debit_date'] ?? 'Not provided'); ?></p>
                                    <p><strong>Policy Start Date:</strong> <?php echo htmlspecialchars($summary_data['banking_details']['4.9_policy_start_date'] ?? 'Not provided'); ?></p>
                                    <p><strong>Premium Amount:</strong> R <?php echo number_format($summary_data['policy']['premium_amount'] ?? 0, 2); ?></p>
                                    <p><strong>Broker Fee:</strong> R <?php echo number_format($summary_data['banking_details']['4.1_broker_fee'] ?? 0, 2); ?></p>
                                    <p><strong>Debit Method:</strong> <?php echo htmlspecialchars($summary_data['banking_details']['4.16_debit_method'] ?? 'Not provided'); ?></p>
                                    <p><strong>Pro-rata Premium:</strong> R <?php echo number_format($summary_data['banking_details']['4.16_pro_rata_amount'] ?? 0, 2); ?></p>
                                </div>
                            </div>

                            <!-- Declarations -->
                            <div class="card mb-3">
                                <div class="card-header">Declarations</div>
                                <div class="card-body">
                                    <p><strong>Policy Details Confirmation:</strong> <?php echo htmlspecialchars($summary_data['declarations']['5.1_policy_details_confirmation'] ?? 'Not provided'); ?></p>
                                    <p><strong>Terms and Conditions Acceptance:</strong> <?php echo htmlspecialchars($summary_data['declarations']['5.2_terms_acceptance'] ?? 'Not provided'); ?></p>
                                    <p><strong>Final Acknowledgement:</strong> <?php echo htmlspecialchars($summary_data['declarations']['5.3_final_acknowledgement'] ?? 'Not provided'); ?></p>
                                </div>
                            </div>

                            <!-- Final Confirmation -->
                            <div class="row mb-2">
                                <label class="col-md-4 col-form-label">Confirm All Details Are Correct:</label>
                                <div class="col-md-8">
                                    <select name="confirm_details" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes">Yes</option>
                                        <option value="no">No (Return to Previous Steps to Edit)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <button type="submit" name="direction" value="previous" class="btn btn-secondary">Previous Step</button>
                    <button type="submit" name="direction" value="finalize" class="btn btn-purple">Finalize Policy</button>
                    <?php if ($summary_data['policy']['status'] === 'active') { ?>
                        <a href="generate_confirmation.php?policy_id=<?php echo htmlspecialchars($policy_id); ?>&quote_id=<?php echo htmlspecialchars($quote_id); ?>&excess_option=<?php echo htmlspecialchars($summary_data['policy']['premium_type'] ?? 'standard'); ?>" class="btn btn-success">Download Confirmation PDF</a>
                    <?php } ?>
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
        // Validate form on submission
        document.getElementById('underwritingForm').addEventListener('submit', function(event) {
            const confirmDetails = document.querySelector('select[name="confirm_details"]');
            if (!confirmDetails.value) {
                event.preventDefault();
                confirmDetails.classList.add('is-invalid');
                alert('Please confirm whether all details are correct.');
            } else {
                confirmDetails.classList.remove('is-invalid');
            }
        });
    });
</script>
</body>
</html>
<?php
$conn->close();
?>