<?php

namespace App\Services\Attendance;

use App\Models\Attendance\StudentAttendance;
use App\Models\Attendance\TeacherAttendance;
use App\Models\Attendance\EmployeeAttendance;
use App\Models\Academic\Student;
use App\Models\Academic\Teacher;
use App\Models\HR\Employee;
use App\Libraries\Database\Connection;
use App\Services\Communication\EmailService;
use App\Services\Communication\SMSService;
use App\Exceptions\ValidationException;

class AttendanceService
{
    private $connection;
    private $emailService;
    private $smsService;
    
    public function __construct()
    {
        $this->connection = Connection::getInstance();
        $this->emailService = new EmailService();
        $this->smsService = new SMSService();
    }
    
    /**
     * Mark student attendance
     */
    public function markStudentAttendance($data)
    {
        $this->validateAttendanceData($data);
        
        $attendance = StudentAttendance::create([
            'student_id' => $data['student_id'],
            'date' => $data['date'],
            'status' => $data['status'],
            'period' => $data['period'] ?? null,
            'subject_id' => $data['subject_id'] ?? null,
            'teacher_id' => $data['teacher_id'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'marked_by' => $data['marked_by'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        // Update student attendance percentage
        $this->updateStudentAttendancePercentage($data['student_id']);
        
        return $attendance;
    }
    
    /**
     * Mark teacher attendance
     */
    public function markTeacherAttendance($data)
    {
        $this->validateAttendanceData($data);
        
        $attendance = TeacherAttendance::create([
            'teacher_id' => $data['teacher_id'],
            'date' => $data['date'],
            'status' => $data['status'],
            'check_in_time' => $data['check_in_time'] ?? null,
            'check_out_time' => $data['check_out_time'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'marked_by' => $data['marked_by'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        return $attendance;
    }
    
    /**
     * Mark employee attendance
     */
    public function markEmployeeAttendance($data)
    {
        $this->validateAttendanceData($data);
        
        $attendance = EmployeeAttendance::create([
            'employee_id' => $data['employee_id'],
            'date' => $data['date'],
            'status' => $data['status'],
            'check_in_time' => $data['check_in_time'] ?? null,
            'check_out_time' => $data['check_out_time'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'marked_by' => $data['marked_by'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        return $attendance;
    }
    
    /**
     * Bulk mark student attendance
     */
    public function bulkMarkStudentAttendance($classId, $date, $attendanceData)
    {
        $students = $this->getClassStudents($classId);
        $results = [];
        
        foreach ($students as $student) {
            $studentId = $student['id'];
            $status = $attendanceData[$studentId] ?? 'absent';
            
            try {
                $attendance = $this->markStudentAttendance([
                    'student_id' => $studentId,
                    'date' => $date,
                    'status' => $status,
                    'marked_by' => $attendanceData['marked_by'] ?? null
                ]);
                
                $results[] = [
                    'student_id' => $studentId,
                    'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                    'status' => 'success',
                    'attendance_id' => $attendance->id
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'student_id' => $studentId,
                    'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Get student attendance
     */
    public function getStudentAttendance($studentId, $startDate = null, $endDate = null)
    {
        $startDate = $startDate ?? date('Y-m-01');
        $endDate = $endDate ?? date('Y-m-t');
        
        $sql = "SELECT 
                    sa.*,
                    s.first_name,
                    s.last_name,
                    s.roll_number,
                    sub.name as subject_name
                FROM student_attendance sa
                LEFT JOIN students s ON sa.student_id = s.id
                LEFT JOIN subjects sub ON sa.subject_id = sub.id
                WHERE sa.student_id = ? 
                AND sa.date BETWEEN ? AND ?
                ORDER BY sa.date DESC, sa.period";
        
        return $this->connection->fetchAll($sql, [$studentId, $startDate, $endDate]);
    }
    
    /**
     * Get class attendance
     */
    public function getClassAttendance($classId, $date)
    {
        $sql = "SELECT 
                    sa.*,
                    s.first_name,
                    s.last_name,
                    s.roll_number,
                    sub.name as subject_name
                FROM student_attendance sa
                LEFT JOIN students s ON sa.student_id = s.id
                LEFT JOIN subjects sub ON sa.subject_id = sub.id
                WHERE s.class_id = ? AND sa.date = ?
                ORDER BY s.roll_number, sa.period";
        
        return $this->connection->fetchAll($sql, [$classId, $date]);
    }
    
    /**
     * Get teacher attendance
     */
    public function getTeacherAttendance($teacherId, $startDate = null, $endDate = null)
    {
        $startDate = $startDate ?? date('Y-m-01');
        $endDate = $endDate ?? date('Y-m-t');
        
        $sql = "SELECT 
                    ta.*,
                    t.first_name,
                    t.last_name,
                    t.employee_id
                FROM teacher_attendance ta
                LEFT JOIN teachers t ON ta.teacher_id = t.id
                WHERE ta.teacher_id = ? 
                AND ta.date BETWEEN ? AND ?
                ORDER BY ta.date DESC";
        
        return $this->connection->fetchAll($sql, [$teacherId, $startDate, $endDate]);
    }
    
    /**
     * Get employee attendance
     */
    public function getEmployeeAttendance($employeeId, $startDate = null, $endDate = null)
    {
        $startDate = $startDate ?? date('Y-m-01');
        $endDate = $endDate ?? date('Y-m-t');
        
        $sql = "SELECT 
                    ea.*,
                    e.first_name,
                    e.last_name,
                    e.employee_id
                FROM employee_attendance ea
                LEFT JOIN employees e ON ea.employee_id = e.id
                WHERE ea.employee_id = ? 
                AND ea.date BETWEEN ? AND ?
                ORDER BY ea.date DESC";
        
        return $this->connection->fetchAll($sql, [$employeeId, $startDate, $endDate]);
    }
    
    /**
     * Calculate attendance percentage
     */
    public function calculateAttendancePercentage($studentId, $startDate = null, $endDate = null)
    {
        $startDate = $startDate ?? date('Y-m-01');
        $endDate = $endDate ?? date('Y-m-t');
        
        $sql = "SELECT 
                    COUNT(*) as total_days,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
                    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days
                FROM student_attendance 
                WHERE student_id = ? AND date BETWEEN ? AND ?";
        
        $result = $this->connection->fetchOne($sql, [$studentId, $startDate, $endDate]);
        
        $totalDays = $result['total_days'];
        $presentDays = $result['present_days'];
        
        $percentage = $totalDays > 0 ? round(($presentDays / $totalDays) * 100, 2) : 0;
        
        return [
            'total_days' => $totalDays,
            'present_days' => $presentDays,
            'absent_days' => $result['absent_days'],
            'late_days' => $result['late_days'],
            'percentage' => $percentage
        ];
    }
    
    /**
     * Update student attendance percentage
     */
    private function updateStudentAttendancePercentage($studentId)
    {
        $attendance = $this->calculateAttendancePercentage($studentId);
        
        $sql = "UPDATE students SET 
                attendance_percentage = ?, 
                updated_at = ? 
                WHERE id = ?";
        
        $this->connection->execute($sql, [
            $attendance['percentage'],
            date('Y-m-d H:i:s'),
            $studentId
        ]);
    }
    
    /**
     * Get attendance statistics
     */
    public function getAttendanceStatistics($classId = null, $startDate = null, $endDate = null)
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
                    COUNT(DISTINCT sa.student_id) as total_students,
                    COUNT(*) as total_attendance_records,
                    SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) as present_count,
                    SUM(CASE WHEN sa.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                    SUM(CASE WHEN sa.status = 'late' THEN 1 ELSE 0 END) as late_count,
                    AVG(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) * 100 as average_percentage
                FROM student_attendance sa
                LEFT JOIN students s ON sa.student_id = s.id
                WHERE {$whereClause}";
        
        return $this->connection->fetchOne($sql, $params);
    }
    
    /**
     * Get attendance defaulters
     */
    public function getAttendanceDefaulters($classId = null, $minPercentage = 75, $startDate = null, $endDate = null)
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
                    s.class_id,
                    c.name as class_name,
                    COUNT(*) as total_days,
                    SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) as present_days,
                    ROUND((SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as percentage
                FROM student_attendance sa
                LEFT JOIN students s ON sa.student_id = s.id
                LEFT JOIN classes c ON s.class_id = c.id
                WHERE {$whereClause}
                GROUP BY s.id, s.first_name, s.last_name, s.roll_number, s.class_id, c.name
                HAVING percentage < ?
                ORDER BY percentage ASC";
        
        $params[] = $minPercentage;
        
        return $this->connection->fetchAll($sql, $params);
    }
    
    /**
     * Send attendance alerts
     */
    public function sendAttendanceAlerts($classId = null, $minPercentage = 75)
    {
        $defaulters = $this->getAttendanceDefaulters($classId, $minPercentage);
        
        foreach ($defaulters as $defaulter) {
            $this->sendAttendanceAlert($defaulter);
        }
        
        return count($defaulters);
    }
    
    /**
     * Send attendance alert for individual student
     */
    private function sendAttendanceAlert($defaulter)
    {
        // Get parent contact information
        $sql = "SELECT p.email, p.phone FROM parents p 
                INNER JOIN student_parents sp ON p.id = sp.parent_id 
                WHERE sp.student_id = ? AND sp.relationship = 'Primary'";
        
        $parent = $this->connection->fetchOne($sql, [$defaulter['id']]);
        
        if (!$parent) {
            return false;
        }
        
        // Send email alert
        if ($parent['email']) {
            $subject = "Attendance Alert - {$defaulter['first_name']} {$defaulter['last_name']}";
            $message = "Dear Parent,\n\n";
            $message .= "This is to inform you that {$defaulter['first_name']} {$defaulter['last_name']} ";
            $message .= "has attendance below the required percentage.\n\n";
            $message .= "Current Attendance: {$defaulter['percentage']}%\n";
            $message .= "Required Attendance: {$minPercentage}%\n";
            $message .= "Present Days: {$defaulter['present_days']} out of {$defaulter['total_days']}\n\n";
            $message .= "Please ensure regular attendance.\n\n";
            $message .= "Best regards,\nSchool Administration";
            
            $this->emailService->send($parent['email'], $subject, $message);
        }
        
        // Send SMS alert
        if ($parent['phone']) {
            $message = "Attendance Alert: {$defaulter['first_name']} has {$defaulter['percentage']}% attendance. Required: {$minPercentage}%";
            $this->smsService->send($parent['phone'], $message);
        }
        
        return true;
    }
    
    /**
     * Get class students
     */
    private function getClassStudents($classId)
    {
        $sql = "SELECT id, first_name, last_name, roll_number 
                FROM students 
                WHERE class_id = ? AND status = 'active'
                ORDER BY roll_number";
        
        return $this->connection->fetchAll($sql, [$classId]);
    }
    
    /**
     * Validate attendance data
     */
    private function validateAttendanceData($data)
    {
        $required = ['date', 'status'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new ValidationException("Field {$field} is required");
            }
        }
        
        $validStatuses = ['present', 'absent', 'late', 'excused'];
        if (!in_array($data['status'], $validStatuses)) {
            throw new ValidationException('Invalid attendance status');
        }
    }
    
    /**
     * Get monthly attendance report
     */
    public function getMonthlyAttendanceReport($classId = null, $month = null, $year = null)
    {
        $month = $month ?? date('m');
        $year = $year ?? date('Y');
        
        $startDate = "{$year}-{$month}-01";
        $endDate = date('Y-m-t', strtotime($startDate));
        
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
                    COUNT(*) as total_days,
                    SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) as present_days,
                    SUM(CASE WHEN sa.status = 'absent' THEN 1 ELSE 0 END) as absent_days,
                    SUM(CASE WHEN sa.status = 'late' THEN 1 ELSE 0 END) as late_days,
                    ROUND((SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as percentage
                FROM student_attendance sa
                LEFT JOIN students s ON sa.student_id = s.id
                LEFT JOIN classes c ON s.class_id = c.id
                WHERE {$whereClause}
                GROUP BY s.id, s.first_name, s.last_name, s.roll_number, c.name
                ORDER BY s.roll_number";
        
        return $this->connection->fetchAll($sql, $params);
    }
    
    /**
     * Get attendance trends
     */
    public function getAttendanceTrends($classId = null, $days = 30)
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
                    ROUND((SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) / COUNT(DISTINCT sa.student_id)) * 100, 2) as percentage
                FROM student_attendance sa
                LEFT JOIN students s ON sa.student_id = s.id
                WHERE {$whereClause}
                GROUP BY sa.date
                ORDER BY sa.date";
        
        return $this->connection->fetchAll($sql, $params);
    }
    
    /**
     * Export attendance report
     */
    public function exportAttendanceReport($classId = null, $startDate = null, $endDate = null, $format = 'csv')
    {
        $report = $this->getMonthlyAttendanceReport($classId, $startDate, $endDate);
        
        if ($format === 'csv') {
            return $this->exportToCSV($report);
        }
        
        return $report;
    }
    
    /**
     * Export to CSV
     */
    private function exportToCSV($data)
    {
        $filename = 'attendance_report_' . date('Y-m-d') . '.csv';
        $filepath = storage_path('exports/' . $filename);
        
        $file = fopen($filepath, 'w');
        
        // Write headers
        fputcsv($file, ['Student Name', 'Roll Number', 'Class', 'Total Days', 'Present Days', 'Absent Days', 'Late Days', 'Percentage']);
        
        // Write data
        foreach ($data as $row) {
            fputcsv($file, [
                $row['first_name'] . ' ' . $row['last_name'],
                $row['roll_number'],
                $row['class_name'],
                $row['total_days'],
                $row['present_days'],
                $row['absent_days'],
                $row['late_days'],
                $row['percentage']
            ]);
        }
        
        fclose($file);
        
        return $filepath;
    }
}