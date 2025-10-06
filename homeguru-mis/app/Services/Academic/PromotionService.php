<?php

namespace App\Services\Academic;

use App\Models\Academic\Student;
use App\Models\Academic\Class;
use App\Models\Academic\AcademicYear;
use App\Libraries\Database\Connection;
use App\Services\Communication\EmailService;
use App\Services\Communication\SMSService;
use App\Exceptions\ValidationException;

class PromotionService
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
     * Promote students to next class
     */
    public function promoteStudents($fromClassId, $toClassId, $academicYear, $options = [])
    {
        $this->validatePromotionData($fromClassId, $toClassId, $academicYear);
        
        $students = $this->getStudentsForPromotion($fromClassId, $academicYear, $options);
        
        if (empty($students)) {
            throw new ValidationException('No students found for promotion');
        }
        
        $promotedCount = 0;
        $failedCount = 0;
        $results = [];
        
        foreach ($students as $student) {
            try {
                $result = $this->promoteStudent($student, $toClassId, $academicYear);
                $results[] = $result;
                $promotedCount++;
            } catch (\Exception $e) {
                $results[] = [
                    'student_id' => $student['id'],
                    'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
                $failedCount++;
            }
        }
        
        // Send promotion notifications
        $this->sendPromotionNotifications($results, $academicYear);
        
        return [
            'total_students' => count($students),
            'promoted' => $promotedCount,
            'failed' => $failedCount,
            'results' => $results
        ];
    }
    
    /**
     * Promote individual student
     */
    public function promoteStudent($student, $toClassId, $academicYear)
    {
        $studentId = is_array($student) ? $student['id'] : $student->id;
        
        // Check if student is eligible for promotion
        if (!$this->isStudentEligibleForPromotion($studentId)) {
            throw new ValidationException('Student is not eligible for promotion');
        }
        
        // Get new roll number
        $newRollNumber = $this->generateRollNumber($toClassId, $academicYear);
        
        // Update student record
        $sql = "UPDATE students SET 
                class_id = ?, 
                section_id = ?, 
                roll_number = ?, 
                academic_year = ?, 
                promoted_at = ?, 
                updated_at = ? 
                WHERE id = ?";
        
        $this->connection->execute($sql, [
            $toClassId,
            $student['section_id'] ?? null,
            $newRollNumber,
            $academicYear,
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
            $studentId
        ]);
        
        // Create promotion record
        $this->createPromotionRecord($studentId, $student['class_id'], $toClassId, $academicYear);
        
        return [
            'student_id' => $studentId,
            'student_name' => $student['first_name'] . ' ' . $student['last_name'],
            'from_class' => $student['class_id'],
            'to_class' => $toClassId,
            'new_roll_number' => $newRollNumber,
            'status' => 'promoted'
        ];
    }
    
    /**
     * Demote student to previous class
     */
    public function demoteStudent($studentId, $reason = null)
    {
        $student = Student::find($studentId);
        
        if (!$student) {
            throw new ValidationException('Student not found');
        }
        
        // Get previous class
        $previousClass = $this->getPreviousClass($student->class_id);
        
        if (!$previousClass) {
            throw new ValidationException('No previous class found');
        }
        
        // Generate new roll number
        $newRollNumber = $this->generateRollNumber($previousClass['id'], $student->academic_year);
        
        // Update student record
        $sql = "UPDATE students SET 
                class_id = ?, 
                roll_number = ?, 
                demoted_at = ?, 
                demotion_reason = ?, 
                updated_at = ? 
                WHERE id = ?";
        
        $this->connection->execute($sql, [
            $previousClass['id'],
            $newRollNumber,
            date('Y-m-d H:i:s'),
            $reason,
            date('Y-m-d H:i:s'),
            $studentId
        ]);
        
        // Create demotion record
        $this->createDemotionRecord($studentId, $student->class_id, $previousClass['id'], $reason);
        
        return [
            'student_id' => $studentId,
            'student_name' => $student->first_name . ' ' . $student->last_name,
            'from_class' => $student->class_id,
            'to_class' => $previousClass['id'],
            'new_roll_number' => $newRollNumber,
            'status' => 'demoted'
        ];
    }
    
    /**
     * Get students for promotion
     */
    public function getStudentsForPromotion($classId, $academicYear, $options = [])
    {
        $sql = "SELECT s.*, c.name as class_name 
                FROM students s 
                LEFT JOIN classes c ON s.class_id = c.id 
                WHERE s.class_id = ? AND s.academic_year = ? AND s.status = 'active'";
        
        $params = [$classId, $academicYear];
        
        // Apply filters
        if (!empty($options['min_attendance'])) {
            $sql .= " AND s.attendance_percentage >= ?";
            $params[] = $options['min_attendance'];
        }
        
        if (!empty($options['min_marks'])) {
            $sql .= " AND s.average_marks >= ?";
            $params[] = $options['min_marks'];
        }
        
        if (!empty($options['exclude_failed'])) {
            $sql .= " AND s.promotion_status != 'failed'";
        }
        
        $sql .= " ORDER BY s.roll_number ASC";
        
        return $this->connection->fetchAll($sql, $params);
    }
    
    /**
     * Check if student is eligible for promotion
     */
    public function isStudentEligibleForPromotion($studentId)
    {
        $sql = "SELECT 
                    s.*,
                    c.min_attendance_percentage,
                    c.min_marks_for_promotion
                FROM students s
                LEFT JOIN classes c ON s.class_id = c.id
                WHERE s.id = ?";
        
        $student = $this->connection->fetchOne($sql, [$studentId]);
        
        if (!$student) {
            return false;
        }
        
        // Check attendance
        if ($student['min_attendance_percentage'] && 
            $student['attendance_percentage'] < $student['min_attendance_percentage']) {
            return false;
        }
        
        // Check marks
        if ($student['min_marks_for_promotion'] && 
            $student['average_marks'] < $student['min_marks_for_promotion']) {
            return false;
        }
        
        // Check if student is active
        if ($student['status'] !== 'active') {
            return false;
        }
        
        return true;
    }
    
    /**
     * Generate roll number
     */
    public function generateRollNumber($classId, $academicYear)
    {
        $sql = "SELECT MAX(CAST(roll_number AS UNSIGNED)) as max_roll 
                FROM students 
                WHERE class_id = ? AND academic_year = ? AND roll_number REGEXP '^[0-9]+$'";
        
        $result = $this->connection->fetchOne($sql, [$classId, $academicYear]);
        $maxRoll = $result['max_roll'] ?? 0;
        
        return $maxRoll + 1;
    }
    
    /**
     * Get previous class
     */
    private function getPreviousClass($currentClassId)
    {
        $sql = "SELECT * FROM classes WHERE id < ? ORDER BY id DESC LIMIT 1";
        return $this->connection->fetchOne($sql, [$currentClassId]);
    }
    
    /**
     * Create promotion record
     */
    private function createPromotionRecord($studentId, $fromClassId, $toClassId, $academicYear)
    {
        $sql = "INSERT INTO student_promotions (
            student_id, from_class_id, to_class_id, academic_year, 
            promoted_at, created_at
        ) VALUES (?, ?, ?, ?, ?, ?)";
        
        $this->connection->execute($sql, [
            $studentId,
            $fromClassId,
            $toClassId,
            $academicYear,
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Create demotion record
     */
    private function createDemotionRecord($studentId, $fromClassId, $toClassId, $reason)
    {
        $sql = "INSERT INTO student_demotions (
            student_id, from_class_id, to_class_id, reason, 
            demoted_at, created_at
        ) VALUES (?, ?, ?, ?, ?, ?)";
        
        $this->connection->execute($sql, [
            $studentId,
            $fromClassId,
            $toClassId,
            $reason,
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Get promotion history
     */
    public function getPromotionHistory($studentId)
    {
        $sql = "SELECT 
                    sp.*,
                    fc.name as from_class_name,
                    tc.name as to_class_name
                FROM student_promotions sp
                LEFT JOIN classes fc ON sp.from_class_id = fc.id
                LEFT JOIN classes tc ON sp.to_class_id = tc.id
                WHERE sp.student_id = ?
                ORDER BY sp.promoted_at DESC";
        
        return $this->connection->fetchAll($sql, [$studentId]);
    }
    
    /**
     * Get demotion history
     */
    public function getDemotionHistory($studentId)
    {
        $sql = "SELECT 
                    sd.*,
                    fc.name as from_class_name,
                    tc.name as to_class_name
                FROM student_demotions sd
                LEFT JOIN classes fc ON sd.from_class_id = fc.id
                LEFT JOIN classes tc ON sd.to_class_id = tc.id
                WHERE sd.student_id = ?
                ORDER BY sd.demoted_at DESC";
        
        return $this->connection->fetchAll($sql, [$studentId]);
    }
    
    /**
     * Get promotion statistics
     */
    public function getPromotionStatistics($academicYear)
    {
        $sql = "SELECT 
                    c.name as class_name,
                    COUNT(s.id) as total_students,
                    SUM(CASE WHEN s.promoted_at IS NOT NULL THEN 1 ELSE 0 END) as promoted,
                    SUM(CASE WHEN s.demoted_at IS NOT NULL THEN 1 ELSE 0 END) as demoted,
                    SUM(CASE WHEN s.promoted_at IS NULL AND s.demoted_at IS NULL THEN 1 ELSE 0 END) as not_promoted
                FROM students s
                LEFT JOIN classes c ON s.class_id = c.id
                WHERE s.academic_year = ?
                GROUP BY c.id, c.name
                ORDER BY c.id";
        
        return $this->connection->fetchAll($sql, [$academicYear]);
    }
    
    /**
     * Send promotion notifications
     */
    private function sendPromotionNotifications($results, $academicYear)
    {
        foreach ($results as $result) {
            if ($result['status'] === 'promoted') {
                $this->sendPromotionNotification($result, $academicYear);
            }
        }
    }
    
    /**
     * Send promotion notification
     */
    private function sendPromotionNotification($result, $academicYear)
    {
        $subject = 'Student Promotion Notification';
        $message = "Dear Parent,\n\n";
        $message .= "We are pleased to inform you that {$result['student_name']} ";
        $message .= "has been promoted to the next class for the academic year {$academicYear}.\n\n";
        $message .= "New Roll Number: {$result['new_roll_number']}\n";
        $message .= "New Class: {$result['to_class']}\n\n";
        $message .= "Please ensure all fees are paid and complete the necessary formalities.\n\n";
        $message .= "Best regards,\nSchool Administration";
        
        // Get parent email
        $sql = "SELECT p.email FROM parents p 
                INNER JOIN student_parents sp ON p.id = sp.parent_id 
                WHERE sp.student_id = ? AND sp.relationship = 'Primary'";
        
        $parent = $this->connection->fetchOne($sql, [$result['student_id']]);
        
        if ($parent) {
            $this->emailService->send($parent['email'], $subject, $message);
        }
    }
    
    /**
     * Validate promotion data
     */
    private function validatePromotionData($fromClassId, $toClassId, $academicYear)
    {
        if ($fromClassId === $toClassId) {
            throw new ValidationException('From class and to class cannot be the same');
        }
        
        if (empty($academicYear)) {
            throw new ValidationException('Academic year is required');
        }
        
        // Check if classes exist
        $fromClass = Class::find($fromClassId);
        $toClass = Class::find($toClassId);
        
        if (!$fromClass) {
            throw new ValidationException('From class not found');
        }
        
        if (!$toClass) {
            throw new ValidationException('To class not found');
        }
    }
    
    /**
     * Bulk promote students
     */
    public function bulkPromoteStudents($promotionData)
    {
        $results = [];
        
        foreach ($promotionData as $data) {
            try {
                $result = $this->promoteStudents(
                    $data['from_class_id'],
                    $data['to_class_id'],
                    $data['academic_year'],
                    $data['options'] ?? []
                );
                $results[] = $result;
            } catch (\Exception $e) {
                $results[] = [
                    'error' => $e->getMessage(),
                    'data' => $data
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Get students not promoted
     */
    public function getStudentsNotPromoted($classId, $academicYear)
    {
        $sql = "SELECT s.*, c.name as class_name 
                FROM students s 
                LEFT JOIN classes c ON s.class_id = c.id 
                WHERE s.class_id = ? AND s.academic_year = ? 
                AND s.status = 'active' AND s.promoted_at IS NULL
                ORDER BY s.roll_number ASC";
        
        return $this->connection->fetchAll($sql, [$classId, $academicYear]);
    }
    
    /**
     * Set promotion criteria
     */
    public function setPromotionCriteria($classId, $criteria)
    {
        $sql = "UPDATE classes SET 
                min_attendance_percentage = ?, 
                min_marks_for_promotion = ?, 
                updated_at = ? 
                WHERE id = ?";
        
        return $this->connection->execute($sql, [
            $criteria['min_attendance_percentage'],
            $criteria['min_marks_for_promotion'],
            date('Y-m-d H:i:s'),
            $classId
        ]);
    }
    
    /**
     * Get promotion criteria
     */
    public function getPromotionCriteria($classId)
    {
        $sql = "SELECT min_attendance_percentage, min_marks_for_promotion 
                FROM classes WHERE id = ?";
        
        return $this->connection->fetchOne($sql, [$classId]);
    }
}