<?php

namespace App\Services\Academic;

use App\Models\Academic\Student;
use App\Models\Academic\Class;
use App\Models\Grade\Grade;
use App\Models\Grade\ExamResult;
use App\Libraries\Database\Connection;
use App\Libraries\PDF\PDFGenerator;
use App\Services\Communication\EmailService;
use App\Exceptions\ValidationException;

class ReportCardService
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
     * Generate report card for student
     */
    public function generateReportCard($studentId, $academicYear, $term = 'annual')
    {
        $student = Student::find($studentId);
        
        if (!$student) {
            throw new ValidationException('Student not found');
        }
        
        // Get student academic data
        $academicData = $this->getStudentAcademicData($studentId, $academicYear, $term);
        
        // Calculate grades and percentages
        $reportData = $this->calculateReportData($academicData);
        
        // Generate report card
        $reportCard = $this->createReportCard($studentId, $academicYear, $term, $reportData);
        
        return $reportCard;
    }
    
    /**
     * Generate report cards for class
     */
    public function generateClassReportCards($classId, $academicYear, $term = 'annual')
    {
        $students = $this->getClassStudents($classId, $academicYear);
        $reportCards = [];
        
        foreach ($students as $student) {
            try {
                $reportCard = $this->generateReportCard($student['id'], $academicYear, $term);
                $reportCards[] = $reportCard;
            } catch (\Exception $e) {
                $reportCards[] = [
                    'student_id' => $student['id'],
                    'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $reportCards;
    }
    
    /**
     * Get student academic data
     */
    private function getStudentAcademicData($studentId, $academicYear, $term)
    {
        $sql = "SELECT 
                    s.*,
                    c.name as class_name,
                    sec.name as section_name,
                    er.subject_id,
                    sub.name as subject_name,
                    sub.code as subject_code,
                    er.exam_id,
                    e.name as exam_name,
                    e.type as exam_type,
                    er.marks_obtained,
                    er.total_marks,
                    er.grade,
                    er.percentage,
                    er.remarks
                FROM students s
                LEFT JOIN classes c ON s.class_id = c.id
                LEFT JOIN sections sec ON s.section_id = sec.id
                LEFT JOIN exam_results er ON s.id = er.student_id
                LEFT JOIN subjects sub ON er.subject_id = sub.id
                LEFT JOIN examinations e ON er.exam_id = e.id
                WHERE s.id = ? AND s.academic_year = ? AND e.term = ?
                ORDER BY sub.name, e.exam_date";
        
        return $this->connection->fetchAll($sql, [$studentId, $academicYear, $term]);
    }
    
    /**
     * Calculate report data
     */
    private function calculateReportData($academicData)
    {
        if (empty($academicData)) {
            return [
                'subjects' => [],
                'overall_percentage' => 0,
                'overall_grade' => 'F',
                'rank' => 0,
                'total_students' => 0
            ];
        }
        
        $subjects = [];
        $totalMarks = 0;
        $obtainedMarks = 0;
        $subjectCount = 0;
        
        foreach ($academicData as $data) {
            $subjectId = $data['subject_id'];
            
            if (!isset($subjects[$subjectId])) {
                $subjects[$subjectId] = [
                    'subject_id' => $subjectId,
                    'subject_name' => $data['subject_name'],
                    'subject_code' => $data['subject_code'],
                    'exams' => [],
                    'total_marks' => 0,
                    'obtained_marks' => 0,
                    'percentage' => 0,
                    'grade' => 'F'
                ];
            }
            
            $subjects[$subjectId]['exams'][] = [
                'exam_name' => $data['exam_name'],
                'exam_type' => $data['exam_type'],
                'marks_obtained' => $data['marks_obtained'],
                'total_marks' => $data['total_marks'],
                'percentage' => $data['percentage'],
                'grade' => $data['grade']
            ];
            
            $subjects[$subjectId]['total_marks'] += $data['total_marks'];
            $subjects[$subjectId]['obtained_marks'] += $data['marks_obtained'];
        }
        
        // Calculate subject-wise percentages and grades
        foreach ($subjects as &$subject) {
            if ($subject['total_marks'] > 0) {
                $subject['percentage'] = round(($subject['obtained_marks'] / $subject['total_marks']) * 100, 2);
                $subject['grade'] = $this->calculateGrade($subject['percentage']);
            }
            
            $totalMarks += $subject['total_marks'];
            $obtainedMarks += $subject['obtained_marks'];
            $subjectCount++;
        }
        
        $overallPercentage = $totalMarks > 0 ? round(($obtainedMarks / $totalMarks) * 100, 2) : 0;
        $overallGrade = $this->calculateGrade($overallPercentage);
        
        return [
            'subjects' => array_values($subjects),
            'overall_percentage' => $overallPercentage,
            'overall_grade' => $overallGrade,
            'total_subjects' => $subjectCount,
            'total_marks' => $totalMarks,
            'obtained_marks' => $obtainedMarks
        ];
    }
    
    /**
     * Calculate grade based on percentage
     */
    private function calculateGrade($percentage)
    {
        if ($percentage >= 90) return 'A+';
        if ($percentage >= 80) return 'A';
        if ($percentage >= 70) return 'B+';
        if ($percentage >= 60) return 'B';
        if ($percentage >= 50) return 'C+';
        if ($percentage >= 40) return 'C';
        if ($percentage >= 33) return 'D';
        return 'F';
    }
    
    /**
     * Create report card record
     */
    private function createReportCard($studentId, $academicYear, $term, $reportData)
    {
        $sql = "INSERT INTO report_cards (
            student_id, academic_year, term, overall_percentage, 
            overall_grade, total_subjects, total_marks, obtained_marks,
            generated_at, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $this->connection->execute($sql, [
            $studentId,
            $academicYear,
            $term,
            $reportData['overall_percentage'],
            $reportData['overall_grade'],
            $reportData['total_subjects'],
            $reportData['total_marks'],
            $reportData['obtained_marks'],
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s')
        ]);
        
        $reportCardId = $this->connection->lastInsertId();
        
        // Store subject-wise data
        $this->storeSubjectData($reportCardId, $reportData['subjects']);
        
        return [
            'report_card_id' => $reportCardId,
            'student_id' => $studentId,
            'academic_year' => $academicYear,
            'term' => $term,
            'overall_percentage' => $reportData['overall_percentage'],
            'overall_grade' => $reportData['overall_grade'],
            'subjects' => $reportData['subjects']
        ];
    }
    
    /**
     * Store subject-wise data
     */
    private function storeSubjectData($reportCardId, $subjects)
    {
        foreach ($subjects as $subject) {
            $sql = "INSERT INTO report_card_subjects (
                report_card_id, subject_id, subject_name, subject_code,
                total_marks, obtained_marks, percentage, grade, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $this->connection->execute($sql, [
                $reportCardId,
                $subject['subject_id'],
                $subject['subject_name'],
                $subject['subject_code'],
                $subject['total_marks'],
                $subject['obtained_marks'],
                $subject['percentage'],
                $subject['grade'],
                date('Y-m-d H:i:s')
            ]);
        }
    }
    
    /**
     * Get class students
     */
    private function getClassStudents($classId, $academicYear)
    {
        $sql = "SELECT id, first_name, last_name, roll_number 
                FROM students 
                WHERE class_id = ? AND academic_year = ? AND status = 'active'
                ORDER BY roll_number";
        
        return $this->connection->fetchAll($sql, [$classId, $academicYear]);
    }
    
    /**
     * Generate PDF report card
     */
    public function generatePDFReportCard($reportCardId)
    {
        $reportCard = $this->getReportCard($reportCardId);
        
        if (!$reportCard) {
            throw new ValidationException('Report card not found');
        }
        
        $html = $this->generateReportCardHTML($reportCard);
        
        $filename = "ReportCard_{$reportCard['student_name']}_{$reportCard['academic_year']}_{$reportCard['term']}.pdf";
        
        return $this->pdfGenerator->generatePDF($html, $filename);
    }
    
    /**
     * Get report card
     */
    public function getReportCard($reportCardId)
    {
        $sql = "SELECT 
                    rc.*,
                    s.first_name,
                    s.last_name,
                    s.roll_number,
                    s.admission_number,
                    c.name as class_name,
                    sec.name as section_name
                FROM report_cards rc
                LEFT JOIN students s ON rc.student_id = s.id
                LEFT JOIN classes c ON s.class_id = c.id
                LEFT JOIN sections sec ON s.section_id = sec.id
                WHERE rc.id = ?";
        
        $reportCard = $this->connection->fetchOne($sql, [$reportCardId]);
        
        if (!$reportCard) {
            return null;
        }
        
        // Get subject data
        $sql = "SELECT * FROM report_card_subjects WHERE report_card_id = ? ORDER BY subject_name";
        $subjects = $this->connection->fetchAll($sql, [$reportCardId]);
        
        $reportCard['subjects'] = $subjects;
        
        return $reportCard;
    }
    
    /**
     * Generate report card HTML
     */
    private function generateReportCardHTML($reportCard)
    {
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Report Card</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { text-align: center; margin-bottom: 30px; }
        .school-name { font-size: 24px; font-weight: bold; margin-bottom: 10px; }
        .report-title { font-size: 18px; margin-bottom: 20px; }
        .student-info { margin-bottom: 20px; }
        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .info-table td { padding: 5px; border: 1px solid #ddd; }
        .info-table .label { font-weight: bold; background-color: #f5f5f5; }
        .subjects-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .subjects-table th, .subjects-table td { padding: 8px; border: 1px solid #ddd; text-align: center; }
        .subjects-table th { background-color: #f5f5f5; font-weight: bold; }
        .summary { margin-top: 20px; }
        .grade-A { color: #28a745; font-weight: bold; }
        .grade-B { color: #17a2b8; font-weight: bold; }
        .grade-C { color: #ffc107; font-weight: bold; }
        .grade-D { color: #fd7e14; font-weight: bold; }
        .grade-F { color: #dc3545; font-weight: bold; }
    </style>
</head>
<body>
    <div class="header">
        <div class="school-name">HomeGuru School</div>
        <div class="report-title">Report Card - ' . $reportCard['term'] . ' ' . $reportCard['academic_year'] . '</div>
    </div>
    
    <div class="student-info">
        <table class="info-table">
            <tr>
                <td class="label">Student Name:</td>
                <td>' . $reportCard['first_name'] . ' ' . $reportCard['last_name'] . '</td>
                <td class="label">Roll Number:</td>
                <td>' . $reportCard['roll_number'] . '</td>
            </tr>
            <tr>
                <td class="label">Admission Number:</td>
                <td>' . $reportCard['admission_number'] . '</td>
                <td class="label">Class:</td>
                <td>' . $reportCard['class_name'] . ' ' . $reportCard['section_name'] . '</td>
            </tr>
        </table>
    </div>
    
    <table class="subjects-table">
        <thead>
            <tr>
                <th>Subject</th>
                <th>Code</th>
                <th>Total Marks</th>
                <th>Obtained Marks</th>
                <th>Percentage</th>
                <th>Grade</th>
            </tr>
        </thead>
        <tbody>';
        
        foreach ($reportCard['subjects'] as $subject) {
            $gradeClass = 'grade-' . $subject['grade'];
            $html .= '<tr>
                <td>' . $subject['subject_name'] . '</td>
                <td>' . $subject['subject_code'] . '</td>
                <td>' . $subject['total_marks'] . '</td>
                <td>' . $subject['obtained_marks'] . '</td>
                <td>' . $subject['percentage'] . '%</td>
                <td class="' . $gradeClass . '">' . $subject['grade'] . '</td>
            </tr>';
        }
        
        $overallGradeClass = 'grade-' . $reportCard['overall_grade'];
        $html .= '</tbody>
    </table>
    
    <div class="summary">
        <table class="info-table">
            <tr>
                <td class="label">Total Subjects:</td>
                <td>' . $reportCard['total_subjects'] . '</td>
                <td class="label">Total Marks:</td>
                <td>' . $reportCard['total_marks'] . '</td>
            </tr>
            <tr>
                <td class="label">Obtained Marks:</td>
                <td>' . $reportCard['obtained_marks'] . '</td>
                <td class="label">Overall Percentage:</td>
                <td>' . $reportCard['overall_percentage'] . '%</td>
            </tr>
            <tr>
                <td class="label">Overall Grade:</td>
                <td class="' . $overallGradeClass . '">' . $reportCard['overall_grade'] . '</td>
                <td class="label">Generated On:</td>
                <td>' . date('d-m-Y H:i:s', strtotime($reportCard['generated_at'])) . '</td>
            </tr>
        </table>
    </div>
</body>
</html>';
        
        return $html;
    }
    
    /**
     * Send report card via email
     */
    public function sendReportCardEmail($reportCardId, $email = null)
    {
        $reportCard = $this->getReportCard($reportCardId);
        
        if (!$reportCard) {
            throw new ValidationException('Report card not found');
        }
        
        // Get parent email if not provided
        if (!$email) {
            $sql = "SELECT p.email FROM parents p 
                    INNER JOIN student_parents sp ON p.id = sp.parent_id 
                    WHERE sp.student_id = ? AND sp.relationship = 'Primary'";
            
            $parent = $this->connection->fetchOne($sql, [$reportCard['student_id']]);
            $email = $parent['email'] ?? null;
        }
        
        if (!$email) {
            throw new ValidationException('No email address found');
        }
        
        // Generate PDF
        $pdfPath = $this->generatePDFReportCard($reportCardId);
        
        // Send email
        $subject = "Report Card - {$reportCard['first_name']} {$reportCard['last_name']} - {$reportCard['academic_year']}";
        $message = "Dear Parent,\n\n";
        $message .= "Please find attached the report card for {$reportCard['first_name']} {$reportCard['last_name']} ";
        $message .= "for the {$reportCard['term']} term of academic year {$reportCard['academic_year']}.\n\n";
        $message .= "Overall Performance: {$reportCard['overall_grade']} ({$reportCard['overall_percentage']}%)\n\n";
        $message .= "Best regards,\nSchool Administration";
        
        $this->emailService->sendWithAttachment($email, $subject, $message, $pdfPath);
        
        return true;
    }
    
    /**
     * Get report card statistics
     */
    public function getReportCardStatistics($academicYear, $term = 'annual')
    {
        $sql = "SELECT 
                    COUNT(*) as total_report_cards,
                    AVG(overall_percentage) as average_percentage,
                    COUNT(CASE WHEN overall_grade = 'A+' THEN 1 END) as grade_a_plus,
                    COUNT(CASE WHEN overall_grade = 'A' THEN 1 END) as grade_a,
                    COUNT(CASE WHEN overall_grade = 'B+' THEN 1 END) as grade_b_plus,
                    COUNT(CASE WHEN overall_grade = 'B' THEN 1 END) as grade_b,
                    COUNT(CASE WHEN overall_grade = 'C+' THEN 1 END) as grade_c_plus,
                    COUNT(CASE WHEN overall_grade = 'C' THEN 1 END) as grade_c,
                    COUNT(CASE WHEN overall_grade = 'D' THEN 1 END) as grade_d,
                    COUNT(CASE WHEN overall_grade = 'F' THEN 1 END) as grade_f
                FROM report_cards 
                WHERE academic_year = ? AND term = ?";
        
        return $this->connection->fetchOne($sql, [$academicYear, $term]);
    }
    
    /**
     * Get class-wise report card statistics
     */
    public function getClassWiseStatistics($academicYear, $term = 'annual')
    {
        $sql = "SELECT 
                    c.name as class_name,
                    COUNT(rc.id) as total_students,
                    AVG(rc.overall_percentage) as average_percentage,
                    COUNT(CASE WHEN rc.overall_grade IN ('A+', 'A') THEN 1 END) as distinction,
                    COUNT(CASE WHEN rc.overall_grade IN ('B+', 'B') THEN 1 END) as first_class,
                    COUNT(CASE WHEN rc.overall_grade IN ('C+', 'C') THEN 1 END) as second_class,
                    COUNT(CASE WHEN rc.overall_grade = 'F' THEN 1 END) as failed
                FROM report_cards rc
                LEFT JOIN students s ON rc.student_id = s.id
                LEFT JOIN classes c ON s.class_id = c.id
                WHERE rc.academic_year = ? AND rc.term = ?
                GROUP BY c.id, c.name
                ORDER BY c.id";
        
        return $this->connection->fetchAll($sql, [$academicYear, $term]);
    }
}