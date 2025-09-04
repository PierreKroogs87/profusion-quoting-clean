<?php
require 'underwriting_common.php';

// Check if we're on the correct step (handled by underwriting_common.php)
// $current_step is already validated in underwriting_common.php

// Initialize variables for edit mode
$edit_mode = isset($_GET['edit_mode']) && $_GET['edit_mode'] === 'true';
$responses = [];

// Fetch policy_id
$stmt = $conn->prepare("SELECT policy_id FROM policies WHERE quote_id = ?");
$stmt->bind_param("i", $quote_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $policy_id = $result->fetch_assoc()['policy_id'];
} else {
    $policy_id = null; // Will create draft in POST if needed (normal mode only)
}
$stmt->close();

// Load existing responses from policy_underwriting_data
if ($policy_id) {
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
        $responses[$row['question_key']] = $row['response'];
    }
    $stmt->close();
}

// Load vehicle responses for display
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
        // Adjust question keys to match new numbering
        $old_key = $row['question_key'];
        $new_key = $old_key;
        if (preg_match('/^3\.(\d+)_(.+)_' . $index . '$/', $old_key, $matches)) {
            $question_num = (int)$matches[1];
            $suffix = $matches[2];
            if ($question_num >= 5) {
                $new_question_num = $question_num + 1;
                $new_key = "3.{$new_question_num}_{$suffix}_$index";
            }
        } elseif (preg_match('/^3\.19_additional_driver_(.+)_' . $index . '_(\d+)$/', $old_key, $matches)) {
            $suffix = $matches[1];
            $driver_index = $matches[2];
            $new_key = "3.20_additional_driver_{$suffix}_{$index}_{$driver_index}";
        }
        $vehicle_responses[$index][$new_key] = $row['response'];
    }
    $stmt->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();
    try {
        // Debug: Log POST data
        error_log("Step 4 POST Data: " . print_r($_POST, true));

        // Determine navigation direction
        $direction = $_POST['direction'] ?? 'next';

        // If no policy exists, create a draft (normal mode only)
        if (!$policy_id && !$edit_mode) {
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
            $stmt->close();
        } else if (!$policy_id && $edit_mode) {
            throw new Exception("No policy found for editing in edit mode.");
        }

        if ($direction === 'previous') {
            if (!$edit_mode) {
                $_SESSION['underwriting_step'][$quote_id] = 3;
            }
            $conn->commit();
            error_log("Step 4: Navigating to previous step (Step 3)");
            header("Location: step3_vehicle_driver_details.php?quote_id=$quote_id" . ($edit_mode ? '&edit_mode=true' : ''));
            exit();
        }

        // Process Step 4 responses
        $responses = [
            '3.18_understands_authorised_driver' => $_POST['understands_authorised_driver'] ?? null,
            '3.20_insurance_refused' => $_POST['insurance_refused'] ?? null,
            '3.21_insolvency_status' => $_POST['insolvency_status'] ?? null,
            '3.22_criminal_convictions' => $_POST['criminal_convictions'] ?? null,
            '3.23_disabilities' => $_POST['disabilities'] ?? null,
            '3.33_excess_understanding' => $_POST['excess_understanding'] ?? null
        ];

        // Validate responses
        if (!in_array($responses['3.18_understands_authorised_driver'], ['yes', 'no'])) {
            throw new Exception("Invalid or missing authorised driver understanding");
        }
        if ($responses['3.18_understands_authorised_driver'] === 'no') {
            throw new Exception("Client does not understand authorised driver policy. Underwriting cannot proceed.");
        }
        if (!in_array($responses['3.33_excess_understanding'], ['yes', 'no'])) {
            throw new Exception("Invalid or missing excess structure understanding");
        }
        if ($responses['3.33_excess_understanding'] === 'no') {
            throw new Exception("Client does not understand excess structure. Underwriting cannot proceed.");
        }
        foreach (['3.20_insurance_refused', '3.21_insolvency_status', '3.22_criminal_convictions', '3.23_disabilities'] as $key) {
            if (!in_array($responses[$key], ['yes', 'no'])) {
                throw new Exception("Invalid or missing response for $key");
            }
            if ($responses[$key] === 'yes') {
                throw new Exception("Response for $key requires management referral");
            }
        }

        // Save responses to policy_underwriting_data
        $stmt = $conn->prepare("
            INSERT INTO policy_underwriting_data (policy_id, section, question_key, response)
            VALUES (?, 'motor_section', ?, ?)
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
            error_log("Step 4 Edit Mode: Changes saved, redirecting to dashboard");
            header("Location: ../dashboard.php");
            exit();
        } else {
            // Advance to next step in normal mode
            $_SESSION['underwriting_step'][$quote_id] = 5;
            $conn->commit();
            error_log("Step 4: Navigating to next step (Step 5)");
            header("Location: step5_banking_details_mandate.php?quote_id=$quote_id");
            exit();
        }

    } catch (Exception $e) {
        $conn->rollback();
        $errors[] = $e->getMessage();
        error_log("Step 4 Transaction failed: " . $e->getMessage());
        $_SESSION['errors'] = $errors;
        header("Location: step4_excess_disclosures.php?quote_id=$quote_id" . ($edit_mode ? '&edit_mode=true' : ''));
        exit();
    }
}

