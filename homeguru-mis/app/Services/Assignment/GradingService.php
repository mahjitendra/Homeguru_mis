<?php

namespace App\Services\Assignment;

use App\Models\Assignment\AssignmentSubmission;
use App\Models\Assignment\AssignmentFeedback;
use App\Models\Grade\Grade;
use App\Models\Grade\GradeScale;
use App\Libraries\Database\Connection;
use App\Services\Communication\NotificationService;
use App\Exceptions\ValidationException;

class GradingService
{
    private $connection;
    private $notificationService;
    
    public function __construct()
    {
        $this->connection = Connection::getInstance();
        $this->notificationService = new NotificationService();
    }
    
    /**
     * Grade assignment submission
     */
    public function gradeSubmission($submissionId, $data)
    {
        $submission = AssignmentSubmission::find($submissionId);
        
        if (!$submission) {
            throw new ValidationException('Submission not found');
        }
        
        $this->validateGradingData($data);
        
        // Calculate grade based on marks
        $grade = $this->calculateGrade($data['marks_obtained'], $submission->assignment->max_marks);
        
        $submission->marks_obtained = $data['marks_obtained'];
        $submission->grade = $grade;
        $submission->feedback = $data['feedback'] ?? null;
        $submission->graded_at = date('Y-m-d H:i:s');
        $submission->graded_by = $data['graded_by'] ?? null;
        $submission->status = 'graded';
        $submission->updated_at = date('Y-m-d H:i:s');
        
        $submission->save();
        
        // Create detailed feedback
        if ($data['feedback'] || !empty($data['detailed_feedback'])) {
            $this->createDetailedFeedback($submissionId, $data);
        }
        
        // Notify student
        $this->notifyStudentGrading($submission);
        
        return $submission;
    }
    
