<?php

namespace App\Services\HR;

use App\Models\HR\Leave;
use App\Models\HR\LeaveType;
use App\Models\HR\LeaveBalance;
use App\Models\HR\Employee;
use App\Libraries\Database\Connection;
use App\Services\Communication\EmailService;
use App\Services\Communication\NotificationService;
use App\Exceptions\ValidationException;

class LeaveService
{
    private $connection;
    private $emailService;
    private $notificationService;
    
    public function __construct()
    {
        $this->connection = Connection::getInstance();
        $this->emailService = new EmailService();
        $this->notificationService = new NotificationService();
    }
    
    /**
     * Apply for leave
     */
    public function applyForLeave($data)
    {
        $this->validateLeaveApplicationData($data);
        
        $employee = Employee::find($data['employee_id']);
        
        if (!$employee) {
            throw new ValidationException('Employee not found');
        }
        
        // Check if leave dates are valid
        if (strtotime($data['start_date']) > strtotime($data['end_date'])) {
            throw new ValidationException('Start date cannot be after end date');
        }
        
        // Check if leave dates are in the past
        if (strtotime($data['start_date']) < time()) {
            throw new ValidationException('Cannot apply for leave in the past');
        }
        
        // Check leave balance
        $leaveBalance = $this->getLeaveBalance($data['employee_id'], $data['leave_type_id']);
        $requestedDays = $this->calculateLeaveDays($data['start_date'], $data['end_date']);
        
        if ($requestedDays > $leaveBalance['available_days']) {
            throw new ValidationException('Insufficient leave balance');
        }
        
        // Check for overlapping leaves
        if ($this->hasOverlappingLeaves($data['employee_id'], $data['start_date'], $data['end_date'])) {
            throw new ValidationException('Leave dates overlap with existing leave application');
        }
        
        $leave = Leave::create([
            'employee_id' => $data['employee_id'],
            'leave_type_id' => $data['leave_type_id'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'total_days' => $requestedDays,
            'reason' => $data['reason'],
            'status' => 'pending',
            'applied_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        // Send notification to manager
        $this->notifyManager($leave);
        
        return $leave;
    }
    
    /**
     * Approve leave
     */
    public function approveLeave($leaveId, $approvedBy, $comments = null)
    {
        $leave = Leave::find($leaveId);
        
        if (!$leave) {
            throw new ValidationException('Leave application not found');
        }
        
        if ($leave->status !== 'pending') {
            throw new ValidationException('Leave application is not pending');
        }
        
        $leave->status = 'approved';
        $leave->approved_by = $approvedBy;
        $leave->approved_at = date('Y-m-d H:i:s');
        $leave->comments = $comments;
        $leave->updated_at = date('Y-m-d H:i:s');
        
        $leave->save();
        
        // Update leave balance
        $this->updateLeaveBalance($leave);
        
        // Send approval notification
        $this->notifyEmployee($leave, 'approved');
        
        return $leave;
    }
    
    /**
     * Reject leave
     */
    public function rejectLeave($leaveId, $rejectedBy, $comments = null)
    {
        $leave = Leave::find($leaveId);
        
        if (!$leave) {
            throw new ValidationException('Leave application not found');
        }
        
        if ($leave->status !== 'pending') {
            throw new ValidationException('Leave application is not pending');
        }
        
        $leave->status = 'rejected';
        $leave->rejected_by = $rejectedBy;
        $leave->rejected_at = date('Y-m-d H:i:s');
        $leave->comments = $comments;
        $leave->updated_at = date('Y-m-d H:i:s');
        
        $leave->save();
        
        // Send rejection notification
        $this->notifyEmployee($leave, 'rejected');
        
        return $leave;
    }
    
    /**
     * Cancel leave
     */
    public function cancelLeave($leaveId, $cancelledBy, $reason = null)
    {
        $leave = Leave::find($leaveId);
        
        if (!$leave) {
            throw new ValidationException('Leave application not found');
        }
        
        if ($leave->status === 'cancelled') {
            throw new ValidationException('Leave application is already cancelled');
        }
        
        if ($leave->status === 'rejected') {
            throw new ValidationException('Cannot cancel rejected leave application');
        }
        
        $leave->status = 'cancelled';
        $leave->cancelled_by = $cancelledBy;
        $leave->cancelled_at = date('Y-m-d H:i:s');
        $leave->cancellation_reason = $reason;
        $leave->updated_at = date('Y-m-d H:i:s');
        
        $leave->save();
        
        // Restore leave balance
        $this->restoreLeaveBalance($leave);
        
        return $leave;
    }
    
    /**
     * Get leave applications
     */
    public function getLeaveApplications($filters = [])
    {
        $sql = "SELECT 
                    l.*,
                    e.first_name,
                    e.last_name,
                    e.employee_id,
                    lt.name as leave_type_name,
                    lt.max_days_per_year,
                    d.name as department_name
                FROM leaves l
                LEFT JOIN employees e ON l.employee_id = e.id
                LEFT JOIN leave_types lt ON l.leave_type_id = lt.id
                LEFT JOIN departments d ON e.department_id = d.id
                WHERE 1=1";
        
        $params = [];
        
        if (!empty($filters['employee_id'])) {
            $sql .= " AND l.employee_id = ?";
            $params[] = $filters['employee_id'];
        }
        
        if (!empty($filters['status'])) {
            $sql .= " AND l.status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['leave_type_id'])) {
            $sql .= " AND l.leave_type_id = ?";
            $params[] = $filters['leave_type_id'];
        }
        
        if (!empty($filters['start_date'])) {
            $sql .= " AND l.start_date >= ?";
            $params[] = $filters['start_date'];
        }
        
        if (!empty($filters['end_date'])) {
            $sql .= " AND l.end_date <= ?";
            $params[] = $filters['end_date'];
        }
        
        $sql .= " ORDER BY l.applied_at DESC";
        
        return $this->connection->fetchAll($sql, $params);
    }
    
    /**
     * Get leave balance
     */
    public function getLeaveBalance($employeeId, $leaveTypeId = null)
    {
        $whereClause = "lb.employee_id = ?";
        $params = [$employeeId];
        
        if ($leaveTypeId) {
            $whereClause .= " AND lb.leave_type_id = ?";
            $params[] = $leaveTypeId;
        }
        
        $sql = "SELECT 
                    lb.*,
                    lt.name as leave_type_name,
                    lt.max_days_per_year
                FROM leave_balances lb
                LEFT JOIN leave_types lt ON lb.leave_type_id = lt.id
                WHERE {$whereClause}";
        
        if ($leaveTypeId) {
            return $this->connection->fetchOne($sql, $params);
        }
        
        return $this->connection->fetchAll($sql, $params);
    }
    
    /**
     * Calculate leave days
     */
    private function calculateLeaveDays($startDate, $endDate)
    {
        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);
        $interval = $start->diff($end);
        
        return $interval->days + 1;
    }
    
    /**
     * Check for overlapping leaves
     */
    private function hasOverlappingLeaves($employeeId, $startDate, $endDate)
    {
        $sql = "SELECT COUNT(*) as count FROM leaves 
                WHERE employee_id = ? 
                AND status IN ('pending', 'approved') 
                AND ((start_date <= ? AND end_date >= ?) OR (start_date <= ? AND end_date >= ?))";
        
        $result = $this->connection->fetchOne($sql, [
            $employeeId, $startDate, $startDate, $endDate, $endDate
        ]);
        
        return $result['count'] > 0;
    }
    
    /**
     * Update leave balance
     */
    private function updateLeaveBalance($leave)
    {
        $sql = "UPDATE leave_balances 
                SET used_days = used_days + ?, 
                    available_days = total_days - used_days - ?,
                    updated_at = ?
                WHERE employee_id = ? AND leave_type_id = ?";
        
        $this->connection->execute($sql, [
            $leave->total_days,
            $leave->total_days,
            date('Y-m-d H:i:s'),
            $leave->employee_id,
            $leave->leave_type_id
        ]);
    }
    
    /**
     * Restore leave balance
     */
    private function restoreLeaveBalance($leave)
    {
        $sql = "UPDATE leave_balances 
                SET used_days = used_days - ?, 
                    available_days = total_days - used_days + ?,
                    updated_at = ?
                WHERE employee_id = ? AND leave_type_id = ?";
        
        $this->connection->execute($sql, [
            $leave->total_days,
            $leave->total_days,
            date('Y-m-d H:i:s'),
            $leave->employee_id,
            $leave->leave_type_id
        ]);
    }
    
    /**
     * Notify manager
     */
    private function notifyManager($leave)
    {
        $employee = $leave->employee;
        $manager = $this->getEmployeeManager($employee->id);
        
        if (!$manager) {
            return;
        }
        
        $this->notificationService->create([
            'user_id' => $manager->id,
            'type' => 'leave_application',
            'title' => 'New Leave Application',
            'message' => "{$employee->first_name} {$employee->last_name} has applied for leave from {$leave->start_date} to {$leave->end_date}",
            'data' => json_encode(['leave_id' => $leave->id])
        ]);
    }
    
    /**
     * Notify employee
     */
    private function notifyEmployee($leave, $action)
    {
        $employee = $leave->employee;
        
        $this->notificationService->create([
            'user_id' => $employee->id,
            'type' => 'leave_' . $action,
            'title' => 'Leave Application ' . ucfirst($action),
            'message' => "Your leave application from {$leave->start_date} to {$leave->end_date} has been {$action}",
            'data' => json_encode(['leave_id' => $leave->id])
        ]);
    }
    
    /**
     * Get employee manager
     */
    private function getEmployeeManager($employeeId)
    {
        $sql = "SELECT m.* FROM employees e
                LEFT JOIN employees m ON e.manager_id = m.id
                WHERE e.id = ?";
        
        return $this->connection->fetchOne($sql, [$employeeId]);
    }
    
    /**
     * Validate leave application data
     */
    private function validateLeaveApplicationData($data)
    {
        $required = ['employee_id', 'leave_type_id', 'start_date', 'end_date', 'reason'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new ValidationException("Field {$field} is required");
            }
        }
    }
    
    /**
     * Get leave statistics
     */
    public function getLeaveStatistics($filters = [])
    {
        $whereClause = "1=1";
        $params = [];
        
        if (!empty($filters['year'])) {
            $whereClause .= " AND YEAR(l.start_date) = ?";
            $params[] = $filters['year'];
        }
        
        if (!empty($filters['department_id'])) {
            $whereClause .= " AND e.department_id = ?";
            $params[] = $filters['department_id'];
        }
        
        $sql = "SELECT 
                    COUNT(*) as total_applications,
                    COUNT(CASE WHEN l.status = 'pending' THEN 1 END) as pending_count,
                    COUNT(CASE WHEN l.status = 'approved' THEN 1 END) as approved_count,
                    COUNT(CASE WHEN l.status = 'rejected' THEN 1 END) as rejected_count,
                    COUNT(CASE WHEN l.status = 'cancelled' THEN 1 END) as cancelled_count,
                    SUM(l.total_days) as total_leave_days
                FROM leaves l
                LEFT JOIN employees e ON l.employee_id = e.id
                WHERE {$whereClause}";
        
        return $this->connection->fetchOne($sql, $params);
    }
    
    /**
     * Get leave type statistics
     */
    public function getLeaveTypeStatistics($year = null)
    {
        $year = $year ?? date('Y');
        
        $sql = "SELECT 
                    lt.name as leave_type_name,
                    COUNT(l.id) as application_count,
                    SUM(l.total_days) as total_days,
                    AVG(l.total_days) as average_days
                FROM leave_types lt
                LEFT JOIN leaves l ON lt.id = l.leave_type_id AND YEAR(l.start_date) = ?
                GROUP BY lt.id, lt.name
                ORDER BY application_count DESC";
        
        return $this->connection->fetchAll($sql, [$year]);
    }
    
    /**
     * Get employee leave summary
     */
    public function getEmployeeLeaveSummary($employeeId, $year = null)
    {
        $year = $year ?? date('Y');
        
        $sql = "SELECT 
                    lt.name as leave_type_name,
                    COUNT(l.id) as application_count,
                    SUM(l.total_days) as total_days,
                    COUNT(CASE WHEN l.status = 'approved' THEN 1 END) as approved_count,
                    SUM(CASE WHEN l.status = 'approved' THEN l.total_days ELSE 0 END) as approved_days
                FROM leave_types lt
                LEFT JOIN leaves l ON lt.id = l.leave_type_id 
                    AND l.employee_id = ? 
                    AND YEAR(l.start_date) = ?
                GROUP BY lt.id, lt.name
                ORDER BY lt.name";
        
        return $this->connection->fetchAll($sql, [$employeeId, $year]);
    }
    
    /**
     * Get leave calendar
     */
    public function getLeaveCalendar($month, $year, $departmentId = null)
    {
        $startDate = "{$year}-{$month}-01";
        $endDate = date('Y-m-t', strtotime($startDate));
        
        $whereClause = "l.start_date <= ? AND l.end_date >= ? AND l.status = 'approved'";
        $params = [$endDate, $startDate];
        
        if ($departmentId) {
            $whereClause .= " AND e.department_id = ?";
            $params[] = $departmentId;
        }
        
        $sql = "SELECT 
                    l.start_date,
                    l.end_date,
                    l.total_days,
                    e.first_name,
                    e.last_name,
                    lt.name as leave_type_name,
                    lt.color as leave_type_color
                FROM leaves l
                LEFT JOIN employees e ON l.employee_id = e.id
                LEFT JOIN leave_types lt ON l.leave_type_id = lt.id
                WHERE {$whereClause}
                ORDER BY l.start_date";
        
        return $this->connection->fetchAll($sql, $params);
    }
    
    /**
     * Export leave report
     */
    public function exportLeaveReport($filters = [])
    {
        $leaves = $this->getLeaveApplications($filters);
        
        $filename = 'leave_report_' . date('Y-m-d') . '.csv';
        $filepath = storage_path('exports/' . $filename);
        
        $file = fopen($filepath, 'w');
        
        // Write headers
        fputcsv($file, [
            'Employee ID', 'Employee Name', 'Department', 'Leave Type',
            'Start Date', 'End Date', 'Total Days', 'Status', 'Applied Date'
        ]);
        
        // Write data
        foreach ($leaves as $leave) {
            fputcsv($file, [
                $leave['employee_id'],
                $leave['first_name'] . ' ' . $leave['last_name'],
                $leave['department_name'],
                $leave['leave_type_name'],
                $leave['start_date'],
                $leave['end_date'],
                $leave['total_days'],
                $leave['status'],
                $leave['applied_at']
            ]);
        }
        
        fclose($file);
        
        return $filepath;
    }
    
    /**
     * Get leave analytics
     */
    public function getLeaveAnalytics($year = null)
    {
        $year = $year ?? date('Y');
        
        $sql = "SELECT 
                    MONTH(l.start_date) as month,
                    COUNT(*) as application_count,
                    SUM(l.total_days) as total_days,
                    COUNT(CASE WHEN l.status = 'approved' THEN 1 END) as approved_count
                FROM leaves l
                WHERE YEAR(l.start_date) = ?
                GROUP BY MONTH(l.start_date)
                ORDER BY month";
        
        return $this->connection->fetchAll($sql, [$year]);
    }
}