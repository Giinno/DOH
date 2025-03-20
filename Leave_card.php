<?php
require_once 'config.php';

/**
 * Function to add monthly leave credits
 * 
 * @param PDO $pdo Database connection
 * @return bool True if credits were added, false otherwise
 */
function addMonthlyLeaveCredits(PDO $pdo) {
    // Get the current date
    $currentDate = new DateTime();
    
    // Check if it's the 1st of the month
    if ($currentDate->format('d') !== '01') {
        return false; // Not the 1st of the month, don't add credits
    }

    // Get the last update date from the global_settings table
    $stmt = $pdo->prepare("SELECT value FROM global_settings WHERE `key` = 'last_leave_credit_update'");
    $stmt->execute();
    $lastUpdate = $stmt->fetchColumn();
    
    if (!$lastUpdate) {
        // If there's no last update date, set it to the first day of the previous month
        $lastUpdate = (clone $currentDate)->modify('-1 month')->format('Y-m-01');
    }
    
    $lastUpdateDate = new DateTime($lastUpdate);
    
    // Check if a month has passed since the last update
    if ($currentDate->format('Y-m') === $lastUpdateDate->format('Y-m')) {
        return false; // Credits already added this month
    }
    
    // Add leave credits for all employees
    $vacationLeaveToAdd = 1.25;
    $sickLeaveToAdd = 1.25;
    
    // Update all employees' leave credits
    $stmt = $pdo->prepare("
        UPDATE leave_credits 
        SET vacation_leave = vacation_leave + :vacation, 
            sick_leave = sick_leave + :sick,
            total_leave_credits = total_leave_credits + :total
    ");
    $stmt->execute([
        ':vacation' => $vacationLeaveToAdd, 
        ':sick' => $sickLeaveToAdd, 
        ':total' => $vacationLeaveToAdd + $sickLeaveToAdd
    ]);
    
    // Log the credit update
    $stmt = $pdo->prepare("
        INSERT INTO leave_credit_updates 
        (update_date, vacation_leave_added, sick_leave_added) 
        VALUES (:update_date, :vacation, :sick)
    ");
    $stmt->execute([
        ':update_date' => $currentDate->format('Y-m-d'),
        ':vacation' => $vacationLeaveToAdd,
        ':sick' => $sickLeaveToAdd
    ]);
    
    // Update the last update date in global_settings
    $stmt = $pdo->prepare("
        INSERT INTO global_settings (`key`, value) 
        VALUES ('last_leave_credit_update', :date) 
        ON DUPLICATE KEY UPDATE value = :date
    ");
    $stmt->execute([':date' => $currentDate->format('Y-m-d')]);
    
    return true;
}

/**
 * Calculate working days between two dates (excluding weekends)
 * 
 * @param string $startDate Start date in Y-m-d format
 * @param string $endDate End date in Y-m-d format
 * @return int Number of working days
 */
function calculateWorkingDays($startDate, $endDate) {
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $days = 0;
    
    while ($start <= $end) {
        // 6 = Saturday, 7 = Sunday
        if ($start->format('N') < 6) {
            $days++;
        }
        $start->modify('+1 day');
    }
    return $days;
}

// Create leave_credit_updates table if it doesn't exist
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS leave_credit_updates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            update_date DATE NOT NULL,
            vacation_leave_added DECIMAL(5,2) NOT NULL,
            sick_leave_added DECIMAL(5,2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
} catch (PDOException $e) {
    // Table might already exist or other error
    error_log("Error creating leave_credit_updates table: " . $e->getMessage());
}

// Check and add monthly leave credits
$creditsAdded = addMonthlyLeaveCredits($pdo);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        try {
            $pdo->beginTransaction();

            switch ($_POST['action']) {
                case 'add':
                    // Validate and sanitize input
                    $sickLeave = !empty($_POST['sick_leave']) ? floatval($_POST['sick_leave']) : 0;
                    $vacationLeave = !empty($_POST['vacation_leave']) ? floatval($_POST['vacation_leave']) : 0;
                    $balanceForwarded = !empty($_POST['balance_forwarded']) ? floatval($_POST['balance_forwarded']) : 0;
                    $firstName = trim($_POST['first_name']);
                    $lastName = trim($_POST['last_name']);
                    
                    // Calculate total leave credits
                    $totalLeaveCredits = $sickLeave + $vacationLeave;

                    // First, insert leave credits
                    $stmt = $pdo->prepare("
                        INSERT INTO leave_credits 
                        (total_leave_credits, sick_leave, vacation_leave, balance_forwarded) 
                        VALUES (:total, :sick, :vacation, :balance)
                    ");
                    $stmt->execute([
                        ':total' => $totalLeaveCredits, 
                        ':sick' => $sickLeave, 
                        ':vacation' => $vacationLeave, 
                        ':balance' => $balanceForwarded
                    ]);
                    $leaveCreditsId = $pdo->lastInsertId();

                    // Then, insert the employee
                    $stmt = $pdo->prepare("
                        INSERT INTO employees 
                        (name, first_name, last_name, leave_credits_id) 
                        VALUES (:name, :first_name, :last_name, :leave_credits_id)
                    ");
                    $stmt->execute([
                        ':name' => $firstName . ' ' . $lastName,
                        ':first_name' => $firstName,
                        ':last_name' => $lastName,
                        ':leave_credits_id' => $leaveCreditsId
                    ]);
                    $message = "Employee added successfully.";
                    break;

                case 'edit':
                    // Validate and sanitize input
                    $sickLeave = !empty($_POST['sick_leave']) ? floatval($_POST['sick_leave']) : 0;
                    $vacationLeave = !empty($_POST['vacation_leave']) ? floatval($_POST['vacation_leave']) : 0;
                    $balanceForwarded = !empty($_POST['balance_forwarded']) ? floatval($_POST['balance_forwarded']) : 0;
                    $firstName = trim($_POST['first_name']);
                    $lastName = trim($_POST['last_name']);
                    $employeeId = intval($_POST['id']);
                    
                    // Calculate total leave credits
                    $totalLeaveCredits = $sickLeave + $vacationLeave;

                    // Update leave credits
                    $stmt = $pdo->prepare("
                        UPDATE leave_credits 
                        SET total_leave_credits = :total, 
                            sick_leave = :sick, 
                            vacation_leave = :vacation, 
                            balance_forwarded = :balance 
                        WHERE id = (SELECT leave_credits_id FROM employees WHERE id = :id)
                    ");
                    $stmt->execute([
                        ':total' => $totalLeaveCredits, 
                        ':sick' => $sickLeave, 
                        ':vacation' => $vacationLeave, 
                        ':balance' => $balanceForwarded,
                        ':id' => $employeeId
                    ]);

                    // Update employee
                    $stmt = $pdo->prepare("
                        UPDATE employees 
                        SET name = :name, 
                            first_name = :first_name, 
                            last_name = :last_name 
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':name' => $firstName . ' ' . $lastName,
                        ':first_name' => $firstName,
                        ':last_name' => $lastName,
                        ':id' => $employeeId
                    ]);
                    $message = "Employee updated successfully.";
                    break;

                case 'delete':
                    $employeeId = intval($_POST['id']);
                    
                    // First, get the leave_credits_id
                    $stmt = $pdo->prepare("SELECT leave_credits_id FROM employees WHERE id = :id");
                    $stmt->execute([':id' => $employeeId]);
                    $leaveCreditsId = $stmt->fetchColumn();

                    // Delete the employee
                    $stmt = $pdo->prepare("DELETE FROM employees WHERE id = :id");
                    $stmt->execute([':id' => $employeeId]);

                    // Delete associated leave credits
                    if ($leaveCreditsId) {
                        $stmt = $pdo->prepare("DELETE FROM leave_credits WHERE id = :id");
                        $stmt->execute([':id' => $leaveCreditsId]);
                    }

                    $message = "Employee deleted successfully.";
                    break;

                case 'apply_leave':
                    $employeeId = intval($_POST['employee_id']);
                    $leaveType = $_POST['leave_type'];
                    $startDate = $_POST['start_date'];
                    $endDate = $_POST['end_date'];

                    // Validate leave type
                    if (!in_array($leaveType, ['sick_leave', 'vacation_leave'])) {
                        throw new Exception("Invalid leave type");
                    }

                    // Calculate number of working days
                    $numberOfDays = calculateWorkingDays($startDate, $endDate);

                    // Get current leave credits
                    $stmt = $pdo->prepare("
                        SELECT lc.* 
                        FROM leave_credits lc 
                        JOIN employees e ON e.leave_credits_id = lc.id 
                        WHERE e.id = :id
                    ");
                    $stmt->execute([':id' => $employeeId]);
                    $currentCredits = $stmt->fetch(PDO::FETCH_ASSOC);

                    // Check if enough credits are available
                    if ($leaveType === 'sick_leave' && $currentCredits['sick_leave'] < $numberOfDays) {
                        throw new Exception("Insufficient sick leave credits");
                    } elseif ($leaveType === 'vacation_leave' && $currentCredits['vacation_leave'] < $numberOfDays) {
                        throw new Exception("Insufficient vacation leave credits");
                    }

                    // Insert leave application
                    $stmt = $pdo->prepare("
                        INSERT INTO leave_applications 
                        (employee_id, leave_type, start_date, end_date, number_of_days) 
                        VALUES (:employee_id, :leave_type, :start_date, :end_date, :days)
                    ");
                    $stmt->execute([
                        ':employee_id' => $employeeId, 
                        ':leave_type' => $leaveType, 
                        ':start_date' => $startDate, 
                        ':end_date' => $endDate, 
                        ':days' => $numberOfDays
                    ]);

                    // Update leave credits
                    $updateField = $leaveType === 'sick_leave' ? 'sick_leave' : 'vacation_leave';
                    $stmt = $pdo->prepare("
                        UPDATE leave_credits 
                        SET $updateField = $updateField - :days,
                            total_leave_credits = total_leave_credits - :days
                        WHERE id = (SELECT leave_credits_id FROM employees WHERE id = :id)
                    ");
                    $stmt->execute([':days' => $numberOfDays, ':id' => $employeeId]);

                    $message = "Leave application submitted successfully";
                    break;
            }

            $pdo->commit();
            // Redirect to prevent form resubmission
            header("Location: " . $_SERVER['PHP_SELF'] . "?message=" . urlencode($message));
            exit();
        } catch (PDOException | Exception $e) {
            $pdo->rollBack();
            $error = "Error: " . $e->getMessage();
            header("Location: " . $_SERVER['PHP_SELF'] . "?error=" . urlencode($error));
            exit();
        }
    }
}

// Fetch all employees with their leave credits
$search = isset($_GET['search']) ? $_GET['search'] : '';
$stmt = $pdo->prepare("
    SELECT 
        e.id,
        e.name,
        e.first_name,
        e.last_name,
        lc.total_leave_credits,
        lc.sick_leave,
        lc.vacation_leave,
        lc.balance_forwarded
    FROM 
        employees e
    LEFT JOIN 
        leave_credits lc ON e.leave_credits_id = lc.id
    WHERE 
        e.name LIKE :search
    ORDER BY 
        e.name
");
$stmt->execute([':search' => "%$search%"]);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch leave history
$stmt = $pdo->prepare("
    SELECT 
        la.*,
        e.name as employee_name
    FROM 
        leave_applications la
    JOIN 
        employees e ON la.employee_id = e.id
    ORDER BY 
        la.start_date DESC
");
$stmt->execute();
$leaveHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch credit update history
$stmt = $pdo->prepare("
    SELECT * FROM leave_credit_updates
    ORDER BY update_date DESC
    LIMIT 10
");
$stmt->execute();
$creditUpdateHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get the last update date
$stmt = $pdo->prepare("SELECT value FROM global_settings WHERE `key` = 'last_leave_credit_update'");
$stmt->execute();
$lastUpdateDate = $stmt->fetchColumn();
$formattedLastUpdate = $lastUpdateDate ? date('F d, Y', strtotime($lastUpdateDate)) : 'Never';

// Calculate next update date
$nextUpdate = $lastUpdateDate ? 
    date('F d, Y', strtotime(date('Y-m-01', strtotime('+1 month', strtotime($lastUpdateDate))))) : 
    date('F d, Y', strtotime(date('Y-m-01', strtotime('+1 month'))));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Summary of Leave Credit Balances</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="./leave_card.css">
</head>
<body>
    <div class="header">
        <div class="header-content">
            <img src="logow.png" alt="DOH Logo" class="img-fluid" style="max-height: 80px;">
            <div class="org-info">
                <h3>REPUBLIC OF THE PHILIPPINES</h3>
                <h3>DEPARTMENT OF HEALTH</h3>
                <h3>ZAMBOANGA PENINSULA CENTER FOR HEALTH DEVELOPMENT</h3>
                <p>Upper Calarian, Zamboanga City 7000</p>
            </div>
        </div>
    </div>

    <div class="container">
        <h2 class="text-center mb-4" style="margin-top: 20px;">SUMMARY OF LEAVE CREDIT BALANCES</h2>
        
        <?php if ($creditsAdded): ?>
        <div class="alert alert-success" role="alert">
            <i class="bx bx-check-circle"></i> Monthly leave credits have been added successfully.
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bx bx-check-circle"></i> <?php echo htmlspecialchars($_GET['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bx bx-error-circle"></i> <?php echo htmlspecialchars($_GET['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <div class="mb-3">
            <button onclick="openModal('add')" class="btn btn-success">
                <i class="bx bx-plus-circle"></i> Add New Employee
            </button>
            <button onclick="openModal('apply_leave')" class="btn btn-primary">
                <i class="bx bx-calendar"></i> Apply Leave
            </button>
            <button onclick="openRecentChangesModal()" class="btn btn-info">
                <i class="bx bx-history"></i> View Recent Changes
            </button>
            <div class="btn-group">
                <button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bx bx-printer"></i> Generate Reports
                </button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="generate_report.php" target="_blank">Summary Report (All Employees)</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><h6 class="dropdown-header">Individual Employee Reports</h6></li>
                    <?php foreach($employees as $emp): ?>
                        <li><a class="dropdown-item" href="employee_leave_report.php?id=<?php echo $emp['id']; ?>" target="_blank">
                            <?php echo htmlspecialchars($emp['name']); ?>
                        </a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <!-- System Information Card -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <i class="bx bx-info-circle"></i> System Information
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Last Leave Credits Update:</strong> <?php echo $formattedLastUpdate; ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Next Scheduled Update:</strong> <?php echo $nextUpdate; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search form -->
        <form id="searchForm" class="mb-3">
            <div class="input-group">
                <span class="input-group-text"><i class="bx bx-search"></i></span>
                <input type="text" id="searchInput" name="search" class="form-control" placeholder="Search by employee name" value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-outline-secondary">Search</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-striped table-bordered table-custom">
                <thead class="table-dark">
                    <tr>
                        <th>NAME OF EMPLOYEE</th>
                        <th>BALANCE FORWARDED</th>
                        <th>VACATION</th>
                        <th>SICK</th>
                        <th>TOTAL</th>
                        <th>ACTIONS</th>
                    </tr>
                </thead>
                <tbody id="employeeTableBody">
                    <?php if (count($employees) > 0): ?>
                        <?php foreach($employees as $employee): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($employee['name']); ?></td>
                            <td class="text-end"><?php echo number_format($employee['balance_forwarded'], 2); ?></td>
                            <td class="text-end"><?php echo number_format($employee['vacation_leave'], 2); ?></td>
                            <td class="text-end"><?php echo number_format($employee['sick_leave'], 2); ?></td>
                            <td class="text-end"><?php echo number_format($employee['total_leave_credits'], 2); ?></td>
                            <td>
                                <button onclick="openModal('edit', <?php echo htmlspecialchars(json_encode($employee)); ?>)" class="btn btn-sm btn-secondary">
                                    <i class="bx bx-edit-alt"></i> Edit
                                </button>
                                <button onclick="openModal('delete', <?php echo htmlspecialchars(json_encode($employee)); ?>)" class="btn btn-sm btn-danger">
                                    <i class="bx bx-trash"></i> Delete
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center">No employees found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add Modal -->
    <div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Employee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label for="first_name" class="form-label">First Name:</label>
                            <input type="text" class="form-control" id="first_name" name="first_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="last_name" class="form-label">Last Name:</label>
                            <input type="text" class="form-control" id="last_name" name="last_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="balance_forwarded" class="form-label">Balance Forwarded:</label>
                            <input type="number" step="0.01" class="form-control" id="balance_forwarded" name="balance_forwarded" value="0" required onchange="updateTotal()">
                        </div>
                        <div class="mb-3">
                            <label for="vacation_leave" class="form-label">Vacation Leave:</label>
                            <input type="number" step="0.01" class="form-control" id="vacation_leave" name="vacation_leave" value="0" required onchange="updateTotal()">
                        </div>
                        <div class="mb-3">
                            <label for="sick_leave" class="form-label">Sick Leave:</label>
                            <input type="number" step="0.01" class="form-control" id="sick_leave" name="sick_leave" value="0" required onchange="updateTotal()">
                        </div>
                        <div class="mb-3">
                            <label for="total_leave_credits" class="form-label">Total Leave Credits:</label>
                            <input type="number" step="0.01" class="form-control" id="total_leave_credits" name="total_leave_credits" value="0" readonly>
                        </div>
                        <button type="submit" class="btn btn-primary">Add Employee</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Employee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="mb-3">
                            <label for="edit_first_name" class="form-label">First Name:</label>
                            <input type="text" class="form-control" id="edit_first_name" name="first_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_last_name" class="form-label">Last Name:</label>
                            <input type="text" class="form-control" id="edit_last_name" name="last_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_balance_forwarded" class="form-label">Balance Forwarded:</label>
                            <input type="number" step="0.01" class="form-control" id="edit_balance_forwarded" name="balance_forwarded" required onchange="updateTotal('edit_')">
                        </div>
                        <div class="mb-3">
                            <label for="edit_vacation_leave" class="form-label">Vacation Leave:</label>
                            <input type="number" step="0.01" class="form-control" id="edit_vacation_leave" name="vacation_leave" required onchange="updateTotal('edit_')">
                        </div>
                        <div class="mb-3">
                            <label for="edit_sick_leave" class="form-label">Sick Leave:</label>
                            <input type="number" step="0.01" class="form-control" id="edit_sick_leave" name="sick_leave" required onchange="updateTotal('edit_')">
                        </div>
                        <div class="mb-3">
                            <label for="edit_total_leave_credits" class="form-label">Total Leave Credits:</label>
                            <input type="number" step="0.01" class="form-control" id="edit_total_leave_credits" name="total_leave_credits" readonly>
                        </div>
                        <button type="submit" class="btn btn-primary">Update Employee</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Delete Employee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this employee?</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" id="delete_id">
                        <button type="submit" class="btn btn-danger">Confirm Delete</button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Apply Leave Modal -->
    <div class="modal fade" id="apply_leaveModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Apply Leave</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="apply_leave">
                        <div class="mb-3">
                            <label for="employee_id" class="form-label">Employee:</label>
                            <select class="form-select" id="employee_id" name="employee_id" required>
                                <?php foreach($employees as $employee): ?>
                                <option value="<?php echo htmlspecialchars($employee['id']); ?>">
                                    <?php echo htmlspecialchars($employee['name']); ?> 
                                    (Sick: <?php echo number_format($employee['sick_leave'], 2); ?>, 
                                    Vacation: <?php echo number_format($employee['vacation_leave'], 2); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="leave_type" class="form-label">Leave Type:</label>
                            <select class="form-select" id="leave_type" name="leave_type" required>
                                <option value="sick_leave">Sick Leave</option>
                                <option value="vacation_leave">Vacation Leave</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="start_date" class="form-label">Start Date:</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" required>
                        </div>
                        <div class="mb-3">
                            <label for="end_date" class="form-label">End Date:</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Submit Leave Application</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Changes Modal -->
    <div class="modal fade" id="recentChangesModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Recent Changes</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Nav tabs -->
                    <ul class="nav nav-tabs" id="recentChangesTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="credit-updates-tab" data-bs-toggle="tab" data-bs-target="#credit-updates" type="button" role="tab" aria-controls="credit-updates" aria-selected="true">
                                <i class="bx bx-plus-circle"></i> Credit Updates
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="leave-history-tab" data-bs-toggle="tab" data-bs-target="#leave-history" type="button" role="tab" aria-controls="leave-history" aria-selected="false">
                                <i class="bx bx-calendar"></i> Leave History
                            </button>
                        </li>
                    </ul>
                    
                    <!-- Tab content -->
                    <div class="tab-content mt-3">
                        <!-- Credit Updates Tab -->
                        <div class="tab-pane fade show active" id="credit-updates" role="tabpanel" aria-labelledby="credit-updates-tab">
                            <div class="table-responsive">
                                <table class="table table-striped table-bordered">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Update Date</th>
                                            <th>Vacation Leave Added</th>
                                            <th>Sick Leave Added</th>
                                            <th>Total Added</th>
                                            <th>Timestamp</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($creditUpdateHistory) > 0): ?>
                                            <?php foreach($creditUpdateHistory as $update): ?>
                                            <tr>
                                                <td><?php echo date('F d, Y', strtotime($update['update_date'])); ?></td>
                                                <td><?php echo number_format($update['vacation_leave_added'], 2); ?></td>
                                                <td><?php echo number_format($update['sick_leave_added'], 2); ?></td>
                                                <td><?php echo number_format($update['vacation_leave_added'] + $update['sick_leave_added'], 2); ?></td>
                                                <td><?php echo date('F d, Y H:i:s', strtotime($update['created_at'])); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5" class="text-center">No credit update history found</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Leave History Tab -->
                        <div class="tab-pane fade" id="leave-history" role="tabpanel" aria-labelledby="leave-history-tab">
                            <div class="table-responsive">
                                <table class="table table-striped table-bordered">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Employee</th>
                                            <th>Leave Type</th>
                                            <th>Start Date</th>
                                            <th>End Date</th>
                                            <th>Days</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($leaveHistory) > 0): ?>
                                            <?php foreach($leaveHistory as $leave): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($leave['employee_name']); ?></td>
                                                <td><?php echo ucfirst(str_replace('_', ' ', $leave['leave_type'])); ?></td>
                                                <td><?php echo date('M d, Y', strtotime($leave['start_date'])); ?></td>
                                                <td><?php echo date('M d, Y', strtotime($leave['end_date'])); ?></td>
                                                <td><?php echo $leave['number_of_days']; ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5" class="text-center">No leave history found</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS and Popper.js -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.min.js"></script>
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        function openModal(type, data) {
            const modal = new bootstrap.Modal(document.getElementById(type + 'Modal'));
            modal.show();
            if (type === 'edit' || type === 'delete') {
                document.getElementById(type + '_id').value = data.id;
                if (type === 'edit') {
                    document.getElementById('edit_first_name').value = data.first_name || '';
                    document.getElementById('edit_last_name').value = data.last_name || '';
                    document.getElementById('edit_balance_forwarded').value = data.balance_forwarded || 0;
                    document.getElementById('edit_vacation_leave').value = data.vacation_leave || 0;
                    document.getElementById('edit_sick_leave').value = data.sick_leave || 0;
                    updateTotal('edit_');
                }
            }
        }

        function openRecentChangesModal() {
            const modal = new bootstrap.Modal(document.getElementById('recentChangesModal'));
            modal.show();
        }

        function updateTotal(prefix = '') {
            const vacationLeave = parseFloat(document.getElementById(prefix + 'vacation_leave').value) || 0;
            const sickLeave = parseFloat(document.getElementById(prefix + 'sick_leave').value) || 0;
            const total = vacationLeave + sickLeave;
            document.getElementById(prefix + 'total_leave_credits').value = total.toFixed(2);
        }

        // Handle search functionality
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const tableRows = document.querySelectorAll('#employeeTableBody tr');

            tableRows.forEach(row => {
                const name = row.querySelector('td:first-child').textContent.toLowerCase();
                if (name.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });

        // Date validation for leave application
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const message = urlParams.get('message');
            const error = urlParams.get('error');

            if (message) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success',
                    text: message,
                });
            } else if (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: error,
                });
            }

            // Date validation for leave application
            const startDate = document.getElementById('start_date');
            const endDate = document.getElementById('end_date');

            if (startDate && endDate) {
                startDate.addEventListener('change', function() {
                    endDate.min = this.value;
                });

                endDate.addEventListener('change', function() {
                    if (startDate.value && this.value < startDate.value) {
                        this.value = startDate.value;
                    }
                });

                // Set minimum date to today
                const today = new Date().toISOString().split('T')[0];
                startDate.min = today;
                endDate.min = today;
            }
        });
    </script>
</body>
</html>