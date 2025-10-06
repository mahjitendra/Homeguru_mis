<?php

namespace App\Services\HR;

use App\Models\HR\Employee;
use App\Models\HR\Department;
use App\Models\HR\Designation;
use App\Models\HR\EmployeeDocument;
use App\Libraries\Database\Connection;
use App\Services\Communication\EmailService;
use App\Services\Communication\SMSService;
use App\Exceptions\ValidationException;

class EmployeeService
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
     * Create employee
     */
    public function createEmployee($data)
    {
        $this->validateEmployeeData($data);
        
        // Generate employee ID
        $employeeId = $this->generateEmployeeId($data['department_id']);
        
        $employee = Employee::create([
            'employee_id' => $employeeId,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'date_of_birth' => $data['date_of_birth'],
            'gender' => $data['gender'],
            'address' => $data['address'],
            'department_id' => $data['department_id'],
            'designation_id' => $data['designation_id'],
            'joining_date' => $data['joining_date'],
            'salary' => $data['salary'] ?? 0,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        // Create employee documents if provided
        if (!empty($data['documents'])) {
            $this->createEmployeeDocuments($employee->id, $data['documents']);
        }
        
        // Send welcome email
        $this->sendWelcomeEmail($employee);
        
        return $employee;
    }
    
    /**
     * Update employee
     */
    public function updateEmployee($employeeId, $data)
    {
        $employee = Employee::find($employeeId);
        
        if (!$employee) {
            throw new ValidationException('Employee not found');
        }
        
        $this->validateEmployeeData($data, $employeeId);
        
        $employee->first_name = $data['first_name'];
        $employee->last_name = $data['last_name'];
        $employee->email = $data['email'];
        $employee->phone = $data['phone'];
        $employee->date_of_birth = $data['date_of_birth'];
        $employee->gender = $data['gender'];
        $employee->address = $data['address'];
        $employee->department_id = $data['department_id'];
        $employee->designation_id = $data['designation_id'];
        $employee->salary = $data['salary'] ?? $employee->salary;
        $employee->updated_at = date('Y-m-d H:i:s');
        
        $employee->save();
        
        return $employee;
    }
    
    /**
     * Delete employee
     */
    public function deleteEmployee($employeeId)
    {
        $employee = Employee::find($employeeId);
        
        if (!$employee) {
            throw new ValidationException('Employee not found');
        }
        
        // Check if employee has any active records
        if ($this->hasActiveRecords($employeeId)) {
            throw new ValidationException('Cannot delete employee with active records');
        }
        
        $employee->delete();
        
        return true;
    }
    
    /**
     * Get employee by ID
     */
    public function getEmployee($employeeId)
    {
        $sql = "SELECT 
                    e.*,
                    d.name as department_name,
                    des.name as designation_name,
                    des.grade as designation_grade
                FROM employees e
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN designations des ON e.designation_id = des.id
                WHERE e.id = ?";
        
        $employee = $this->connection->fetchOne($sql, [$employeeId]);
        
        if (!$employee) {
            return null;
        }
        
        // Get employee documents
        $employee['documents'] = $this->getEmployeeDocuments($employeeId);
        
        return $employee;
    }
    
    /**
     * Get all employees
     */
    public function getEmployees($filters = [])
    {
        $sql = "SELECT 
                    e.*,
                    d.name as department_name,
                    des.name as designation_name
                FROM employees e
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN designations des ON e.designation_id = des.id
                WHERE 1=1";
        
        $params = [];
        
        if (!empty($filters['department_id'])) {
            $sql .= " AND e.department_id = ?";
            $params[] = $filters['department_id'];
        }
        
        if (!empty($filters['designation_id'])) {
            $sql .= " AND e.designation_id = ?";
            $params[] = $filters['designation_id'];
        }
        
        if (!empty($filters['status'])) {
            $sql .= " AND e.status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['search'])) {
            $sql .= " AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ?)";
            $searchTerm = "%{$filters['search']}%";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
        }
        
        $sql .= " ORDER BY e.first_name, e.last_name";
        
        return $this->connection->fetchAll($sql, $params);
    }
    
    /**
     * Get employee documents
     */
    public function getEmployeeDocuments($employeeId)
    {
        $sql = "SELECT * FROM employee_documents WHERE employee_id = ? ORDER BY created_at DESC";
        return $this->connection->fetchAll($sql, [$employeeId]);
    }
    
    /**
     * Create employee documents
     */
    private function createEmployeeDocuments($employeeId, $documents)
    {
        foreach ($documents as $document) {
            EmployeeDocument::create([
                'employee_id' => $employeeId,
                'document_type' => $document['document_type'],
                'document_name' => $document['document_name'],
                'file_path' => $document['file_path'],
                'uploaded_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
    }
    
    /**
     * Generate employee ID
     */
    private function generateEmployeeId($departmentId)
    {
        $department = Department::find($departmentId);
        $deptCode = $department ? $department->code : 'EMP';
        
        $year = date('Y');
        
        $sql = "SELECT COUNT(*) as count FROM employees 
                WHERE employee_id LIKE ? AND YEAR(joining_date) = ?";
        
        $result = $this->connection->fetchOne($sql, ["{$deptCode}{$year}%", $year]);
        $sequence = str_pad($result['count'] + 1, 3, '0', STR_PAD_LEFT);
        
        return "{$deptCode}{$year}{$sequence}";
    }
    
    /**
     * Check if employee has active records
     */
    private function hasActiveRecords($employeeId)
    {
        // Check for active attendance records
        $sql = "SELECT COUNT(*) as count FROM employee_attendance 
                WHERE employee_id = ? AND date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $result = $this->connection->fetchOne($sql, [$employeeId]);
        
        if ($result['count'] > 0) {
            return true;
        }
        
        // Check for active leave records
        $sql = "SELECT COUNT(*) as count FROM leaves 
                WHERE employee_id = ? AND status IN ('pending', 'approved') 
                AND start_date <= NOW() AND end_date >= NOW()";
        $result = $this->connection->fetchOne($sql, [$employeeId]);
        
        if ($result['count'] > 0) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Send welcome email
     */
    private function sendWelcomeEmail($employee)
    {
        $subject = "Welcome to HomeGuru School - {$employee->first_name} {$employee->last_name}";
        $message = "Dear {$employee->first_name},\n\n";
        $message .= "Welcome to HomeGuru School! We are excited to have you join our team.\n\n";
        $message .= "Your Employee Details:\n";
        $message .= "Employee ID: {$employee->employee_id}\n";
        $message .= "Department: {$employee->department->name}\n";
        $message .= "Designation: {$employee->designation->name}\n";
        $message .= "Joining Date: {$employee->joining_date}\n\n";
        $message .= "Please complete your profile and upload necessary documents.\n\n";
        $message .= "Best regards,\nHR Department";
        
        $this->emailService->send($employee->email, $subject, $message);
    }
    
    /**
     * Validate employee data
     */
    private function validateEmployeeData($data, $excludeEmployeeId = null)
    {
        $required = ['first_name', 'last_name', 'email', 'phone', 'date_of_birth', 'gender', 'address', 'department_id', 'designation_id', 'joining_date'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new ValidationException("Field {$field} is required");
            }
        }
        
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Invalid email format');
        }
        
        // Check if email already exists
        $sql = "SELECT COUNT(*) as count FROM employees WHERE email = ?";
        $params = [$data['email']];
        
        if ($excludeEmployeeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeEmployeeId;
        }
        
        $result = $this->connection->fetchOne($sql, $params);
        
        if ($result['count'] > 0) {
            throw new ValidationException('Email already exists');
        }
        
        // Check if phone already exists
        $sql = "SELECT COUNT(*) as count FROM employees WHERE phone = ?";
        $params = [$data['phone']];
        
        if ($excludeEmployeeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeEmployeeId;
        }
        
        $result = $this->connection->fetchOne($sql, $params);
        
        if ($result['count'] > 0) {
            throw new ValidationException('Phone number already exists');
        }
    }
    
    /**
     * Get employee statistics
     */
    public function getEmployeeStatistics()
    {
        $sql = "SELECT 
                    COUNT(*) as total_employees,
                    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_employees,
                    COUNT(CASE WHEN status = 'inactive' THEN 1 END) as inactive_employees,
                    COUNT(CASE WHEN gender = 'male' THEN 1 END) as male_employees,
                    COUNT(CASE WHEN gender = 'female' THEN 1 END) as female_employees,
                    AVG(salary) as average_salary
                FROM employees";
        
        return $this->connection->fetchOne($sql);
    }
    
    /**
     * Get department-wise statistics
     */
    public function getDepartmentWiseStatistics()
    {
        $sql = "SELECT 
                    d.name as department_name,
                    COUNT(e.id) as employee_count,
                    AVG(e.salary) as average_salary,
                    COUNT(CASE WHEN e.status = 'active' THEN 1 END) as active_count
                FROM departments d
                LEFT JOIN employees e ON d.id = e.department_id
                GROUP BY d.id, d.name
                ORDER BY employee_count DESC";
        
        return $this->connection->fetchAll($sql);
    }
    
    /**
     * Get designation-wise statistics
     */
    public function getDesignationWiseStatistics()
    {
        $sql = "SELECT 
                    des.name as designation_name,
                    des.grade,
                    COUNT(e.id) as employee_count,
                    AVG(e.salary) as average_salary
                FROM designations des
                LEFT JOIN employees e ON des.id = e.designation_id
                GROUP BY des.id, des.name, des.grade
                ORDER BY des.grade DESC";
        
        return $this->connection->fetchAll($sql);
    }
    
    /**
     * Get employee analytics
     */
    public function getEmployeeAnalytics()
    {
        $sql = "SELECT 
                    YEAR(joining_date) as year,
                    COUNT(*) as new_employees
                FROM employees
                WHERE joining_date >= DATE_SUB(NOW(), INTERVAL 5 YEAR)
                GROUP BY YEAR(joining_date)
                ORDER BY year";
        
        return $this->connection->fetchAll($sql);
    }
    
    /**
     * Export employees to CSV
     */
    public function exportEmployeesToCSV($filters = [])
    {
        $employees = $this->getEmployees($filters);
        
        $filename = 'employees_' . date('Y-m-d') . '.csv';
        $filepath = storage_path('exports/' . $filename);
        
        $file = fopen($filepath, 'w');
        
        // Write headers
        fputcsv($file, [
            'Employee ID', 'Name', 'Email', 'Phone', 'Department', 'Designation',
            'Joining Date', 'Salary', 'Status', 'Date of Birth', 'Gender'
        ]);
        
        // Write data
        foreach ($employees as $employee) {
            fputcsv($file, [
                $employee['employee_id'],
                $employee['first_name'] . ' ' . $employee['last_name'],
                $employee['email'],
                $employee['phone'],
                $employee['department_name'],
                $employee['designation_name'],
                $employee['joining_date'],
                $employee['salary'],
                $employee['status'],
                $employee['date_of_birth'],
                $employee['gender']
            ]);
        }
        
        fclose($file);
        
        return $filepath;
    }
    
    /**
     * Get employee directory
     */
    public function getEmployeeDirectory($filters = [])
    {
        $sql = "SELECT 
                    e.employee_id,
                    e.first_name,
                    e.last_name,
                    e.email,
                    e.phone,
                    d.name as department_name,
                    des.name as designation_name,
                    e.status
                FROM employees e
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN designations des ON e.designation_id = des.id
                WHERE e.status = 'active'";
        
        $params = [];
        
        if (!empty($filters['department_id'])) {
            $sql .= " AND e.department_id = ?";
            $params[] = $filters['department_id'];
        }
        
        if (!empty($filters['search'])) {
            $sql .= " AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ?)";
            $searchTerm = "%{$filters['search']}%";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
        }
        
        $sql .= " ORDER BY d.name, des.grade DESC, e.first_name";
        
        return $this->connection->fetchAll($sql, $params);
    }
    
    /**
     * Get employee profile
     */
    public function getEmployeeProfile($employeeId)
    {
        $employee = $this->getEmployee($employeeId);
        
        if (!$employee) {
            return null;
        }
        
        // Get additional profile information
        $employee['attendance_summary'] = $this->getAttendanceSummary($employeeId);
        $employee['leave_summary'] = $this->getLeaveSummary($employeeId);
        $employee['performance_summary'] = $this->getPerformanceSummary($employeeId);
        
        return $employee;
    }
    
    /**
     * Get attendance summary
     */
    private function getAttendanceSummary($employeeId)
    {
        $sql = "SELECT 
                    COUNT(*) as total_days,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
                    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days
                FROM employee_attendance 
                WHERE employee_id = ? AND date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        
        return $this->connection->fetchOne($sql, [$employeeId]);
    }
    
    /**
     * Get leave summary
     */
    private function getLeaveSummary($employeeId)
    {
        $sql = "SELECT 
                    COUNT(*) as total_leaves,
                    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_leaves,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_leaves,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_leaves
                FROM leaves 
                WHERE employee_id = ? AND YEAR(start_date) = YEAR(NOW())";
        
        return $this->connection->fetchOne($sql, [$employeeId]);
    }
    
    /**
     * Get performance summary
     */
    private function getPerformanceSummary($employeeId)
    {
        $sql = "SELECT 
                    AVG(rating) as average_rating,
                    COUNT(*) as total_appraisals
                FROM performance_appraisals 
                WHERE employee_id = ? AND YEAR(appraisal_date) = YEAR(NOW())";
        
        return $this->connection->fetchOne($sql, [$employeeId]);
    }
}