    /**
     * Bulk grade submissions
     */
    public function bulkGradeSubmissions($assignmentId, $gradingData)
    {
        $submissions = AssignmentSubmission::where('assignment_id', $assignmentId)
            ->where('status', 'submitted')
            ->get();
        
        $results = [];
        
        foreach ($submissions as $submission) {
            $submissionId = $submission->id;
            $data = $gradingData[$submissionId] ?? null;
            
            if (!$data) {
                $results[] = [
                    'submission_id' => $submissionId,
                    'status' => 'skipped',
                    'message' => 'No grading data provided'
                ];
                continue;
            }
            
            try {
                $gradedSubmission = $this->gradeSubmission($submissionId, $data);
                
                $results[] = [
                    'submission_id' => $submissionId,
                    'status' => 'success',
                    'marks_obtained' => $gradedSubmission->marks_obtained,
                    'grade' => $gradedSubmission->grade
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'submission_id' => $submissionId,
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Create detailed feedback
     */
    public function createDetailedFeedback($submissionId, $data)
    {
        $feedback = AssignmentFeedback::create([
            'submission_id' => $submissionId,
            'feedback' => $data['feedback'] ?? '',
            'detailed_feedback' => $data['detailed_feedback'] ?? null,
            'rubric_scores' => $data['rubric_scores'] ?? null,
            'strengths' => $data['strengths'] ?? null,
            'improvements' => $data['improvements'] ?? null,
            'given_by' => $data['graded_by'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        return $feedback;
    }
    
    /**
     * Get grading rubric
     */
    public function getGradingRubric($assignmentId)
    {
        $sql = "SELECT * FROM grading_rubrics WHERE assignment_id = ? ORDER BY order_index";
        return $this->connection->fetchAll($sql, [$assignmentId]);
    }
    
    /**
     * Create grading rubric
     */
    public function createGradingRubric($assignmentId, $rubricData)
    {
        $this->validateRubricData($rubricData);
        
        $sql = "INSERT INTO grading_rubrics (
            assignment_id, criteria, description, max_points, 
            order_index, created_at
        ) VALUES (?, ?, ?, ?, ?, ?)";
        
        $this->connection->execute($sql, [
            $assignmentId,
            $rubricData['criteria'],
            $rubricData['description'],
            $rubricData['max_points'],
            $rubricData['order_index'] ?? 1,
            date('Y-m-d H:i:s')
        ]);
        
        return $this->connection->lastInsertId();
    }
    
    /**
     * Update grading rubric
     */
    public function updateGradingRubric($rubricId, $data)
    {
        $this->validateRubricData($data);
        
        $sql = "UPDATE grading_rubrics SET 
                criteria = ?, 
                description = ?, 
                max_points = ?, 
                order_index = ?, 
                updated_at = ? 
                WHERE id = ?";
        
        return $this->connection->execute($sql, [
            $data['criteria'],
            $data['description'],
            $data['max_points'],
            $data['order_index'] ?? 1,
            date('Y-m-d H:i:s'),
            $rubricId
        ]);
    }
    
    /**
     * Delete grading rubric
     */
    public function deleteGradingRubric($rubricId)
    {
        $sql = "DELETE FROM grading_rubrics WHERE id = ?";
        return $this->connection->execute($sql, [$rubricId]);
    }
    
    /**
     * Calculate grade based on marks
     */
    public function calculateGrade($marksObtained, $maxMarks)
    {
        if ($maxMarks <= 0) {
            return 'F';
        }
        
        $percentage = ($marksObtained / $maxMarks) * 100;
        
        // Get grade scale
        $gradeScale = $this->getGradeScale();
        
        foreach ($gradeScale as $grade) {
            if ($percentage >= $grade['min_percentage']) {
                return $grade['grade'];
            }
        }
        
        return 'F';
    }
    
    /**
     * Get grade scale
     */
    public function getGradeScale()
    {
        $sql = "SELECT * FROM grade_scales ORDER BY min_percentage DESC";
        $result = $this->connection->fetchAll($sql);
        
        if (empty($result)) {
            // Default grade scale
            return [
                ['grade' => 'A+', 'min_percentage' => 90, 'description' => 'Excellent'],
                ['grade' => 'A', 'min_percentage' => 80, 'description' => 'Very Good'],
                ['grade' => 'B+', 'min_percentage' => 70, 'description' => 'Good'],
                ['grade' => 'B', 'min_percentage' => 60, 'description' => 'Satisfactory'],
                ['grade' => 'C+', 'min_percentage' => 50, 'description' => 'Average'],
                ['grade' => 'C', 'min_percentage' => 40, 'description' => 'Below Average'],
                ['grade' => 'D', 'min_percentage' => 33, 'description' => 'Poor'],
                ['grade' => 'F', 'min_percentage' => 0, 'description' => 'Fail']
            ];
        }
        
        return $result;
    }
    
    /**
     * Get grading statistics
     */
    public function getGradingStatistics($assignmentId)
    {
        $sql = "SELECT 
                    COUNT(*) as total_submissions,
                    COUNT(CASE WHEN status = 'graded' THEN 1 END) as graded_count,
                    COUNT(CASE WHEN status = 'submitted' THEN 1 END) as pending_count,
                    AVG(marks_obtained) as average_marks,
                    MIN(marks_obtained) as min_marks,
                    MAX(marks_obtained) as max_marks
                FROM assignment_submissions 
                WHERE assignment_id = ?";
        
        $result = $this->connection->fetchOne($sql, [$assignmentId]);
        
        // Get grade distribution
        $gradeDistribution = $this->getGradeDistribution($assignmentId);
        
        return array_merge($result, ['grade_distribution' => $gradeDistribution]);
    }
    
    /**
     * Get grade distribution
     */
    public function getGradeDistribution($assignmentId)
    {
        $sql = "SELECT 
                    grade,
                    COUNT(*) as count
                FROM assignment_submissions 
                WHERE assignment_id = ? AND status = 'graded'
                GROUP BY grade
                ORDER BY grade";
        
        return $this->connection->fetchAll($sql, [$assignmentId]);
    }
    
    /**
     * Get grading history
     */
    public function getGradingHistory($teacherId, $startDate = null, $endDate = null)
    {
        $startDate = $startDate ?? date('Y-m-01');
        $endDate = $endDate ?? date('Y-m-t');
        
        $sql = "SELECT 
                    sub.*,
                    a.title as assignment_title,
                    s.first_name as student_first_name,
                    s.last_name as student_last_name,
                    subj.name as subject_name
                FROM assignment_submissions sub
                LEFT JOIN assignments a ON sub.assignment_id = a.id
                LEFT JOIN students s ON sub.student_id = s.id
                LEFT JOIN subjects subj ON a.subject_id = subj.id
                WHERE sub.graded_by = ? 
                AND sub.graded_at BETWEEN ? AND ?
                ORDER BY sub.graded_at DESC";
        
        return $this->connection->fetchAll($sql, [$teacherId, $startDate, $endDate]);
    }
    
    /**
     * Get student grading summary
     */
    public function getStudentGradingSummary($studentId, $subjectId = null)
    {
        $whereClause = "sub.student_id = ?";
        $params = [$studentId];
        
        if ($subjectId) {
            $whereClause .= " AND a.subject_id = ?";
            $params[] = $subjectId;
        }
        
        $sql = "SELECT 
                    COUNT(*) as total_assignments,
                    COUNT(CASE WHEN sub.status = 'graded' THEN 1 END) as graded_count,
                    AVG(sub.marks_obtained) as average_marks,
                    SUM(sub.marks_obtained) as total_marks,
                    SUM(a.max_marks) as total_max_marks
                FROM assignment_submissions sub
                LEFT JOIN assignments a ON sub.assignment_id = a.id
                WHERE {$whereClause}";
        
        $result = $this->connection->fetchOne($sql, $params);
        
        // Calculate overall percentage
        $overallPercentage = 0;
        if ($result['total_max_marks'] > 0) {
            $overallPercentage = round(($result['total_marks'] / $result['total_max_marks']) * 100, 2);
        }
        
        $result['overall_percentage'] = $overallPercentage;
        $result['overall_grade'] = $this->calculateGrade($result['total_marks'], $result['total_max_marks']);
        
        return $result;
    }
    
    /**
     * Export grading report
     */
    public function exportGradingReport($assignmentId, $format = 'csv')
    {
        $submissions = $this->getAssignmentSubmissions($assignmentId);
        
        if ($format === 'csv') {
            return $this->exportToCSV($submissions);
        }
        
        return $submissions;
    }
    
    /**
     * Get assignment submissions for export
     */
    private function getAssignmentSubmissions($assignmentId)
    {
        $sql = "SELECT 
                    sub.*,
                    s.first_name,
                    s.last_name,
                    s.roll_number,
                    sec.name as section_name,
                    a.title as assignment_title,
                    a.max_marks
                FROM assignment_submissions sub
                LEFT JOIN students s ON sub.student_id = s.id
                LEFT JOIN sections sec ON s.section_id = sec.id
                LEFT JOIN assignments a ON sub.assignment_id = a.id
                WHERE sub.assignment_id = ?
                ORDER BY s.roll_number";
        
        return $this->connection->fetchAll($sql, [$assignmentId]);
    }
    
    /**
     * Export to CSV
     */
    private function exportToCSV($submissions)
    {
        $filename = 'grading_report_' . date('Y-m-d') . '.csv';
        $filepath = storage_path('exports/' . $filename);
        
        $file = fopen($filepath, 'w');
        
        // Write headers
        fputcsv($file, [
            'Student Name', 'Roll Number', 'Section', 'Assignment Title',
            'Marks Obtained', 'Max Marks', 'Grade', 'Status', 'Submitted At', 'Graded At'
        ]);
        
        // Write data
        foreach ($submissions as $submission) {
            fputcsv($file, [
                $submission['first_name'] . ' ' . $submission['last_name'],
                $submission['roll_number'],
                $submission['section_name'],
                $submission['assignment_title'],
                $submission['marks_obtained'],
                $submission['max_marks'],
                $submission['grade'],
                $submission['status'],
                $submission['submitted_at'],
                $submission['graded_at']
            ]);
        }
        
        fclose($file);
        
        return $filepath;
    }
    
    /**
     * Notify student about grading
     */
    private function notifyStudentGrading($submission)
    {
        $assignment = $submission->assignment;
        $student = $submission->student;
        
        $this->notificationService->create([
            'user_id' => $submission->student_id,
            'type' => 'assignment_graded',
            'title' => 'Assignment Graded',
            'message' => "Your assignment '{$assignment->title}' has been graded. You received {$submission->marks_obtained}/{$assignment->max_marks} marks (Grade: {$submission->grade})",
            'data' => json_encode(['submission_id' => $submission->id])
        ]);
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
     * Validate rubric data
     */
    private function validateRubricData($data)
    {
        $required = ['criteria', 'description', 'max_points'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new ValidationException("Field {$field} is required");
            }
        }
        
        if (!is_numeric($data['max_points']) || $data['max_points'] <= 0) {
            throw new ValidationException('Max points must be a positive number');
        }
    }
    
    /**
     * Get grading analytics
     */
    public function getGradingAnalytics($teacherId = null, $startDate = null, $endDate = null)
    {
        $startDate = $startDate ?? date('Y-m-01');
        $endDate = $endDate ?? date('Y-m-t');
        
        $whereClause = "sub.graded_at BETWEEN ? AND ?";
        $params = [$startDate, $endDate];
        
        if ($teacherId) {
            $whereClause .= " AND sub.graded_by = ?";
            $params[] = $teacherId;
        }
        
        $sql = "SELECT 
                    COUNT(*) as total_graded,
                    AVG(sub.marks_obtained) as average_marks,
                    COUNT(DISTINCT sub.assignment_id) as assignments_graded,
                    COUNT(DISTINCT sub.student_id) as students_graded
                FROM assignment_submissions sub
                WHERE {$whereClause} AND sub.status = 'graded'";
        
        return $this->connection->fetchOne($sql, $params);
    }
}