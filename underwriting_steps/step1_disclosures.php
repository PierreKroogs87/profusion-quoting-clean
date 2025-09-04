<?php
require 'underwriting_common.php'; // Same directory: /home/profusi3/public_html/quoting/underwriting_steps/

// Initialize variables for edit mode
$edit_mode = isset($_GET['edit_mode']) && $_GET['edit_mode'] === 'true';
$responses = [];
$valid_product_types = ['premium6', 'premium5', 'premium4', 'premium_flat'];

// Fetch or create policy_id
$stmt = $conn->prepare("SELECT policy_id FROM policies WHERE quote_id = ?");
$stmt->bind_param("i", $quote_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $policy_id = $result->fetch_assoc()['policy_id'];
} else if (!$edit_mode) {
    // Get product_type and premium_amount
    $product_type = $_GET['product_type'] ?? null;
    $premium_amount = null;

    // Debug: Log input values
    error_log("Step 1: product_type from URL: " . ($product_type ?? 'null'));

    // If product_type not in URL or invalid, try quotes table
    if (!$product_type || !in_array($product_type, $valid_product_types)) {
        $stmt = $conn->prepare("SELECT premium_type, premium_amount FROM quotes WHERE quote_id = ?");
        $stmt->bind_param("i", $quote_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $quote_data_row = $result->fetch_assoc();
            $product_type = $quote_data_row['premium_type'] ?? null;
            $premium_amount = $quote_data_row['premium_amount'] ?? null;
            error_log("Step 1: quotes.premium_type: " . ($product_type ?? 'null') . ", quotes.premium_amount: " . ($premium_amount ?? 'null'));
        } else {
            error_log("Step 1: No quote found for quote_id=$quote_id");
        }
        $stmt->close();
    }

    // Use defaults if still missing
    if (!$product_type || !in_array($product_type, $valid_product_types)) {
        $product_type = 'premium6'; // Default
        error_log("Step 1: Using default product_type=premium6 for quote_id=$quote_id");
    }
    if ($premium_amount === null) {
        $premium_amount = 0.00; // Default
        error_log("Step 1: Using default premium_amount=0.00 for quote_id=$quote_id");
    }

    // Create draft policy
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

    // Set session data for consistency
    $_SESSION['underwriting_product'][$quote_id] = [
        'product_type' => $product_type,
        'premium_amount' => $premium_amount
    ];
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
        WHERE policy_id = ? AND section = 'confirmation_client_details'
    ");
    $stmt->bind_param("i", $policy_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $responses[$row['question_key']] = $row['response'];
    }
    $stmt->close();
    error_log("Edit mode: Loaded responses for step 1: " . print_r($responses, true));
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();
    try {
        // Debug: Log POST data
        error_log("Step 1 POST Data: " . print_r($_POST, true));

        // Determine navigation direction
        $direction = $_POST['direction'] ?? 'next';

        // Process Step 1 responses
        $responses = [
            '1.1_confirm_client_available' => $_POST['confirm_client_available'] ?? null,
            '1.3_client_has_time' => $_POST['client_has_time'] ?? null,
            '1.4_acknowledge_recording' => $_POST['acknowledge_recording'] ?? null,
            '1.8_claims_disclosure_consent' => $_POST['claims_disclosure_consent'] ?? null,
            '1.9_insurer_credit_consent' => $_POST['insurer_credit_consent'] ?? null,
            '1.11_accuracy_understanding' => $_POST['accuracy_understanding'] ?? null
        ];

        // Validate responses
        foreach ($responses as $key => $value) {
            if ($value === null || !in_array($value, ['yes', 'no', 'no (Reschedule Call)'])) {
                throw new Exception("Invalid or missing response for $key");
            }
            if ($key === '1.1_confirm_client_available' && $value === 'no (Reschedule Call)') {
                throw new Exception("Client is not available. Please reschedule the call.");
            }
            if ($key === '1.3_client_has_time' && $value === 'no (Reschedule Call)') {
                throw new Exception("Client does not have time. Please reschedule the call.");
            }
            if ($value === 'no') {
                throw new Exception("Client did not consent to $key. Underwriting cannot proceed.");
            }
        }

        // Save responses
        $stmt = $conn->prepare("
            INSERT INTO policy_underwriting_data (policy_id, section, question_key, response)
            VALUES (?, 'confirmation_client_details', ?, ?)
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
            error_log("Step 1 Edit Mode: Changes saved, redirecting to dashboard");
            header("Location: ../dashboard.php");
            exit();
        } else {
            // Advance to next step in normal mode
            $_SESSION['underwriting_step'][$quote_id] = 2;
            $conn->commit();
            error_log("Step 1: Navigating to next step (Step 2)");
            header("Location: step2_policy_holder_info.php?quote_id=$quote_id");
            exit();
        }

    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $errors[] = $e->getMessage();
        error_log("Step 1 Transaction failed: " . $e->getMessage());
        $_SESSION['errors'] = $errors;
        // Stay on step1_disclosures.php to display error
        // header("Location: step1_disclosures.php?quote_id=$quote_id" . ($edit_mode ? '&edit_mode=true' : ''));
        // exit();
    }
}

