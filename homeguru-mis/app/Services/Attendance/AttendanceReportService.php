<?php

namespace App\Services\Attendance;

use App\Libraries\Database\Connection;
use App\Libraries\PDF\PDFGenerator;
use App\Services\Communication\EmailService;
use App\Exceptions\ValidationException;

class AttendanceReportService
{
    private $connection;
    private $pdfGenerator;
    private $emailService;
    
    public function __construct()
    {
        $this->connection = Connection::getInstance();
        $this->pdfGenerator = new PDFGenerator();
        $this->emailService = new EmailService();
    }
    
    /**
     * Generate daily attendance report
     */
    public function generateDailyReport($date, $classId = null)
    {
        $sql = "SELECT 
                    s.id,
                    s.first_name,
                    s.last_name,
                    s.roll_number,
                    c.name as class_name,
                    sec.name as section_name,
                    sa.status,
                    sa.period,
                    sub.name as subject_name,
                    t.first_name as teacher_first_name,
                    t.last_name as teacher_last_name
                FROM student_attendance sa
                LEFT JOIN students s ON sa.student_id = s.id
                LEFT JOIN classes c ON s.class_id = c.id
                LEFT JOIN sections sec ON s.section_id = sec.id
                LEFT JOIN subjects sub ON sa.subject_id = sub.id
                LEFT JOIN teachers t ON sa.teacher_id = t.id
                WHERE sa.date = ?";
        
        $params = [$date];
        
        if ($classId) {
            $sql .= " AND s.class_id = ?";
            $params[] = $classId;
        }
        
        $sql .= " ORDER BY c.name, s.roll_number, sa.period";
        
        $attendance = $this->connection->fetchAll($sql, $params);
        
        // Calculate summary
        $summary = $this->calculateDailySummary($attendance);
        
        return [
            'date' => $date,
            'attendance' => $attendance,
            'summary' => $summary
        ];
    }
    
