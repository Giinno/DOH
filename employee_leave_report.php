<?php
require_once 'config.php';

$employee_id = isset($_GET['id']) ? $_GET['id'] : null;

if (!$employee_id) {
    header("Location: leave_card.php");
    exit();
}

// Fetch employee details with leave credits
$stmt = $pdo->prepare("
    SELECT 
        e.id,
        e.name,
        e.first_name,
        e.last_name,
        lc.total_leave_credits,
        lc.sick_leave,
        lc.vacation_leave,
        lc.balance_forwarded,
        (
            SELECT COALESCE(SUM(number_of_days), 0)
            FROM leave_applications
            WHERE employee_id = e.id
            AND leave_type = 'sick_leave'
        ) as used_sick_leave,
        (
            SELECT COALESCE(SUM(number_of_days), 0)
            FROM leave_applications
            WHERE employee_id = e.id
            AND leave_type = 'vacation_leave'
        ) as used_vacation_leave
    FROM 
        employees e
    LEFT JOIN 
        leave_credits lc ON e.leave_credits_id = lc.id
    WHERE 
        e.id = ?
");
$stmt->execute([$employee_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    header("Location: leave_card.php");
    exit();
}

// Fetch leave history for the employee
$stmt = $pdo->prepare("
    SELECT 
        leave_type,
        start_date,
        end_date,
        number_of_days
    FROM 
        leave_applications
    WHERE 
        employee_id = ?
    ORDER BY 
        start_date DESC
");
$stmt->execute([$employee_id]);
$leave_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Leave History Report</title>
    <style>
        @media print {
            .no-print { display: none; }
            body { padding: 20px; }
        }
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #007bff;
            padding-bottom: 20px;
        }
        .header img {
            max-height: 100px;
            margin-bottom: 10px;
        }
        .org-info h3 { margin: 5px 0; }
        .employee-info, .summary-box {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        th, td {
            border: 1px solid #dee2e6;
            padding: 12px;
            text-align: left;
        }
        th {
            background-color: #007bff;
            color: white;
            font-weight: bold;
        }
        tr:nth-child(even) { background-color: #f8f9fa; }
        .text-end { text-align: right; }
        .report-date {
            text-align: right;
            margin-bottom: 20px;
            font-style: italic;
        }
        .print-button {
            padding: 10px 20px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-bottom: 20px;
            transition: background-color 0.3s;
        }
        .print-button:hover { background-color: #0056b3; }
        .summary-box h3 { margin-top: 0; color: #007bff; }
        .leave-type { text-transform: capitalize; }
        .signatures {
            display: flex;
            justify-content: space-between;
            margin-top: 50px;
        }
        .signature-box {
            width: 45%;
        }
        .signature-line {
            border-top: 1px solid #000;
            width: 80%;
            margin-top: 40px;
            text-align: center;
        }
    </style>
</head>
<body>
    <button onclick="window.print()" class="print-button no-print">Print Report</button>
    
    <div class="header">
        <img src="logo.jpg" alt="DOH Logo">
        <div class="org-info">
            <h3>REPUBLIC OF THE PHILIPPINES</h3>
            <h3>DEPARTMENT OF HEALTH</h3>
            <h3>ZAMBOANGA PENINSULA CENTER FOR HEALTH DEVELOPMENT</h3>
            <p>Upper Calarian, Zamboanga City 7000</p>
        </div>
    </div>

    <div class="report-date">
        <p>Report Generated: <?php echo date('F d, Y'); ?></p>
    </div>

    <h2 style="text-align: center; color: #007bff;">EMPLOYEE LEAVE HISTORY REPORT</h2>

    <div class="employee-info">
        <h3 style="color: #007bff;">Employee Information</h3>
        <p><strong>Name:</strong> <?php echo htmlspecialchars($employee['name']); ?></p>
        
        <div class="summary-box">
            <h3>Leave Credits Summary</h3>
            <table>
                <tr>
                    <th>Leave Type</th>
                    <th>Total Credits</th>
                    <th>Used</th>
                    <th>Remaining</th>
                </tr>
                <tr>
                    <td>Vacation Leave</td>
                    <td class="text-end"><?php echo number_format($employee['vacation_leave'], 2); ?></td>
                    <td class="text-end"><?php echo number_format($employee['used_vacation_leave'], 2); ?></td>
                    <td class="text-end"><?php echo number_format($employee['vacation_leave'] - $employee['used_vacation_leave'], 2); ?></td>
                </tr>
                <tr>
                    <td>Sick Leave</td>
                    <td class="text-end"><?php echo number_format($employee['sick_leave'], 2); ?></td>
                    <td class="text-end"><?php echo number_format($employee['used_sick_leave'], 2); ?></td>
                    <td class="text-end"><?php echo number_format($employee['sick_leave'] - $employee['used_sick_leave'], 2); ?></td>
                </tr>
            </table>
        </div>
    </div>

    <h3 style="color: #007bff;">Leave History</h3>
    <table>
        <thead>
            <tr>
                <th>Leave Type</th>
                <th>Start Date</th>
                <th>End Date</th>
                <th>Number of Days</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($leave_history)): ?>
            <tr>
                <td colspan="4" style="text-align: center;">No leave history found</td>
            </tr>
            <?php else: ?>
                <?php foreach($leave_history as $leave): ?>
                <tr>
                    <td class="leave-type"><?php echo str_replace('_', ' ', $leave['leave_type']); ?></td>
                    <td><?php echo date('F d, Y', strtotime($leave['start_date'])); ?></td>
                    <td><?php echo date('F d, Y', strtotime($leave['end_date'])); ?></td>
                    <td class="text-end"><?php echo number_format($leave['number_of_days'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="signatures">
        <div class="signature-box">
            <p>Prepared by:</p>
            <div class="signature-line">
                <p>HR Officer</p>
            </div>
        </div>
        <div class="signature-box">
            <p>Noted by:</p>
            <div class="signature-line">
                <p>Department Head</p>
            </div>
        </div>
    </div>
</body>
</html>