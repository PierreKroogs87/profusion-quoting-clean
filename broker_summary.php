<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login_management/login.php");
    exit();
}
require 'db_connect.php';

// Get user role and brokerage_id from session
$role = $_SESSION['role_name'];
$brokerage_id = $_SESSION['brokerage_id'];

// Determine if user is Profusion
$is_profusion = strpos($role, 'Profusion') === 0;

// Check authorization for consultant breakdown and navigation
$can_see_breakdown = $is_profusion || ($role == 'Broker Manager' || $role == 'Broker Admin');
$can_access_summary = $can_see_breakdown;

// Fetch brokerage data
$brokerages = [];
if ($is_profusion) {
    $result = $conn->query("SELECT brokerage_id, brokerage_name FROM brokerages");
    while ($row = $result->fetch_assoc()) {
        $brokerages[] = $row;
    }
} else {
    $stmt = $conn->prepare("SELECT brokerage_id, brokerage_name FROM brokerages WHERE brokerage_id = ?");
    $stmt->bind_param("i", $brokerage_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $brokerages[] = $result->fetch_assoc() ?: ['brokerage_id' => $brokerage_id, 'brokerage_name' => 'Unknown'];
    $stmt->close();
}

// Fetch summary data for each brokerage
$broker_summaries = [];
$colors = ['#e6f3fa', '#f0e6fa', '#e6fae6', '#faf0e6', '#f5e6f0']; // Light colors for cards
foreach ($brokerages as $index => &$brokerage) {
    $brokerage_id = $brokerage['brokerage_id'];
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_quotes, 
            SUM(COALESCE(premium6, premium5, premium4, premium_flat, 0)) as total_premium,
            (SELECT COUNT(*) FROM policies p WHERE p.brokerage_id = ? AND p.status = 'active') as active_policies,
            (SELECT SUM(premium_amount) FROM policies p WHERE p.brokerage_id = ? AND p.status = 'active') as active_premium_total
        FROM quotes 
        WHERE brokerage_id = ?
    ");
    $stmt->bind_param("iii", $brokerage_id, $brokerage_id, $brokerage_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $brokerage['summary'] = $result->fetch_assoc() ?: ['total_quotes' => 0, 'total_premium' => 0, 'active_policies' => 0, 'active_premium_total' => 0];
    $stmt->close();

    // Assign a color to each brokerage
    $brokerage['color'] = $colors[$index % count($colors)];

    // Fetch consultant breakdown if authorized
    if ($can_see_breakdown) {
        $stmt = $conn->prepare("
            SELECT u.username, COUNT(q.quote_id) as quote_count
            FROM users u
            LEFT JOIN quotes q ON u.user_id = q.user_id
            WHERE u.brokerage_id = ?
            GROUP BY u.user_id, u.username
            HAVING COUNT(q.quote_id) > 0
        ");
        $stmt->bind_param("i", $brokerage_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $brokerage['consultants'] = [];
        while ($row = $result->fetch_assoc()) {
            $brokerage['consultants'][] = $row;
        }
        $stmt->close();
    }
}
unset($brokerage); // Unset reference to avoid issues
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Broker Summary - Insurance App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --purple: #6A0DAD;
            --white: #fff;
            --font-scale: 0.9; /* Reduced for compactness */
            --base-font: 12px; /* Smaller font size */
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
            padding: calc(0.5rem * var(--font-scale)) 0; /* Reduced padding */
            text-align: center;
            width: 100%;
            flex-shrink: 0;
        }
        header {
            padding: calc(0.5rem * var(--font-scale)) 0; /* Reduced padding */
            text-align: left;
        }
        header img {
            height: calc(80px * var(--font-scale)); /* Smaller logo */
        }
        .navbar {
            background-color: var(--white);
            padding: calc(0.3rem * var(--font-scale)) 0.5rem; /* Reduced padding */
            justify-content: flex-start;
        }
        .navbtn-purple {
            background-color: var(--purple);
            border-color: var(--purple);
            color: var(--white);
            font-size: calc(0.9rem * var(--font-scale));
            padding: calc(0.3rem * var(--font-scale)) calc(0.5rem * var(--font-scale));
            text-decoration: none;
            margin: 0 calc(0.2rem * var(--font-scale));
        }
        .navbtn-purple:hover {
            background-color: #4B0082;
            border-color: #4B0082;
            color: var(--white);
        }
        .card {
            margin-bottom: calc(0.5rem * var(--font-scale)); /* Reduced margin */
            font-size: calc(0.9rem * var(--font-scale)); /* Smaller font */
        }
        .card-header {
            background-color: var(--purple);
            color: var(--white);
            font-size: calc(1rem * var(--font-scale));
            padding: calc(0.3rem * var(--font-scale)) 0.5rem; /* Reduced padding */
        }
        .card-body {
            padding: calc(0.5rem * var(--font-scale)); /* Reduced padding */
        }
        .table {
            font-size: calc(0.9rem * var(--font-scale)); /* Smaller table font */
        }
        .table th, .table td {
            padding: calc(0.3rem * var(--font-scale)); /* Reduced table padding */
        }
        .summary-table th {
            background-color: var(--purple);
            color: var(--white);
        }
    </style>
</head>
<body>
    <main>
        <div class="container mt-3">
            <header>
                <a href="broker_summary.php"><img src="images/logo.png" alt="Profusion Insurance Logo"></a>
            </header>
            <nav class="navbar navbar-expand-lg navbar-dark">
                <div class="container-fluid">
                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <div class="collapse navbar-collapse" id="navbarNav">
                        <ul class="navbar-nav">
                            <li class="nav-item"><a class="nav-link btn navbtn-purple" href="dashboard.php">Dashboard</a></li>
                            <?php if ($can_access_summary): ?>
                                <li class="nav-item"><a class="nav-link btn navbtn-purple" href="broker_summary.php">Broker Summary</a></li>
                            <?php endif; ?>
                            <?php if (strpos($role, 'Profusion') === 0): ?>
                                <li class="nav-item"><a class="nav-link btn navbtn-purple" href="user_management/manage_users.php">Manage Users</a></li>
                                <li class="nav-item"><a class="nav-link btn navbtn-purple" href="broker_management/manage_brokers.php">Manage Brokers</a></li>
                            <?php endif; ?>
                            <li class="nav-item"><a class="nav-link btn navbtn-purple" href="login_management/logout.php">Logout</a></li>
                        </ul>
                    </div>
                </div>
            </nav>

            <div class="container mt-3">
                <h1>Broker Summary</h1>

                <!-- Summary Table -->
                <div class="card mb-3">
                    <div class="card-header">Broker Summary Overview</div>
                    <div class="card-body">
                        <table class="table table-striped summary-table">
                            <thead>
                                <tr>
                                    <th>Broker Name</th>
                                    <th>Total Quotes</th>
                                    <th>Total Premium (R)</th>
                                    <th>Activated Policies</th>
                                    <th>Activated Policies Premium (R)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($brokerages as $brokerage): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($brokerage['brokerage_name']); ?></td>
                                        <td><?php echo number_format($brokerage['summary']['total_quotes']); ?></td>
                                        <td><?php echo number_format($brokerage['summary']['total_premium'], 2); ?></td>
                                        <td><?php echo number_format($brokerage['summary']['active_policies']); ?></td>
                                        <td><?php echo number_format($brokerage['summary']['active_premium_total'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Individual Brokerage Cards -->
                <?php foreach ($brokerages as $brokerage): ?>
                    <div class="card mb-3" style="background-color: <?php echo $brokerage['color']; ?>;">
                        <div class="card-header">Summary - <?php echo htmlspecialchars($brokerage['brokerage_name']); ?></div>
                        <div class="card-body">
                            <p><strong>Total Quotes:</strong> <?php echo number_format($brokerage['summary']['total_quotes']); ?></p>
                            <p><strong>Total Premium Quoted:</strong> R<?php echo number_format($brokerage['summary']['total_premium'], 2); ?></p>
                            <p><strong>Activated Policies:</strong> <?php echo number_format($brokerage['summary']['active_policies']); ?></p>
                            <p><strong>Activated Policies Premium:</strong> R<?php echo number_format($brokerage['summary']['active_premium_total'], 2); ?></p>
                        </div>
                    </div>

                    <?php if ($can_see_breakdown && !empty($brokerage['consultants'])): ?>
                        <div class="card mb-3" style="background-color: <?php echo $brokerage['color']; ?>;">
                            <div class="card-header">Consultant Breakdown - <?php echo htmlspecialchars($brokerage['brokerage_name']); ?></div>
                            <div class="card-body">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Consultant Username</th>
                                            <th>Quotes Done</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($brokerage['consultants'] as $c): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($c['username']); ?></td>
                                                <td><?php echo number_format($c['quote_count']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>

                <!-- Daily Quotes Graph -->
                <div class="card mb-3">
                    <div class="card-header">Daily Quotes - <?php echo $is_profusion ? 'All Brokerages' : htmlspecialchars($brokerages[0]['brokerage_name']); ?></div>
                    <div class="card-body">
                        <div class="row mb-2">
                            <div class="col-md-6">
                                <label for="year" class="form-label">Year:</label>
                                <select id="year" class="form-select">
                                    <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                                        <option value="<?php echo $y; ?>" <?php echo $y == date('Y') ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="month" class="form-label">Month:</label>
                                <select id="month" class="form-select">
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?php echo $m; ?>" <?php echo $m == date('m') ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <canvas id="quoteChart" height="80"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <footer class="text-center py-2 mt-3">
        <p>© 2025 Profusion Insurance</p>
    </footer>

    <script>
        const brokerageId = <?php echo json_encode($is_profusion ? 'all' : $brokerage_id); ?>;
        let quoteChart;

        function fetchGraphData(year, month) {
            fetch(`get_graph_data.php?brokerage_id=${brokerageId}&year=${year}&month=${month}`)
                .then(response => response.json())
                .then(data => {
                    const days = Object.keys(data).length;
                    const labels = Array.from({length: days}, (_, i) => i + 1);
                    const values = Object.values(data);

                    if (quoteChart) quoteChart.destroy();
                    quoteChart = new Chart(document.getElementById('quoteChart').getContext('2d'), {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'Quotes per Day',
                                data: values,
                                borderColor: '#6A0DAD',
                                fill: false
                            }]
                        },
                        options: {
                            scales: {
                                x: { title: { display: true, text: 'Day of Month' } },
                                y: { beginAtZero: true, title: { display: true, text: 'Number of Quotes' } }
                            }
                        }
                    });
                });
        }

        // Load initial data
        fetchGraphData(<?php echo date('Y'); ?>, <?php echo date('m'); ?>);

        // Update graph on selection change
        document.getElementById('year').addEventListener('change', () => {
            fetchGraphData(document.getElementById('year').value, document.getElementById('month').value);
        });
        document.getElementById('month').addEventListener('change', () => {
            fetchGraphData(document.getElementById('year').value, document.getElementById('month').value);
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>