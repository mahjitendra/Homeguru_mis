<?php

namespace App\Services\Finance;

use App\Models\Finance\Receipt;
use App\Models\Finance\Payment;
use App\Models\Finance\Invoice;
use App\Libraries\Database\Connection;
use App\Libraries\PDF\PDFGenerator;
use App\Services\Communication\EmailService;
use App\Exceptions\ValidationException;

class ReceiptService
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
     * Generate receipt
     */
    public function generateReceipt($paymentId)
    {
        $payment = Payment::find($paymentId);
        
        if (!$payment) {
            throw new ValidationException('Payment not found');
        }
        
        // Check if receipt already exists
        $existingReceipt = Receipt::where('payment_id', $paymentId)->first();
        
        if ($existingReceipt) {
            return $existingReceipt;
        }
        
        $receipt = Receipt::create([
            'payment_id' => $paymentId,
            'receipt_number' => $this->generateReceiptNumber(),
            'amount' => $payment->amount,
            'payment_date' => $payment->payment_date,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        return $receipt;
    }
    
    /**
     * Generate receipt number
     */
    private function generateReceiptNumber()
    {
        $year = date('Y');
        $month = date('m');
        
        $sql = "SELECT COUNT(*) as count FROM receipts WHERE receipt_number LIKE ?";
        $result = $this->connection->fetchOne($sql, ["RCP-{$year}{$month}%"]);
        $sequence = str_pad($result['count'] + 1, 4, '0', STR_PAD_LEFT);
        
        return "RCP-{$year}{$month}{$sequence}";
    }
    
    /**
     * Get receipt by ID
     */
    public function getReceipt($receiptId)
    {
        $sql = "SELECT 
                    r.*,
                    p.amount as payment_amount,
                    p.payment_date,
                    p.transaction_id,
                    p.reference_number,
                    p.cheque_number,
                    p.bank_name,
                    pm.name as payment_method_name,
                    pm.type as payment_method_type,
                    s.first_name,
                    s.last_name,
                    s.roll_number,
                    s.admission_number,
                    c.name as class_name,
                    sec.name as section_name,
                    i.invoice_number,
                    i.total_amount as invoice_total,
                    i.net_amount as invoice_net_amount
                FROM receipts r
                LEFT JOIN payments p ON r.payment_id = p.id
                LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id
                LEFT JOIN students s ON p.student_id = s.id
                LEFT JOIN classes c ON s.class_id = c.id
                LEFT JOIN sections sec ON s.section_id = sec.id
                LEFT JOIN invoices i ON p.invoice_id = i.id
                WHERE r.id = ?";
        
        return $this->connection->fetchOne($sql, [$receiptId]);
    }
    
    /**
     * Get receipts for student
     */
    public function getStudentReceipts($studentId, $startDate = null, $endDate = null)
    {
        $whereClause = "p.student_id = ?";
        $params = [$studentId];
        
        if ($startDate) {
            $whereClause .= " AND p.payment_date >= ?";
            $params[] = $startDate;
        }
        
        if ($endDate) {
            $whereClause .= " AND p.payment_date <= ?";
            $params[] = $endDate;
        }
        
        $sql = "SELECT 
                    r.*,
                    p.amount as payment_amount,
                    p.payment_date,
                    pm.name as payment_method_name,
                    i.invoice_number
                FROM receipts r
                LEFT JOIN payments p ON r.payment_id = p.id
                LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id
                LEFT JOIN invoices i ON p.invoice_id = i.id
                WHERE {$whereClause}
                ORDER BY p.payment_date DESC";
        
        return $this->connection->fetchAll($sql, $params);
    }
    
    /**
     * Get receipts for date range
     */
    public function getReceiptsByDateRange($startDate, $endDate, $filters = [])
    {
        $whereClause = "p.payment_date BETWEEN ? AND ?";
        $params = [$startDate, $endDate];
        
        if (!empty($filters['class_id'])) {
            $whereClause .= " AND s.class_id = ?";
            $params[] = $filters['class_id'];
        }
        
        if (!empty($filters['payment_method_id'])) {
            $whereClause .= " AND p.payment_method_id = ?";
            $params[] = $filters['payment_method_id'];
        }
        
        $sql = "SELECT 
                    r.*,
                    p.amount as payment_amount,
                    p.payment_date,
                    pm.name as payment_method_name,
                    s.first_name,
                    s.last_name,
                    s.roll_number,
                    c.name as class_name,
                    i.invoice_number
                FROM receipts r
                LEFT JOIN payments p ON r.payment_id = p.id
                LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id
                LEFT JOIN students s ON p.student_id = s.id
                LEFT JOIN classes c ON s.class_id = c.id
                LEFT JOIN invoices i ON p.invoice_id = i.id
                WHERE {$whereClause}
                ORDER BY p.payment_date DESC";
        
        return $this->connection->fetchAll($sql, $params);
    }
    
    /**
     * Generate receipt PDF
     */
    public function generateReceiptPDF($receiptId)
    {
        $receipt = $this->getReceipt($receiptId);
        
        if (!$receipt) {
            throw new ValidationException('Receipt not found');
        }
        
        $html = $this->generateReceiptHTML($receipt);
        $filename = "Receipt_{$receipt['receipt_number']}.pdf";
        
        return $this->pdfGenerator->generatePDF($html, $filename);
    }
    
    /**
     * Generate receipt HTML
     */
    private function generateReceiptHTML($receipt)
    {
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Receipt - ' . $receipt['receipt_number'] . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { text-align: center; margin-bottom: 30px; }
        .school-name { font-size: 24px; font-weight: bold; margin-bottom: 10px; }
        .receipt-title { font-size: 18px; margin-bottom: 20px; }
        .receipt-info { margin-bottom: 20px; }
        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .info-table td { padding: 5px; border: 1px solid #ddd; }
        .info-table .label { font-weight: bold; background-color: #f5f5f5; }
        .payment-details { margin-bottom: 20px; }
        .payment-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .payment-table th, .payment-table td { padding: 8px; border: 1px solid #ddd; text-align: center; }
        .payment-table th { background-color: #f5f5f5; font-weight: bold; }
        .amount { text-align: right; font-weight: bold; }
        .footer { margin-top: 30px; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <div class="school-name">HomeGuru School</div>
        <div class="receipt-title">Payment Receipt</div>
    </div>
    
    <div class="receipt-info">
        <table class="info-table">
            <tr>
                <td class="label">Receipt Number:</td>
                <td>' . $receipt['receipt_number'] . '</td>
                <td class="label">Receipt Date:</td>
                <td>' . date('d-m-Y', strtotime($receipt['created_at'])) . '</td>
            </tr>
            <tr>
                <td class="label">Student Name:</td>
                <td>' . $receipt['first_name'] . ' ' . $receipt['last_name'] . '</td>
                <td class="label">Roll Number:</td>
                <td>' . $receipt['roll_number'] . '</td>
            </tr>
            <tr>
                <td class="label">Admission Number:</td>
                <td>' . $receipt['admission_number'] . '</td>
                <td class="label">Class:</td>
                <td>' . $receipt['class_name'] . ' ' . $receipt['section_name'] . '</td>
            </tr>
        </table>
    </div>
    
    <div class="payment-details">
        <table class="payment-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Fee Payment - Invoice ' . $receipt['invoice_number'] . '</td>
                    <td class="amount">₹' . number_format($receipt['payment_amount'], 2) . '</td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <div class="payment-details">
        <table class="info-table">
            <tr>
                <td class="label">Payment Method:</td>
                <td>' . $receipt['payment_method_name'] . '</td>
                <td class="label">Payment Date:</td>
                <td>' . date('d-m-Y', strtotime($receipt['payment_date'])) . '</td>
            </tr>';
        
        if ($receipt['transaction_id']) {
            $html .= '<tr>
                <td class="label">Transaction ID:</td>
                <td>' . $receipt['transaction_id'] . '</td>
                <td class="label">Reference Number:</td>
                <td>' . $receipt['reference_number'] . '</td>
            </tr>';
        }
        
        if ($receipt['cheque_number']) {
            $html .= '<tr>
                <td class="label">Cheque Number:</td>
                <td>' . $receipt['cheque_number'] . '</td>
                <td class="label">Bank Name:</td>
                <td>' . $receipt['bank_name'] . '</td>
            </tr>';
        }
        
        $html .= '</table>
    </div>
    
    <div class="footer">
        <p>This is a computer generated receipt and does not require signature.</p>
        <p>Thank you for your payment!</p>
    </div>
</body>
</html>';
        
        return $html;
    }
    
    /**
     * Send receipt via email
     */
    public function sendReceiptEmail($receiptId, $email = null)
    {
        $receipt = $this->getReceipt($receiptId);
        
        if (!$receipt) {
            throw new ValidationException('Receipt not found');
        }
        
        // Get parent email if not provided
        if (!$email) {
            $sql = "SELECT p.email FROM parents p 
                    INNER JOIN student_parents sp ON p.id = sp.parent_id 
                    WHERE sp.student_id = ? AND sp.relationship = 'Primary'";
            
            $parent = $this->connection->fetchOne($sql, [$receipt['student_id']]);
            $email = $parent['email'] ?? null;
        }
        
        if (!$email) {
            throw new ValidationException('No email address found');
        }
        
        // Generate PDF
        $pdfPath = $this->generateReceiptPDF($receiptId);
        
        // Send email
        $subject = "Payment Receipt - {$receipt['first_name']} {$receipt['last_name']} - {$receipt['receipt_number']}";
        $message = "Dear Parent,\n\n";
        $message .= "Please find attached the payment receipt for {$receipt['first_name']} {$receipt['last_name']}.\n\n";
        $message .= "Receipt Details:\n";
        $message .= "Receipt Number: {$receipt['receipt_number']}\n";
        $message .= "Amount: ₹{$receipt['payment_amount']}\n";
        $message .= "Payment Date: {$receipt['payment_date']}\n";
        $message .= "Payment Method: {$receipt['payment_method_name']}\n\n";
        $message .= "Thank you for your payment.\n\n";
        $message .= "Best regards,\nSchool Administration";
        
        $this->emailService->sendWithAttachment($email, $subject, $message, $pdfPath);
        
        return true;
    }
    
    /**
     * Get receipt statistics
     */
    public function getReceiptStatistics($startDate = null, $endDate = null)
    {
        $startDate = $startDate ?? date('Y-m-01');
        $endDate = $endDate ?? date('Y-m-t');
        
        $sql = "SELECT 
                    COUNT(*) as total_receipts,
                    SUM(p.amount) as total_amount,
                    COUNT(DISTINCT p.student_id) as unique_students,
                    COUNT(DISTINCT p.payment_method_id) as payment_methods_used
                FROM receipts r
                LEFT JOIN payments p ON r.payment_id = p.id
                WHERE p.payment_date BETWEEN ? AND ?";
        
        return $this->connection->fetchOne($sql, [$startDate, $endDate]);
    }
    
    /**
     * Get daily receipt summary
     */
    public function getDailyReceiptSummary($date)
    {
        $sql = "SELECT 
                    COUNT(*) as receipt_count,
                    SUM(p.amount) as total_amount,
                    COUNT(DISTINCT p.student_id) as student_count,
                    COUNT(DISTINCT p.payment_method_id) as payment_method_count
                FROM receipts r
                LEFT JOIN payments p ON r.payment_id = p.id
                WHERE p.payment_date = ?";
        
        return $this->connection->fetchOne($sql, [$date]);
    }
    
    /**
     * Get monthly receipt summary
     */
    public function getMonthlyReceiptSummary($month, $year)
    {
        $startDate = "{$year}-{$month}-01";
        $endDate = date('Y-m-t', strtotime($startDate));
        
        $sql = "SELECT 
                    COUNT(*) as receipt_count,
                    SUM(p.amount) as total_amount,
                    COUNT(DISTINCT p.student_id) as student_count,
                    COUNT(DISTINCT p.payment_method_id) as payment_method_count,
                    AVG(p.amount) as average_amount
                FROM receipts r
                LEFT JOIN payments p ON r.payment_id = p.id
                WHERE p.payment_date BETWEEN ? AND ?";
        
        return $this->connection->fetchOne($sql, [$startDate, $endDate]);
    }
    
    /**
     * Export receipts to CSV
     */
    public function exportReceiptsToCSV($startDate, $endDate, $filters = [])
    {
        $receipts = $this->getReceiptsByDateRange($startDate, $endDate, $filters);
        
        $filename = 'receipts_' . date('Y-m-d') . '.csv';
        $filepath = storage_path('exports/' . $filename);
        
        $file = fopen($filepath, 'w');
        
        // Write headers
        fputcsv($file, [
            'Receipt Number', 'Student Name', 'Roll Number', 'Class', 'Invoice Number',
            'Amount', 'Payment Date', 'Payment Method', 'Transaction ID', 'Reference Number'
        ]);
        
        // Write data
        foreach ($receipts as $receipt) {
            fputcsv($file, [
                $receipt['receipt_number'],
                $receipt['first_name'] . ' ' . $receipt['last_name'],
                $receipt['roll_number'],
                $receipt['class_name'],
                $receipt['invoice_number'],
                $receipt['payment_amount'],
                $receipt['payment_date'],
                $receipt['payment_method_name'],
                $receipt['transaction_id'],
                $receipt['reference_number']
            ]);
        }
        
        fclose($file);
        
        return $filepath;
    }
    
    /**
     * Get receipt analytics
     */
    public function getReceiptAnalytics($startDate = null, $endDate = null)
    {
        $startDate = $startDate ?? date('Y-m-01');
        $endDate = $endDate ?? date('Y-m-t');
        
        $sql = "SELECT 
                    DATE(p.payment_date) as payment_date,
                    COUNT(*) as receipt_count,
                    SUM(p.amount) as total_amount,
                    COUNT(DISTINCT p.student_id) as student_count
                FROM receipts r
                LEFT JOIN payments p ON r.payment_id = p.id
                WHERE p.payment_date BETWEEN ? AND ?
                GROUP BY DATE(p.payment_date)
                ORDER BY payment_date";
        
        return $this->connection->fetchAll($sql, [$startDate, $endDate]);
    }
    
    /**
     * Get payment method wise receipts
     */
    public function getPaymentMethodWiseReceipts($startDate = null, $endDate = null)
    {
        $startDate = $startDate ?? date('Y-m-01');
        $endDate = $endDate ?? date('Y-m-t');
        
        $sql = "SELECT 
                    pm.name as payment_method_name,
                    pm.type as payment_method_type,
                    COUNT(*) as receipt_count,
                    SUM(p.amount) as total_amount,
                    AVG(p.amount) as average_amount
                FROM receipts r
                LEFT JOIN payments p ON r.payment_id = p.id
                LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id
                WHERE p.payment_date BETWEEN ? AND ?
                GROUP BY pm.id, pm.name, pm.type
                ORDER BY total_amount DESC";
        
        return $this->connection->fetchAll($sql, [$startDate, $endDate]);
    }
    
    /**
     * Get class-wise receipt summary
     */
    public function getClassWiseReceiptSummary($startDate = null, $endDate = null)
    {
        $startDate = $startDate ?? date('Y-m-01');
        $endDate = $endDate ?? date('Y-m-t');
        
        $sql = "SELECT 
                    c.name as class_name,
                    COUNT(*) as receipt_count,
                    SUM(p.amount) as total_amount,
                    COUNT(DISTINCT p.student_id) as student_count
                FROM receipts r
                LEFT JOIN payments p ON r.payment_id = p.id
                LEFT JOIN students s ON p.student_id = s.id
                LEFT JOIN classes c ON s.class_id = c.id
                WHERE p.payment_date BETWEEN ? AND ?
                GROUP BY c.id, c.name
                ORDER BY total_amount DESC";
        
        return $this->connection->fetchAll($sql, [$startDate, $endDate]);
    }
}