<?php
// File: underwriting_steps/generate_confirmation.php

// Include necessary files
require 'underwriting_common.php';
require 'tcpdf/tcpdf.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    error_log("[DEBUG] Redirect to login.php: No user_id in session");
    ob_end_clean();
    header("Location: ../login_management/login.php");
    exit();
}

// Get policy_id from URL
$policy_id = $_GET['policy_id'] ?? null;
if (!$policy_id || !is_numeric($policy_id)) {
    error_log("[DEBUG] Invalid or missing policy_id in generate_confirmation.php");
    $_SESSION['errors'] = ["Invalid or missing policy ID."];
    ob_end_clean();
    header("Location: ../dashboard.php");
    exit();
}
error_log("[DEBUG] Received policy_id: $policy_id");

// Validate excess_option
$excess_option = $_GET['excess_option'] ?? null;
$valid_options = ['premium6', 'premium5', 'premium4', 'premium_flat'];
if ($excess_option && !in_array($excess_option, $valid_options)) {
    error_log("[DEBUG] Invalid excess_option: $excess_option, defaulting to premium_flat");
    $excess_option = 'premium_flat';
}
error_log("[DEBUG] Received excess_option: " . ($excess_option ?? 'none'));

// Fetch policy data
if (strpos($_SESSION['role_name'], 'Profusion') === 0) {
    error_log("[DEBUG] Using query without restrictions for Profusion role");
    $stmt = $conn->prepare("
        SELECT p.*, q.title, q.initials, q.surname, q.client_id, q.suburb_client, q.postal_code_client, b.brokerage_name
        FROM policies p
        JOIN quotes q ON p.quote_id = q.quote_id
        LEFT JOIN brokerages b ON p.brokerage_id = b.brokerage_id
        WHERE p.policy_id = ?
    ");
    $stmt->bind_param("i", $policy_id);
} else {
    error_log("[DEBUG] Using query with user_id filter for role: " . ($_SESSION['role_name'] ?? 'Not set'));
    $stmt = $conn->prepare("
        SELECT p.*, q.title, q.initials, q.surname, q.client_id, q.suburb_client, q.postal_code_client, b.brokerage_name
        FROM policies p
        JOIN quotes q ON p.quote_id = q.quote_id
        LEFT JOIN brokerages b ON p.brokerage_id = b.brokerage_id
        WHERE p.policy_id = ? AND (p.user_id = ? OR ? IN (SELECT user_id FROM users WHERE brokerage_id = p.brokerage_id))
    ");
    $stmt->bind_param("iii", $policy_id, $_SESSION['user_id'], $_SESSION['user_id']);
}
$stmt->execute();
$policy_result = $stmt->get_result();
if ($policy_result->num_rows === 0) {
    error_log("[DEBUG] No policy found for policy_id=$policy_id for user_id={$_SESSION['user_id']}");
    $_SESSION['errors'] = ["No active policy found for this policy ID."];
    ob_end_clean();
    header("Location: ../dashboard.php");
    exit();
}
$policy_data = $policy_result->fetch_assoc();
$quote_id = $policy_data['quote_id'];
if (!$quote_id || !is_numeric($quote_id)) {
    error_log("[DEBUG] Invalid or missing quote_id in policy data for policy_id=$policy_id");
    $_SESSION['errors'] = ["Invalid or missing quote ID in policy data."];
    ob_end_clean();
    header("Location: ../dashboard.php");
    exit();
}
// Verify quote_id exists in quotes table
$quote_check_stmt = $conn->prepare("SELECT quote_id FROM quotes WHERE quote_id = ?");
$quote_check_stmt->bind_param("i", $quote_id);
$quote_check_stmt->execute();
$quote_check_result = $quote_check_stmt->get_result();
if ($quote_check_result->num_rows === 0) {
    error_log("[DEBUG] No quote found for quote_id=$quote_id for policy_id=$policy_id");
    $_SESSION['errors'] = ["No quote found for the associated quote ID."];
    ob_end_clean();
    header("Location: ../dashboard.php");
    exit();
}
$quote_check_stmt->close();
$stmt->close();
error_log("[DEBUG] Fetched policy data for policy_id=$policy_id, quote_id=$quote_id: " . print_r($policy_data, true));

// Fetch vehicle and driver data
$vehicles = [];
$vehicle_stmt = $conn->prepare("
    SELECT qv.*, qd.driver_initials, qd.driver_surname, qd.dob
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
    $chassis_key = "3.4_chassis_number_$index";
    $engine_key = "3.4_engine_number_$index";
    $finance_key = "3.5_finance_house_$index";
    $owner_key = "3.6_registered_owner_name_$index";
    $use_key = "3.9_vehicle_use_$index";
    $security_key = "3.29_security_device_$index";
    $underwriting_stmt = $conn->prepare("
        SELECT question_key, response
        FROM policy_underwriting_data
        WHERE policy_id = ? AND section = 'motor_section' AND question_key IN (?, ?, ?, ?, ?, ?)
    ");
    $underwriting_stmt->bind_param("issssss", $policy_id, $chassis_key, $engine_key, $finance_key, $owner_key, $use_key, $security_key);
    $underwriting_stmt->execute();
    $underwriting_result = $underwriting_stmt->get_result();
    $underwriting_data = [];
    while ($row = $underwriting_result->fetch_assoc()) {
        $underwriting_data[$row['question_key']] = $row['response'];
    }
    $underwriting_stmt->close();
    error_log("[DEBUG] Underwriting data for vehicle_id={$vehicle['vehicle_id']}, index=$index: " . print_r($underwriting_data, true));

    $vehicles[] = [
        'vehicle' => $vehicle,
        'driver' => [
            'driver_initials' => $vehicle['driver_initials'] ?? 'N/A',
            'driver_surname' => $vehicle['driver_surname'] ?? 'N/A',
            'dob' => $vehicle['dob'] ?? null
        ],
        'chassis_number' => $underwriting_data[$chassis_key] ?? 'N/A',
        'engine_number' => $underwriting_data[$engine_key] ?? 'N/A',
        'finance_house' => $underwriting_data[$finance_key] ?? 'N/A',
        'registered_owner' => $underwriting_data[$owner_key] ?? 'N/A',
        'vehicle_use' => $underwriting_data[$use_key] ?? 'private',
        'security_device' => $underwriting_data[$security_key] ?? 'tracker'
    ];
    $index++;
}
$vehicle_stmt->close();
if (empty($vehicles)) {
    error_log("[DEBUG] No vehicles found for quote_id=$quote_id");
    $_SESSION['errors'] = ["No vehicles found for this policy."];
    ob_end_clean();
    header("Location: ../dashboard.php");
    exit();
}
error_log("[DEBUG] Fetched vehicles for policy_id=$policy_id: " . print_r($vehicles, true));

// Fetch consultant name
$consultant_stmt = $conn->prepare("SELECT username FROM users WHERE user_id = ?");
$consultant_stmt->bind_param("i", $_SESSION['user_id']);
$consultant_stmt->execute();
$user_result = $consultant_stmt->get_result();
$consultant = $user_result->num_rows > 0 ? $user_result->fetch_assoc() : ['username' => 'Unknown'];
$consultant_stmt->close();
error_log("[DEBUG] Fetched consultant name for user_id={$_SESSION['user_id']}: " . print_r($consultant, true));

class CustomTCPDF extends TCPDF {
    public function Header() {
        // Set position and font for header content
        $this->Image('../images/logo.png', 1, 1, 100, '', 'PNG');
        $this->SetXY(3, 3);
        $this->SetFont('helvetica', '', 6);
        $this->Write(3, 'Tel: 010 502 1923', '', 0, 'R');
        $this->Ln(3);
        $this->Write(3, 'Physical Address: 17 Smith Road, Bedfordview, 2007', '', 0, 'R');
        $this->Ln(3);
        $this->Write(3, 'Profusion Underwriting Managers is an authorized FSP – FSP no 53071', '', 0, 'R');
        $this->Ln(3);
        $this->Write(3, 'Reg No – 2020/803373/07 Director’s – P Kruger, F.S. Angiers', '', 0, 'R');
        $this->Ln(10);
    }
}

// Initialize TCPDF
$pdf = new CustomTCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('Profusion Underwriting Managers');
$pdf->SetTitle('Confirmation of Insurance');
$pdf->SetSubject('Policy Confirmation');
$pdf->SetMargins(10, 30, 10);
$pdf->SetAutoPageBreak(true, 10);
$pdf->AddPage();

// Set font
$pdf->SetFont('helvetica', '', 10);

// Header
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Write(0, 'Confirmation of Insurance', '', 0, 'C');
$pdf->Ln(10);
$pdf->SetFont('helvetica', '', 10);
$pdf->Write(0, date('d/m/Y'), '', 0, 'R');
$pdf->Ln(10);
$pdf->Write(0, "Re: Confirmation of Insurance Cover Policy No: PF{$policy_id}", '', 0, 'L');
$pdf->Ln(10);
$pdf->Write(0, 'This document serves to confirm that the following risk is insured on the abovementioned policy number:', '', 0, 'L');
$pdf->Ln(5);

// Policy Information
$pdf->SetFillColor(112, 48, 160);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(190, 10, 'Policy Information', 1, 1, 'C', 1);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', 10);
$policy_info = [
    'Profusion Consultant' => ucwords(strtolower($consultant['username'])),
    'Policy Holder' => ucwords(strtolower("{$policy_data['title']} {$policy_data['initials']} {$policy_data['surname']}")),
    'Policy Holder ID Number' => $policy_data['client_id'], // Numeric, no change
    'Policy Administrator' => 'Profusion Underwriting Managers', // Static, no change
    'Policy Number' => "PF{$policy_id}", // Contains ID, no change
    'Registered Owner' => ucwords(strtolower($policy_data['account_holder'] ?? ($policy_data['title'] . ' ' . $policy_data['initials'] . ' ' . $policy_data['surname']))),
    'Regular Driver' => !empty($vehicles) ? ucwords(strtolower("{$vehicles[0]['driver']['driver_initials']} {$vehicles[0]['driver']['driver_surname']}")) : 'N/A',
    'Policy Start Date' => date('d/m/Y', strtotime($policy_data['policy_start_date'])), // Date, no change
    'Policy Premium' => "R " . number_format($policy_data['premium_amount'], 2), // Number, no change
    'Risk Address' => !empty($vehicles) ? ucwords(strtolower("{$vehicles[0]['vehicle']['street']}, {$vehicles[0]['vehicle']['suburb_vehicle']}, {$vehicles[0]['vehicle']['postal_code']}")) : 'N/A',
    'Broker' => ucwords(strtolower($policy_data['brokerage_name'] ?? 'N/A'))
];
foreach ($policy_info as $key => $value) {
    $pdf->Cell(95, 8, $key, 1, 0, 'L');
    $pdf->Cell(95, 8, $value, 1, 1, 'R');
}
$pdf->Ln(5);

// Insured Items
$pdf->SetFillColor(112, 48, 160);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(190, 10, 'Insured Items', 1, 1, 'C', 1);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', 10);
$pdf->Ln(5);

foreach ($vehicles as $index => $vehicle_data) {
    // Estimate table height (Motor header + 9 rows of details)
    $header_height = 10; // Motor header is 10 mm
    $row_height = 8; // Each detail row is 8 mm
    $num_rows = 9; // Number of rows in vehicle_info
    $table_height = $header_height + ($num_rows * $row_height); // 10 + (9 * 8) = 82 mm
    $current_y = $pdf->GetY();
    $page_bottom = $pdf->getPageHeight() - $pdf->getBreakMargin();

    // Check if table fits on current page
    if ($current_y + $table_height > $page_bottom) {
        $pdf->AddPage();
    }

    // Start transaction
    $pdf->startTransaction();
    $start_y = $pdf->GetY();

    // Render Motor header
    $pdf->SetFillColor(112, 48, 160);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(190, 10, 'Motor', 1, 1, 'C', 1);

    // Render vehicle details
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 10);
    $vehicle = $vehicle_data['vehicle'];
    $vehicle_info = [
        'Motor Vehicle Description' => trim("{$vehicle['vehicle_year']} {$vehicle['vehicle_make']} {$vehicle['vehicle_model']}"),
        'Insured Value' => "R " . number_format($vehicle['vehicle_value'], 2),
        'Registration Number' => 'TBA',
        'Vin Number' => strtoupper($vehicle_data['chassis_number']),
        'Engine Number' => strtoupper($vehicle_data['engine_number']),
        'Type Of Cover' => 'Comprehensive (Excluding Mechanical Breakdown)',
        'Use of Vehicle' => ucwords(strtolower($vehicle_data['vehicle_use'])),
        'Security Requirements' => ucwords(strtolower($vehicle_data['security_device'])),
        'Policy Inception Date' => date('d/m/Y', strtotime($policy_data['policy_start_date']))
    ];
    foreach ($vehicle_info as $key => $value) {
        $pdf->Cell(95, 8, $key, 1, 0, 'L');
        $pdf->Cell(95, 8, $value, 1, 1, 'R');
    }

    // Verify table didn't split (safety check)
    $end_y = $pdf->GetY();
    if ($end_y > $page_bottom) {
        // Table split unexpectedly, roll back and start new page
        $pdf->rollbackTransaction(true);
        $pdf->AddPage();
        // Redraw the table
        $pdf->SetFillColor(112, 48, 160);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(190, 10, 'Motor', 1, 1, 'C', 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 10);
        foreach ($vehicle_info as $key => $value) {
            $pdf->Cell(95, 8, $key, 1, 0, 'L');
            $pdf->Cell(95, 8, $value, 1, 1, 'R');
        }
    } else {
        // Table fits, commit the transaction
        $pdf->commitTransaction();
    }
    // Add spacing after each vehicle table
    $pdf->Ln(5);
}

// Finance Noting of Interest
$pdf->SetFillColor(112, 48, 160);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(190, 10, 'Finance Noting of Interest', 1, 1, 'C', 1);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', 10);
$finance_house = !empty($vehicles) && $vehicles[0]['finance_house'] !== 'N/A' ? $vehicles[0]['finance_house'] : 'N/A';
$pdf->Cell(95, 8, 'Finance Institution', 1, 0, 'L');
$pdf->Cell(95, 8, htmlspecialchars($finance_house), 1, 1, 'R');
$pdf->Ln(5);

// Fetch excess_label dynamically from policy_underwriting_data or URL
$premium_type = $excess_option ?? null;
if (!$premium_type) {
    $underwriting_stmt = $conn->prepare("
        SELECT response
        FROM policy_underwriting_data
        WHERE policy_id = ? AND section = 'bank_details_mandate' AND question_key = '4.1_product_type'
    ");
    $underwriting_stmt->bind_param("i", $policy_id);
    $underwriting_stmt->execute();
    $underwriting_result = $underwriting_stmt->get_result();
    $premium_type = $underwriting_result->num_rows > 0 ? $underwriting_result->fetch_assoc()['response'] : ($policy_data['premium_type'] ?? 'premium_flat');
    $underwriting_stmt->close();
}
error_log("[DEBUG] Using premium_type: $premium_type for policy_id=$policy_id");

$excess_label = [
    'premium6' => '6% of sum insured, minimum R5000',
    'premium5' => '5% of sum insured, minimum R5000',
    'premium4' => '4% of sum insured, minimum R5000',
    'premium_flat' => 'Flat R3500'
][$premium_type] ?? 'Flat R3500';

// Motor Vehicle Excess Structure
$pdf->SetFillColor(112, 48, 160);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(190, 10, 'Motor Vehicle Excess Structure', 1, 1, 'C', 1);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', 10);
$excess_structure = [
    ['description' => 'Basic Excess', 'value' => $excess_label],
];
foreach ($excess_structure as $excess) {
    $pdf->Cell(95, 8, $excess['description'], 1, 0, 'L');
    $pdf->Cell(95, 8, $excess['value'], 1, 1, 'R');
}
$pdf->Cell(190, 8, 'Please note that additional excesses may apply, refer to your policy schedule.', 1, 1, 'L');

// Inspection Requirements
$pdf->SetFont('helvetica', '', 10);
// Start transaction
$pdf->startTransaction();
$start_y = $pdf->GetY();

// Check if content will fit (estimate height: content ~60mm)
$estimated_height = 60; // Approximate height in mm (adjust if needed after testing)
$page_bottom = $pdf->getPageHeight() - $pdf->getBreakMargin();
if ($start_y + $estimated_height > $page_bottom) {
    $pdf->AddPage();
}

// Render content with writeHTML, including heading and border
$html = <<<EOD
<table cellpadding="3">
    <tr>
        <td style="padding: 1mm 2mm 2mm 2mm;">
            <p style="margin: 0; font-weight: bold; font-size: 12pt; color: black;">Inspection Requirements</p>
            <p style="margin: 0 0 1mm 0;">You are required to take your vehicle for an inspection at any <b>PG Glass or Glassfit</b> outlet and ask them to give you an inspection certificate for your vehicle and send it through to us.</p>
            <p style="margin: 0 0 1mm 0;">Alternatively, a self-inspection can be completed and submitted to <u>clientcare@profusionum.com</u>.</p>
            <p style="margin: 0 0 1mm 0; font-weight: bold;">Self-inspection guidelines:</p>
            <ul style="margin: 0 0 1mm 0;">
                <li>Photos of the left and right side of the vehicle, where the entire vehicle, including the wheels, is visible.</li>
                <li>Photos of the back and front of the vehicle, where the entire vehicle, including the wheels and roof, is visible.</li>
                <li>A photo of the front of the vehicle, with the bonnet open and the engine visible, including the number plate.</li>
                <li>A photo of the license disc currently on the vehicle. Send a new photo when the new disc is fitted.</li>
                <li>A photo of the odometer with the vehicle turned on and dashlights illuminated.</li>
                <li>A photo of the back and front of the driver’s license of the person driving the vehicle most often.</li>
            </ul>
            <p style="margin: 0;">You have 24 hours to complete the inspection from the start date of your policy. If not inspected within 24 hours, your cover will be restricted to third party cover only until the vehicle is inspected.</p>
        </td>
    </tr>
</table>
EOD;
$pdf->writeHTML($html, true, false, true, false, '');

// Check if content fits on the current page
$end_y = $pdf->GetY();
if ($end_y > $page_bottom) {
    // Content would split, roll back and start a new page
    $pdf->rollbackTransaction(true);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 10);
    $pdf->writeHTML($html, true, false, true, false, '');
} else {
    // Content fits, commit the transaction
    $pdf->commitTransaction();
}
$pdf->Ln(5);

// Important Notes
$pdf->AddPage();
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(190, 10, 'IMPORTANT NOTES (Please read the following notes):', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 10);
$html = <<<EOD
<ol>
    <li>This confirmation of Insurance is subject to the terms, conditions and requirements of the Insurance Company’s Contract of Insurance.</li>
    <li>We acknowledge notification that the above financial institution has financial interest in the property covered under the above policy. However, we do not commit to notify said company of all amendments and claims that may possibly arise, except in the instance where the vehicle is written off or cannot be recovered in the event of a theft/hijacking incident. Further, we do not accept liability whatsoever for any omissions on our part to rendering such a service and neither the terms nor the conditions of the policy are deemed to be in any way affected.</li>
    <li>Debit Orders:
        <ol type="a">
            <li>Your Normal debit order date will be on the <b>{$policy_data['debit_date']}th</b> of every month.</li>
            <li>This also serves to confirm that you have given Profusion Underwriters the authority to perform monthly debits on your account. Should your bank require you to produce proof of cover in order for them to allow our debit orders to go off, this confirmation will carry sufficient authority for them to do so.</li>
            <li>If there are three (3) consecutive Returned Debits on the policy, the policy will be cancelled without the possibility of reinstatement and you will be notified by sms on the cellphone numbers you have provided.</li>
            <li>If a premium is unpaid, the onus rests on the insured to make arrangements for a re-debit to take place within 15 days of the unpaid debit order date.</li>
            <li>If this is not done, the cover will lapse for that financial period and the inception date of the insurance will be adjusted to the first of the following month.</li>
            <li>If the bank account details change we require at least five (5) days notice prior to the next debit order date. Notice can be given in writing and emailed to clientcare@profusionum.com or by phoning us on 010 502 1923.</li>
        </ol>
    </li>
    <li>Ad-hoc fees: If an ad-hoc fee is disclosed by the underwriting consultant, this admin fee will be payable within 72 hours. Ad-hoc fees are nonrefundable.</li>
    <li>Cancellation process:
        <ol type="a">
            <li>If you wish to cancel this policy we require written notice of cancellation received via your broker (details noted above). We will only cancel upon consent from your broker.</li>
            <li>Upon receipt of the cancellation letter from your broker, a thirty (30) day notice period will be served. Should your debit order date fall within this thirty (30) day notice period, the premium will be deducted accordingly.</li>
            <li>Any written notice must contain your policy/id number, effective date of cancellation and a reason for cancellation. If any of the above mentioned information is omitted from the letter, the notice will become null and void and the policy will carry on as per agreement.</li>
        </ol>
    </li>
    <li>Policy schedule: Policy schedules should be received via e-mail within 7 days subject to the correct contact details being disclosed. If you do not receive your policy schedule within this specified time frame, the onus will fall on the insured to notify Profusion Underwriting Managers of this.</li>
    <li>Minimum Security Requirements:
        <ol type="a">
            <li>If stated on the confirmation of insurance above that the vehicle requires a tracking device this is regarded as the minimum requirement. If this requirement is not met there will be NO COVER until the tracking device is fitted and active.</li>
            <li>We require the proof of Installation of the tracking device in order to validate that the device has been fitted in the event of a claim.</li>
        </ol>
    </li>
    <li>Car hire: Please note that car hire is not issued immediately upon submission of a claim, it is subject to the receipt of all the relevant claims documents. Once the appointed assessor has evaluated the claim and the claim has been authorized, the car hire benefit will commence for a period of 25 consecutive days. Please note that hired vehicles may not be driven on gravel roads.</li>
    <li>Exclusions for contraventions of South-African Road Traffic laws: No claim under this policy shall be payable for any loss, damage, or liability arising from or in connection with any act or omission by the insured that constitutes a breach of any applicable South-African Road Traffic laws as set out in South-African National Road Traffic Act 93, of 1996, or regulations. This exclusion applies irrespective of whether such breach was inadvertent or deliberate.</li>
</ol>
EOD;
$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Ln(5);

// Start a new page for Motor Excess Section
$pdf->AddPage();
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Write(0, 'Profusion Nucleus Motor Excess', '', 0, 'C');
$pdf->Ln(5);
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(5);

// Determine pensioner status
$is_pensioner = false;
if (!empty($vehicles) && !empty($vehicles[0]['driver']['dob'])) {
    $dob = new DateTime($vehicles[0]['driver']['dob']);
    $today = new DateTime();
    $age = $today->diff($dob)->y;
    $is_pensioner = $age > 55;
}
error_log("[DEBUG] Driver DOB: " . ($vehicles[0]['driver']['dob'] ?? 'none') . ", Is Pensioner: " . ($is_pensioner ? 'Yes' : 'No'));

// Motor Basic Excess
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetFillColor(112, 48, 160);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(190, 10, 'MOTOR BASIC EXCESS', 1, 1, 'C', 1);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', 10);

$html = <<<EOD
<p>The most relevant basic excess will be applicable in the event of any loss or event that results in claim being submitted.</p>
<p><b>MOTOR PRIVATE USE</b></p>
<p>Use of your vehicle for social and domestic purposes, including driving between your home and regular place of work. Use as stated on your schedule.</p>
<table border="1" cellpadding="4">
    <tr><td width="60%"><b>Motor Private Basic Excess</b></td><td width="40%" style="text-align: right;">$excess_label</td></tr>
</table>
<p><b>MOTOR BUSINESS USE (IF BUSINESS USE IS SELECTED)</b></p>
<p>If in addition to private use, your vehicle is used for business and professional purposes. Please note that if your vehicle is being used for business use and we have not been informed, there will be <b>NO COVER.</b></p>
<table border="1" cellpadding="4">
    <tr><td width="60%"><b>Motor Business Use Excess (Additional to Basic excess)</b></td><td width="40%" style="text-align: right;">1% of sum insured minimum R1000</td></tr>
    <tr><td width="60%"><b>Windscreen/Fixed Glass</b></td><td width="40%" style="text-align: right;">25% of claim minimum R1000</td></tr>
    <tr><td width="60%"><b>Radio</b></td><td width="40%" style="text-align: right;">10% of claim with minimum R1000</td></tr>
</table>
<p>If the Radio is specified above excess is applicable, on factory fitted radio’s that are not specified Basic Excess A & B apply where applicable.</p>
EOD;

if ($is_pensioner) {
    $html .= <<<EOD
<p>Any Pensioner will not have any basic excess (Accident/Damage or Theft/Hijacking), but will be liable for additional excesses where applicable.</p>
EOD;
}

$html .= <<<EOD
<p><b>PLEASE NOTE:</b></p>
<ul>
    <li>Where immobilizer requirement is NOT adhered to: <b>No Theft Cover</b></li>
    <li>If stated on the confirmation of insurance above that the vehicle requires a tracking device this is regarded as the minimum requirement. If this requirement is not met there will be <b>NO COVER</b> until the tracking device is fitted and active.</li>
</ul>
<p><b>MOTOR ADDITIONAL EXCESS</b></p>
<p>Additional Excess <b>MAY</b> apply depending on the events around the claim and where applicable.</p>
<table border="1" cellpadding="4">
    <tr><td width="60%"><strong>Single vehicle accident/Loss</strong></td><td width="40%" style="text-align: right;">10% of claim minimum R5000</td></tr>
    <tr><td width="60%"><strong>License code other than 8/B/EB</strong></td><td width="40%" style="text-align: right;">10% of claim minimum R5000</td></tr>
    <tr><td width="60%"><strong>Claim within the first 90 days from inception date</strong></td><td width="40%" style="text-align: right;">15% of claim minimum R5000</td></tr>
    <tr><td width="60%"><strong>Accident/Write-off/Total Loss within the first 12 months from inception</strong></td><td width="40%" style="text-align: right;">25% of claim minimum R10000</td></tr>
    <tr><td width="60%"><strong>Accident/Write-off/Total Loss after the first 12 months from inception</strong></td><td width="40%" style="text-align: right;">15% of claim, minimum R10000</td></tr>
    <tr><td width="60%"><strong>Accident/Write-off/Total Loss between the hours of 9P.M. and 4A.M.</strong></td><td width="40%" style="text-align: right;">25% of the claim minimum R10000</td></tr>
</table>
<p>Should you have any queries, please contact Profusion.</p>
<p><b>Regards,</b><br>{$consultant['username']}<br><b>Profusion Underwriting Managers</b></p>
EOD;
$pdf->writeHTML($html, true, false, true, false, '');

// Output PDF
ob_end_clean();
$pdf->Output("Confirmation_of_Insurance_PF{$policy_id}.pdf", 'D');
$conn->close();
?>