    /**
     * Generate monthly attendance report
     */
    public function generateMonthlyReport($month, $year, $classId = null)
    {
        $startDate = "{$year}-{$month}-01";
        $endDate = date('Y-m-t', strtotime($startDate));
        
        $sql = "SELECT 
                    s.id,
                    s.first_name,
                    s.last_name,
                    s.roll_number,
                    c.name as class_name,
                    sec.name as section_name,
                    COUNT(*) as total_days,
                    SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) as present_days,
                    SUM(CASE WHEN sa.status = 'absent' THEN 1 ELSE 0 END) as absent_days,
                    SUM(CASE WHEN sa.status = 'late' THEN 1 ELSE 0 END) as late_days,
                    ROUND((SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as percentage
                FROM student_attendance sa
                LEFT JOIN students s ON sa.student_id = s.id
                LEFT JOIN classes c ON s.class_id = c.id
                LEFT JOIN sections sec ON s.section_id = sec.id
                WHERE sa.date BETWEEN ? AND ?";
        
        $params = [$startDate, $endDate];
        
        if ($classId) {
            $sql .= " AND s.class_id = ?";
            $params[] = $classId;
        }
        
        $sql .= " GROUP BY s.id, s.first_name, s.last_name, s.roll_number, c.name, sec.name
                  ORDER BY c.name, s.roll_number";
        
        $attendance = $this->connection->fetchAll($sql, $params);
        
        // Calculate summary
        $summary = $this->calculateMonthlySummary($attendance);
        
        return [
            'month' => $month,
            'year' => $year,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'attendance' => $attendance,
            'summary' => $summary
        ];
    }
    
    /**
     * Generate class-wise attendance report
     */
    public function generateClassWiseReport($classId, $startDate, $endDate)
    {
        $sql = "SELECT 
                    s.id,
                    s.first_name,
                    s.last_name,
                    s.roll_number,
                    c.name as class_name,
                    sec.name as section_name,
                    COUNT(*) as total_days,
                    SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) as present_days,
                    SUM(CASE WHEN sa.status = 'absent' THEN 1 ELSE 0 END) as absent_days,
                    SUM(CASE WHEN sa.status = 'late' THEN 1 ELSE 0 END) as late_days,
                    ROUND((SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as percentage
                FROM student_attendance sa
                LEFT JOIN students s ON sa.student_id = s.id
                LEFT JOIN classes c ON s.class_id = c.id
                LEFT JOIN sections sec ON s.section_id = sec.id
                WHERE s.class_id = ? AND sa.date BETWEEN ? AND ?
                GROUP BY s.id, s.first_name, s.last_name, s.roll_number, c.name, sec.name
                ORDER BY s.roll_number";
        
        $attendance = $this->connection->fetchAll($sql, [$classId, $startDate, $endDate]);
        
        // Calculate summary
        $summary = $this->calculateMonthlySummary($attendance);
        
        return [
            'class_id' => $classId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'attendance' => $attendance,
            'summary' => $summary
        ];
    }
    
    /**
     * Generate teacher attendance report
     */
    public function generateTeacherAttendanceReport($teacherId = null, $startDate, $endDate)
    {
        $whereClause = "ta.date BETWEEN ? AND ?";
        $params = [$startDate, $endDate];
        
        if ($teacherId) {
            $whereClause .= " AND ta.teacher_id = ?";
            $params[] = $teacherId;
        }
        
        $sql = "SELECT 
                    t.id,
                    t.first_name,
                    t.last_name,
                    t.employee_id,
                    d.name as department_name,
                    COUNT(*) as total_days,
                    SUM(CASE WHEN ta.status = 'present' THEN 1 ELSE 0 END) as present_days,
                    SUM(CASE WHEN ta.status = 'absent' THEN 1 ELSE 0 END) as absent_days,
                    SUM(CASE WHEN ta.status = 'late' THEN 1 ELSE 0 END) as late_days,
                    ROUND((SUM(CASE WHEN ta.status = 'present' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as percentage
                FROM teacher_attendance ta
                LEFT JOIN teachers t ON ta.teacher_id = t.id
                LEFT JOIN departments d ON t.department_id = d.id
                WHERE {$whereClause}
                GROUP BY t.id, t.first_name, t.last_name, t.employee_id, d.name
                ORDER BY t.first_name, t.last_name";
        
        $attendance = $this->connection->fetchAll($sql, $params);
        
        // Calculate summary
        $summary = $this->calculateMonthlySummary($attendance);
        
        return [
            'teacher_id' => $teacherId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'attendance' => $attendance,
            'summary' => $summary
        ];
    }
    
    /**
     * Generate employee attendance report
     */
    public function generateEmployeeAttendanceReport($employeeId = null, $startDate, $endDate)
    {
        $whereClause = "ea.date BETWEEN ? AND ?";
        $params = [$startDate, $endDate];
        
        if ($employeeId) {
            $whereClause .= " AND ea.employee_id = ?";
            $params[] = $employeeId;
        }
        
        $sql = "SELECT 
                    e.id,
                    e.first_name,
                    e.last_name,
                    e.employee_id,
                    d.name as department_name,
                    des.name as designation_name,
                    COUNT(*) as total_days,
                    SUM(CASE WHEN ea.status = 'present' THEN 1 ELSE 0 END) as present_days,
                    SUM(CASE WHEN ea.status = 'absent' THEN 1 ELSE 0 END) as absent_days,
                    SUM(CASE WHEN ea.status = 'late' THEN 1 ELSE 0 END) as late_days,
                    ROUND((SUM(CASE WHEN ea.status = 'present' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as percentage
                FROM employee_attendance ea
                LEFT JOIN employees e ON ea.employee_id = e.id
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN designations des ON e.designation_id = des.id
                WHERE {$whereClause}
                GROUP BY e.id, e.first_name, e.last_name, e.employee_id, d.name, des.name
                ORDER BY e.first_name, e.last_name";
        
        $attendance = $this->connection->fetchAll($sql, $params);
        
        // Calculate summary
        $summary = $this->calculateMonthlySummary($attendance);
        
        return [
            'employee_id' => $employeeId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'attendance' => $attendance,
            'summary' => $summary
        ];
    }
    
    /**
     * Generate attendance trends report
     */
    public function generateTrendsReport($classId = null, $days = 30)
    {
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        
        $whereClause = "sa.date BETWEEN ? AND ?";
        $params = [$startDate, $endDate];
        
        if ($classId) {
            $whereClause .= " AND s.class_id = ?";
            $params[] = $classId;
        }
        
        $sql = "SELECT 
                    sa.date,
                    COUNT(DISTINCT sa.student_id) as total_students,
                    SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) as present_count,
                    SUM(CASE WHEN sa.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                    SUM(CASE WHEN sa.status = 'late' THEN 1 ELSE 0 END) as late_count,
                    ROUND((SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) / COUNT(DISTINCT sa.student_id)) * 100, 2) as percentage
                FROM student_attendance sa
                LEFT JOIN students s ON sa.student_id = s.id
                WHERE {$whereClause}
                GROUP BY sa.date
                ORDER BY sa.date";
        
        $trends = $this->connection->fetchAll($sql, $params);
        
        // Calculate trend analysis
        $analysis = $this->analyzeTrends($trends);
        
        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days' => $days,
            'trends' => $trends,
            'analysis' => $analysis
        ];
    }
    
    /**
     * Generate attendance defaulters report
     */
    public function generateDefaultersReport($classId = null, $minPercentage = 75, $startDate = null, $endDate = null)
    {
        $startDate = $startDate ?? date('Y-m-01');
        $endDate = $endDate ?? date('Y-m-t');
        
        $whereClause = "sa.date BETWEEN ? AND ?";
        $params = [$startDate, $endDate];
        
        if ($classId) {
            $whereClause .= " AND s.class_id = ?";
            $params[] = $classId;
        }
        
        $sql = "SELECT 
                    s.id,
                    s.first_name,
                    s.last_name,
                    s.roll_number,
                    c.name as class_name,
                    sec.name as section_name,
                    COUNT(*) as total_days,
                    SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) as present_days,
                    SUM(CASE WHEN sa.status = 'absent' THEN 1 ELSE 0 END) as absent_days,
                    ROUND((SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as percentage
                FROM student_attendance sa
                LEFT JOIN students s ON sa.student_id = s.id
                LEFT JOIN classes c ON s.class_id = c.id
                LEFT JOIN sections sec ON s.section_id = sec.id
                WHERE {$whereClause}
                GROUP BY s.id, s.first_name, s.last_name, s.roll_number, c.name, sec.name
                HAVING percentage < ?
                ORDER BY percentage ASC";
        
        $params[] = $minPercentage;
        
        $defaulters = $this->connection->fetchAll($sql, $params);
        
        // Calculate summary
        $summary = [
            'total_defaulters' => count($defaulters),
            'min_percentage' => $minPercentage,
            'average_percentage' => count($defaulters) > 0 ? 
                round(array_sum(array_column($defaulters, 'percentage')) / count($defaulters), 2) : 0
        ];
        
        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'min_percentage' => $minPercentage,
            'defaulters' => $defaulters,
            'summary' => $summary
        ];
    }
    
    /**
     * Calculate daily summary
     */
    private function calculateDailySummary($attendance)
    {
        $totalStudents = count(array_unique(array_column($attendance, 'id')));
        $totalRecords = count($attendance);
        $presentCount = count(array_filter($attendance, function($record) {
            return $record['status'] === 'present';
        }));
        $absentCount = count(array_filter($attendance, function($record) {
            return $record['status'] === 'absent';
        }));
        $lateCount = count(array_filter($attendance, function($record) {
            return $record['status'] === 'late';
        }));
        
        $percentage = $totalRecords > 0 ? round(($presentCount / $totalRecords) * 100, 2) : 0;
        
        return [
            'total_students' => $totalStudents,
            'total_records' => $totalRecords,
            'present_count' => $presentCount,
            'absent_count' => $absentCount,
            'late_count' => $lateCount,
            'percentage' => $percentage
        ];
    }
    
    /**
     * Calculate monthly summary
     */
    private function calculateMonthlySummary($attendance)
    {
        $totalStudents = count($attendance);
        $totalDays = array_sum(array_column($attendance, 'total_days'));
        $totalPresent = array_sum(array_column($attendance, 'present_days'));
        $totalAbsent = array_sum(array_column($attendance, 'absent_days'));
        $totalLate = array_sum(array_column($attendance, 'late_days'));
        
        $averagePercentage = $totalStudents > 0 ? 
            round(array_sum(array_column($attendance, 'percentage')) / $totalStudents, 2) : 0;
        
        return [
            'total_students' => $totalStudents,
            'total_days' => $totalDays,
            'total_present' => $totalPresent,
            'total_absent' => $totalAbsent,
            'total_late' => $totalLate,
            'average_percentage' => $averagePercentage
        ];
    }
    
    /**
     * Analyze trends
     */
    private function analyzeTrends($trends)
    {
        if (empty($trends)) {
            return [
                'trend' => 'stable',
                'average_percentage' => 0,
                'improvement' => 0
            ];
        }
        
        $percentages = array_column($trends, 'percentage');
        $averagePercentage = round(array_sum($percentages) / count($percentages), 2);
        
        // Calculate trend (improving, declining, stable)
        $firstHalf = array_slice($percentages, 0, floor(count($percentages) / 2));
        $secondHalf = array_slice($percentages, floor(count($percentages) / 2));
        
        $firstHalfAvg = round(array_sum($firstHalf) / count($firstHalf), 2);
        $secondHalfAvg = round(array_sum($secondHalf) / count($secondHalf), 2);
        
        $improvement = $secondHalfAvg - $firstHalfAvg;
        
        if ($improvement > 2) {
            $trend = 'improving';
        } elseif ($improvement < -2) {
            $trend = 'declining';
        } else {
            $trend = 'stable';
        }
        
        return [
            'trend' => $trend,
            'average_percentage' => $averagePercentage,
            'improvement' => $improvement,
            'first_half_avg' => $firstHalfAvg,
            'second_half_avg' => $secondHalfAvg
        ];
    }
    
    /**
     * Generate PDF report
     */
    public function generatePDFReport($reportData, $reportType = 'daily')
    {
        $html = $this->generateReportHTML($reportData, $reportType);
        $filename = "attendance_report_{$reportType}_" . date('Y-m-d') . ".pdf";
        
        return $this->pdfGenerator->generatePDF($html, $filename);
    }
    
    /**
     * Generate report HTML
     */
    private function generateReportHTML($reportData, $reportType)
    {
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Attendance Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { text-align: center; margin-bottom: 30px; }
        .school-name { font-size: 24px; font-weight: bold; margin-bottom: 10px; }
        .report-title { font-size: 18px; margin-bottom: 20px; }
        .summary { margin-bottom: 20px; }
        .summary-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .summary-table td { padding: 8px; border: 1px solid #ddd; }
        .summary-table .label { font-weight: bold; background-color: #f5f5f5; }
        .attendance-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .attendance-table th, .attendance-table td { padding: 8px; border: 1px solid #ddd; text-align: center; }
        .attendance-table th { background-color: #f5f5f5; font-weight: bold; }
        .percentage-good { color: #28a745; font-weight: bold; }
        .percentage-warning { color: #ffc107; font-weight: bold; }
        .percentage-danger { color: #dc3545; font-weight: bold; }
    </style>
</head>
<body>
    <div class="header">
        <div class="school-name">HomeGuru School</div>
        <div class="report-title">Attendance Report - ' . ucfirst($reportType) . '</div>
    </div>';
        
        // Add summary section
        if (isset($reportData['summary'])) {
            $html .= $this->generateSummaryHTML($reportData['summary']);
        }
        
        // Add attendance table
        if (isset($reportData['attendance'])) {
            $html .= $this->generateAttendanceTableHTML($reportData['attendance']);
        }
        
        $html .= '</body></html>';
        
        return $html;
    }
    
    /**
     * Generate summary HTML
     */
    private function generateSummaryHTML($summary)
    {
        $html = '<div class="summary">
            <table class="summary-table">
                <tr>
                    <td class="label">Total Students:</td>
                    <td>' . $summary['total_students'] . '</td>
                    <td class="label">Total Days:</td>
                    <td>' . ($summary['total_days'] ?? $summary['total_records']) . '</td>
                </tr>
                <tr>
                    <td class="label">Present:</td>
                    <td>' . ($summary['total_present'] ?? $summary['present_count']) . '</td>
                    <td class="label">Absent:</td>
                    <td>' . ($summary['total_absent'] ?? $summary['absent_count']) . '</td>
                </tr>
                <tr>
                    <td class="label">Late:</td>
                    <td>' . ($summary['total_late'] ?? $summary['late_count']) . '</td>
                    <td class="label">Average Percentage:</td>
                    <td>' . ($summary['average_percentage'] ?? $summary['percentage']) . '%</td>
                </tr>
            </table>
        </div>';
        
        return $html;
    }
    
    /**
     * Generate attendance table HTML
     */
    private function generateAttendanceTableHTML($attendance)
    {
        if (empty($attendance)) {
            return '<p>No attendance data found.</p>';
        }
        
        $html = '<table class="attendance-table">
            <thead>
                <tr>
                    <th>Student Name</th>
                    <th>Roll Number</th>
                    <th>Class</th>
                    <th>Total Days</th>
                    <th>Present Days</th>
                    <th>Absent Days</th>
                    <th>Late Days</th>
                    <th>Percentage</th>
                </tr>
            </thead>
            <tbody>';
        
        foreach ($attendance as $record) {
            $percentage = $record['percentage'] ?? 0;
            $percentageClass = $this->getPercentageClass($percentage);
            
            $html .= '<tr>
                <td>' . $record['first_name'] . ' ' . $record['last_name'] . '</td>
                <td>' . $record['roll_number'] . '</td>
                <td>' . $record['class_name'] . '</td>
                <td>' . ($record['total_days'] ?? 1) . '</td>
                <td>' . ($record['present_days'] ?? 0) . '</td>
                <td>' . ($record['absent_days'] ?? 0) . '</td>
                <td>' . ($record['late_days'] ?? 0) . '</td>
                <td class="' . $percentageClass . '">' . $percentage . '%</td>
            </tr>';
        }
        
        $html .= '</tbody></table>';
        
        return $html;
    }
    
    /**
     * Get percentage CSS class
     */
    private function getPercentageClass($percentage)
    {
        if ($percentage >= 80) {
            return 'percentage-good';
        } elseif ($percentage >= 60) {
            return 'percentage-warning';
        } else {
            return 'percentage-danger';
        }
    }
    
    /**
     * Export report to CSV
     */
    public function exportToCSV($reportData, $filename = null)
    {
        $filename = $filename ?? 'attendance_report_' . date('Y-m-d') . '.csv';
        $filepath = storage_path('exports/' . $filename);
        
        $file = fopen($filepath, 'w');
        
        // Write headers
        fputcsv($file, ['Student Name', 'Roll Number', 'Class', 'Total Days', 'Present Days', 'Absent Days', 'Late Days', 'Percentage']);
        
        // Write data
        foreach ($reportData['attendance'] as $record) {
            fputcsv($file, [
                $record['first_name'] . ' ' . $record['last_name'],
                $record['roll_number'],
                $record['class_name'],
                $record['total_days'] ?? 1,
                $record['present_days'] ?? 0,
                $record['absent_days'] ?? 0,
                $record['late_days'] ?? 0,
                $record['percentage'] ?? 0
            ]);
        }
        
        fclose($file);
        
        return $filepath;
    }
}