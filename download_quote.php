<?php
ob_start(); // Start output buffering
session_start();

// Suppress errors in production
ini_set('display_errors', 0);
error_reporting(0);

if (!isset($_SESSION['user_id'])) {
    error_log("[DEBUG] Redirect to login.php: No user_id in session");
    ob_end_clean();
    header("Location: login_management/login.php");
    exit();
}

// Log session details
error_log("[DEBUG] User ID: " . $_SESSION['user_id'] . ", Role: " . ($_SESSION['role_name'] ?? 'Not set') . ", Brokerage ID: " . ($_SESSION['brokerage_id'] ?? 'Not set'));

require 'db_connect.php';
require 'tcpdf/tcpdf.php';
require 'quote_commit_management/quote_calculator.php'; // Include for fallback fee calculation and getSecurityRequirements

// Validate logo file
$logo_path = 'images/logo.png';
if (!file_exists($logo_path)) {
    error_log("[DEBUG] Logo file missing: $logo_path");
    ob_end_clean();
    header("Location: dashboard.php?error=logo_missing");
    exit();
}

// Validate quote_id
if (!isset($_GET['quote_id'])) {
    error_log("[DEBUG] Redirect to dashboard.php: Missing quote_id");
    ob_end_clean();
    header("Location: dashboard.php");
    exit();
}

$quote_id = intval($_GET['quote_id']);
$excess_option = $_GET['excess_option'] ?? 'premium_flat';
$valid_options = ['premium6', 'premium5', 'premium4', 'premium_flat'];
if (!in_array($excess_option, $valid_options)) {
    error_log("[DEBUG] Invalid excess_option: $excess_option, defaulting to premium_flat");
    $excess_option = 'premium_flat';
}

// Log received parameters
error_log("[DEBUG] Received quote_id: $quote_id, excess_option: $excess_option");

// Check if user is authorized to view discount details
$is_authorized = in_array($_SESSION['role_name'], ['Profusion SuperAdmin', 'Profusion Manager', 'Profusion Consultant']);