// Start HTML
start_html($script_sections[4]['name']);
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
            <form method="post" action="step4_excess_disclosures.php?quote_id=<?php echo htmlspecialchars($quote_id); ?><?php echo $edit_mode ? '&edit_mode=true' : ''; ?>" class="row g-3" id="underwritingForm">
                <div class="col-12">
                    <div class="card mb-3">
                        <div class="card-header section-heading"><?php echo htmlspecialchars($script_sections[4]['name']); ?></div>
                        <div class="card-body">
                            <?php if (!empty($_SESSION['errors'])): ?>
                                <div class="alert alert-danger">
                                    <?php foreach ($_SESSION['errors'] as $error): ?>
                                        <p><?php echo htmlspecialchars($error); ?></p>
                                    <?php endforeach; ?>
                                    <?php unset($_SESSION['errors']); ?>
                                </div>
                            <?php endif; ?>
                            <p><strong>3.33</strong> Below is the full excess structure for your policy based on the selected product (<?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $_SESSION['underwriting_product'][$quote_id]['product_type']))); ?>). Please review and confirm your understanding. The consultant can calculate the excesses manually if you request clarity.</p>
                            <?php
                            $product_type = $_SESSION['underwriting_product'][$quote_id]['product_type'] ?? 'premium6';
                            $basic_excess_label = [
                                'premium6' => '6% of the sum insured, minimum R5000',
                                'premium5' => '5% of the sum insured, minimum R5000',
                                'premium4' => '4% of the sum insured, minimum R5000',
                                'premium_flat' => 'Flat R3500'
                            ][$product_type];
                            foreach ($vehicles_data as $index => $data) {
                                $vehicle = $data['vehicle'];
                                $driver = $data['driver'];
                                $vehicle_description = htmlspecialchars(trim("{$vehicle['vehicle_year']} {$vehicle['vehicle_make']} {$vehicle['vehicle_model']}"));
                                // Fetch vehicle use and driver DOB from policy_underwriting_data for accuracy
                                $vehicle_use = $vehicle_responses[$index]['3.9_vehicle_use_' . $index] ?? $vehicle['vehicle_use'] ?? 'private';
                                $driver_dob = $vehicle_responses[$index]['3.11_driver_dob_' . $index] ?? $driver['dob'] ?? null;
                                $is_pensioner = false;
                                if ($driver_dob) {
                                    $dob = new DateTime($driver_dob);
                                    $today = new DateTime();
                                    $age = $today->diff($dob)->y;
                                    $is_pensioner = $age > 55;
                                }
                                ?>
                                <h5>Vehicle <?php echo $index + 1; ?>: <?php echo $vehicle_description; ?></h5>
                                <p><strong>3.33.1 Basic Excess</strong>: Applicable in the event of Accident, Accident or Theft/Hijacking, and payable for any claim.</p>
                                <ul>
                                    <li><strong>Private Use</strong>: <?php echo $basic_excess_label; ?></li>
                                    <?php if ($vehicle_use === 'business') { ?>
                                        <li><strong>Business Use</strong>: Basic Excess plus 1% of the sum insured, minimum plus R1000</li>
                                    <?php } ?>
                                    <li><strong>Note</strong>: Please note that this excess amount calculated is subject to change based on the vehicle’s value. The consultant can calculate the excess upon request.</li>
                                </ul>
                                <?php if ($is_pensioner) { ?>
                                    <p><strong>3.33.2 Pensioner Exemption</strong>: As the driver is over 55, they will not pay the basic excess for Accident/Damages or Theft/Hijacking if <?php echo $product_type === 'premium4' ? 'Option 1 (4% Excess)' : 'this option'; ?> is selected. Additional excesses will still apply.</p>
                                    <p><strong>Note</strong>: A pensioner will only receive this benefit if they are over 55 years with a valid driver’s license and not earning any regular income.</p>
                                <?php } ?>
                                <p><strong>3.33.3 Excess Change</strong>: Should the policyholder decide to change their excess to Option 2 (5%) or Option 3 (6%), the basic excess and additional excesses that apply will be payable at the time of a claim.</p>
                                <p><strong>Windscreen Claims</strong>: 25% of claim, minimum R1000</p>
                                <p><strong>Radio or Specified Accessories (Per Item Claimed)</strong>: 25% of claim, minimum R1000. On factory-fitted radios that are not specified, the Basic Excess will apply where applicable.</p>
                                <p><strong>3.34 Additional Excesses</strong>: Over and above the basic excesses, the following could be applicable depending on different circumstances:</p>
                                <ul>
                                    <li><strong>3.34.1 Non-Code 08/B/EB License</strong>: 10% of claim, minimum R5000</li>
                                    <li><strong>3.34.2 Single Vehicle Accident/Loss</strong>: 10% of claim, minimum R5000</li>
                                    <li><strong>3.34.3 Claim within First 90 Days</strong>: 15% of claim, minimum R5000</li>
                                    <li><strong>3.34.4 Multi-Claimant (Additional Claim in 12 Months)</strong>: 10% of claim, minimum R5000</li>
                                    <li><strong>3.34.5 Write-Off within First 12 Months</strong>: 25% of claim, minimum R10,000</li>
                                    <li><strong>3.34.6 Write-Off after First 12 Months</strong>: 15% of claim, minimum R10,000</li>
                                    <li><strong>3.34.7 Motor Accident between 9 P.M. and 4 A.M.</strong>: 25% of claim, minimum R10,000</li>
                                </ul>
                            <?php } ?>
                            <p><strong>Confirmation</strong>: Do you understand the excess structure outlined above?</p>
                            <div class="row mb-2">
                                <label class="col-md-4 col-form-label">Excess Structure Understanding:</label>
                                <div class="col-md-8">
                                    <select name="excess_understanding" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option value="yes" <?php echo ($responses['3.33_excess_understanding'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($responses['3.33_excess_understanding'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mt-3">
                                <p><strong>3.18</strong> This is an AUTHORISED DRIVER POLICY. Anyone that drives the vehicle must be disclosed to us. Do you understand?</p>
                                <div class="row mb-2">
                                    <label class="col-md-4 col-form-label">Understands Authorised Driver Policy:</label>
                                    <div class="col-md-8">
                                        <select name="understands_authorised_driver" class="form-select" required>
                                            <option value="">Choose...</option>
                                            <option value="yes" <?php echo ($responses['3.18_understands_authorised_driver'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                            <option value="no" <?php echo ($responses['3.18_understands_authorised_driver'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                        </select>
                                    </div>
                                </div>
                                <p><strong>3.20</strong> Has an insurer ever refused to insure you or cancelled a policy?</p>
                                <div class="row mb-2">
                                    <label class="col-md-4 col-form-label">Insurance Refused/Cancelled:</label>
                                    <div class="col-md-8">
                                        <select name="insurance_refused" class="form-select" required>
                                            <option value="">Choose...</option>
                                            <option value="no" <?php echo ($responses['3.20_insurance_refused'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                            <option value="yes" <?php echo ($responses['3.20_insurance_refused'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes (Refer to Management)</option>
                                        </select>
                                    </div>
                                </div>
                                <p><strong>3.21</strong> Have any drivers been insolvent or under debt administration?</p>
                                <div class="row mb-2">
                                    <label class="col-md-4 col-form-label">Insolvency/Debt Administration:</label>
                                    <div class="col-md-8">
                                        <select name="insolvency_status" class="form-select" required>
                                            <option value="">Choose...</option>
                                            <option value="no" <?php echo ($responses['3.21_insolvency_status'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                            <option value="yes" <?php echo ($responses['3.21_insolvency_status'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes (Refer to Management)</option>
                                        </select>
                                    </div>
                                </div>
                                <p><strong>3.22</strong> Have any drivers been convicted of any offence?</p>
                                <div class="row mb-2">
                                    <label class="col-md-4 col-form-label">Criminal Convictions:</label>
                                    <div class="col-md-8">
                                        <select name="criminal_convictions" class="form-select" required>
                                            <option value="">Choose...</option>
                                            <option value="no" <?php echo ($responses['3.22_criminal_convictions'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                            <option value="yes" <?php echo ($responses['3.22_criminal_convictions'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes (Refer to Management)</option>
                                        </select>
                                    </div>
                                </div>
                                <p><strong>3.23</strong> Do any drivers suffer from disabilities or illnesses affecting driving?</p>
                                <div class="row mb-2">
                                    <label class="col-md-4 col-form-label">Disabilities/Illnesses:</label>
                                    <div class="col-md-8">
                                        <select name="disabilities" class="form-select" required>
                                            <option value="">Choose...</option>
                                            <option value="no" <?php echo ($responses['3.23_disabilities'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                            <option value="yes" <?php echo ($responses['3.23_disabilities'] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes (Refer to Management)</option>
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
        // Client-side validation
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