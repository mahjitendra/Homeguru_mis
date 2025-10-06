<?php

namespace App\Services\Finance;

use App\Models\Finance\FeeStructure;
use App\Models\Finance\FeeCategory;
use App\Models\Finance\FeeDiscount;
use App\Models\Finance\Invoice;
use App\Models\Finance\Payment;
use App\Models\Academic\Student;
use App\Libraries\Database\Connection;
use App\Services\Communication\EmailService;
use App\Services\Communication\SMSService;
use App\Exceptions\ValidationException;

class FeeService
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
     * Create fee structure
     */
    public function createFeeStructure($data)
    {
        $this->validateFeeStructureData($data);
        
        $feeStructure = FeeStructure::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'class_id' => $data['class_id'],
            'academic_year' => $data['academic_year'],
            'term' => $data['term'] ?? 'annual',
            'total_amount' => $data['total_amount'],
            'due_date' => $data['due_date'],
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        // Create fee categories
        if (!empty($data['categories'])) {
            $this->createFeeCategories($feeStructure->id, $data['categories']);
        }
        
        return $feeStructure;
    }
    
    /**
     * Create fee categories
     */
    private function createFeeCategories($feeStructureId, $categories)
    {
        foreach ($categories as $category) {
            FeeCategory::create([
                'fee_structure_id' => $feeStructureId,
                'name' => $category['name'],
                'description' => $category['description'] ?? null,
                'amount' => $category['amount'],
                'is_optional' => $category['is_optional'] ?? false,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
    }
    
    /**
     * Update fee structure
     */
    public function updateFeeStructure($feeStructureId, $data)
    {
        $feeStructure = FeeStructure::find($feeStructureId);
        
        if (!$feeStructure) {
            throw new ValidationException('Fee structure not found');
        }
        
        $this->validateFeeStructureData($data);
        
        $feeStructure->name = $data['name'];
        $feeStructure->description = $data['description'] ?? $feeStructure->description;
        $feeStructure->total_amount = $data['total_amount'];
        $feeStructure->due_date = $data['due_date'];
        $feeStructure->updated_at = date('Y-m-d H:i:s');
        
        $feeStructure->save();
        
        return $feeStructure;
    }
    
    /**
     * Delete fee structure
     */
    public function deleteFeeStructure($feeStructureId)
    {
        $feeStructure = FeeStructure::find($feeStructureId);
        
        if (!$feeStructure) {
            throw new ValidationException('Fee structure not found');
        }
        
        // Check if there are invoices for this fee structure
        $invoices = Invoice::where('fee_structure_id', $feeStructureId)->get();
        
        if (!empty($invoices)) {
            throw new ValidationException('Cannot delete fee structure with existing invoices');
        }
        
        $feeStructure->delete();
        
        return true;
    }
    
    /**
     * Generate invoices for students
     */
    public function generateInvoices($feeStructureId, $studentIds = null)
    {
        $feeStructure = FeeStructure::find($feeStructureId);
        
        if (!$feeStructure) {
            throw new ValidationException('Fee structure not found');
        }
        
        // Get students for this fee structure
        $students = $this->getStudentsForFeeStructure($feeStructure, $studentIds);
        
        $invoices = [];
        
        foreach ($students as $student) {
            try {
                $invoice = $this->createInvoice($feeStructure, $student);
                $invoices[] = $invoice;
            } catch (\Exception $e) {
                $invoices[] = [
                    'student_id' => $student['id'],
                    'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $invoices;
    }
    
    /**
     * Create invoice for student
     */
    private function createInvoice($feeStructure, $student)
    {
        // Check if invoice already exists
        $existingInvoice = Invoice::where('fee_structure_id', $feeStructure->id)
            ->where('student_id', $student['id'])
            ->first();
        
        if ($existingInvoice) {
            throw new ValidationException('Invoice already exists for this student');
        }
        
        // Calculate total amount with discounts
        $totalAmount = $this->calculateTotalAmount($feeStructure, $student);
        
        $invoice = Invoice::create([
            'invoice_number' => $this->generateInvoiceNumber(),
            'student_id' => $student['id'],
            'fee_structure_id' => $feeStructure->id,
            'total_amount' => $totalAmount,
            'discount_amount' => $this->calculateDiscountAmount($feeStructure, $student),
            'net_amount' => $totalAmount - $this->calculateDiscountAmount($feeStructure, $student),
            'due_date' => $feeStructure->due_date,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        // Create invoice items
        $this->createInvoiceItems($invoice->id, $feeStructure);
        
        // Send invoice notification
        $this->sendInvoiceNotification($invoice, $student);
        
        return $invoice;
    }
    
    /**
     * Calculate total amount
     */
    private function calculateTotalAmount($feeStructure, $student)
    {
        $totalAmount = $feeStructure->total_amount;
        
        // Apply any applicable discounts
        $discounts = $this->getApplicableDiscounts($student);
        $discountAmount = 0;
        
        foreach ($discounts as $discount) {
            if ($discount['type'] === 'percentage') {
                $discountAmount += ($totalAmount * $discount['value']) / 100;
            } else {
                $discountAmount += $discount['value'];
            }
        }
        
        return $totalAmount - $discountAmount;
    }
    
    /**
     * Calculate discount amount
     */
    private function calculateDiscountAmount($feeStructure, $student)
    {
        $discounts = $this->getApplicableDiscounts($student);
        $discountAmount = 0;
        
        foreach ($discounts as $discount) {
            if ($discount['type'] === 'percentage') {
                $discountAmount += ($feeStructure->total_amount * $discount['value']) / 100;
            } else {
                $discountAmount += $discount['value'];
            }
        }
        
        return $discountAmount;
    }
    
    /**
     * Get applicable discounts
     */
    private function getApplicableDiscounts($student)
    {
        $sql = "SELECT * FROM fee_discounts 
                WHERE status = 'active' 
                AND (class_id = ? OR class_id IS NULL)
                AND (academic_year = ? OR academic_year IS NULL)
                AND (start_date <= ? AND end_date >= ?)";
        
        return $this->connection->fetchAll($sql, [
            $student['class_id'],
            $student['academic_year'],
            date('Y-m-d'),
            date('Y-m-d')
        ]);
    }
    
    /**
     * Create invoice items
     */
    private function createInvoiceItems($invoiceId, $feeStructure)
    {
        $categories = FeeCategory::where('fee_structure_id', $feeStructure->id)->get();
        
        foreach ($categories as $category) {
            $sql = "INSERT INTO invoice_items (
                invoice_id, category_name, description, amount, 
                is_optional, created_at
            ) VALUES (?, ?, ?, ?, ?, ?)";
            
            $this->connection->execute($sql, [
                $invoiceId,
                $category->name,
                $category->description,
                $category->amount,
                $category->is_optional,
                date('Y-m-d H:i:s')
            ]);
        }
    }
    
    /**
     * Generate invoice number
     */
    private function generateInvoiceNumber()
    {
        $year = date('Y');
        $month = date('m');
        
        $sql = "SELECT COUNT(*) as count FROM invoices 
                WHERE invoice_number LIKE ?";
        
        $result = $this->connection->fetchOne($sql, ["INV-{$year}{$month}%"]);
        $sequence = str_pad($result['count'] + 1, 4, '0', STR_PAD_LEFT);
        
        return "INV-{$year}{$month}{$sequence}";
    }
    
    /**
     * Get students for fee structure
     */
    private function getStudentsForFeeStructure($feeStructure, $studentIds = null)
    {
        $sql = "SELECT s.*, c.name as class_name 
                FROM students s
                LEFT JOIN classes c ON s.class_id = c.id
                WHERE s.class_id = ? AND s.academic_year = ? AND s.status = 'active'";
        
        $params = [$feeStructure->class_id, $feeStructure->academic_year];
        
        if ($studentIds) {
            $placeholders = str_repeat('?,', count($studentIds) - 1) . '?';
            $sql .= " AND s.id IN ({$placeholders})";
            $params = array_merge($params, $studentIds);
        }
        
        $sql .= " ORDER BY s.roll_number";
        
        return $this->connection->fetchAll($sql, $params);
    }
    
    /**
     * Get fee structures
     */
    public function getFeeStructures($filters = [])
    {
        $sql = "SELECT 
                    fs.*,
                    c.name as class_name
                FROM fee_structures fs
                LEFT JOIN classes c ON fs.class_id = c.id
                WHERE 1=1";
        
        $params = [];
        
        if (!empty($filters['class_id'])) {
            $sql .= " AND fs.class_id = ?";
            $params[] = $filters['class_id'];
        }
        
        if (!empty($filters['academic_year'])) {
            $sql .= " AND fs.academic_year = ?";
            $params[] = $filters['academic_year'];
        }
        
        if (!empty($filters['status'])) {
            $sql .= " AND fs.status = ?";
            $params[] = $filters['status'];
        }
        
        $sql .= " ORDER BY fs.created_at DESC";
        
        return $this->connection->fetchAll($sql, $params);
    }
    
    /**
     * Get student invoices
     */
    public function getStudentInvoices($studentId, $status = null)
    {
        $sql = "SELECT 
                    i.*,
                    fs.name as fee_structure_name,
                    fs.term,
                    c.name as class_name
                FROM invoices i
                LEFT JOIN fee_structures fs ON i.fee_structure_id = fs.id
                LEFT JOIN classes c ON fs.class_id = c.id
                WHERE i.student_id = ?";
        
        $params = [$studentId];
        
        if ($status) {
            $sql .= " AND i.status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY i.created_at DESC";
        
        return $this->connection->fetchAll($sql, $params);
    }
    
    /**
     * Get fee statistics
     */
    public function getFeeStatistics($academicYear = null)
    {
        $academicYear = $academicYear ?? date('Y');
        
        $sql = "SELECT 
                    COUNT(*) as total_invoices,
                    SUM(total_amount) as total_amount,
                    SUM(net_amount) as net_amount,
                    SUM(paid_amount) as paid_amount,
                    SUM(pending_amount) as pending_amount,
                    COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_invoices,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_invoices,
                    COUNT(CASE WHEN status = 'overdue' THEN 1 END) as overdue_invoices
                FROM invoices i
                LEFT JOIN fee_structures fs ON i.fee_structure_id = fs.id
                WHERE fs.academic_year = ?";
        
        return $this->connection->fetchOne($sql, [$academicYear]);
    }
    
    /**
     * Get class-wise fee statistics
     */
    public function getClassWiseFeeStatistics($academicYear = null)
    {
        $academicYear = $academicYear ?? date('Y');
        
        $sql = "SELECT 
                    c.name as class_name,
                    COUNT(i.id) as total_invoices,
                    SUM(i.total_amount) as total_amount,
                    SUM(i.paid_amount) as paid_amount,
                    SUM(i.pending_amount) as pending_amount,
                    COUNT(CASE WHEN i.status = 'paid' THEN 1 END) as paid_invoices,
                    COUNT(CASE WHEN i.status = 'pending' THEN 1 END) as pending_invoices
                FROM invoices i
                LEFT JOIN fee_structures fs ON i.fee_structure_id = fs.id
                LEFT JOIN classes c ON fs.class_id = c.id
                WHERE fs.academic_year = ?
                GROUP BY c.id, c.name
                ORDER BY c.name";
        
        return $this->connection->fetchAll($sql, [$academicYear]);
    }
    
    /**
     * Send invoice notification
     */
    private function sendInvoiceNotification($invoice, $student)
    {
        // Get parent email
        $sql = "SELECT p.email, p.phone FROM parents p 
                INNER JOIN student_parents sp ON p.id = sp.parent_id 
                WHERE sp.student_id = ? AND sp.relationship = 'Primary'";
        
        $parent = $this->connection->fetchOne($sql, [$student['id']]);
        
        if (!$parent) {
            return;
        }
        
        // Send email
        if ($parent['email']) {
            $subject = "Fee Invoice - {$student['first_name']} {$student['last_name']}";
            $message = "Dear Parent,\n\n";
            $message .= "A new fee invoice has been generated for {$student['first_name']} {$student['last_name']}.\n\n";
            $message .= "Invoice Details:\n";
            $message .= "Invoice Number: {$invoice->invoice_number}\n";
            $message .= "Total Amount: ₹{$invoice->total_amount}\n";
            $message .= "Due Date: {$invoice->due_date}\n\n";
            $message .= "Please make payment before the due date.\n\n";
            $message .= "Best regards,\nSchool Administration";
            
            $this->emailService->send($parent['email'], $subject, $message);
        }
        
        // Send SMS
        if ($parent['phone']) {
            $message = "Fee Invoice generated for {$student['first_name']}. Amount: ₹{$invoice->total_amount}. Due: {$invoice->due_date}";
            $this->smsService->send($parent['phone'], $message);
        }
    }
    
    /**
     * Validate fee structure data
     */
    private function validateFeeStructureData($data)
    {
        $required = ['name', 'class_id', 'academic_year', 'total_amount', 'due_date'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new ValidationException("Field {$field} is required");
            }
        }
        
        if (!is_numeric($data['total_amount']) || $data['total_amount'] <= 0) {
            throw new ValidationException('Total amount must be a positive number');
        }
        
        if (strtotime($data['due_date']) < time()) {
            throw new ValidationException('Due date cannot be in the past');
        }
    }
    
    /**
     * Create fee discount
     */
    public function createFeeDiscount($data)
    {
        $this->validateDiscountData($data);
        
        $discount = FeeDiscount::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'],
            'value' => $data['value'],
            'class_id' => $data['class_id'] ?? null,
            'academic_year' => $data['academic_year'] ?? null,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        return $discount;
    }
    
    /**
     * Validate discount data
     */
    private function validateDiscountData($data)
    {
        $required = ['name', 'type', 'value', 'start_date', 'end_date'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new ValidationException("Field {$field} is required");
            }
        }
        
        $validTypes = ['percentage', 'fixed'];
        if (!in_array($data['type'], $validTypes)) {
            throw new ValidationException('Invalid discount type');
        }
        
        if (!is_numeric($data['value']) || $data['value'] <= 0) {
            throw new ValidationException('Discount value must be a positive number');
        }
        
        if ($data['type'] === 'percentage' && $data['value'] > 100) {
            throw new ValidationException('Percentage discount cannot exceed 100%');
        }
        
        if (strtotime($data['start_date']) > strtotime($data['end_date'])) {
            throw new ValidationException('Start date cannot be after end date');
        }
    }
}