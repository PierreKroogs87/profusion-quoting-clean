<?php
session_start();
if (!isset($_SESSION['user_id']) || trim($_SESSION['role_name']) !== 'Profusion SuperAdmin') {
    header("Location: ../dashboard.php");
    exit();
}
require '../db_connect.php';

$brokerage_id = isset($_GET['brokerage_id']) ? (int)$_GET['brokerage_id'] : 0;
$broker = ['brokerage_name' => '', 'broker_fee' => 0.00];

if ($brokerage_id > 0) {
    $stmt = $conn->prepare("SELECT brokerage_name, broker_fee FROM brokerages WHERE brokerage_id = ?");
    $stmt->bind_param("i", $brokerage_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $broker = $result->fetch_assoc();
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $brokerage_id > 0 ? 'Edit Broker' : 'Add Broker'; ?> - Insurance App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --purple: #6A0DAD;
            --green: #28A745;
            --white: #fff;
            --font-scale: 0.75;
        }
        body {
            font-size: calc(1rem * var(--font-scale));
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        main {
            flex: 1 0 auto;
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
            color: white;
            font-size: calc(1.2rem * var(--font-scale));
            padding: calc(0.5rem * var(--font-scale)) calc(0.5rem * var(--font-scale));
            text-decoration: none;
        }
        .navbtn-purple:hover {
            background-color: #4B0082;
            border-color: #4B0082;
            color: white;
        }
        .btn-purple {
            background-color: var(--purple);
            border-color: var(--purple);
            color: white;
            font-size: calc(1rem * var(--font-scale));
            padding: calc(0.375rem * var(--font-scale)) calc(0.75rem * var(--font-scale));
        }
        .btn-purple:hover {
            background-color: #4B0082;
            border-color: #4B0082;
        }
        .form-label, .form-control {
            font-size: calc(1rem * var(--font-scale));
            padding: calc(0.375rem * var(--font-scale)) calc(0.75rem * var(--font-scale));
        }
        footer {
            background-color: var(--white);
            color: var(--purple);
            font-size: calc(1rem * var(--font-scale));
            padding: calc(1rem * var(--font-scale)) 0;
            text-align: center;
            flex-shrink: 0;
        }
    </style>
</head>
<body>
    <main>
        <div class="container mt-3">
            <header>
                <a href="../dashboard.php">
                    <img src="../images/logo.png" alt="Profusion Insurance Logo">
                </a>
            </header>
            <nav class="navbar navbar-expand-lg navbar-dark">
                <div class="container-fluid">
                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <div class="collapse navbar-collapse" id="navbarNav">
                        <ul class="navbar-nav">
                            <li class="nav-item">
                                <a class="nav-link btn navbtn-purple" href="../dashboard.php">Dashboard</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link btn navbtn-purple" href="../user_management/manage_users.php">Manage Users</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link btn navbtn-purple" href="manage_brokers.php">Manage Brokers</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link btn navbtn-purple" href="../login_management/logout.php">Logout</a>
                            </li>
                        </ul>
                    </div>
                </div>
            </nav>

            <div class="container mt-4">
                <h2 class="mb-4"><?php echo $brokerage_id > 0 ? 'Edit Broker' : 'Add New Broker'; ?></h2>
                <form method="post" action="save_broker.php" class="row g-3">
                    <?php if ($brokerage_id > 0) { ?>
                        <input type="hidden" name="brokerage_id" value="<?php echo htmlspecialchars((string)$brokerage_id); ?>">
                    <?php } ?>
                    <div class="col-md-6">
                        <label for="brokerage_name" class="form-label">Brokerage Name</label>
                        <input type="text" class="form-control" id="brokerage_name" name="brokerage_name" value="<?php echo htmlspecialchars($broker['brokerage_name']); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="broker_fee" class="form-label">Broker Fee (R)</label>
                        <input type="number" step="0.01" min="0" class="form-control" id="broker_fee" name="broker_fee" value="<?php echo htmlspecialchars(number_format($broker['broker_fee'], 2, '.', '')); ?>" required>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-purple"><?php echo $brokerage_id > 0 ? 'Save Changes' : 'Add Broker'; ?></button>
                        <a href="manage_brokers.php" class="btn btn-secondary ms-2">Cancel</a>
                    </div>
                </form>
                <p class="mt-3"><a href="../dashboard.php" class="text-decoration-none">Back to Dashboard</a></p>
            </div>

            <footer class="text-center py-3 mt-4">
                <p>© 2025 Profusion Insurance</p>
            </footer>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
$conn->close();
?>