// Start HTML
start_html($script_sections[1]['name']);
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
                $file_path = $section['file']; // All step files in underwriting_steps/
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
            <form method="post" action="step1_disclosures.php?quote_id=<?php echo htmlspecialchars($quote_id); ?><?php echo $edit_mode ? '&edit_mode=true' : ''; ?>" class="row g-3" id="underwritingForm">
                <div class="col-12">
                    <div class="card mb-3">
                        <div class="card-header section-heading"><?php echo htmlspecialchars($script_sections[1]['name']); ?></div>
                        <div class="card-body">
                            <?php if (!empty($errors)): ?>
                                <div class="alert alert-danger">
                                    <?php foreach ($errors as $error): ?>
                                        <p><?php echo htmlspecialchars($error); ?></p>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <p><strong>1.1</strong> Good day, may I please speak to <?php echo htmlspecialchars($quote_data['title'] . ' ' . $quote_data['initials'] . ' ' . $quote_data['surname']); ?>. My name is [Your Name] and I’m phoning you from Profusion, an authorised financial services provider.</p>
                            <div class="row mb-2">
                                <label class="col-md-4 col-form-label">Confirm client is available:</label>
                                <div class="col-md-8">
                                    <select name="confirm_client_available" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($responses['1.1_confirm_client_available'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no (Reschedule Call)" <?php echo ($responses['1.1_confirm_client_available'] ?? '') === 'no (Reschedule Call)' ? 'selected' : ''; ?>>No (Reschedule Call)</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>1.2</strong> I got your details from [Broker Consultant] at <?php echo htmlspecialchars($quote_data['brokerage_name'] ?? 'N/A'); ?> and he/she has asked me to give you a call in connection with your insurance on your <?php
                                foreach ($vehicles_data as $index => $data) {
                                    $vehicle = $data['vehicle'];
                                    echo htmlspecialchars(trim("{$vehicle['vehicle_year']} {$vehicle['vehicle_make']} {$vehicle['vehicle_model']}"));
                                    if ($index < count($vehicles_data) - 1) echo ', ';
                                }
                            ?>.</p>
                            <p><strong>1.3</strong> Do you have a few minutes so we can finalise your insurance for you?</p>
                            <div class="row mb-2">
                                <label class="col-md-4 col-form-label">Client has time to proceed:</label>
                                <div class="col-md-8">
                                    <select name="client_has_time" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($responses['1.3_client_has_time'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no (Reschedule Call)" <?php echo ($responses['1.3_client_has_time'] ?? '') === 'no (Reschedule Call)' ? 'selected' : ''; ?>>No (Reschedule Call)</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>1.4</strong> Please note that ALL conversations with Profusion are recorded for your protection and ours.</p>
                            <div class="row mb-2">
                                <label class="col-md-4 col-form-label">Client acknowledges recording:</label>
                                <div class="col-md-8">
                                    <select name="acknowledge_recording" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($responses['1.4_acknowledge_recording'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($responses['1.4_acknowledge_recording'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>1.5</strong> Please be aware that we respect the confidentiality of your information in terms of the legislation applicable to all insurance companies.</p>
                            <p><strong>1.6</strong> All information that we keep with us, we are not going to share with anyone else.</p>
                            <p><strong>1.7</strong> I must also inform you that we may check the information that you provide.</p>
                            <p><strong>1.8</strong> We require your consent to confirm and disclose information related to claims on this policy and any financial history where applicable. Do you agree?</p>
                            <div class="row mb-2">
                                <label class="col-md-4 col-form-label">Consent for claims disclosure:</label>
                                <div class="col-md-8">
                                    <select name="claims_disclosure_consent" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($responses['1.8_claims_disclosure_consent'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($responses['1.8_claims_disclosure_consent'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>1.9</strong> In order to enable us to underwrite your risk correctly and fairly, we require your permission to share this information with other insurers and to access your credit profile. Do you give us permission for the above?</p>
                            <div class="row mb-2">
                                <label class="col-md-4 col-form-label">Consent for sharing with insurers and credit check:</label>
                                <div class="col-md-8">
                                    <select name="insurer_credit_consent" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($responses['1.9_insurer_credit_consent'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($responses['1.9_insurer_credit_consent'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            </div>
                            <p><strong>1.10</strong> I am a full time Profusion employee and am authorised/under supervision by Profusion to give you the best advice. I am paid on a performance basis through a remuneration system.</p>
                            <p><strong>1.11</strong> The validity of this agreement in premium is based on the accuracy of the information provided and any incorrect information given by you will negatively affect the outcome of a claim so it is vital that you supply us with all the correct information, do you understand?</p>
                            <div class="row mb-2">
                                <label class="col-md-4 col-form-label">Client understands accuracy importance:</label>
                                <div class="col-md-8">
                                    <select name="accuracy_understanding" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($responses['1.11_accuracy_understanding'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($responses['1.11_accuracy_understanding'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
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