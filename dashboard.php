<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    error_log("Redirect to login.php: No user_id in session");
    header("Location: index.php");
    ob_end_clean();
    exit();
}
require 'db_connect.php';
require 'tcpdf/tcpdf.php';
$role = $_SESSION['role_name'] ?? 'Broker';
$brokerage_id = $_SESSION['brokerage_id'] ?? NULL;
$quotes_per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $quotes_per_page;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$search_type = isset($_GET['search_type']) ? $_GET['search_type'] : 'client_id';
$search_param = $search;
if (strpos($role, 'Profusion') === 0) {
    if ($search) {
        if ($search_type === 'quote_id') {
            $count_query = "SELECT COUNT(*) FROM quotes WHERE quote_id = ?";
            $count_stmt = $conn->prepare($count_query);
            $count_stmt->bind_param("i", $search_param);
        } else {
            $count_query = "SELECT COUNT(*) FROM quotes WHERE client_id LIKE ?";
            $count_stmt = $conn->prepare($count_query);
            $search_param = '%' . $search . '%';
            $count_stmt->bind_param("s", $search_param);
        }
    } else {
        $count_query = "SELECT COUNT(*) FROM quotes";
        $count_stmt = $conn->prepare($count_query);
    }
    $count_stmt->execute();
} else {
    if ($brokerage_id === NULL) {
        die("Error: Brokerage ID not set for non-Profusion user.");
    }
    if ($search) {
        if ($search_type === 'quote_id') {
            $count_query = "SELECT COUNT(*) FROM quotes WHERE brokerage_id = ? AND quote_id = ?";
            $count_stmt = $conn->prepare($count_query);
            $count_stmt->bind_param("ii", $brokerage_id, $search_param);
        } else {
            $count_query = "SELECT COUNT(*) FROM quotes WHERE brokerage_id = ? AND client_id LIKE ?";
            $count_stmt = $conn->prepare($count_query);
            $search_param = '%' . $search . '%';
            $count_stmt->bind_param("is", $brokerage_id, $search_param);
        }
    } else {
        $count_query = "SELECT COUNT(*) FROM quotes WHERE brokerage_id = ?";
        $count_stmt = $conn->prepare($count_query);
        $count_stmt->bind_param("i", $brokerage_id);
    }
    $count_stmt->execute();
}
$count_stmt->bind_result($total_quotes);
$count_stmt->fetch();
$count_stmt->close();
$total_pages = ceil($total_quotes / $quotes_per_page);
if (strpos($role, 'Profusion') === 0) {
    if ($search) {
        if ($search_type === 'quote_id') {
            $quote_query = "
                SELECT q.quote_id, q.title, q.initials, q.surname, b.brokerage_name,
                       q.premium6, q.premium5, q.premium4, q.premium_flat, p.status, p.premium_type, u.username
                FROM quotes q
                LEFT JOIN brokerages b ON q.brokerage_id = b.brokerage_id
                LEFT JOIN policies p ON q.quote_id = p.quote_id
                LEFT JOIN users u ON q.user_id = u.user_id
                WHERE q.quote_id = ?
                ORDER BY q.quote_id DESC
                LIMIT ? OFFSET ?";
            $stmt = $conn->prepare($quote_query);
            $stmt->bind_param("iii", $search_param, $quotes_per_page, $offset);
        } else {
            $quote_query = "
                SELECT q.quote_id, q.title, q.initials, q.surname, b.brokerage_name,
                       q.premium6, q.premium5, q.premium4, q.premium_flat, p.status, p.premium_type, u.username
                FROM quotes q
                LEFT JOIN brokerages b ON q.brokerage_id = b.brokerage_id
                LEFT JOIN policies p ON q.quote_id = p.quote_id
                LEFT JOIN users u ON q.user_id = u.user_id
                WHERE q.client_id LIKE ?
                ORDER BY q.quote_id DESC
                LIMIT ? OFFSET ?";
            $stmt = $conn->prepare($quote_query);
            $search_param = '%' . $search . '%';
            $stmt->bind_param("sii", $search_param, $quotes_per_page, $offset);
        }
    } else {
        $quote_query = "
            SELECT q.quote_id, q.title, q.initials, q.surname, b.brokerage_name,
                   q.premium6, q.premium5, q.premium4, q.premium_flat, p.status, p.premium_type, u.username
            FROM quotes q
            LEFT JOIN brokerages b ON q.brokerage_id = b.brokerage_id
            LEFT JOIN policies p ON q.quote_id = p.quote_id
            LEFT JOIN users u ON q.user_id = u.user_id
            ORDER BY q.quote_id DESC
            LIMIT ? OFFSET ?";
        $stmt = $conn->prepare($quote_query);
        $stmt->bind_param("ii", $quotes_per_page, $offset);
    }
} else {
    if ($search) {
        if ($search_type === 'quote_id') {
            $quote_query = "
                SELECT q.quote_id, q.title, q.initials, q.surname, b.brokerage_name,
                       q.premium6, q.premium5, q.premium4, q.premium_flat, p.status, p.premium_type, u.username
                FROM quotes q
                LEFT JOIN brokerages b ON q.brokerage_id = b.brokerage_id
                LEFT JOIN policies p ON q.quote_id = p.quote_id
                LEFT JOIN users u ON q.user_id = u.user_id
                WHERE q.brokerage_id = ? AND q.quote_id = ?
                ORDER BY q.quote_id DESC
                LIMIT ? OFFSET ?";
            $stmt = $conn->prepare($quote_query);
            $stmt->bind_param("iiii", $brokerage_id, $search_param, $quotes_per_page, $offset);
        } else {
            $quote_query = "
                SELECT q.quote_id, q.title, q.initials, q.surname, b.brokerage_name,
                       q.premium6, q.premium5, q.premium4, q.premium_flat, p.status, p.premium_type, u.username
                FROM quotes q
                LEFT JOIN brokerages b ON q.brokerage_id = b.brokerage_id
                LEFT JOIN policies p ON q.quote_id = p.quote_id
                LEFT JOIN users u ON q.user_id = u.user_id
                WHERE q.brokerage_id = ? AND q.client_id LIKE ?
                ORDER BY q.quote_id DESC
                LIMIT ? OFFSET ?";
            $stmt = $conn->prepare($quote_query);
            $search_param = '%' . $search . '%';
            $stmt->bind_param("isii", $brokerage_id, $search_param, $quotes_per_page, $offset);
        }
    } else {
        $quote_query = "
            SELECT q.quote_id, q.title, q.initials, q.surname, b.brokerage_name,
                   q.premium6, q.premium5, q.premium4, q.premium_flat, p.status, p.premium_type, u.username
            FROM quotes q
            LEFT JOIN brokerages b ON q.brokerage_id = b.brokerage_id
            LEFT JOIN policies p ON q.quote_id = p.quote_id
            LEFT JOIN users u ON q.user_id = u.user_id
            WHERE q.brokerage_id = ?
            ORDER BY q.quote_id DESC
            LIMIT ? OFFSET ?";
        $stmt = $conn->prepare($quote_query);
        $stmt->bind_param("iii", $brokerage_id, $quotes_per_page, $offset);
    }
}
$stmt->execute();
$result = $stmt->get_result();
$is_superadmin = $role === 'Profusion SuperAdmin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
        .btn-blue {
            background-color: #007BFF;
            border-color: #007BFF;
            color: white;
            font-size: calc(1rem * var(--font-scale));
            padding: calc(0.375rem * var(--font-scale)) calc(0.75rem * var(--font-scale));
        }
        .btn-blue:hover {
            background-color: #0056b3;
            border-color: #004085;
            color: white;
        }
        .btn-blue:disabled {
            background-color: #6c757d;
            border-color: #6c757d;
            cursor: not-allowed;
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
                <a href="dashboard.php"><img src="images/logo.png" alt="Profusion Insurance Logo"></a>
            </header>
            <nav class="navbar navbar-expand-lg navbar-dark">
                <div class="container-fluid">
                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <div class="collapse navbar-collapse" id="navbarNav">
                        <ul class="navbar-nav">
                            <li class="nav-item"><a class="nav-link btn navbtn-purple" href="home.php">Home</a></li>
                            <li class="nav-item"><a class="nav-link btn navbtn-purple" href="dashboard.php">Dashboard</a></li>
                            <?php if (strpos($_SESSION['role_name'], 'Profusion') === 0 || $_SESSION['role_name'] == 'Broker Manager' || $_SESSION['role_name'] == 'Broker Admin') { ?>
                                <li class="nav-item"><a class="nav-link btn navbtn-purple" href="broker_summary.php">Broker Summary</a></li>
                            <?php } ?>
                            <?php if (strpos($_SESSION['role_name'], 'Profusion') === 0) { ?>
                                <li class="nav-item"><a class="nav-link btn navbtn-purple" href="user_management/manage_users.php">Manage Users</a></li>
                                <li class="nav-item"><a class="nav-link btn navbtn-purple" href="broker_management/manage_brokers.php">Manage Brokers</a></li>
                            <?php } ?>
                            <li class="nav-item"><a class="nav-link btn navbtn-purple" href="login_management/logout.php">Logout</a></li>
                        </ul>
                    </div>
                </div>
            </nav>
            <h2 class="mb-4">Dashboard</h2>
            <div class="container mt-4">
                <form class="row g-3 mb-4" method="GET" action="dashboard.php">
                    <div class="col-md-4">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" class="form-control" placeholder="Enter search term">
                    </div>
                    <div class="col-md-4">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="search_type" value="quote_id" <?php echo $search_type === 'quote_id' ? 'checked' : ''; ?> id="quote_id">
                            <label class="form-check-label" for="quote_id">Quote ID</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="search_type" value="client_id" <?php echo $search_type === 'client_id' ? 'checked' : ''; ?> id="client_id">
                            <label class="form-check-label" for="client_id">Client ID</label>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-purple w-100">Search</button>
                    </div>
                </form>
                <div class="mt-3 mb-4" style="text-align: left">
                    <a href="quote_management/new_quote.php" class="btn btn-purple">Create New Quote</a>
                </div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th>Quote ID</th>
                                        <th>Title</th>
                                        <th>Initials</th>
                                        <th>Surname</th>
                                        <th>Brokerage</th>
                                        <th>Premium (R)</th>
                                        <th>Policy Status</th>
                                        <th>Username</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                    <tbody>
                                        <?php while ($row = $result->fetch_assoc()) { ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($row['quote_id']); ?></td>
                                                <td><?php echo htmlspecialchars($row['title'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($row['initials'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($row['surname'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($row['brokerage_name'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <?php
                                                    // Logic: For active policies, use the selected premium_type; otherwise, default to premium6
                                                    $premium = $row['premium6'] ?? 0; // Default to premium6
                                                    if (($row['status'] ?? 'Quote') === 'active') {
                                                        $premium_type = $row['premium_type'] ?? 'premium6'; // Fallback if null
                                                        if ($premium_type === 'premium5') {
                                                            $premium = $row['premium5'] ?? 0;
                                                        } elseif ($premium_type === 'premium4') {
                                                            $premium = $row['premium4'] ?? 0;
                                                        } elseif ($premium_type === 'premium_flat') {
                                                            $premium = $row['premium_flat'] ?? 0;
                                                        } // Else stays as premium6
                                                    }
                                                    echo 'R' . number_format($premium, 2);
                                                    ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($row['status'] ?? 'Quote'); ?></td>
                                                <td><?php echo htmlspecialchars($row['username'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <a href="quote_management/edit_quote.php?quote_id=<?php echo urlencode($row['quote_id']); ?>" class="btn btn-purple btn-sm me-2">Edit</a>
                                                    <a href="download_quote.php?quote_id=<?php echo urlencode($row['quote_id']); ?>" class="btn btn-green btn-sm me-2">Download Quote</a>
                                                    <?php if (strpos($_SESSION['role_name'], 'Profusion') === 0 || $_SESSION['role_name'] == 'Broker Manager') { ?>
                                                        <a href="underwriting_steps/step1_disclosures.php?quote_id=<?php echo urlencode($row['quote_id']); ?>&product_type=<?php echo urlencode($row['premium_type'] ?? 'premium6'); ?>"
                                                           class="btn btn-blue btn-sm convert-policy-btn <?php echo ($row['status'] === 'active') ? 'disabled' : ''; ?>"
                                                           data-quote-id="<?php echo htmlspecialchars($row['quote_id']); ?>"
                                                           data-status="<?php echo htmlspecialchars($row['status'] ?? 'Not Started'); ?>">
                                                           Convert to Policy
                                                        </a>
                                                        <?php
                                                        error_log("Checking Download Confirmation for quote_id={$row['quote_id']}, status=" . ($row['status'] ?? 'Quote'));
                                                        if ($row['status'] === 'active') {
                                                            $policy_stmt = $conn->prepare("SELECT policy_id FROM policies WHERE quote_id = ? AND status = 'active'");
                                                            $policy_stmt->bind_param("i", $row['quote_id']);
                                                            $policy_stmt->execute();
                                                            $policy_result = $policy_stmt->get_result();
                                                            $policy_id = $policy_result->num_rows > 0 ? $policy_result->fetch_assoc()['policy_id'] : null;
                                                            $policy_stmt->close();
                                                            error_log("Policy ID for quote_id={$row['quote_id']}: " . ($policy_id ?? 'Not found'));
                                                            if ($policy_id) { ?>
                                                                <a href="underwriting_steps/generate_confirmation.php?policy_id=<?php echo urlencode($policy_id); ?>"
                                                                   class="btn btn-purple btn-sm me-2">Download Confirmation</a>
                                                                <a href="underwriting_steps/step1_disclosures.php?quote_id=<?php echo urlencode($row['quote_id']); ?>&edit_mode=true" class="btn btn-primary btn-sm me-2">Edit Policy Details</a>
                                                            <?php } ?>
                                                        <?php } ?>
                                                    <?php } ?>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                    <nav aria-label="Page navigation" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <?php
                            $block_size = 10; // Number of pages to show at a time
                            $current_block = ceil($page / $block_size);
                            $start_page = ($current_block - 1) * $block_size + 1;
                            $end_page = min($start_page + $block_size - 1, $total_pages);
                   
                            // Previous Block (<<)
                            if ($start_page > 1) {
                                $prev_block_page = max(1, $start_page - $block_size);
                                $prev_block_url = "?page=$prev_block_page&search=" . urlencode($search) . "&search_type=" . urlencode($search_type);
                                echo '<li class="page-item"><a class="page-link" href="' . $prev_block_url . '">&laquo;</a></li>'; // &laquo; is <<
                            }
                   
                            // Previous Page (<)
                            if ($page > 1) {
                                $prev_url = "?page=" . ($page - 1) . "&search=" . urlencode($search) . "&search_type=" . urlencode($search_type);
                                echo '<li class="page-item"><a class="page-link" href="' . $prev_url . '">&lt;</a></li>'; // &lt; is <
                            } else {
                                echo '<li class="page-item disabled"><a class="page-link" href="#">&lt;</a></li>';
                            }
                   
                            // Numbered Pages in Current Block
                            for ($i = $start_page; $i <= $end_page; $i++) {
                                $page_url = "?page=$i&search=" . urlencode($search) . "&search_type=" . urlencode($search_type);
                                $active_class = ($i == $page) ? 'active' : '';
                                echo '<li class="page-item ' . $active_class . '"><a class="page-link" href="' . $page_url . '">' . $i . '</a></li>';
                            }
                   
                            // Next Page (>)
                            if ($page < $total_pages) {
                                $next_url = "?page=" . ($page + 1) . "&search=" . urlencode($search) . "&search_type=" . urlencode($search_type);
                                echo '<li class="page-item"><a class="page-link" href="' . $next_url . '">&gt;</a></li>'; // &gt; is >
                            } else {
                                echo '<li class="page-item disabled"><a class="page-link" href="#">&gt;</a></li>';
                            }
                   
                            // Next Block (>>)
                            if ($end_page < $total_pages) {
                                $next_block_page = $end_page + 1;
                                $next_block_url = "?page=$next_block_page&search=" . urlencode($search) . "&search_type=" . urlencode($search_type);
                                echo '<li class="page-item"><a class="page-link" href="' . $next_block_url . '">&raquo;</a></li>'; // &raquo; is >>
                            }
                            ?>
                        </ul>
                    </nav>
                </div>
                            <!-- Bootstrap Modal for Error Message -->
                            <!-- Error Modal -->
                <div class="modal fade" id="policyErrorModal" tabindex="-1" aria-labelledby="policyErrorModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="policyErrorModalLabel">Error</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                This policy is already active and cannot be converted again.
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
               
                <!-- Product Selection Modal -->
                <div class="modal fade" id="productSelectionModal" tabindex="-1" aria-labelledby="productSelectionModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="productSelectionModalLabel">Select Product Type</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form id="productSelectionForm">
                                    <input type="hidden" id="modal_quote_id" name="quote_id">
                                    <div class="mb-3">
                                        <label for="product_type" class="form-label">Product Type</label>
                                        <select id="product_type" name="product_type" class="form-select" required>
                                            <option value="">Select a product...</option>
                                            <option value="premium6">Premium 6%</option>
                                            <option value="premium5">Premium 5%</option>
                                            <option value="premium4">Premium 4%</option>
                                            <option value="premium_flat">Premium Flat</option>
                                        </select>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" id="confirmProductBtn" class="btn btn-purple">Confirm</button>
                            </div>
                        </div>
                    </div>
                </div>
                                  
        <br><br><br><br><br>
        <footer class="text-center py-3 mt-4">
            <p>© 2025 Profusion Insurance</p>
        </footer>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const convertButtons = document.querySelectorAll('.convert-policy-btn');
            const policyErrorModal = new bootstrap.Modal(document.getElementById('policyErrorModal'));
            const productModal = new bootstrap.Modal(document.getElementById('productSelectionModal'));
            const productForm = document.getElementById('productSelectionForm');
            const confirmButton = document.getElementById('confirmProductBtn');
            const quoteIdInput = document.getElementById('modal_quote_id');
            const productSelect = document.getElementById('product_type');
       
            convertButtons.forEach(button => {
                button.addEventListener('click', function(event) {
                    event.preventDefault();
                    const status = this.getAttribute('data-status');
                    const quoteId = this.getAttribute('data-quote-id');
       
                    if (status === 'active') {
                        policyErrorModal.show();
                        return;
                    }
       
                    // Show product selection modal
                    quoteIdInput.value = quoteId;
                    productSelect.value = ''; // Reset selection
                    productModal.show();
                });
            });
       
            confirmButton.addEventListener('click', function() {
                const quoteId = quoteIdInput.value;
                const productType = productSelect.value;
                if (!productType) {
                    alert('Please select a product.');
                    return;
                }
                // Redirect to step1 with quote_id and product_type
                window.location.href = `underwriting_steps/step1_disclosures.php?quote_id=${quoteId}&product_type=${productType}`;
                productModal.hide();
            });
        });
        </script>
    </body>
</html>
<?php
$stmt->close();
$conn->close();
?>