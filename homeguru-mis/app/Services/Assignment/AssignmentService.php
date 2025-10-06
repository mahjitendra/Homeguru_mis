<?php

namespace App\Services\Assignment;

use App\Models\Assignment\Assignment;
use App\Models\Assignment\AssignmentSubmission;
use App\Models\Assignment\AssignmentFeedback;
use App\Models\Academic\Student;
use App\Models\Academic\Class;
use App\Models\Academic\Subject;
use App\Libraries\Database\Connection;
use App\Services\Communication\EmailService;
use App\Services\Communication\NotificationService;
use App\Exceptions\ValidationException;

class AssignmentService
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
     * Create assignment
     */
    public function createAssignment($data)
    {
        $this->validateAssignmentData($data);
        
        $assignment = Assignment::create([
            'title' => $data['title'],
            'description' => $data['description'],
            'subject_id' => $data['subject_id'],
            'class_id' => $data['class_id'],
            'section_id' => $data['section_id'] ?? null,
            'teacher_id' => $data['teacher_id'],
            'due_date' => $data['due_date'],
            'max_marks' => $data['max_marks'],
            'instructions' => $data['instructions'] ?? null,
            'attachments' => $data['attachments'] ?? null,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        // Send notifications to students
        $this->notifyStudents($assignment);
        
        return $assignment;
    }
    
    /**
     * Update assignment
     */
    public function updateAssignment($assignmentId, $data)
    {
        $assignment = Assignment::find($assignmentId);
        
        if (!$assignment) {
            throw new ValidationException('Assignment not found');
        }
        
        $this->validateAssignmentData($data);
        
        $assignment->title = $data['title'];
        $assignment->description = $data['description'];
        $assignment->due_date = $data['due_date'];
        $assignment->max_marks = $data['max_marks'];
        $assignment->instructions = $data['instructions'] ?? $assignment->instructions;
        $assignment->attachments = $data['attachments'] ?? $assignment->attachments;
        $assignment->updated_at = date('Y-m-d H:i:s');
        
        $assignment->save();
        
        return $assignment;
    }
    
    /**
     * Delete assignment
     */
    public function deleteAssignment($assignmentId)
    {
        $assignment = Assignment::find($assignmentId);
        
        if (!$assignment) {
            throw new ValidationException('Assignment not found');
        }
        
        // Check if there are submissions
        $submissions = AssignmentSubmission::where('assignment_id', $assignmentId)->get();
        
        if (!empty($submissions)) {
            throw new ValidationException('Cannot delete assignment with submissions');
        }
        
        $assignment->delete();
        
        return true;
    }
    
    /**
     * Submit assignment
     */
    public function submitAssignment($data)
    {
        $this->validateSubmissionData($data);
        
        $assignment = Assignment::find($data['assignment_id']);
        
        if (!$assignment) {
            throw new ValidationException('Assignment not found');
        }
        
        // Check if assignment is still active
        if ($assignment->status !== 'active') {
            throw new ValidationException('Assignment is no longer active');
        }
        
        // Check if due date has passed
        if (strtotime($assignment->due_date) < time()) {
            throw new ValidationException('Assignment due date has passed');
        }
        
        // Check if already submitted
        $existingSubmission = AssignmentSubmission::where('assignment_id', $data['assignment_id'])
            ->where('student_id', $data['student_id'])
            ->first();
        
        if ($existingSubmission) {
            throw new ValidationException('Assignment already submitted');
        }
        
        $submission = AssignmentSubmission::create([
            'assignment_id' => $data['assignment_id'],
            'student_id' => $data['student_id'],
            'submission_text' => $data['submission_text'] ?? null,
            'attachments' => $data['attachments'] ?? null,
            'submitted_at' => date('Y-m-d H:i:s'),
            'status' => 'submitted',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        // Notify teacher
        $this->notifyTeacherSubmission($submission);
        
        return $submission;
    }
    
    /**
     * Grade assignment
     */
    public function gradeAssignment($submissionId, $data)
    {
        $submission = AssignmentSubmission::find($submissionId);
        
        if (!$submission) {
            throw new ValidationException('Submission not found');
        }
        
        $this->validateGradingData($data);
        
        $submission->marks_obtained = $data['marks_obtained'];
        $submission->feedback = $data['feedback'] ?? null;
        $submission->graded_at = date('Y-m-d H:i:s');
        $submission->graded_by = $data['graded_by'] ?? null;
        $submission->status = 'graded';
        $submission->updated_at = date('Y-m-d H:i:s');
        
        $submission->save();
        
        // Create feedback record
        if ($data['feedback']) {
            AssignmentFeedback::create([
                'submission_id' => $submissionId,
                'feedback' => $data['feedback'],
                'given_by' => $data['graded_by'] ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
        
        // Notify student
        $this->notifyStudentGrading($submission);
        
        return $submission;
    }
    
    /**
     * Get assignments for class
     */
    public function getAssignmentsForClass($classId, $sectionId = null, $status = 'active')
    {
        $sql = "SELECT 
                    a.*,
                    s.name as subject_name,
                    s.code as subject_code,
                    t.first_name as teacher_first_name,
                    t.last_name as teacher_last_name,
                    COUNT(sub.id) as submission_count
                FROM assignments a
                LEFT JOIN subjects s ON a.subject_id = s.id
                LEFT JOIN teachers t ON a.teacher_id = t.id
                LEFT JOIN assignment_submissions sub ON a.id = sub.assignment_id
                WHERE a.class_id = ?";
        
        $params = [$classId];
        
        if ($sectionId) {
            $sql .= " AND (a.section_id = ? OR a.section_id IS NULL)";
            $params[] = $sectionId;
        }
        
        if ($status) {
            $sql .= " AND a.status = ?";
            $params[] = $status;
        }
        
        $sql .= " GROUP BY a.id ORDER BY a.due_date ASC";
        
        return $this->connection->fetchAll($sql, $params);
    }
    
    /**
     * Get assignments for student
     */
    public function getAssignmentsForStudent($studentId, $status = 'active')
    {
        $sql = "SELECT 
                    a.*,
                    s.name as subject_name,
                    s.code as subject_code,
                    t.first_name as teacher_first_name,
                    t.last_name as teacher_last_name,
                    sub.id as submission_id,
                    sub.status as submission_status,
                    sub.marks_obtained,
                    sub.submitted_at
                FROM assignments a
                LEFT JOIN subjects s ON a.subject_id = s.id
                LEFT JOIN teachers t ON a.teacher_id = t.id
                LEFT JOIN students st ON a.class_id = st.class_id
                LEFT JOIN assignment_submissions sub ON a.id = sub.assignment_id AND sub.student_id = ?
                WHERE st.id = ?";
        
        $params = [$studentId, $studentId];
        
        if ($status) {
            $sql .= " AND a.status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY a.due_date ASC";
        
        return $this->connection->fetchAll($sql, $params);
    }
    
    /**
     * Get assignment submissions
     */
    public function getAssignmentSubmissions($assignmentId)
    {
        $sql = "SELECT 
                    sub.*,
                    s.first_name,
                    s.last_name,
                    s.roll_number,
                    sec.name as section_name
                FROM assignment_submissions sub
                LEFT JOIN students s ON sub.student_id = s.id
                LEFT JOIN sections sec ON s.section_id = sec.id
                WHERE sub.assignment_id = ?
                ORDER BY sub.submitted_at DESC";
        
        return $this->connection->fetchAll($sql, [$assignmentId]);
    }
    
    /**
     * Get student submissions
     */
    public function getStudentSubmissions($studentId, $status = null)
    {
        $sql = "SELECT 
                    sub.*,
                    a.title as assignment_title,
                    a.due_date,
                    a.max_marks,
                    s.name as subject_name,
                    t.first_name as teacher_first_name,
                    t.last_name as teacher_last_name
                FROM assignment_submissions sub
                LEFT JOIN assignments a ON sub.assignment_id = a.id
                LEFT JOIN subjects s ON a.subject_id = s.id
                LEFT JOIN teachers t ON a.teacher_id = t.id
                WHERE sub.student_id = ?";
        
        $params = [$studentId];
        
        if ($status) {
            $sql .= " AND sub.status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY sub.submitted_at DESC";
        
        return $this->connection->fetchAll($sql, $params);
    }
    
    /**
     * Get assignment statistics
     */
    public function getAssignmentStatistics($teacherId = null, $classId = null)
    {
        $whereClause = "1=1";
        $params = [];
        
        if ($teacherId) {
            $whereClause .= " AND a.teacher_id = ?";
            $params[] = $teacherId;
        }
        
        if ($classId) {
            $whereClause .= " AND a.class_id = ?";
            $params[] = $classId;
        }
        
        $sql = "SELECT 
                    COUNT(DISTINCT a.id) as total_assignments,
                    COUNT(sub.id) as total_submissions,
                    COUNT(CASE WHEN sub.status = 'submitted' THEN 1 END) as submitted_count,
                    COUNT(CASE WHEN sub.status = 'graded' THEN 1 END) as graded_count,
                    AVG(sub.marks_obtained) as average_marks
                FROM assignments a
                LEFT JOIN assignment_submissions sub ON a.id = sub.assignment_id
                WHERE {$whereClause}";
        
        return $this->connection->fetchOne($sql, $params);
    }
    
    /**
     * Get pending assignments
     */
    public function getPendingAssignments($studentId)
    {
        $sql = "SELECT 
                    a.*,
                    s.name as subject_name,
                    t.first_name as teacher_first_name,
                    t.last_name as teacher_last_name
                FROM assignments a
                LEFT JOIN subjects s ON a.subject_id = s.id
                LEFT JOIN teachers t ON a.teacher_id = t.id
                LEFT JOIN students st ON a.class_id = st.class_id
                LEFT JOIN assignment_submissions sub ON a.id = sub.assignment_id AND sub.student_id = ?
                WHERE st.id = ? 
                AND a.status = 'active' 
                AND sub.id IS NULL
                AND a.due_date > NOW()
                ORDER BY a.due_date ASC";
        
        return $this->connection->fetchAll($sql, [$studentId, $studentId]);
    }
    
    /**
     * Get overdue assignments
     */
    public function getOverdueAssignments($studentId)
    {
        $sql = "SELECT 
                    a.*,
                    s.name as subject_name,
                    t.first_name as teacher_first_name,
                    t.last_name as teacher_last_name
                FROM assignments a
                LEFT JOIN subjects s ON a.subject_id = s.id
                LEFT JOIN teachers t ON a.teacher_id = t.id
                LEFT JOIN students st ON a.class_id = st.class_id
                LEFT JOIN assignment_submissions sub ON a.id = sub.assignment_id AND sub.student_id = ?
                WHERE st.id = ? 
                AND a.status = 'active' 
                AND sub.id IS NULL
                AND a.due_date < NOW()
                ORDER BY a.due_date ASC";
        
        return $this->connection->fetchAll($sql, [$studentId, $studentId]);
    }
    
    /**
     * Notify students about assignment
     */
    private function notifyStudents($assignment)
    {
        $students = $this->getClassStudents($assignment->class_id, $assignment->section_id);
        
        foreach ($students as $student) {
            $this->notificationService->create([
                'user_id' => $student['id'],
                'type' => 'assignment_created',
                'title' => 'New Assignment: ' . $assignment->title,
                'message' => 'A new assignment has been created for ' . $assignment->subject->name . '. Due date: ' . $assignment->due_date,
                'data' => json_encode(['assignment_id' => $assignment->id])
            ]);
        }
    }
    
    /**
     * Notify teacher about submission
     */
    private function notifyTeacherSubmission($submission)
    {
        $assignment = Assignment::find($submission->assignment_id);
        $student = Student::find($submission->student_id);
        
        $this->notificationService->create([
            'user_id' => $assignment->teacher_id,
            'type' => 'assignment_submitted',
            'title' => 'Assignment Submitted',
            'message' => $student->first_name . ' ' . $student->last_name . ' has submitted the assignment: ' . $assignment->title,
            'data' => json_encode(['submission_id' => $submission->id])
        ]);
    }
    
    /**
     * Notify student about grading
     */
    private function notifyStudentGrading($submission)
    {
        $assignment = Assignment::find($submission->assignment_id);
        
        $this->notificationService->create([
            'user_id' => $submission->student_id,
            'type' => 'assignment_graded',
            'title' => 'Assignment Graded',
            'message' => 'Your assignment "' . $assignment->title . '" has been graded. Marks: ' . $submission->marks_obtained . '/' . $assignment->max_marks,
            'data' => json_encode(['submission_id' => $submission->id])
        ]);
    }
    
    /**
     * Get class students
     */
    private function getClassStudents($classId, $sectionId = null)
    {
        $sql = "SELECT id, first_name, last_name, email FROM students WHERE class_id = ?";
        $params = [$classId];
        
        if ($sectionId) {
            $sql .= " AND section_id = ?";
            $params[] = $sectionId;
        }
        
        $sql .= " AND status = 'active'";
        
        return $this->connection->fetchAll($sql, $params);
    }
    
    /**
     * Validate assignment data
     */
    private function validateAssignmentData($data)
    {
        $required = ['title', 'description', 'subject_id', 'class_id', 'teacher_id', 'due_date', 'max_marks'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new ValidationException("Field {$field} is required");
            }
        }
        
        if (!is_numeric($data['max_marks']) || $data['max_marks'] <= 0) {
            throw new ValidationException('Max marks must be a positive number');
        }
        
        if (strtotime($data['due_date']) < time()) {
            throw new ValidationException('Due date cannot be in the past');
        }
    }
    
    /**
     * Validate submission data
     */
    private function validateSubmissionData($data)
    {
        $required = ['assignment_id', 'student_id'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new ValidationException("Field {$field} is required");
            }
        }
        
        if (empty($data['submission_text']) && empty($data['attachments'])) {
            throw new ValidationException('Either submission text or attachments must be provided');
        }
    }
    
    /**
     * Validate grading data
     */
    private function validateGradingData($data)
    {
        if (empty($data['marks_obtained'])) {
            throw new ValidationException('Marks obtained is required');
        }
        
        if (!is_numeric($data['marks_obtained']) || $data['marks_obtained'] < 0) {
            throw new ValidationException('Marks obtained must be a non-negative number');
        }
    }
    
    /**
     * Get assignment analytics
     */
    public function getAssignmentAnalytics($assignmentId)
    {
        $assignment = Assignment::find($assignmentId);
        
        if (!$assignment) {
            throw new ValidationException('Assignment not found');
        }
        
        $submissions = $this->getAssignmentSubmissions($assignmentId);
        $totalStudents = $this->getClassStudentCount($assignment->class_id, $assignment->section_id);
        
        $submittedCount = count($submissions);
        $gradedCount = count(array_filter($submissions, function($sub) {
            return $sub['status'] === 'graded';
        }));
        
        $averageMarks = 0;
        if ($gradedCount > 0) {
            $totalMarks = array_sum(array_column($submissions, 'marks_obtained'));
            $averageMarks = round($totalMarks / $gradedCount, 2);
        }
        
        return [
            'total_students' => $totalStudents,
            'submitted_count' => $submittedCount,
            'graded_count' => $gradedCount,
            'submission_rate' => $totalStudents > 0 ? round(($submittedCount / $totalStudents) * 100, 2) : 0,
            'grading_rate' => $submittedCount > 0 ? round(($gradedCount / $submittedCount) * 100, 2) : 0,
            'average_marks' => $averageMarks,
            'max_marks' => $assignment->max_marks
        ];
    }
    
    /**
     * Get class student count
     */
    private function getClassStudentCount($classId, $sectionId = null)
    {
        $sql = "SELECT COUNT(*) as count FROM students WHERE class_id = ? AND status = 'active'";
        $params = [$classId];
        
        if ($sectionId) {
            $sql .= " AND section_id = ?";
            $params[] = $sectionId;
        }
        
        $result = $this->connection->fetchOne($sql, $params);
        return $result['count'];
    }
}