<?php
session_start();
if (!isset($_SESSION['user_id']) || strpos($_SESSION['role_name'], 'Profusion') !== 0) {
    header("Location: ../dashboard.php");
    exit();
}
require '../db_connect.php';

// Fetch user data
$user_id = $_GET['user_id'];
$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_result = $stmt->get_result();
$user = $user_result->fetch_assoc();
$stmt->close();

// Fetch roles and brokerages
$roles_query = "SELECT * FROM roles";
$roles_result = mysqli_query($conn, $roles_query);
$brokerages_query = "SELECT * FROM brokerages";
$brokerages_result = mysqli_query($conn, $brokerages_query);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $role_id = $_POST['role_id'];
    $brokerage_id = $_POST['brokerage_id'] ?: NULL;

    // Only update password if a new one is provided
    if (!empty($_POST['password'])) {
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET username = ?, password = ?, role_id = ?, brokerage_id = ? WHERE user_id = ?");
        $stmt->bind_param("ssiii", $username, $password, $role_id, $brokerage_id, $user_id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET username = ?, role_id = ?, brokerage_id = ? WHERE user_id = ?");
        $stmt->bind_param("siii", $username, $role_id, $brokerage_id, $user_id);
    }

    if ($stmt->execute()) {
        header("Location: manage_users.php");
        exit();
    } else {
        die("Error updating user: " . $stmt->error);
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User - Insurance App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Your existing CSS styles here -->
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
<body>
    <main>
        <div class="container mt-3">
            <!-- Your existing header and navbar HTML here -->

            <form method="post">
                <table class="table table-bordered">
                    <tr>
                        <td><label class="form-label">Username:</label></td>
                        <td><input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" class="form-control" required></td>
                    </tr>
                    <tr>
                        <td><label class="form-label">Password (leave blank to keep current):</label></td>
                        <td><input type="password" name="password" class="form-control"></td>
                    </tr>
                    <tr>
                        <td><label class="form-label">Role:</label></td>
                        <td>
                            <select name="role_id" class="form-select" required>
                                <?php while ($role = mysqli_fetch_assoc($roles_result)) { ?>
                                    <option value="<?php echo $role['role_id']; ?>" <?php if ($role['role_id'] == $user['role_id']) echo 'selected'; ?>><?php echo htmlspecialchars($role['role_name']); ?></option>
                                <?php } ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td><label class="form-label">Brokerage:</label></td>
                        <td>
                            <select name="brokerage_id" class="form-select">
                                <option value="">None</option>
                                <?php while ($brokerage = mysqli_fetch_assoc($brokerages_result)) { ?>
                                    <option value="<?php echo $brokerage['brokerage_id']; ?>" <?php if ($brokerage['brokerage_id'] == $user['brokerage_id']) echo 'selected'; ?>><?php echo htmlspecialchars($brokerage['brokerage_name']); ?></option>
                                <?php } ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2" class="text-center">
                            <input type="submit" value="Update User" class="btn btn-purple">
                            <a href="manage_users.php" class="btn btn-green">Back to Manage Users</a>
                        </td>
                    </tr>
                </table>
            </form>
        </div>
    </main>

    <!-- Your existing footer and scripts here -->

<?php
mysqli_close($conn);
?>
</body>
</html>