// Fetch quote
$brokerage_roles = ['Profusion SuperAdmin', 'Broker Admin', 'Broker Consultant', 'Profusion Manager', 'Broker Manager', 'Profusion Consultant'];
if (strpos($_SESSION['role_name'], 'Profusion') === 0) {
    error_log("[DEBUG] Using query without restrictions for Profusion role");
    $stmt = $conn->prepare("SELECT *, fees FROM quotes WHERE quote_id = ?");
    $stmt->bind_param("i", $quote_id);
} elseif (in_array($_SESSION['role_name'] ?? '', $brokerage_roles) && isset($_SESSION['brokerage_id'])) {
    error_log("[DEBUG] Using query with brokerage_id filter for role: " . $_SESSION['role_name']);
    $stmt = $conn->prepare("SELECT *, fees FROM quotes WHERE quote_id = ? AND brokerage_id = ?");
    $stmt->bind_param("ii", $quote_id, $_SESSION['brokerage_id']);
} else {
    error_log("[DEBUG] Using query with user_id filter for role: " . ($_SESSION['role_name'] ?? 'Not set'));
    $stmt = $conn->prepare("SELECT *, fees FROM quotes WHERE quote_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $quote_id, $_SESSION['user_id']);
}
$stmt->execute();
$result = $stmt->get_result();
$quote = $result->fetch_assoc();

// Log query result
error_log("[DEBUG] Query result rows: " . $result->num_rows);
if ($quote) {
    error_log("[DEBUG] Quote found: quote_id=$quote_id, client_id=" . ($quote['client_id'] ?? 'N/A') . ", user_id=" . ($quote['user_id'] ?? 'N/A') . ", brokerage_id=" . ($quote['brokerage_id'] ?? 'N/A'));
} else {
    error_log("[DEBUG] Quote not found for quote_id=$quote_id, user_id=" . ($_SESSION['user_id'] ?? 'Not set') . ", brokerage_id=" . ($_SESSION['brokerage_id'] ?? 'Not set'));
}

$stmt->close();

if (!$quote) {
    error_log("[DEBUG] Redirect to dashboard.php: Quote not found");
    ob_end_clean();
    header("Location: dashboard.php?error=" . urlencode("Quote ID $quote_id not found. Contact support if this persists."));
    exit();
}

// Fetch all vehicles and drivers
$vehicles = [];
$stmt = $conn->prepare("SELECT * FROM quote_vehicles WHERE quote_id = ? AND deleted_at IS NULL");
$stmt->bind_param("i", $quote_id);
$stmt->execute();
$vehicle_result = $stmt->get_result();
while ($vehicle = $vehicle_result->fetch_assoc()) {
    $driver_stmt = $conn->prepare("SELECT * FROM quote_drivers WHERE vehicle_id = ? AND quote_id = ? AND deleted_at IS NULL");
    $driver_stmt->bind_param("ii", $vehicle['vehicle_id'], $quote_id);
    $driver_stmt->execute();
    $driver_result = $driver_stmt->get_result();
    $driver = $driver_result->num_rows > 0 ? $driver_result->fetch_assoc() : [];
    $driver_stmt->close();
    $vehicles[] = [
        'vehicle' => $vehicle,
        'driver' => $driver
    ];
}
$stmt->close();

// Log vehicles data
error_log("[DEBUG] Fetched vehicles: " . print_r($vehicles, true));

if (empty($vehicles)) {
    error_log("[DEBUG] No vehicles found for quote_id=$quote_id");
    ob_end_clean();
    header("Location: dashboard.php?error=" . urlencode("No vehicles found for Quote ID $quote_id."));
    exit();
}

// Load premiums and fees
$premiums = [
    'premium6' => $quote['premium6'] ?? 0,
    'premium5' => $quote['premium5'] ?? 0,
    'premium4' => $quote['premium4'] ?? 0,
    'premium_flat' => $quote['premium_flat'] ?? 0
];

// Define car hire costs
$car_hire_costs = [
    'None' => 0.00,
    'Group B Manual Hatchback' => 85.00,
    'Group C Manual Sedan' => 95.00,
    'Group D Automatic Hatchback' => 110.00,
    'Group H 1 Ton LDV' => 130.00,
    'Group M Luxury Hatchback' => 320.00
];

// Calculate total car hire fee
$car_hire_total = 0.00;
foreach ($vehicles as $vehicle_data) {
    $car_hire = $vehicle_data['vehicle']['car_hire'] ?? '';
    if (isset($car_hire_costs[$car_hire])) {
        $car_hire_total += $car_hire_costs[$car_hire];
    }
}

// Prefer session breakdown for fees if available
$breakdown = isset($_SESSION['quote_breakdown']) ? $_SESSION['quote_breakdown'] : [];
$fees = isset($breakdown['policy_fees']) && is_array($breakdown['policy_fees']) ? $breakdown['policy_fees'] : [];
if (empty($fees)) {
    // Fallback to database fees
    $fees = isset($quote['fees']) ? json_decode($quote['fees'], true) : [];
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($fees)) {
        error_log("[DEBUG] Invalid fees JSON in database for quote_id=$quote_id, recalculating fees");
        // Recalculate fees using quote_calculator.php logic
        $saved_vehicles = [];
        foreach ($vehicles as $vehicle_data) {
            $vehicle = $vehicle_data['vehicle'];
            $driver = $vehicle_data['driver'];
            $saved_vehicles[] = [
                'vehicle' => [
                    'value' => floatval($vehicle['vehicle_value']),
                    'make' => $vehicle['vehicle_make'],
                    'model' => $vehicle['vehicle_model'],
                    'use' => $vehicle['vehicle_use'],
                    'parking' => $vehicle['parking'],
                    'car_hire' => $vehicle['car_hire'],
                    'discount_percentage' => floatval($vehicle['discount_percentage'] ?? 0.0)
                ],
                'driver' => [
                    'age' => intval($driver['age'] ?? 30),
                    'licence_type' => $driver['licence_type'] ?? 'B',
                    'licence_held' => intval($driver['licence_held'] ?? 0),
                    'marital_status' => $driver['driver_marital_status'] ?? 'Single',
                    'ncb' => $driver['ncb'] ?? '0',
                    'title' => $driver['driver_title'] ?? 'Mr'
                ]
            ];
        }
        $calculation = calculateQuote($conn, $quote['brokerage_id'] ?? null, $saved_vehicles, $quote['waive_broker_fee'] ?? 0);
        $fees = $calculation['breakdown']['policy_fees'];
    }
}

// Override car_hire fee with calculated total
$fees['car_hire'] = $car_hire_total;
$total_fees = array_sum($fees);

