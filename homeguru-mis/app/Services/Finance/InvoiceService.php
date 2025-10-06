<?php

namespace App\Services\Finance;

use App\Models\Finance\Invoice;
use App\Models\Finance\InvoiceItem;
use App\Models\Finance\Payment;
use App\Models\Academic\Student;
use App\Libraries\Database\Connection;
use App\Services\Communication\EmailService;
use App\Services\Communication\SMSService;
use App\Exceptions\ValidationException;

class InvoiceService
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
     * Get invoice by ID
     */
    public function getInvoice($invoiceId)
    {
        $sql = "SELECT 
                    i.*,
                    s.first_name,
                    s.last_name,
                    s.roll_number,
                    s.admission_number,
                    c.name as class_name,
                    sec.name as section_name,
                    fs.name as fee_structure_name,
                    fs.term
                FROM invoices i
                LEFT JOIN students s ON i.student_id = s.id
                LEFT JOIN classes c ON s.class_id = c.id
                LEFT JOIN sections sec ON s.section_id = sec.id
                LEFT JOIN fee_structures fs ON i.fee_structure_id = fs.id
                WHERE i.id = ?";
        
        $invoice = $this->connection->fetchOne($sql, [$invoiceId]);
        
        if (!$invoice) {
            return null;
        }
        
        // Get invoice items
        $invoice['items'] = $this->getInvoiceItems($invoiceId);
        
        // Get payments
        $invoice['payments'] = $this->getInvoicePayments($invoiceId);
        
        return $invoice;
    }
    
    /**
     * Get invoice items
     */
    public function getInvoiceItems($invoiceId)
    {
        $sql = "SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id";
        return $this->connection->fetchAll($sql, [$invoiceId]);
    }
    
    /**
     * Get invoice payments
     */
    public function getInvoicePayments($invoiceId)
    {
        $sql = "SELECT 
                    p.*,
                    pm.name as payment_method_name
                FROM payments p
                LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id
                WHERE p.invoice_id = ?
                ORDER BY p.payment_date DESC";
        
        return $this->connection->fetchAll($sql, [$invoiceId]);
    }
    
    /**
     * Update invoice status
     */
    public function updateInvoiceStatus($invoiceId, $status)
    {
        $invoice = Invoice::find($invoiceId);
        
        if (!$invoice) {
            throw new ValidationException('Invoice not found');
        }
        
        $validStatuses = ['pending', 'paid', 'overdue', 'cancelled'];
        if (!in_array($status, $validStatuses)) {
            throw new ValidationException('Invalid invoice status');
        }
        
        $invoice->status = $status;
        $invoice->updated_at = date('Y-m-d H:i:s');
        
        if ($status === 'paid') {
            $invoice->paid_at = date('Y-m-d H:i:s');
        }
        
        $invoice->save();
        
        return $invoice;
    }
    
    /**
     * Calculate invoice totals
     */
    public function calculateInvoiceTotals($invoiceId)
    {
        $invoice = Invoice::find($invoiceId);
        
        if (!$invoice) {
            throw new ValidationException('Invoice not found');
        }
        
        // Get total payments
        $sql = "SELECT SUM(amount) as total_paid FROM payments WHERE invoice_id = ? AND status = 'completed'";
        $result = $this->connection->fetchOne($sql, [$invoiceId]);
        $totalPaid = $result['total_paid'] ?? 0;
        
        // Calculate pending amount
        $pendingAmount = $invoice->net_amount - $totalPaid;
        
        // Update invoice
        $invoice->paid_amount = $totalPaid;
        $invoice->pending_amount = $pendingAmount;
        $invoice->status = $pendingAmount <= 0 ? 'paid' : ($invoice->due_date < date('Y-m-d') ? 'overdue' : 'pending');
        $invoice->updated_at = date('Y-m-d H:i:s');
        
        $invoice->save();
        
        return [
            'total_amount' => $invoice->total_amount,
            'discount_amount' => $invoice->discount_amount,
            'net_amount' => $invoice->net_amount,
            'paid_amount' => $totalPaid,
            'pending_amount' => $pendingAmount,
            'status' => $invoice->status
        ];
    }
    
    /**
     * Generate invoice PDF
     */
    public function generateInvoicePDF($invoiceId)
    {
        $invoice = $this->getInvoice($invoiceId);
        
        if (!$invoice) {
            throw new ValidationException('Invoice not found');
        }
        
        $html = $this->generateInvoiceHTML($invoice);
        $filename = "Invoice_{$invoice['invoice_number']}.pdf";
        
        return $this->pdfGenerator->generatePDF($html, $filename);
    }
    
    /**
     * Generate invoice HTML
     */
    private function generateInvoiceHTML($invoice)
    {
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Invoice - ' . $invoice['invoice_number'] . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { text-align: center; margin-bottom: 30px; }
        .school-name { font-size: 24px; font-weight: bold; margin-bottom: 10px; }
        .invoice-title { font-size: 18px; margin-bottom: 20px; }
        .invoice-info { margin-bottom: 20px; }
        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .info-table td { padding: 5px; border: 1px solid #ddd; }
        .info-table .label { font-weight: bold; background-color: #f5f5f5; }
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .items-table th, .items-table td { padding: 8px; border: 1px solid #ddd; text-align: center; }
        .items-table th { background-color: #f5f5f5; font-weight: bold; }
        .totals { margin-top: 20px; }
        .totals-table { width: 50%; margin-left: auto; border-collapse: collapse; }
        .totals-table td { padding: 8px; border: 1px solid #ddd; }
        .totals-table .label { font-weight: bold; background-color: #f5f5f5; }
        .amount { text-align: right; }
    </style>
</head>
<body>
    <div class="header">
        <div class="school-name">HomeGuru School</div>
        <div class="invoice-title">Fee Invoice</div>
    </div>
    
    <div class="invoice-info">
        <table class="info-table">
            <tr>
                <td class="label">Invoice Number:</td>
                <td>' . $invoice['invoice_number'] . '</td>
                <td class="label">Invoice Date:</td>
                <td>' . date('d-m-Y', strtotime($invoice['created_at'])) . '</td>
            </tr>
            <tr>
                <td class="label">Student Name:</td>
                <td>' . $invoice['first_name'] . ' ' . $invoice['last_name'] . '</td>
                <td class="label">Roll Number:</td>
                <td>' . $invoice['roll_number'] . '</td>
            </tr>
            <tr>
                <td class="label">Class:</td>
                <td>' . $invoice['class_name'] . ' ' . $invoice['section_name'] . '</td>
                <td class="label">Due Date:</td>
                <td>' . date('d-m-Y', strtotime($invoice['due_date'])) . '</td>
            </tr>
        </table>
    </div>
    
    <table class="items-table">
        <thead>
            <tr>
                <th>Description</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>';
        
        foreach ($invoice['items'] as $item) {
            $html .= '<tr>
                <td>' . $item['category_name'] . ($item['description'] ? ' - ' . $item['description'] : '') . '</td>
                <td class="amount">₹' . number_format($item['amount'], 2) . '</td>
            </tr>';
        }
        
        $html .= '</tbody>
    </table>
    
    <div class="totals">
        <table class="totals-table">
            <tr>
                <td class="label">Total Amount:</td>
                <td class="amount">₹' . number_format($invoice['total_amount'], 2) . '</td>
            </tr>
            <tr>
                <td class="label">Discount:</td>
                <td class="amount">-₹' . number_format($invoice['discount_amount'], 2) . '</td>
            </tr>
            <tr>
                <td class="label">Net Amount:</td>
                <td class="amount">₹' . number_format($invoice['net_amount'], 2) . '</td>
            </tr>
            <tr>
                <td class="label">Paid Amount:</td>
                <td class="amount">₹' . number_format($invoice['paid_amount'], 2) . '</td>
            </tr>
            <tr>
                <td class="label">Pending Amount:</td>
                <td class="amount">₹' . number_format($invoice['pending_amount'], 2) . '</td>
            </tr>
        </table>
    </div>
</body>
</html>';
        
        return $html;
    }
    
    /**
     * Send invoice via email
     */
    public function sendInvoiceEmail($invoiceId, $email = null)
    {
        $invoice = $this->getInvoice($invoiceId);
        
        if (!$invoice) {
            throw new ValidationException('Invoice not found');
        }
        
        // Get parent email if not provided
        if (!$email) {
            $sql = "SELECT p.email FROM parents p 
                    INNER JOIN student_parents sp ON p.id = sp.parent_id 
                    WHERE sp.student_id = ? AND sp.relationship = 'Primary'";
            
            $parent = $this->connection->fetchOne($sql, [$invoice['student_id']]);
            $email = $parent['email'] ?? null;
        }
        
        if (!$email) {
            throw new ValidationException('No email address found');
        }
        
        // Generate PDF
        $pdfPath = $this->generateInvoicePDF($invoiceId);
        
        // Send email
        $subject = "Fee Invoice - {$invoice['first_name']} {$invoice['last_name']} - {$invoice['invoice_number']}";
        $message = "Dear Parent,\n\n";
        $message .= "Please find attached the fee invoice for {$invoice['first_name']} {$invoice['last_name']}.\n\n";
        $message .= "Invoice Details:\n";
        $message .= "Invoice Number: {$invoice['invoice_number']}\n";
        $message .= "Total Amount: ₹{$invoice['total_amount']}\n";
        $message .= "Net Amount: ₹{$invoice['net_amount']}\n";
        $message .= "Due Date: {$invoice['due_date']}\n\n";
        $message .= "Please make payment before the due date.\n\n";
        $message .= "Best regards,\nSchool Administration";
        
        $this->emailService->sendWithAttachment($email, $subject, $message, $pdfPath);
        
        return true;
    }
    
    /**
     * Get invoice statistics
     */
    public function getInvoiceStatistics($filters = [])
    {
        $whereClause = "1=1";
        $params = [];
        
        if (!empty($filters['academic_year'])) {
            $whereClause .= " AND fs.academic_year = ?";
            $params[] = $filters['academic_year'];
        }
        
        if (!empty($filters['class_id'])) {
            $whereClause .= " AND fs.class_id = ?";
            $params[] = $filters['class_id'];
        }
        
        if (!empty($filters['status'])) {
            $whereClause .= " AND i.status = ?";
            $params[] = $filters['status'];
        }
        
        $sql = "SELECT 
                    COUNT(*) as total_invoices,
                    SUM(i.total_amount) as total_amount,
                    SUM(i.net_amount) as net_amount,
                    SUM(i.paid_amount) as paid_amount,
                    SUM(i.pending_amount) as pending_amount,
                    COUNT(CASE WHEN i.status = 'paid' THEN 1 END) as paid_invoices,
                    COUNT(CASE WHEN i.status = 'pending' THEN 1 END) as pending_invoices,
                    COUNT(CASE WHEN i.status = 'overdue' THEN 1 END) as overdue_invoices
                FROM invoices i
                LEFT JOIN fee_structures fs ON i.fee_structure_id = fs.id
                WHERE {$whereClause}";
        
        return $this->connection->fetchOne($sql, $params);
    }
    
    /**
     * Get overdue invoices
     */
    public function getOverdueInvoices($days = 0)
    {
        $sql = "SELECT 
                    i.*,
                    s.first_name,
                    s.last_name,
                    s.roll_number,
                    c.name as class_name,
                    fs.name as fee_structure_name
                FROM invoices i
                LEFT JOIN students s ON i.student_id = s.id
                LEFT JOIN classes c ON s.class_id = c.id
                LEFT JOIN fee_structures fs ON i.fee_structure_id = fs.id
                WHERE i.status IN ('pending', 'overdue') 
                AND i.due_date < DATE_SUB(NOW(), INTERVAL ? DAY)
                ORDER BY i.due_date ASC";
        
        return $this->connection->fetchAll($sql, [$days]);
    }
    
    /**
     * Send overdue reminders
     */
    public function sendOverdueReminders($days = 0)
    {
        $overdueInvoices = $this->getOverdueInvoices($days);
        $sentCount = 0;
        
        foreach ($overdueInvoices as $invoice) {
            try {
                $this->sendOverdueReminder($invoice);
                $sentCount++;
            } catch (\Exception $e) {
                // Log error but continue
                error_log("Failed to send overdue reminder for invoice {$invoice['id']}: " . $e->getMessage());
            }
        }
        
        return $sentCount;
    }
    
    /**
     * Send overdue reminder
     */
    private function sendOverdueReminder($invoice)
    {
        // Get parent contact information
        $sql = "SELECT p.email, p.phone FROM parents p 
                INNER JOIN student_parents sp ON p.id = sp.parent_id 
                WHERE sp.student_id = ? AND sp.relationship = 'Primary'";
        
        $parent = $this->connection->fetchOne($sql, [$invoice['student_id']]);
        
        if (!$parent) {
            return false;
        }
        
        // Send email reminder
        if ($parent['email']) {
            $subject = "Overdue Fee Reminder - {$invoice['first_name']} {$invoice['last_name']}";
            $message = "Dear Parent,\n\n";
            $message .= "This is a reminder that the fee payment for {$invoice['first_name']} {$invoice['last_name']} is overdue.\n\n";
            $message .= "Invoice Details:\n";
            $message .= "Invoice Number: {$invoice['invoice_number']}\n";
            $message .= "Amount Due: ₹{$invoice['pending_amount']}\n";
            $message .= "Due Date: {$invoice['due_date']}\n";
            $message .= "Days Overdue: " . (strtotime('now') - strtotime($invoice['due_date'])) / (60 * 60 * 24) . "\n\n";
            $message .= "Please make payment at the earliest to avoid any inconvenience.\n\n";
            $message .= "Best regards,\nSchool Administration";
            
            $this->emailService->send($parent['email'], $subject, $message);
        }
        
        // Send SMS reminder
        if ($parent['phone']) {
            $message = "Fee payment overdue for {$invoice['first_name']}. Amount: ₹{$invoice['pending_amount']}. Please pay immediately.";
            $this->smsService->send($parent['phone'], $message);
        }
        
        return true;
    }
    
    /**
     * Export invoices to CSV
     */
    public function exportInvoicesToCSV($filters = [])
    {
        $whereClause = "1=1";
        $params = [];
        
        if (!empty($filters['academic_year'])) {
            $whereClause .= " AND fs.academic_year = ?";
            $params[] = $filters['academic_year'];
        }
        
        if (!empty($filters['class_id'])) {
            $whereClause .= " AND fs.class_id = ?";
            $params[] = $filters['class_id'];
        }
        
        if (!empty($filters['status'])) {
            $whereClause .= " AND i.status = ?";
            $params[] = $filters['status'];
        }
        
        $sql = "SELECT 
                    i.invoice_number,
                    s.first_name,
                    s.last_name,
                    s.roll_number,
                    c.name as class_name,
                    fs.name as fee_structure_name,
                    i.total_amount,
                    i.net_amount,
                    i.paid_amount,
                    i.pending_amount,
                    i.status,
                    i.due_date,
                    i.created_at
                FROM invoices i
                LEFT JOIN students s ON i.student_id = s.id
                LEFT JOIN classes c ON s.class_id = c.id
                LEFT JOIN fee_structures fs ON i.fee_structure_id = fs.id
                WHERE {$whereClause}
                ORDER BY i.created_at DESC";
        
        $invoices = $this->connection->fetchAll($sql, $params);
        
        $filename = 'invoices_' . date('Y-m-d') . '.csv';
        $filepath = storage_path('exports/' . $filename);
        
        $file = fopen($filepath, 'w');
        
        // Write headers
        fputcsv($file, [
            'Invoice Number', 'Student Name', 'Roll Number', 'Class', 'Fee Structure',
            'Total Amount', 'Net Amount', 'Paid Amount', 'Pending Amount', 'Status',
            'Due Date', 'Created Date'
        ]);
        
        // Write data
        foreach ($invoices as $invoice) {
            fputcsv($file, [
                $invoice['invoice_number'],
                $invoice['first_name'] . ' ' . $invoice['last_name'],
                $invoice['roll_number'],
                $invoice['class_name'],
                $invoice['fee_structure_name'],
                $invoice['total_amount'],
                $invoice['net_amount'],
                $invoice['paid_amount'],
                $invoice['pending_amount'],
                $invoice['status'],
                $invoice['due_date'],
                $invoice['created_at']
            ]);
        }
        
        fclose($file);
        
        return $filepath;
    }
    
    /**
     * Get invoice analytics
     */
    public function getInvoiceAnalytics($academicYear = null)
    {
        $academicYear = $academicYear ?? date('Y');
        
        $sql = "SELECT 
                    MONTH(i.created_at) as month,
                    COUNT(*) as invoice_count,
                    SUM(i.total_amount) as total_amount,
                    SUM(i.paid_amount) as paid_amount,
                    SUM(i.pending_amount) as pending_amount
                FROM invoices i
                LEFT JOIN fee_structures fs ON i.fee_structure_id = fs.id
                WHERE fs.academic_year = ?
                GROUP BY MONTH(i.created_at)
                ORDER BY month";
        
        return $this->connection->fetchAll($sql, [$academicYear]);
    }
}