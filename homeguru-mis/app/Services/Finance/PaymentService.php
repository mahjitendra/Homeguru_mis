<?php

namespace App\Services\Finance;

use App\Models\Finance\Payment;
use App\Models\Finance\PaymentMethod;
use App\Models\Finance\Invoice;
use App\Models\Finance\Receipt;
use App\Libraries\Database\Connection;
use App\Services\Communication\EmailService;
use App\Services\Communication\SMSService;
use App\Services\Payment\PaymentGatewayService;
use App\Exceptions\ValidationException;

class PaymentService
{
    private $connection;
    private $emailService;
    private $smsService;
    private $paymentGatewayService;
    
    public function __construct()
    {
        $this->connection = Connection::getInstance();
        $this->emailService = new EmailService();
        $this->smsService = new SMSService();
        $this->paymentGatewayService = new PaymentGatewayService();
    }
    
    /**
     * Process payment
     */
    public function processPayment($data)
    {
        $this->validatePaymentData($data);
        
        $invoice = Invoice::find($data['invoice_id']);
        
        if (!$invoice) {
            throw new ValidationException('Invoice not found');
        }
        
        // Check if payment amount is valid
        if ($data['amount'] > $invoice->pending_amount) {
            throw new ValidationException('Payment amount cannot exceed pending amount');
        }
        
        // Create payment record
        $payment = Payment::create([
            'invoice_id' => $data['invoice_id'],
            'student_id' => $invoice->student_id,
            'amount' => $data['amount'],
            'payment_method_id' => $data['payment_method_id'],
            'payment_date' => $data['payment_date'] ?? date('Y-m-d'),
            'transaction_id' => $data['transaction_id'] ?? null,
            'reference_number' => $data['reference_number'] ?? null,
            'status' => 'pending',
            'notes' => $data['notes'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        // Process payment based on method
        $result = $this->processPaymentByMethod($payment, $data);
        
        if ($result['success']) {
            $this->updatePaymentStatus($payment->id, 'completed');
            $this->updateInvoiceTotals($data['invoice_id']);
            $this->generateReceipt($payment->id);
            $this->sendPaymentConfirmation($payment);
        } else {
            $this->updatePaymentStatus($payment->id, 'failed');
            throw new ValidationException($result['message']);
        }
        
        return $payment;
    }
    
    /**
     * Process payment by method
     */
    private function processPaymentByMethod($payment, $data)
    {
        $paymentMethod = PaymentMethod::find($payment->payment_method_id);
        
        switch ($paymentMethod->type) {
            case 'cash':
                return $this->processCashPayment($payment, $data);
            case 'cheque':
                return $this->processChequePayment($payment, $data);
            case 'bank_transfer':
                return $this->processBankTransferPayment($payment, $data);
            case 'online':
                return $this->processOnlinePayment($payment, $data);
            default:
                throw new ValidationException('Invalid payment method');
        }
    }
    
    /**
     * Process cash payment
     */
    private function processCashPayment($payment, $data)
    {
        // Cash payments are immediately successful
        return ['success' => true, 'message' => 'Payment processed successfully'];
    }
    
    /**
     * Process cheque payment
     */
    private function processChequePayment($payment, $data)
    {
        // Validate cheque details
        if (empty($data['cheque_number']) || empty($data['bank_name'])) {
            throw new ValidationException('Cheque number and bank name are required');
        }
        
        // Update payment with cheque details
        $payment->cheque_number = $data['cheque_number'];
        $payment->bank_name = $data['bank_name'];
        $payment->cheque_date = $data['cheque_date'] ?? date('Y-m-d');
        $payment->save();
        
        // Cheque payments are pending until cleared
        return ['success' => true, 'message' => 'Cheque payment recorded, pending clearance'];
    }
    
    /**
     * Process bank transfer payment
     */
    private function processBankTransferPayment($payment, $data)
    {
        // Validate transfer details
        if (empty($data['reference_number'])) {
            throw new ValidationException('Reference number is required for bank transfer');
        }
        
        // Update payment with transfer details
        $payment->reference_number = $data['reference_number'];
        $payment->bank_name = $data['bank_name'] ?? null;
        $payment->save();
        
        // Bank transfers are immediately successful
        return ['success' => true, 'message' => 'Bank transfer payment processed successfully'];
    }
    
    /**
     * Process online payment
     */
    private function processOnlinePayment($payment, $data)
    {
        // Use payment gateway service
        $gatewayData = [
            'amount' => $payment->amount,
            'currency' => 'INR',
            'order_id' => $payment->id,
            'customer_id' => $payment->student_id,
            'description' => 'Fee Payment - Invoice ' . $payment->invoice->invoice_number
        ];
        
        $result = $this->paymentGatewayService->createPayment($gatewayData);
        
        if ($result['success']) {
            $payment->transaction_id = $result['transaction_id'];
            $payment->gateway_response = json_encode($result['response']);
            $payment->save();
            
            return ['success' => true, 'message' => 'Online payment initiated successfully'];
        } else {
            return ['success' => false, 'message' => $result['message']];
        }
    }
    
    /**
     * Update payment status
     */
    public function updatePaymentStatus($paymentId, $status)
    {
        $payment = Payment::find($paymentId);
        
        if (!$payment) {
            throw new ValidationException('Payment not found');
        }
        
        $validStatuses = ['pending', 'completed', 'failed', 'cancelled', 'refunded'];
        if (!in_array($status, $validStatuses)) {
            throw new ValidationException('Invalid payment status');
        }
        
        $payment->status = $status;
        $payment->updated_at = date('Y-m-d H:i:s');
        
        if ($status === 'completed') {
            $payment->completed_at = date('Y-m-d H:i:s');
        }
        
        $payment->save();
        
        return $payment;
    }
    
    /**
     * Update invoice totals
     */
    private function updateInvoiceTotals($invoiceId)
    {
        $invoice = Invoice::find($invoiceId);
        
        // Get total completed payments
        $sql = "SELECT SUM(amount) as total_paid FROM payments WHERE invoice_id = ? AND status = 'completed'";
        $result = $this->connection->fetchOne($sql, [$invoiceId]);
        $totalPaid = $result['total_paid'] ?? 0;
        
        // Update invoice
        $invoice->paid_amount = $totalPaid;
        $invoice->pending_amount = $invoice->net_amount - $totalPaid;
        $invoice->status = $invoice->pending_amount <= 0 ? 'paid' : ($invoice->due_date < date('Y-m-d') ? 'overdue' : 'pending');
        $invoice->updated_at = date('Y-m-d H:i:s');
        
        $invoice->save();
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
     * Get payment by ID
     */
    public function getPayment($paymentId)
    {
        $sql = "SELECT 
                    p.*,
                    pm.name as payment_method_name,
                    pm.type as payment_method_type,
                    s.first_name,
                    s.last_name,
                    s.roll_number,
                    i.invoice_number
                FROM payments p
                LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id
                LEFT JOIN students s ON p.student_id = s.id
                LEFT JOIN invoices i ON p.invoice_id = i.id
                WHERE p.id = ?";
        
        return $this->connection->fetchOne($sql, [$paymentId]);
    }
    
    /**
     * Get payments for student
     */
    public function getStudentPayments($studentId, $status = null)
    {
        $sql = "SELECT 
                    p.*,
                    pm.name as payment_method_name,
                    pm.type as payment_method_type,
                    i.invoice_number,
                    r.receipt_number
                FROM payments p
                LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id
                LEFT JOIN invoices i ON p.invoice_id = i.id
                LEFT JOIN receipts r ON p.id = r.payment_id
                WHERE p.student_id = ?";
        
        $params = [$studentId];
        
        if ($status) {
            $sql .= " AND p.status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY p.payment_date DESC";
        
        return $this->connection->fetchAll($sql, $params);
    }
    
    /**
     * Get payments for invoice
     */
    public function getInvoicePayments($invoiceId)
    {
        $sql = "SELECT 
                    p.*,
                    pm.name as payment_method_name,
                    pm.type as payment_method_type,
                    r.receipt_number
                FROM payments p
                LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id
                LEFT JOIN receipts r ON p.id = r.payment_id
                WHERE p.invoice_id = ?
                ORDER BY p.payment_date DESC";
        
        return $this->connection->fetchAll($sql, [$invoiceId]);
    }
    
    /**
     * Get payment statistics
     */
    public function getPaymentStatistics($filters = [])
    {
        $whereClause = "1=1";
        $params = [];
        
        if (!empty($filters['start_date'])) {
            $whereClause .= " AND p.payment_date >= ?";
            $params[] = $filters['start_date'];
        }
        
        if (!empty($filters['end_date'])) {
            $whereClause .= " AND p.payment_date <= ?";
            $params[] = $filters['end_date'];
        }
        
        if (!empty($filters['payment_method_id'])) {
            $whereClause .= " AND p.payment_method_id = ?";
            $params[] = $filters['payment_method_id'];
        }
        
        $sql = "SELECT 
                    COUNT(*) as total_payments,
                    SUM(p.amount) as total_amount,
                    COUNT(CASE WHEN p.status = 'completed' THEN 1 END) as completed_payments,
                    COUNT(CASE WHEN p.status = 'pending' THEN 1 END) as pending_payments,
                    COUNT(CASE WHEN p.status = 'failed' THEN 1 END) as failed_payments,
                    SUM(CASE WHEN p.status = 'completed' THEN p.amount ELSE 0 END) as completed_amount
                FROM payments p
                WHERE {$whereClause}";
        
        return $this->connection->fetchOne($sql, $params);
    }
    
    /**
     * Get payment methods
     */
    public function getPaymentMethods()
    {
        $sql = "SELECT * FROM payment_methods WHERE status = 'active' ORDER BY name";
        return $this->connection->fetchAll($sql);
    }
    
    /**
     * Create payment method
     */
    public function createPaymentMethod($data)
    {
        $this->validatePaymentMethodData($data);
        
        $paymentMethod = PaymentMethod::create([
            'name' => $data['name'],
            'type' => $data['type'],
            'description' => $data['description'] ?? null,
            'is_online' => $data['is_online'] ?? false,
            'gateway_config' => $data['gateway_config'] ?? null,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        return $paymentMethod;
    }
    
    /**
     * Send payment confirmation
     */
    private function sendPaymentConfirmation($payment)
    {
        $student = $payment->student;
        $invoice = $payment->invoice;
        
        // Get parent contact information
        $sql = "SELECT p.email, p.phone FROM parents p 
                INNER JOIN student_parents sp ON p.id = sp.parent_id 
                WHERE sp.student_id = ? AND sp.relationship = 'Primary'";
        
        $parent = $this->connection->fetchOne($sql, [$payment->student_id]);
        
        if (!$parent) {
            return;
        }
        
        // Send email confirmation
        if ($parent['email']) {
            $subject = "Payment Confirmation - {$student->first_name} {$student->last_name}";
            $message = "Dear Parent,\n\n";
            $message .= "Payment has been successfully processed for {$student->first_name} {$student->last_name}.\n\n";
            $message .= "Payment Details:\n";
            $message .= "Amount: ₹{$payment->amount}\n";
            $message .= "Payment Date: {$payment->payment_date}\n";
            $message .= "Invoice Number: {$invoice->invoice_number}\n";
            $message .= "Payment Method: {$payment->payment_method->name}\n\n";
            $message .= "Thank you for your payment.\n\n";
            $message .= "Best regards,\nSchool Administration";
            
            $this->emailService->send($parent['email'], $subject, $message);
        }
        
        // Send SMS confirmation
        if ($parent['phone']) {
            $message = "Payment of ₹{$payment->amount} received for {$student->first_name}. Invoice: {$invoice->invoice_number}";
            $this->smsService->send($parent['phone'], $message);
        }
    }
    
    /**
     * Validate payment data
     */
    private function validatePaymentData($data)
    {
        $required = ['invoice_id', 'amount', 'payment_method_id'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new ValidationException("Field {$field} is required");
            }
        }
        
        if (!is_numeric($data['amount']) || $data['amount'] <= 0) {
            throw new ValidationException('Amount must be a positive number');
        }
    }
    
    /**
     * Validate payment method data
     */
    private function validatePaymentMethodData($data)
    {
        $required = ['name', 'type'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new ValidationException("Field {$field} is required");
            }
        }
        
        $validTypes = ['cash', 'cheque', 'bank_transfer', 'online'];
        if (!in_array($data['type'], $validTypes)) {
            throw new ValidationException('Invalid payment method type');
        }
    }
    
    /**
     * Refund payment
     */
    public function refundPayment($paymentId, $refundData)
    {
        $payment = Payment::find($paymentId);
        
        if (!$payment) {
            throw new ValidationException('Payment not found');
        }
        
        if ($payment->status !== 'completed') {
            throw new ValidationException('Only completed payments can be refunded');
        }
        
        $refundAmount = $refundData['amount'] ?? $payment->amount;
        
        if ($refundAmount > $payment->amount) {
            throw new ValidationException('Refund amount cannot exceed payment amount');
        }
        
        // Create refund record
        $refund = Payment::create([
            'invoice_id' => $payment->invoice_id,
            'student_id' => $payment->student_id,
            'amount' => -$refundAmount, // Negative amount for refund
            'payment_method_id' => $payment->payment_method_id,
            'payment_date' => date('Y-m-d'),
            'status' => 'completed',
            'notes' => $refundData['notes'] ?? 'Refund for payment #' . $payment->id,
            'refund_of' => $payment->id,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        // Update original payment status
        $payment->status = 'refunded';
        $payment->updated_at = date('Y-m-d H:i:s');
        $payment->save();
        
        // Update invoice totals
        $this->updateInvoiceTotals($payment->invoice_id);
        
        return $refund;
    }
    
    /**
     * Get payment analytics
     */
    public function getPaymentAnalytics($startDate = null, $endDate = null)
    {
        $startDate = $startDate ?? date('Y-m-01');
        $endDate = $endDate ?? date('Y-m-t');
        
        $sql = "SELECT 
                    DATE(p.payment_date) as payment_date,
                    COUNT(*) as payment_count,
                    SUM(p.amount) as total_amount,
                    COUNT(CASE WHEN p.status = 'completed' THEN 1 END) as completed_count,
                    SUM(CASE WHEN p.status = 'completed' THEN p.amount ELSE 0 END) as completed_amount
                FROM payments p
                WHERE p.payment_date BETWEEN ? AND ?
                GROUP BY DATE(p.payment_date)
                ORDER BY payment_date";
        
        return $this->connection->fetchAll($sql, [$startDate, $endDate]);
    }
}