// Log premiums and fees
error_log("[DEBUG] Database premiums: quote_id=$quote_id, premium6={$premiums['premium6']}, premium5={$premiums['premium5']}, premium4={$premiums['premium4']}, premium_flat={$premiums['premium_flat']}");
error_log("[DEBUG] Fees: " . json_encode($fees));

// Excess calculations
$excesses = [];
$excess_labels = [];
foreach ($vehicles as $index => $vehicle_data) {
    $vehicle_value = floatval($vehicle_data['vehicle']['vehicle_value'] ?? 0);
    $excesses[$index] = [
        'premium6' => $vehicle_value ? max(0.06 * $vehicle_value, 5000) : 0,
        'premium5' => $vehicle_value ? max(0.05 * $vehicle_value, 5000) : 0,
        'premium4' => $vehicle_value ? max(0.04 * $vehicle_value, 5000) : 0,
        'premium_flat' => 3500
    ];
    $excess_labels[$index] = [
        'premium6' => '6% OF SUM INSURED MIN R5000.00',
        'premium5' => '5% OF SUM INSURED MIN R5000.00',
        'premium4' => '4% OF SUM INSURED MIN R5000.00',
        'premium_flat' => 'FLAT R3500.00'
    ];
}
$labels = [
    'premium6' => 'Premium 6',
    'premium5' => 'Premium 5',
    'premium4' => 'Premium 4',
    'premium_flat' => 'Premium Flat'
];

// Selected premium details
$selected_premium = $premiums[$excess_option];
$selected_excesses = array_column($excesses, $excess_option);
$selected_excess_labels = array_column($excess_labels, $excess_option);

// Excess buyback included in premium_flat
$excess_buyback = $excess_option === 'premium_flat' ? 195 : 0;

// Compute security output
$security_outputs = [];
foreach ($vehicles as $index => $vehicle_data) {
    $vehicle = $vehicle_data['vehicle'];
    $security = getSecurityRequirements($vehicle['vehicle_make'], $vehicle['vehicle_model'], floatval($vehicle['vehicle_value']));
    $security_outputs[$index] = htmlspecialchars(ucwords(strtolower($security)));
}

