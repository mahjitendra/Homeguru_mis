<?php

namespace App\Services\Academic;

use App\Models\Academic\Student;
use App\Models\Academic\Parent;
use App\Models\Academic\Class;
use App\Models\Academic\AcademicYear;
use App\Libraries\Database\Connection;
use App\Services\Communication\EmailService;
use App\Services\Communication\SMSService;
use App\Exceptions\ValidationException;

class AdmissionService
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
     * Create admission inquiry
     */
    public function createInquiry($data)
    {
        $this->validateInquiryData($data);
        
        $inquiry = [
            'student_name' => $data['student_name'],
            'date_of_birth' => $data['date_of_birth'],
            'gender' => $data['gender'],
            'parent_name' => $data['parent_name'],
            'parent_phone' => $data['parent_phone'],
            'parent_email' => $data['parent_email'],
            'address' => $data['address'],
            'previous_school' => $data['previous_school'] ?? null,
            'admission_class' => $data['admission_class'],
            'academic_year' => $data['academic_year'],
            'source' => $data['source'] ?? 'website',
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        $sql = "INSERT INTO admission_inquiries (
            student_name, date_of_birth, gender, parent_name, parent_phone, 
            parent_email, address, previous_school, admission_class, academic_year, 
            source, status, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $this->connection->execute($sql, array_values($inquiry));
        $inquiryId = $this->connection->lastInsertId();
        
        // Send confirmation email
        $this->sendInquiryConfirmation($inquiry);
        
        return $inquiryId;
    }
    
    /**
     * Process admission application
     */
    public function processApplication($inquiryId, $data)
    {
        $inquiry = $this->getInquiry($inquiryId);
        
        if (!$inquiry) {
            throw new ValidationException('Inquiry not found');
        }
        
        if ($inquiry['status'] !== 'pending') {
            throw new ValidationException('Inquiry already processed');
        }
        
        $this->validateApplicationData($data);
        
        // Create student record
        $student = $this->createStudentFromInquiry($inquiry, $data);
        
        // Create parent record
        $parent = $this->createParentFromInquiry($inquiry, $data);
        
        // Link student to parent
        $this->linkStudentToParent($student->id, $parent->id);
        
        // Update inquiry status
        $this->updateInquiryStatus($inquiryId, 'processed');
        
        // Send admission confirmation
        $this->sendAdmissionConfirmation($student, $parent);
        
        return [
            'student_id' => $student->id,
            'parent_id' => $parent->id,
            'admission_number' => $student->admission_number
        ];
    }
    
    /**
     * Generate admission number
     */
    public function generateAdmissionNumber($academicYear, $classId)
    {
        $year = date('Y', strtotime($academicYear . '-01-01'));
        $class = Class::find($classId);
        $classCode = $class ? $class->code : 'GEN';
        
        $sql = "SELECT COUNT(*) as count FROM students 
                WHERE academic_year = ? AND admission_number LIKE ?";
        
        $result = $this->connection->fetchOne($sql, [
            $academicYear,
            $year . $classCode . '%'
        ]);
        
        $sequence = str_pad($result['count'] + 1, 3, '0', STR_PAD_LEFT);
        
        return $year . $classCode . $sequence;
    }
    
    /**
     * Create student from inquiry
     */
    private function createStudentFromInquiry($inquiry, $data)
    {
        $admissionNumber = $this->generateAdmissionNumber(
            $inquiry['academic_year'], 
            $data['class_id']
        );
        
        $student = Student::create([
            'admission_number' => $admissionNumber,
            'roll_number' => $data['roll_number'] ?? null,
            'first_name' => $inquiry['student_name'],
            'last_name' => $data['last_name'] ?? '',
            'date_of_birth' => $inquiry['date_of_birth'],
            'gender' => $inquiry['gender'],
            'blood_group' => $data['blood_group'] ?? null,
            'religion' => $data['religion'] ?? null,
            'caste' => $data['caste'] ?? null,
            'nationality' => $data['nationality'] ?? 'Indian',
            'aadhaar_number' => $data['aadhaar_number'] ?? null,
            'address' => $inquiry['address'],
            'phone' => $data['phone'] ?? $inquiry['parent_phone'],
            'email' => $data['email'] ?? $inquiry['parent_email'],
            'class_id' => $data['class_id'],
            'section_id' => $data['section_id'] ?? null,
            'academic_year' => $inquiry['academic_year'],
            'admission_date' => date('Y-m-d'),
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        return $student;
    }
    
    /**
     * Create parent from inquiry
     */
    private function createParentFromInquiry($inquiry, $data)
    {
        $parent = Parent::create([
            'first_name' => $inquiry['parent_name'],
            'last_name' => $data['parent_last_name'] ?? '',
            'relationship' => $data['relationship'] ?? 'Father',
            'occupation' => $data['occupation'] ?? null,
            'phone' => $inquiry['parent_phone'],
            'email' => $inquiry['parent_email'],
            'address' => $inquiry['address'],
            'emergency_contact' => $data['emergency_contact'] ?? null,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        return $parent;
    }
    
    /**
     * Link student to parent
     */
    private function linkStudentToParent($studentId, $parentId)
    {
        $sql = "INSERT INTO student_parents (student_id, parent_id, relationship, created_at) 
                VALUES (?, ?, 'Primary', ?)";
        
        $this->connection->execute($sql, [
            $studentId,
            $parentId,
            date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Get inquiry by ID
     */
    public function getInquiry($inquiryId)
    {
        $sql = "SELECT * FROM admission_inquiries WHERE id = ?";
        return $this->connection->fetchOne($sql, [$inquiryId]);
    }
    
    /**
     * Get all inquiries
     */
    public function getInquiries($filters = [])
    {
        $sql = "SELECT * FROM admission_inquiries WHERE 1=1";
        $params = [];
        
        if (!empty($filters['status'])) {
            $sql .= " AND status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['academic_year'])) {
            $sql .= " AND academic_year = ?";
            $params[] = $filters['academic_year'];
        }
        
        if (!empty($filters['admission_class'])) {
            $sql .= " AND admission_class = ?";
            $params[] = $filters['admission_class'];
        }
        
        $sql .= " ORDER BY created_at DESC";
        
        return $this->connection->fetchAll($sql, $params);
    }
    
    /**
     * Update inquiry status
     */
    public function updateInquiryStatus($inquiryId, $status)
    {
        $sql = "UPDATE admission_inquiries SET status = ?, updated_at = ? WHERE id = ?";
        return $this->connection->execute($sql, [
            $status,
            date('Y-m-d H:i:s'),
            $inquiryId
        ]);
    }
    
    /**
     * Schedule entrance test
     */
    public function scheduleEntranceTest($inquiryId, $testData)
    {
        $inquiry = $this->getInquiry($inquiryId);
        
        if (!$inquiry) {
            throw new ValidationException('Inquiry not found');
        }
        
        $test = [
            'inquiry_id' => $inquiryId,
            'test_date' => $testData['test_date'],
            'test_time' => $testData['test_time'],
            'venue' => $testData['venue'],
            'instructions' => $testData['instructions'] ?? null,
            'status' => 'scheduled',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        $sql = "INSERT INTO entrance_tests (
            inquiry_id, test_date, test_time, venue, instructions, 
            status, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $this->connection->execute($sql, array_values($test));
        $testId = $this->connection->lastInsertId();
        
        // Send test notification
        $this->sendTestNotification($inquiry, $test);
        
        return $testId;
    }
    
    /**
     * Record test results
     */
    public function recordTestResults($testId, $results)
    {
        $sql = "UPDATE entrance_tests SET 
                marks_obtained = ?, 
                total_marks = ?, 
                percentage = ?, 
                grade = ?, 
                status = ?, 
                updated_at = ? 
                WHERE id = ?";
        
        return $this->connection->execute($sql, [
            $results['marks_obtained'],
            $results['total_marks'],
            $results['percentage'],
            $results['grade'],
            'completed',
            date('Y-m-d H:i:s'),
            $testId
        ]);
    }
    
    /**
     * Generate merit list
     */
    public function generateMeritList($academicYear, $classId)
    {
        $sql = "SELECT 
                    ai.*, 
                    et.marks_obtained, 
                    et.total_marks, 
                    et.percentage, 
                    et.grade
                FROM admission_inquiries ai
                LEFT JOIN entrance_tests et ON ai.id = et.inquiry_id
                WHERE ai.academic_year = ? 
                AND ai.admission_class = ?
                AND ai.status = 'processed'
                ORDER BY et.percentage DESC, ai.created_at ASC";
        
        return $this->connection->fetchAll($sql, [$academicYear, $classId]);
    }
    
    /**
     * Send inquiry confirmation
     */
    private function sendInquiryConfirmation($inquiry)
    {
        $subject = 'Admission Inquiry Confirmation';
        $message = "Dear {$inquiry['parent_name']},\n\n";
        $message .= "Thank you for your interest in our school. ";
        $message .= "We have received your admission inquiry for {$inquiry['student_name']} ";
        $message .= "for class {$inquiry['admission_class']} for the academic year {$inquiry['academic_year']}.\n\n";
        $message .= "We will review your application and contact you soon.\n\n";
        $message .= "Best regards,\nAdmissions Team";
        
        $this->emailService->send(
            $inquiry['parent_email'],
            $subject,
            $message
        );
    }
    
    /**
     * Send admission confirmation
     */
    private function sendAdmissionConfirmation($student, $parent)
    {
        $subject = 'Admission Confirmation';
        $message = "Dear {$parent->first_name},\n\n";
        $message .= "Congratulations! We are pleased to inform you that ";
        $message .= "{$student->first_name} has been admitted to our school.\n\n";
        $message .= "Admission Details:\n";
        $message .= "Admission Number: {$student->admission_number}\n";
        $message .= "Class: {$student->class_id}\n";
        $message .= "Academic Year: {$student->academic_year}\n";
        $message .= "Admission Date: {$student->admission_date}\n\n";
        $message .= "Please complete the remaining formalities at the earliest.\n\n";
        $message .= "Best regards,\nAdmissions Team";
        
        $this->emailService->send(
            $parent->email,
            $subject,
            $message
        );
    }
    
    /**
     * Send test notification
     */
    private function sendTestNotification($inquiry, $test)
    {
        $subject = 'Entrance Test Schedule';
        $message = "Dear {$inquiry['parent_name']},\n\n";
        $message .= "The entrance test for {$inquiry['student_name']} has been scheduled as follows:\n\n";
        $message .= "Date: {$test['test_date']}\n";
        $message .= "Time: {$test['test_time']}\n";
        $message .= "Venue: {$test['venue']}\n\n";
        
        if ($test['instructions']) {
            $message .= "Instructions:\n{$test['instructions']}\n\n";
        }
        
        $message .= "Please arrive 15 minutes before the scheduled time.\n\n";
        $message .= "Best regards,\nAdmissions Team";
        
        $this->emailService->send(
            $inquiry['parent_email'],
            $subject,
            $message
        );
    }
    
    /**
     * Validate inquiry data
     */
    private function validateInquiryData($data)
    {
        $required = ['student_name', 'date_of_birth', 'gender', 'parent_name', 'parent_phone', 'parent_email', 'address', 'admission_class', 'academic_year'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new ValidationException("Field {$field} is required");
            }
        }
        
        if (!filter_var($data['parent_email'], FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Invalid email format');
        }
        
        if (!preg_match('/^[0-9]{10}$/', $data['parent_phone'])) {
            throw new ValidationException('Invalid phone number format');
        }
    }
    
    /**
     * Validate application data
     */
    private function validateApplicationData($data)
    {
        $required = ['class_id'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new ValidationException("Field {$field} is required");
            }
        }
    }
    
    /**
     * Get admission statistics
     */
    public function getAdmissionStatistics($academicYear)
    {
        $sql = "SELECT 
                    status,
                    COUNT(*) as count
                FROM admission_inquiries 
                WHERE academic_year = ?
                GROUP BY status";
        
        $results = $this->connection->fetchAll($sql, [$academicYear]);
        
        $statistics = [
            'total_inquiries' => 0,
            'pending' => 0,
            'processed' => 0,
            'rejected' => 0
        ];
        
        foreach ($results as $result) {
            $statistics['total_inquiries'] += $result['count'];
            $statistics[$result['status']] = $result['count'];
        }
        
        return $statistics;
    }
    
    /**
     * Get class-wise admission statistics
     */
    public function getClassWiseStatistics($academicYear)
    {
        $sql = "SELECT 
                    admission_class,
                    COUNT(*) as count
                FROM admission_inquiries 
                WHERE academic_year = ?
                GROUP BY admission_class
                ORDER BY admission_class";
        
        return $this->connection->fetchAll($sql, [$academicYear]);
    }
    
    /**
     * Get source-wise statistics
     */
    public function getSourceWiseStatistics($academicYear)
    {
        $sql = "SELECT 
                    source,
                    COUNT(*) as count
                FROM admission_inquiries 
                WHERE academic_year = ?
                GROUP BY source
                ORDER BY count DESC";
        
        return $this->connection->fetchAll($sql, [$academicYear]);
    }
}