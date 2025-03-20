<?php
require_once 'config.php';

// Fetch all employees with their leave credits
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
    ORDER BY 
        e.name
");
$stmt->execute();
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Credits Report</title>
    <style>
        @media print {
            .no-print {
                display: none;
            }
            body {
                padding: 20px;
            }
        }
        
        body {
            font-family: Arial, sans-serif;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .header img {
            max-height: 80px;
        }
        
        .org-info h3 {
            margin: 5px 0;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        th, td {
            border: 1px solid #000;
            padding: 8px;
            text-align: left;
        }
        
        th {
            background-color: #f2f2f2;
        }
        
        .text-end {
            text-align: right;
        }
        
        .report-date {
            text-align: right;
            margin-bottom: 20px;
        }
        
        .print-button {
            padding: 10px 20px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-bottom: 20px;
        }
        
        .print-button:hover {
            background-color: #0056b3;
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

    <h2 style="text-align: center;">EMPLOYEE LEAVE CREDITS SUMMARY REPORT</h2>

    <table>
        <thead>
            <tr>
                <th>Employee Name</th>
                <th>Balance Forwarded</th>
                <th>Vacation Leave Credits</th>
                <th>Used Vacation Leave</th>
                <th>Remaining Vacation Leave</th>
                <th>Sick Leave Credits</th>
                <th>Used Sick Leave</th>
                <th>Remaining Sick Leave</th>
                <th>Total Remaining Credits</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($employees as $employee): ?>
                <?php
                    $remaining_vacation = $employee['vacation_leave'] - $employee['used_vacation_leave'];
                    $remaining_sick = $employee['sick_leave'] - $employee['used_sick_leave'];
                    $total_remaining = $remaining_vacation + $remaining_sick;
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($employee['name']); ?></td>
                    <td class="text-end"><?php echo number_format($employee['balance_forwarded'], 2); ?></td>
                    <td class="text-end"><?php echo number_format($employee['vacation_leave'], 2); ?></td>
                    <td class="text-end"><?php echo number_format($employee['used_vacation_leave'], 2); ?></td>
                    <td class="text-end"><?php echo number_format($remaining_vacation, 2); ?></td>
                    <td class="text-end"><?php echo number_format($employee['sick_leave'], 2); ?></td>
                    <td class="text-end"><?php echo number_format($employee['used_sick_leave'], 2); ?></td>
                    <td class="text-end"><?php echo number_format($remaining_sick, 2); ?></td>
                    <td class="text-end"><?php echo number_format($total_remaining, 2); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div style="margin-top: 50px;">
        <div style="float: left; width: 45%;">
            <p style="margin-bottom: 40px;">Prepared by:</p>
            <div style="border-top: 1px solid #000; width: 80%; text-align: center;">
                <p>HR Officer</p>
            </div>
        </div>
        <div style="float: right; width: 45%;">
            <p style="margin-bottom: 40px;">Noted by:</p>
            <div style="border-top: 1px solid #000; width: 80%; text-align: center;">
                <p>Department Head</p>
            </div>
        </div>
    </div>
</body>
</html>