<?php
require 'underwriting_common.php';

// Check if we're on the correct step (handled by underwriting_common.php)
// $current_step is already validated in underwriting_common.php

// Initialize variables for edit mode
$edit_mode = isset($_GET['edit_mode']) && $_GET['edit_mode'] === 'true';
$responses = [
    '5.1_proposal_basis' => '',
    '5.2_information_accuracy' => '',
    '5.3_confirmation_receipt' => '',
    '5.4_vehicle_inspection' => '',
    '5.5_schedule_documents' => '',
    '5.7_read_policy_documents' => '',
    '5.10_pro_rata_confirmation' => '',
    '5.8_referral_contact' => ''
];

// Fetch policy_id
$stmt = $conn->prepare("SELECT policy_id FROM policies WHERE quote_id = ?");
$stmt->bind_param("i", $quote_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $policy_id = $result->fetch_assoc()['policy_id'];
} else {
    error_log("No policy found for quote_id=$quote_id");
    $_SESSION['errors'] = ["No policy found for this quote ID."];
    header("Location: ../dashboard.php");
    exit();
}
$stmt->close();

// Load existing responses from policy_underwriting_data if in edit_mode
if ($edit_mode && $policy_id) {
    $stmt = $conn->prepare("
        SELECT question_key, response
        FROM policy_underwriting_data
        WHERE policy_id = ? AND section = 'declarations'
    ");
    if (!$stmt) {
        error_log("Failed to prepare query for policy_underwriting_data: " . $conn->error);
        $_SESSION['errors'] = ["Database query preparation failed."];
        header("Location: ../dashboard.php");
        exit();
    }
    $stmt->bind_param("i", $policy_id);
    if (!$stmt->execute()) {
        error_log("Query execution failed for policy_id=$policy_id: " . $stmt->error);
        $_SESSION['errors'] = ["Failed to retrieve responses from database."];
        header("Location: ../dashboard.php");
        exit();
    }
    $result = $stmt->get_result();
    $row_count = $result->num_rows;
    error_log("Edit mode: Found $row_count rows for policy_id=$policy_id in section 'declarations'");
    while ($row = $result->fetch_assoc()) {
        $responses[$row['question_key']] = $row['response'];
    }
    $stmt->close();
    error_log("Edit mode: Loaded responses for step 6: " . print_r($responses, true));
}

// Calculate pro-rata premium (for 5.10)
$today = new DateTime();
$year = $today->format('Y');
$month = $today->format('m');
$days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$current_day = (int)$today->format('d');
$days_remaining = $days_in_month - $current_day + 1; // Include today
$premium_amount = $_SESSION['underwriting_product'][$quote_id]['premium_amount'] ?? 0;
$pro_rata_premium = ($premium_amount / $days_in_month) * $days_remaining;
$pro_rata_premium = round($pro_rata_premium, 2);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();
    try {
        // Process Step 6 responses
        $responses = [
            '5.1_proposal_basis' => $_POST['proposal_basis'] ?? null,
            '5.2_information_accuracy' => $_POST['information_accuracy'] ?? null,
            '5.3_confirmation_receipt' => $_POST['confirmation_receipt'] ?? null,
            '5.4_vehicle_inspection' => $_POST['vehicle_inspection'] ?? null,
            '5.5_schedule_documents' => $_POST['schedule_documents'] ?? null,
            '5.7_read_policy_documents' => $_POST['read_policy_documents'] ?? null,
            '5.10_pro_rata_confirmation' => $_POST['pro_rata_confirmation'] ?? null,
            '5.8_referral_contact' => $_POST['referral_contact'] ?? ''
        ];

        // Validate responses
        if (!in_array($responses['5.1_proposal_basis'], ['yes', 'no'])) {
            throw new Exception("Invalid or missing proposal basis confirmation");
        }
        if (!in_array($responses['5.2_information_accuracy'], ['yes', 'no'])) {
            throw new Exception("Invalid or missing information accuracy confirmation");
        }
        if (!in_array($responses['5.3_confirmation_receipt'], ['yes', 'no'])) {
            throw new Exception("Invalid or missing confirmation receipt acknowledgment");
        }
        if (!in_array($responses['5.4_vehicle_inspection'], ['yes', 'no'])) {
            throw new Exception("Invalid or missing vehicle inspection understanding");
        }
        if (!in_array($responses['5.5_schedule_documents'], ['yes', 'no'])) {
            throw new Exception("Invalid or missing schedule documents receipt confirmation");
        }
        if (!in_array($responses['5.7_read_policy_documents'], ['yes', 'no'])) {
            throw new Exception("Invalid or missing policy documents reading confirmation");
        }
        if (!in_array($responses['5.10_pro_rata_confirmation'], ['yes', 'no'])) {
            throw new Exception("Invalid or missing pro-rata premium confirmation");
        }
        foreach (['5.1_proposal_basis', '5.2_information_accuracy', '5.3_confirmation_receipt', '5.4_vehicle_inspection', '5.5_schedule_documents', '5.7_read_policy_documents', '5.10_pro_rata_confirmation'] as $key) {
            if ($responses[$key] === 'no') {
                throw new Exception("Client did not confirm $key. Underwriting cannot proceed.");
            }
        }

        // Save responses to policy_underwriting_data
        $stmt = $conn->prepare("
            INSERT INTO policy_underwriting_data (policy_id, section, question_key, response)
            VALUES (?, 'declarations', ?, ?)
            ON DUPLICATE KEY UPDATE response = ?
        ");
        foreach ($responses as $key => $value) {
            $stmt->bind_param("isss", $policy_id, $key, $value, $value);
            if (!$stmt->execute()) {
                throw new Exception("Failed to save response for $key: " . $stmt->error);
            }
        }
        $stmt->close();

        if ($direction === 'previous') {
            if (!$edit_mode) {
                $_SESSION['underwriting_step'][$quote_id] = 5;
            }
            $conn->commit();
            error_log("Step 6: Navigating to previous step (Step 5)");
            header("Location: step5_banking_details_mandate.php?quote_id=$quote_id" . ($edit_mode ? '&edit_mode=true' : ''));
            exit();
        }

            // If in edit_mode, redirect to dashboard after save; else advance to next step
        if ($edit_mode) {
            $conn->commit();
            error_log("Step 6 Edit Mode: Changes saved, redirecting to dashboard");
            header("Location: ../dashboard.php");
            exit();
        } else {
            // Advance to next step in normal mode
            $_SESSION['underwriting_step'][$quote_id] = 7;
            $conn->commit();
            error_log("Step 6: Navigating to next step (Step 7)");
            header("Location: step7_finalization.php?quote_id=$quote_id");
            exit();
        }

    } catch (Exception $e) {
        $conn->rollback();
        $errors[] = $e->getMessage();
        error_log("Step 6 Transaction failed: " . $e->getMessage());
        $_SESSION['errors'] = $errors;
        header("Location: step6_declarations.php?quote_id=$quote_id" . ($edit_mode ? '&edit_mode=true' : ''));
        exit();
    }
}

// Start HTML
start_html($script_sections[6]['name']);
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
    <?php if ($edit_mode && empty(array_filter($responses, fn($value) => !empty($value)))) { ?>
        <div class="alert alert-warning">
            <p>No previous responses found for this policy. Please verify the data or complete the form to save new responses.</p>
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
            <form method="post" action="step6_declarations.php?quote_id=<?php echo htmlspecialchars($quote_id); ?><?php echo $edit_mode ? '&edit_mode=true' : ''; ?>" class="row g-3" id="underwritingForm">
                <div class="col-12">
                    <div class="card mb-3">
                        <div class="card-header section-heading"><?php echo htmlspecialchars($script_sections[6]['name']); ?></div>
                        <div class="card-body">
                            <?php if (!empty($_SESSION['errors'])): ?>
                                <div class="alert alert-danger">
                                    <?php foreach ($_SESSION['errors'] as $error): ?>
                                        <p><?php echo htmlspecialchars($error); ?></p>
                                    <?php endforeach; ?>
                                    <?php unset($_SESSION['errors']); ?>
                                </div>
                            <?php endif; ?>
                            <p><strong>5.1</strong> Do you understand that this proposal forms the basis of your contract with the insurer?</p>
                            <div class="row mb-2">
                                <label for="proposal_basis" class="col-md-4 col-form-label">Proposal Basis:</label>
                                <div class="col-md-8">
                                    <select name="proposal_basis" id="proposal_basis" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($responses['5.1_proposal_basis'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($responses['5.1_proposal_basis'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>5.2</strong> Do you confirm that the information contained in this proposal is true and correct?</p>
                            <div class="row mb-2">
                                <label for="information_accuracy" class="col-md-4 col-form-label">Information Accuracy:</label>
                                <div class="col-md-8">
                                    <select name="information_accuracy" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($responses['5.2_information_accuracy'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($responses['5.2_information_accuracy'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>5.3</strong> Kindly acknowledge receipt of your confirmation of insurance which will be sent to your broker, by emailing clientcare@profusionum.com with your inspection certificate. Do you understand?</p>
                            <div class="row mb-2">
                                <label for="confirmation_receipt" class="col-md-4 col-form-label">Confirmation Receipt Understanding:</label>
                                <div class="col-md-8">
                                    <select name="confirmation_receipt" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($responses['5.3_confirmation_receipt'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($responses['5.3_confirmation_receipt'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>5.4</strong> Very importantly, you will be sent a link which will re-direct you to our app which will assist you with completing an inspection of your vehicle. You have 24 hours to complete the inspection from the start date of your policy. If the vehicle has not been inspected within the allowed 24 hours, your cover will be restricted to third party cover only, until the vehicle has been inspected. Third party cover means, we will not repair the insured vehicle. Profusion will only repair the third party’s vehicle, should a third party be involved.</p>
                            <div class="row mb-2">
                                <label for="vehicle_inspection" class="col-md-4 col-form-label">Vehicle Inspection Understanding:</label>
                                <div class="col-md-8">
                                    <select name="vehicle_inspection" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($responses['5.4_vehicle_inspection'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($responses['5.4_vehicle_inspection'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>5.7</strong> You should receive your schedule documents within the next 30 minutes via e-mail, on the e-mail address you have provided us with. If you do not receive them please ensure that you contact us. Please note that it is YOUR responsibility to read and understand your policy documents. Your Policy schedule must be read together with your policy wording which will be sent with your schedule or downloaded at <a href="http://www.profusionum.com/documents" target="_blank">www.profusionum.com/documents</a>.</p>
                            <div class="row mb-2">
                                <label for="schedule_documents" class="col-md-4 col-form-label">Schedule Documents Receipt:</label>
                                <div class="col-md-8">
                                    <select name="schedule_documents" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($responses['5.5_schedule_documents'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($responses['5.5_schedule_documents'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>5.9</strong> Please read your policy documents as they contain very important information, if there is anything you do not understand please call us.</p>
                            <div class="row mb-2">
                                <label for="read_policy_documents" class="col-md-4 col-form-label">Read Policy Documents:</label>
                                <div class="col-md-8">
                                    <select name="read_policy_documents" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($responses['5.7_read_policy_documents'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($responses['5.7_read_policy_documents'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>5.10</strong> Kindly please remember to make arrangements for your pro rata premium of R<?php echo number_format($pro_rata_premium, 2); ?> which will go off within the next 72 hours, do you understand?</p>
                            <div class="row mb-2">
                                <label for="pro_rata_confirmation" class="col-md-4 col-form-label">Pro-Rata Premium Confirmation:</label>
                                <div class="col-md-8">
                                    <select name="pro_rata_confirmation" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($responses['5.10_pro_rata_confirmation'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($responses['5.10_pro_rata_confirmation'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>5.11</strong> Do you have anyone that I can contact that could benefit from our services?</p>
                            <div class="row mb-2">
                                <label for="referral_contact" class="col-md-4 col-form-label">Referral Contact:</label>
                                <div class="col-md-8">
                                    <input type="text" name="referral_contact" id="referral_contact" value="<?php echo htmlspecialchars($responses['5.8_referral_contact'] ?? ''); ?>" class="form-control" pattern="[A-Za-z0-9\s,.@\-]*" maxlength="100">
                                    <small class="form-text text-muted">Optional: Enter a contact name or leave blank.</small>
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
        // Validate form on submission
        document.getElementById('underwritingForm').addEventListener('submit', function(event) {
            const requiredSelects = document.querySelectorAll('select[required]');
            let valid = true;
            requiredSelects.forEach(select => {
                if (!select.value) {
                    valid = false;
                    select.classList.add('is-invalid');
                } else {
                    select.classList.remove('is-invalid');
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