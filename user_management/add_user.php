<?php
session_start();
if (!isset($_SESSION['user_id']) || strpos($_SESSION['role_name'], 'Profusion') !== 0) {
    header("Location: ../dashboard.php");
    exit();
}
require '../db_connect.php';

$roles_query = "SELECT role_id, role_name FROM roles";
$roles_result = mysqli_query($conn, $roles_query);

$brokerages_query = "SELECT brokerage_id, brokerage_name FROM brokerages";
$brokerages_result = mysqli_query($conn, $brokerages_query);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role_id = $_POST['role_id'];
    $brokerage_id = $_POST['brokerage_id'] ?: NULL;

    $stmt = $conn->prepare("INSERT INTO users (username, password, role_id, brokerage_id) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssii", $username, $password, $role_id, $brokerage_id);
    if ($stmt->execute()) {
        header("Location: manage_users.php");
        exit();
    } else {
        die("Error inserting user: " . $stmt->error . " | Query: " . $conn->error);
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add User - Insurance App</title>
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
            text-decoration: none;
        }
        .btn-purple:hover { 
            background-color: #4B0082; 
            border-color: #4B0082; 
            color: white; 
        }
        .btn-green { 
            background-color: var(--green); 
            border-color: var(--green); 
            color: white; 
            font-size: calc(1rem * var(--font-scale));
            padding: calc(0.375rem * var(--font-scale)) calc(0.75rem * var(--font-scale));
        }
        .btn-green:hover { 
            background-color: #218838; 
            border-color: #1E7E34; 
            color: white; 
        }
        .form-label, .form-control, .form-select { 
            font-size: calc(1rem * var(--font-scale));
            padding: calc(0.375rem * var(--font-scale)) calc(0.75rem * var(--font-scale));
        }
        footer { 
            background-color: var(--white); 
            color: var(--purple); 
            font-size: calc(1rem * var(--font-scale));
            padding: calc(1rem * var(--font-scale)) 0;
            text-align: center;
            width: 100%;
            flex-shrink: 0;
        }
         .table thead th { 
            padding: calc(0.25rem * var(--font-scale));
            line-height: 1.2;
            white-space: nowrap;
            background-color: var(--purple);
            color: var(--white);
        }
        .table td { 
            padding: calc(0.25rem * var(--font-scale));
            line-height: 1.2;
            white-space: nowrap;
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
                                <a class="nav-link btn navbtn-purple" href="manage_users.php">Manage Users</a>
                            </li>
                             <li class="nav-item">
                                <a class="nav-link btn navbtn-purple" href="../broker_management/manage_brokers.php">Manage Brokers</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link btn navbtn-purple" href="../login_management/logout.php">Logout</a>
                            </li>
                        </ul>
                    </div>
                </div>
            </nav>

    <div class="container mt-4">
        <h2 class="mb-4">Add New User</h2>
        <form method="post" class="row g-3">
            <div class="col-md-6">
                <label for="username" class="form-label">Username:</label>
                <input type="text" name="username" id="username" class="form-control" required>
            </div>
            <div class="col-md-6">
                <label for="password" class="form-label">Password:</label>
                <input type="password" name="password" id="password" class="form-control" required>
            </div>
            <div class="col-md-6">
                <label for="role_id" class="form-label">Role:</label>
                <select name="role_id" id="role_id" class="form-select" required>
                    <?php while ($role = mysqli_fetch_assoc($roles_result)) { ?>
                        <option value="<?php echo $role['role_id']; ?>"><?php echo htmlspecialchars($role['role_name']); ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-6">
                <label for="brokerage_id" class="form-label">Brokerage:</label>
                <select name="brokerage_id" id="brokerage_id" class="form-select">
                    <option value="">None</option>
                    <?php while ($brokerage = mysqli_fetch_assoc($brokerages_result)) { ?>
                        <option value="<?php echo $brokerage['brokerage_id']; ?>"><?php echo htmlspecialchars($brokerage['brokerage_name']); ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-purple">Add User</button>
                <a href="manage_users.php" class="btn btn-link ms-3">Back to Manage Users</a>
            </div>
        </form>
    </div>

    <footer class="text-center py-3 mt-4">
        <p>© 2025 Profusion Insurance</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelector('form').onsubmit = function() {
            return confirm('Are you sure you want to add this user?');
        };
    </script>
</body>
</html>

<?php
mysqli_close($conn);
?>