// Create PDF
class CustomPDF extends TCPDF {
    private $quote_id;
    public function __construct($quote_id) {
        parent::__construct(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $this->quote_id = $quote_id;
    }
    public function Header() {
        $this->Image('images/logo.png', 15, 5, 50, '', 'PNG', '', 'T', false, 400, '', false, false, 0);
        $this->SetY(5);
        $this->SetFont('helvetica', 'B', 14);
        $this->Cell(0, 10, 'Profusion Underwriting Managers Quote - ID: ' . $this->quote_id, 0, 1, 'R');
        $this->SetDrawColor(106, 13, 173);
        $this->Line(15, 25, 195, 25);
    }
    public function Footer() {
        $this->SetY(-20);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Profusion Underwriting Managers (Pty) Ltd | www.profusionum.com | +27 10 502 1923 | FSP:53071', 0, 0, 'C');
        $this->Cell(0, 10, 'PAGE ' . $this->getPage(), 0, 0, 'R');
    }
}

$pdf = new CustomPDF($quote_id);
$pdf->SetCreator('Profusion Underwriting Managers (Pty) Ltd');
$pdf->SetAuthor('Profusion Underwriting Managers (Pty) Ltd');
$pdf->SetTitle("Insurance Quote ID {$quote_id}");
$pdf->SetMargins(15, 30, 15);
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(10);
$pdf->SetAutoPageBreak(true, 25);
$pdf->setFont('helvetica', '', 10);

// Content Page
$pdf->AddPage();
$html = '
<style>
    h1 { color: #6A0DAD; text-align: center; font-size: 12px; }
    h2 { font-size: 11px; color: white; text-align: center; background-color: #6A0DAD; }
    h3 { font-size: 10px; color: white; text-align: center; background-color: #6A0DAD; }
    h4 { font-size: 10px; color: white; text-align: center; background-color: #6A0DAD; page-break-before: always; }
    .card { border: 1px solid #6A0DAD; background-color: #F3F3F3; }
    table { width: 100%; font-size: 9px; border-collapse: collapse; }
    th { background-color: #6A0DAD; color: white; font-weight: bold; text-align: left; }
    td { white-space: nowrap; }
    tr:nth-child(even) { background-color: #F3F3F3; }
    .label { width: 60%; font-weight: bold; color: #333; display: block; }
    .value { width: 40%; }
    p { font-size: 9px; }
    .heading { background-color: #6A0DAD; font-weight: bold; text-align: center; color: white; display: block; }
</style>

<h1>INSURANCE QUOTE DETAILS</h1>

<h2>CLIENT INFORMATION</h2>
<div class="card">
    <table border="0" cellpadding="6">
        <tbody>
            <tr><td class="label">TITLE:</td><td class="value">' . htmlspecialchars(ucwords(strtolower($quote['title'] ?? 'N/A'))) . '</td></tr>
            <tr><td class="label">INITIALS:</td><td class="value">' . htmlspecialchars(ucwords(strtolower($quote['initials'] ?? 'N/A'))) . '</td></tr>
            <tr><td class="label">SURNAME:</td><td class="value">' . htmlspecialchars(ucwords(strtolower($quote['surname'] ?? 'N/A'))) . '</td></tr>
            <tr><td class="label">CLIENT ID:</td><td class="value">' . htmlspecialchars(ucwords(strtolower($quote['client_id'] ?? 'N/A'))) . '</td></tr>
            <tr><td class="label">MARITAL STATUS:</td><td class="value">' . htmlspecialchars(ucwords(strtolower($quote['marital_status'] ?? 'N/A'))) . '</td></tr>
            <tr><td class="label">SUBURB:</td><td class="value">' . htmlspecialchars(ucwords(strtolower($quote['suburb_client'] ?? 'N/A'))) . '</td></tr>
        </tbody>
    </table>
</div>

<h2>QUOTE SUMMARY</h2>
<h2>PREMIUM</h2>
<div class="card">
    <table border="0" cellpadding="6">
        <tbody>
            <tr><td class="label">TOTAL PREMIUM (FEES INCLUDED):</td><td class="value"><strong>R ' . number_format($selected_premium, 2) . '</strong></td></tr>';

if (!empty($breakdown)) {
    $total_risk_premium_key = 'total_risk_' . $excess_option;
    $total_risk_premium = isset($breakdown[$total_risk_premium_key]) ? floatval($breakdown[$total_risk_premium_key]) : 0.00;
    $html .= '
            <tr><td class="label"><strong>TOTAL RISK PREMIUM:</strong></td><td class="value"><strong>R ' . number_format($total_risk_premium, 2) . '</strong></td></tr>';
}

$html .= '
            <tr><th class="heading" colspan="2">FEE BREAKDOWN (INCLUDED IN PREMIUM)</th></tr>
            <tr><td class="label">BROKER FEE:</td><td class="value">R ' . number_format($fees['broker_fee'] ?? 0, 2) . '</td></tr>
            <tr><td class="label">CONVENIENCE DRIVE:</td><td class="value">R ' . number_format($fees['convenience_drive'] ?? 0, 2) . '</td></tr>
            <tr><td class="label">CAR HIRE:</td><td class="value">R ' . number_format($fees['car_hire'] ?? 0, 2) . '</td></tr>
            <tr><td class="label">SASRIA MOTOR:</td><td class="value">R ' . number_format($fees['sasria_motor'] ?? 0, 2) . '</td></tr>
            <tr><td class="label">PERSONAL LIABILITY:</td><td class="value">R ' . number_format($fees['personal_liability'] ?? 0, 2) . '</td></tr>
            <tr><td class="label">EXCESS BUYBACK:</td><td class="value">R ' . number_format($excess_buyback, 2) . '</td></tr>
            <tr><td class="label">CLAIMS ASSIST:</td><td class="value">R ' . number_format($fees['claims_assist'] ?? 0, 2) . '</td></tr>
            <tr><td class="label">LEGAL ASSIST:</td><td class="value">R ' . number_format($fees['legal_assist'] ?? 0, 2) . '</td></tr>
            <tr><td class="label"><strong>TOTAL FEES:</strong></td><td class="value"><strong>R ' . number_format($total_fees, 2) . '</strong></td></tr>
        </tbody>
    </table>
</div>';

foreach ($vehicles as $index => $vehicle_data) {
    $vehicle = $vehicle_data['vehicle'];
    $driver = $vehicle_data['driver'];
    $vehicle_description = htmlspecialchars(ucwords(strtolower(trim("{$vehicle['vehicle_year']} {$vehicle['vehicle_make']} {$vehicle['vehicle_model']}"))));
    $risk_premium_key = 'motor_risk_' . $excess_option;
    $risk_premium = isset($breakdown[$index][$risk_premium_key]) ? floatval($breakdown[$index][$risk_premium_key]) : 0.00;
    $html .= '
    <h4>VEHICLE INFORMATION - ITEM ' . ($index + 1) . '</h4>
    <div class="card">
        <table border="0" cellpadding="6">
            <tbody>
                <tr><td class="label">YEAR:</td><td class="value">' . htmlspecialchars(ucwords(strtolower($vehicle['vehicle_year'] ?? 'N/A'))) . '</td></tr>
                <tr><td class="label">MAKE:</td><td class="value">' . htmlspecialchars(ucwords(strtolower($vehicle['vehicle_make'] ?? 'N/A'))) . '</td></tr>
                <tr><td class="label">MODEL:</td><td class="value">' . htmlspecialchars(ucwords(strtolower($vehicle['vehicle_model'] ?? 'N/A'))) . '</td></tr>
                <tr><td class="label">SUM INSURED:</td><td class="value">R ' . number_format($vehicle['vehicle_value'] ?? 0, 2) . '</td></tr>
                <tr><td class="label">COVERAGE TYPE:</td><td class="value">' . htmlspecialchars(ucwords(strtolower($vehicle['coverage_type'] ?? 'N/A'))) . '</td></tr>
                <tr><td class="label">VEHICLE USE:</td><td class="value">' . htmlspecialchars(ucwords(strtolower($vehicle['vehicle_use'] ?? 'N/A'))) . '</td></tr>
                <tr><td class="label">PARKING:</td><td class="value">' . htmlspecialchars(ucwords(strtolower($vehicle['parking'] ?? 'N/A'))) . '</td></tr>
                <tr><td class="label">CAR HIRE OPTION:</td><td class="value">' . htmlspecialchars(ucwords(strtolower($vehicle['car_hire'] ?? 'N/A'))) . '</td></tr>
                <tr><td class="label">ADDRESS:</td><td class="value">' . htmlspecialchars(ucwords(strtolower(($vehicle['street'] ?? '') . ', ' . ($vehicle['suburb_vehicle'] ?? '') . ', ' . ($vehicle['postal_code'] ?? '')))) . '</td></tr>
                <tr><td class="label">REQUIREMENT:</td><td class="value">' . $security_outputs[$index] . '</td></tr>
                <tr><td class="label">BASIC EXCESS:</td><td class="value">' . htmlspecialchars(ucwords(strtolower($selected_excess_labels[$index]))) . '</td></tr>
                <tr><td class="label">RISK PREMIUM:</td><td class="value">R ' . number_format($risk_premium, 2) . '</td></tr>
            </tbody>
        </table>
    </div>

    <h3>DRIVER INFORMATION - DRIVER - ITEM ' . ($index + 1) . '</h3>
    <div class="card">
        <table border="0" cellpadding="6">
            <tbody>
                <tr><td class="label">TITLE:</td><td class="value">' . htmlspecialchars(ucwords(strtolower($driver['driver_title'] ?? 'N/A'))) . '</td></tr>
                <tr><td class="label">INITIALS:</td><td class="value">' . htmlspecialchars(ucwords(strtolower($driver['driver_initials'] ?? 'N/A'))) . '</td></tr>
                <tr><td class="label">SURNAME:</td><td class="value">' . htmlspecialchars(ucwords(strtolower($driver['driver_surname'] ?? 'N/A'))) . '</td></tr>
                <tr><td class="label">ID NUMBER:</td><td class="value">' . htmlspecialchars(ucwords(strtolower($driver['driver_id_number'] ?? 'N/A'))) . '</td></tr>
                <tr><td class="label">DATE OF BIRTH:</td><td class="value">' . htmlspecialchars(ucwords(strtolower($driver['dob'] ?? 'N/A'))) . '</td></tr>
                <tr><td class="label">AGE:</td><td class="value">' . htmlspecialchars(ucwords(strtolower($driver['age'] ?? 'N/A'))) . '</td></tr>
                <tr><td class="label">LICENCE TYPE:</td><td class="value">' . htmlspecialchars(ucwords(strtolower($driver['licence_type'] ?? 'N/A'))) . '</td></tr>
                <tr><td class="label">YEAR OF ISSUE:</td><td class="value">' . htmlspecialchars(ucwords(strtolower($driver['year_of_issue'] ?? 'N/A'))) . '</td></tr>
                <tr><td class="label">YEARS HELD:</td><td class="value">' . htmlspecialchars(ucwords(strtolower($driver['licence_held'] ?? 'N/A'))) . '</td></tr>
                <tr><td class="label">MARITAL STATUS:</td><td class="value">' . htmlspecialchars(ucwords(strtolower($driver['driver_marital_status'] ?? 'N/A'))) . '</td></tr>
                <tr><td class="label">NO CLAIMS BONUS:</td><td class="value">' . htmlspecialchars(ucwords(strtolower($driver['ncb'] ?? 'N/A'))) . '</td></tr>
            </tbody>
        </table>
    </div>';
}

$html .= '
<h4>PROFUSION NUCLEUS MOTOR EXCESS</h4>
<div class="card">
    <p><strong>PLEASE READ ALL INFORMATION BELOW</strong></p>
    <p>The most relevant basic excess applies in the event of a claim.</p>
    <p>Motor Private Use: For social and domestic use, including home-to-work driving.</p>
    <table border="0" cellpadding="6">
        <tbody>
            <tr><td class="label">Motor Private Use Option 1:</td><td class="value">4% of sum insured minimum R5000</td></tr>
            <tr><td class="label">Motor Private Use Option 2:</td><td class="value">5% of sum insured minimum R5000</td></tr>
            <tr><td class="label">Motor Private Use Option 3:</td><td class="value">6% of sum insured minimum R5000</td></tr>
        </tbody>
    </table>
    <p><strong>Motor Business Use (If Selected): Additional business use requires disclosure, or there is NO COVER</strong></p>
    <table border="0" cellpadding="6">
        <tbody>
            <tr><td class="label">Motor Business Use Excess:</td><td class="value">Additional 1% of sum insured minimum R1000</td></tr>
            <tr><td class="label">Windscreen/Fixed Glass:</td><td class="value">25% of claim minimum R1000</td></tr>
            <tr><td class="label">Radio/CD Player:</td><td class="value">25% of claim minimum R1000</td></tr>
        </tbody>
    </table>
    <p><strong>Pensioners are exempt from basic excess for accidents/theft if selecting Option 1, but additional excesses apply.</strong></p>
    <p><strong>Please Note:</strong></p>
    <table border="0" cellpadding="6">
        <tbody>
            <tr><td class="label">Immobilizer Not Adhered To:</td><td class="value">No Theft Cover</td></tr>
            <tr><td class="label">Tracking Device Not Fitted/Paid:</td><td class="value">No Theft Cover</td></tr>
        </tbody>
    </table>
</div>

<h3>MOTOR ADDITIONAL EXCESS</h3>
<div class="card">
    <table border="0" cellpadding="6">
        <tbody>
            <tr><td class="label">Single Vehicle Accident/Loss:</td><td class="value">10% of claim minimum R5000</td></tr>
            <tr><td class="label">Driver License Code Not 8/B/EB:</td><td class="value">10% of claim minimum R5000</td></tr>
            <tr><td class="label">Claim Within First 90 Days:</td><td class="value">15% of claim minimum R5000</td></tr>
            <tr><td class="label">Write Off Within First 12 Months:</td><td class="value">25% of claim minimum R10000</td></tr>
            <tr><td class="label">Write Off After 12 Months:</td><td class="value">15% of claim minimum R5000</td></tr>
            <tr><td class="label">Claim Between 9 P.M. and 4 A.M.:</td><td class="value">25% of claim minimum R10000</td></tr>
        </tbody>
    </table>
</div>

<p>THIS QUOTE IS VALID FOR 7 DAYS FROM ISSUANCE. PLEASE CONTACT US TO PROCEED WITH THE POLICY OR FOR ANY CLARIFICATIONS.</p>
<p><strong>DISCLAIMER:</strong> THIS QUOTE IS AN ESTIMATE BASED ON THE PROVIDED INFORMATION. FINAL PREMIUMS AND TERMS ARE SUBJECT TO UNDERWRITING APPROVAL.</p>
';

$pdf->writeHTML($html, true, false, true, false, '');

// Output PDF
ob_end_clean();
$filename = "Profusion_Quote_{$quote_id}.pdf";
error_log("[DEBUG] PDF generated for quote_id: $quote_id, excess_option: $excess_option, total=$selected_premium");
$pdf->Output($filename, 'D');
exit();

mysqli_close($conn);
?>