<?php

namespace App\Services\HR;

use App\Models\HR\Payroll;
use App\Models\HR\Salary;
use App\Models\HR\SalaryComponent;
use App\Models\HR\Payslip;
use App\Models\HR\Employee;
use App\Libraries\Database\Connection;
use App\Services\Communication\EmailService;
use App\Exceptions\ValidationException;

class PayrollService
{
    private $connection;
    private $emailService;
    
    public function __construct()
    {
        $this->connection = Connection::getInstance();
        $this->emailService = new EmailService();
    }
    
    /**
     * Process monthly payroll
     */
    public function processMonthlyPayroll($month, $year)
    {
        $employees = $this->getActiveEmployees();
        $processedCount = 0;
        $errors = [];
        
        foreach ($employees as $employee) {
            try {
                $this->processEmployeePayroll($employee['id'], $month, $year);
                $processedCount++;
            } catch (\Exception $e) {
                $errors[] = [
                    'employee_id' => $employee['id'],
                    'employee_name' => $employee['first_name'] . ' ' . $employee['last_name'],
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return [
            'processed_count' => $processedCount,
            'total_employees' => count($employees),
            'errors' => $errors
        ];
    }
    
    /**
     * Process employee payroll
     */
    public function processEmployeePayroll($employeeId, $month, $year)
    {
        $employee = Employee::find($employeeId);
        
        if (!$employee) {
            throw new ValidationException('Employee not found');
        }
        
        // Check if payroll already processed
        $existingPayroll = Payroll::where('employee_id', $employeeId)
            ->where('month', $month)
            ->where('year', $year)
            ->first();
        
        if ($existingPayroll) {
            throw new ValidationException('Payroll already processed for this employee');
        }
        
        // Get salary components
        $salaryComponents = $this->getSalaryComponents($employeeId);
        
        // Calculate basic salary
        $basicSalary = $this->calculateBasicSalary($employee, $salaryComponents);
        
        // Calculate allowances
        $allowances = $this->calculateAllowances($employee, $salaryComponents);
        
        // Calculate deductions
        $deductions = $this->calculateDeductions($employee, $salaryComponents, $month, $year);
        
        // Calculate net salary
        $grossSalary = $basicSalary + array_sum($allowances);
        $totalDeductions = array_sum($deductions);
        $netSalary = $grossSalary - $totalDeductions;
        
        // Create payroll record
        $payroll = Payroll::create([
            'employee_id' => $employeeId,
            'month' => $month,
            'year' => $year,
            'basic_salary' => $basicSalary,
            'gross_salary' => $grossSalary,
            'total_deductions' => $totalDeductions,
            'net_salary' => $netSalary,
            'status' => 'processed',
            'processed_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        // Create salary component records
        $this->createSalaryComponentRecords($payroll->id, $salaryComponents, $allowances, $deductions);
        
        // Generate payslip
        $this->generatePayslip($payroll->id);
        
        return $payroll;
    }
    
    /**
     * Get salary components
     */
    private function getSalaryComponents($employeeId)
    {
        $sql = "SELECT 
                    sc.*,
                    sc.amount as component_amount
                FROM salary_components sc
                WHERE sc.employee_id = ? AND sc.status = 'active'
                ORDER BY sc.type, sc.order_index";
        
        return $this->connection->fetchAll($sql, [$employeeId]);
    }
    
    /**
     * Calculate basic salary
     */
    private function calculateBasicSalary($employee, $salaryComponents)
    {
        $basicComponent = array_filter($salaryComponents, function($component) {
            return $component['type'] === 'basic';
        });
        
        if (!empty($basicComponent)) {
            return array_sum(array_column($basicComponent, 'amount'));
        }
        
        // Fallback to employee salary
        return $employee->salary;
    }
    
    /**
     * Calculate allowances
     */
    private function calculateAllowances($employee, $salaryComponents)
    {
        $allowances = [];
        
        foreach ($salaryComponents as $component) {
            if ($component['type'] === 'allowance') {
                $allowances[$component['name']] = $component['amount'];
            }
        }
        
        return $allowances;
    }
    
    /**
     * Calculate deductions
     */
    private function calculateDeductions($employee, $salaryComponents, $month, $year)
    {
        $deductions = [];
        
        foreach ($salaryComponents as $component) {
            if ($component['type'] === 'deduction') {
                $amount = $component['amount'];
                
                // Calculate leave deductions
                if ($component['name'] === 'Leave Deduction') {
                    $amount = $this->calculateLeaveDeduction($employee->id, $month, $year, $component['amount']);
                }
                
                $deductions[$component['name']] = $amount;
            }
        }
        
        // Calculate tax deductions
        $taxDeduction = $this->calculateTaxDeduction($employee, $month, $year);
        if ($taxDeduction > 0) {
            $deductions['Tax Deduction'] = $taxDeduction;
        }
        
        return $deductions;
    }
    
    /**
     * Calculate leave deduction
     */
    private function calculateLeaveDeduction($employeeId, $month, $year)
    {
        $sql = "SELECT COUNT(*) as leave_days FROM leaves 
                WHERE employee_id = ? 
                AND MONTH(start_date) = ? 
                AND YEAR(start_date) = ? 
                AND status = 'approved'";
        
        $result = $this->connection->fetchOne($sql, [$employeeId, $month, $year]);
        $leaveDays = $result['leave_days'] ?? 0;
        
        // Calculate deduction based on leave days
        $dailySalary = $this->getDailySalary($employeeId);
        
        return $leaveDays * $dailySalary;
    }
    
    /**
     * Calculate tax deduction
     */
    private function calculateTaxDeduction($employee, $month, $year)
    {
        $annualSalary = $employee->salary * 12;
        
        // Simple tax calculation (can be made more complex)
        if ($annualSalary <= 250000) {
            return 0;
        } elseif ($annualSalary <= 500000) {
            return ($annualSalary - 250000) * 0.05 / 12;
        } elseif ($annualSalary <= 1000000) {
            return (250000 * 0.05 + ($annualSalary - 500000) * 0.20) / 12;
        } else {
            return (250000 * 0.05 + 500000 * 0.20 + ($annualSalary - 1000000) * 0.30) / 12;
        }
    }
    
    /**
     * Get daily salary
     */
    private function getDailySalary($employeeId)
    {
        $sql = "SELECT salary FROM employees WHERE id = ?";
        $result = $this->connection->fetchOne($sql, [$employeeId]);
        
        return $result['salary'] / 30; // Assuming 30 days per month
    }
    
    /**
     * Create salary component records
     */
    private function createSalaryComponentRecords($payrollId, $salaryComponents, $allowances, $deductions)
    {
        foreach ($salaryComponents as $component) {
            $amount = 0;
            
            if ($component['type'] === 'allowance' && isset($allowances[$component['name']])) {
                $amount = $allowances[$component['name']];
            } elseif ($component['type'] === 'deduction' && isset($deductions[$component['name']])) {
                $amount = $deductions[$component['name']];
            }
            
            $sql = "INSERT INTO payroll_components (
                payroll_id, component_name, component_type, amount, created_at
            ) VALUES (?, ?, ?, ?, ?)";
            
            $this->connection->execute($sql, [
                $payrollId,
                $component['name'],
                $component['type'],
                $amount,
                date('Y-m-d H:i:s')
            ]);
        }
    }
    
    /**
     * Generate payslip
     */
    public function generatePayslip($payrollId)
    {
        $payroll = Payroll::find($payrollId);
        
        if (!$payroll) {
            throw new ValidationException('Payroll not found');
        }
        
        $payslip = Payslip::create([
            'payroll_id' => $payrollId,
            'employee_id' => $payroll->employee_id,
            'payslip_number' => $this->generatePayslipNumber(),
            'month' => $payroll->month,
            'year' => $payroll->year,
            'basic_salary' => $payroll->basic_salary,
            'gross_salary' => $payroll->gross_salary,
            'total_deductions' => $payroll->total_deductions,
            'net_salary' => $payroll->net_salary,
            'status' => 'generated',
            'generated_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        return $payslip;
    }
    
    /**
     * Generate payslip number
     */
    private function generatePayslipNumber()
    {
        $year = date('Y');
        $month = date('m');
        
        $sql = "SELECT COUNT(*) as count FROM payslips WHERE payslip_number LIKE ?";
        $result = $this->connection->fetchOne($sql, ["PS-{$year}{$month}%"]);
        $sequence = str_pad($result['count'] + 1, 4, '0', STR_PAD_LEFT);
        
        return "PS-{$year}{$month}{$sequence}";
    }
    
    /**
     * Get payroll by ID
     */
    public function getPayroll($payrollId)
    {
        $sql = "SELECT 
                    p.*,
                    e.first_name,
                    e.last_name,
                    e.employee_id,
                    d.name as department_name,
                    des.name as designation_name
                FROM payrolls p
                LEFT JOIN employees e ON p.employee_id = e.id
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN designations des ON e.designation_id = des.id
                WHERE p.id = ?";
        
        $payroll = $this->connection->fetchOne($sql, [$payrollId]);
        
        if (!$payroll) {
            return null;
        }
        
        // Get payroll components
        $payroll['components'] = $this->getPayrollComponents($payrollId);
        
        return $payroll;
    }
    
    /**
     * Get payroll components
     */
    private function getPayrollComponents($payrollId)
    {
        $sql = "SELECT * FROM payroll_components WHERE payroll_id = ? ORDER BY component_type, component_name";
        return $this->connection->fetchAll($sql, [$payrollId]);
    }
    
    /**
     * Get employee payrolls
     */
    public function getEmployeePayrolls($employeeId, $year = null)
    {
        $year = $year ?? date('Y');
        
        $sql = "SELECT 
                    p.*,
                    ps.payslip_number,
                    ps.status as payslip_status
                FROM payrolls p
                LEFT JOIN payslips ps ON p.id = ps.payroll_id
                WHERE p.employee_id = ? AND p.year = ?
                ORDER BY p.month DESC";
        
        return $this->connection->fetchAll($sql, [$employeeId, $year]);
    }
    
    /**
     * Get payroll statistics
     */
    public function getPayrollStatistics($month = null, $year = null)
    {
        $month = $month ?? date('m');
        $year = $year ?? date('Y');
        
        $sql = "SELECT 
                    COUNT(*) as total_payrolls,
                    SUM(basic_salary) as total_basic_salary,
                    SUM(gross_salary) as total_gross_salary,
                    SUM(total_deductions) as total_deductions,
                    SUM(net_salary) as total_net_salary,
                    AVG(net_salary) as average_net_salary
                FROM payrolls 
                WHERE month = ? AND year = ?";
        
        return $this->connection->fetchOne($sql, [$month, $year]);
    }
    
    /**
     * Get department-wise payroll statistics
     */
    public function getDepartmentWisePayrollStatistics($month = null, $year = null)
    {
        $month = $month ?? date('m');
        $year = $year ?? date('Y');
        
        $sql = "SELECT 
                    d.name as department_name,
                    COUNT(p.id) as employee_count,
                    SUM(p.basic_salary) as total_basic_salary,
                    SUM(p.gross_salary) as total_gross_salary,
                    SUM(p.net_salary) as total_net_salary,
                    AVG(p.net_salary) as average_net_salary
                FROM payrolls p
                LEFT JOIN employees e ON p.employee_id = e.id
                LEFT JOIN departments d ON e.department_id = d.id
                WHERE p.month = ? AND p.year = ?
                GROUP BY d.id, d.name
                ORDER BY total_net_salary DESC";
        
        return $this->connection->fetchAll($sql, [$month, $year]);
    }
    
    /**
     * Get active employees
     */
    private function getActiveEmployees()
    {
        $sql = "SELECT id, first_name, last_name, employee_id, salary 
                FROM employees 
                WHERE status = 'active' 
                ORDER BY first_name, last_name";
        
        return $this->connection->fetchAll($sql);
    }
    
    /**
     * Send payslip via email
     */
    public function sendPayslipEmail($payslipId)
    {
        $payslip = Payslip::find($payslipId);
        
        if (!$payslip) {
            throw new ValidationException('Payslip not found');
        }
        
        $employee = $payslip->employee;
        
        $subject = "Payslip - {$employee->first_name} {$employee->last_name} - {$payslip->month}/{$payslip->year}";
        $message = "Dear {$employee->first_name},\n\n";
        $message .= "Please find attached your payslip for {$payslip->month}/{$payslip->year}.\n\n";
        $message .= "Payslip Details:\n";
        $message .= "Payslip Number: {$payslip->payslip_number}\n";
        $message .= "Month: {$payslip->month}/{$payslip->year}\n";
        $message .= "Net Salary: ₹{$payslip->net_salary}\n\n";
        $message .= "If you have any questions, please contact HR department.\n\n";
        $message .= "Best regards,\nHR Department";
        
        $this->emailService->send($employee->email, $subject, $message);
        
        return true;
    }
    
    /**
     * Get payroll analytics
     */
    public function getPayrollAnalytics($year = null)
    {
        $year = $year ?? date('Y');
        
        $sql = "SELECT 
                    month,
                    COUNT(*) as employee_count,
                    SUM(basic_salary) as total_basic_salary,
                    SUM(gross_salary) as total_gross_salary,
                    SUM(net_salary) as total_net_salary,
                    AVG(net_salary) as average_net_salary
                FROM payrolls 
                WHERE year = ?
                GROUP BY month
                ORDER BY month";
        
        return $this->connection->fetchAll($sql, [$year]);
    }
    
    /**
     * Export payroll to CSV
     */
    public function exportPayrollToCSV($month, $year)
    {
        $sql = "SELECT 
                    e.employee_id,
                    e.first_name,
                    e.last_name,
                    d.name as department_name,
                    des.name as designation_name,
                    p.basic_salary,
                    p.gross_salary,
                    p.total_deductions,
                    p.net_salary
                FROM payrolls p
                LEFT JOIN employees e ON p.employee_id = e.id
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN designations des ON e.designation_id = des.id
                WHERE p.month = ? AND p.year = ?
                ORDER BY e.first_name, e.last_name";
        
        $payrolls = $this->connection->fetchAll($sql, [$month, $year]);
        
        $filename = "payroll_{$month}_{$year}.csv";
        $filepath = storage_path('exports/' . $filename);
        
        $file = fopen($filepath, 'w');
        
        // Write headers
        fputcsv($file, [
            'Employee ID', 'Name', 'Department', 'Designation',
            'Basic Salary', 'Gross Salary', 'Total Deductions', 'Net Salary'
        ]);
        
        // Write data
        foreach ($payrolls as $payroll) {
            fputcsv($file, [
                $payroll['employee_id'],
                $payroll['first_name'] . ' ' . $payroll['last_name'],
                $payroll['department_name'],
                $payroll['designation_name'],
                $payroll['basic_salary'],
                $payroll['gross_salary'],
                $payroll['total_deductions'],
                $payroll['net_salary']
            ]);
        }
        
        fclose($file);
        
        return $filepath;
    }
}