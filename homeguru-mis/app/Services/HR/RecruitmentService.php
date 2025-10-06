<?php

namespace App\Services\HR;

use App\Models\HR\Recruitment;
use App\Models\HR\JobPosting;
use App\Models\HR\Applicant;
use App\Models\HR\Interview;
use App\Libraries\Database\Connection;
use App\Services\Communication\EmailService;
use App\Services\Communication\SMSService;
use App\Exceptions\ValidationException;

class RecruitmentService
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
     * Create job posting
     */
    public function createJobPosting($data)
    {
        $this->validateJobPostingData($data);
        
        $jobPosting = JobPosting::create([
            'title' => $data['title'],
            'description' => $data['description'],
            'requirements' => $data['requirements'],
            'department_id' => $data['department_id'],
            'designation_id' => $data['designation_id'],
            'location' => $data['location'],
            'employment_type' => $data['employment_type'],
            'experience_required' => $data['experience_required'],
            'education_required' => $data['education_required'],
            'skills_required' => $data['skills_required'],
            'salary_range' => $data['salary_range'],
            'application_deadline' => $data['application_deadline'],
            'status' => 'active',
            'created_by' => $data['created_by'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        return $jobPosting;
    }
    
    /**
     * Update job posting
     */
    public function updateJobPosting($jobPostingId, $data)
    {
        $jobPosting = JobPosting::find($jobPostingId);
        
        if (!$jobPosting) {
            throw new ValidationException('Job posting not found');
        }
        
        $this->validateJobPostingData($data);
        
        $jobPosting->title = $data['title'];
        $jobPosting->description = $data['description'];
        $jobPosting->requirements = $data['requirements'];
        $jobPosting->department_id = $data['department_id'];
        $jobPosting->designation_id = $data['designation_id'];
        $jobPosting->location = $data['location'];
        $jobPosting->employment_type = $data['employment_type'];
        $jobPosting->experience_required = $data['experience_required'];
        $jobPosting->education_required = $data['education_required'];
        $jobPosting->skills_required = $data['skills_required'];
        $jobPosting->salary_range = $data['salary_range'];
        $jobPosting->application_deadline = $data['application_deadline'];
        $jobPosting->updated_at = date('Y-m-d H:i:s');
        
        $jobPosting->save();
        
        return $jobPosting;
    }
    
    /**
     * Delete job posting
     */
    public function deleteJobPosting($jobPostingId)
    {
        $jobPosting = JobPosting::find($jobPostingId);
        
        if (!$jobPosting) {
            throw new ValidationException('Job posting not found');
        }
        
        // Check if there are applications
        $applications = Applicant::where('job_posting_id', $jobPostingId)->get();
        
        if (!empty($applications)) {
            throw new ValidationException('Cannot delete job posting with existing applications');
        }
        
        $jobPosting->delete();
        
        return true;
    }
    
    /**
     * Get job postings
     */
    public function getJobPostings($filters = [])
    {
        $sql = "SELECT 
                    jp.*,
                    d.name as department_name,
                    des.name as designation_name,
                    COUNT(a.id) as application_count
                FROM job_postings jp
                LEFT JOIN departments d ON jp.department_id = d.id
                LEFT JOIN designations des ON jp.designation_id = des.id
                LEFT JOIN applicants a ON jp.id = a.job_posting_id
                WHERE 1=1";
        
        $params = [];
        
        if (!empty($filters['department_id'])) {
            $sql .= " AND jp.department_id = ?";
            $params[] = $filters['department_id'];
        }
        
        if (!empty($filters['designation_id'])) {
            $sql .= " AND jp.designation_id = ?";
            $params[] = $filters['designation_id'];
        }
        
        if (!empty($filters['status'])) {
            $sql .= " AND jp.status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['employment_type'])) {
            $sql .= " AND jp.employment_type = ?";
            $params[] = $filters['employment_type'];
        }
        
        if (!empty($filters['search'])) {
            $sql .= " AND (jp.title LIKE ? OR jp.description LIKE ?)";
            $searchTerm = "%{$filters['search']}%";
            $params = array_merge($params, [$searchTerm, $searchTerm]);
        }
        
        $sql .= " GROUP BY jp.id ORDER BY jp.created_at DESC";
        
        return $this->connection->fetchAll($sql, $params);
    }
    
    /**
     * Apply for job
     */
    public function applyForJob($data)
    {
        $this->validateApplicationData($data);
        
        $jobPosting = JobPosting::find($data['job_posting_id']);
        
        if (!$jobPosting) {
            throw new ValidationException('Job posting not found');
        }
        
        if ($jobPosting->status !== 'active') {
            throw new ValidationException('Job posting is not active');
        }
        
        if (strtotime($jobPosting->application_deadline) < time()) {
            throw new ValidationException('Application deadline has passed');
        }
        
        // Check if already applied
        $existingApplication = Applicant::where('job_posting_id', $data['job_posting_id'])
            ->where('email', $data['email'])
            ->first();
        
        if ($existingApplication) {
            throw new ValidationException('You have already applied for this position');
        }
        
        $applicant = Applicant::create([
            'job_posting_id' => $data['job_posting_id'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'resume_path' => $data['resume_path'],
            'cover_letter' => $data['cover_letter'] ?? null,
            'experience_years' => $data['experience_years'] ?? 0,
            'education' => $data['education'] ?? null,
            'skills' => $data['skills'] ?? null,
            'current_salary' => $data['current_salary'] ?? null,
            'expected_salary' => $data['expected_salary'] ?? null,
            'status' => 'applied',
            'applied_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        // Send confirmation email
        $this->sendApplicationConfirmation($applicant);
        
        return $applicant;
    }
    
    /**
     * Get applicants for job
     */
    public function getApplicantsForJob($jobPostingId, $status = null)
    {
        $sql = "SELECT 
                    a.*,
                    jp.title as job_title,
                    d.name as department_name
                FROM applicants a
                LEFT JOIN job_postings jp ON a.job_posting_id = jp.id
                LEFT JOIN departments d ON jp.department_id = d.id
                WHERE a.job_posting_id = ?";
        
        $params = [$jobPostingId];
        
        if ($status) {
            $sql .= " AND a.status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY a.applied_at DESC";
        
        return $this->connection->fetchAll($sql, $params);
    }
    
    /**
     * Update applicant status
     */
    public function updateApplicantStatus($applicantId, $status, $notes = null)
    {
        $applicant = Applicant::find($applicantId);
        
        if (!$applicant) {
            throw new ValidationException('Applicant not found');
        }
        
        $validStatuses = ['applied', 'shortlisted', 'rejected', 'hired'];
        if (!in_array($status, $validStatuses)) {
            throw new ValidationException('Invalid applicant status');
        }
        
        $applicant->status = $status;
        $applicant->notes = $notes;
        $applicant->updated_at = date('Y-m-d H:i:s');
        
        if ($status === 'hired') {
            $applicant->hired_at = date('Y-m-d H:i:s');
        }
        
        $applicant->save();
        
        // Send status update notification
        $this->sendStatusUpdateNotification($applicant);
        
        return $applicant;
    }
    
    /**
     * Schedule interview
     */
    public function scheduleInterview($data)
    {
        $this->validateInterviewData($data);
        
        $applicant = Applicant::find($data['applicant_id']);
        
        if (!$applicant) {
            throw new ValidationException('Applicant not found');
        }
        
        $interview = Interview::create([
            'applicant_id' => $data['applicant_id'],
            'interviewer_id' => $data['interviewer_id'],
            'interview_date' => $data['interview_date'],
            'interview_time' => $data['interview_time'],
            'interview_type' => $data['interview_type'],
            'location' => $data['location'],
            'notes' => $data['notes'] ?? null,
            'status' => 'scheduled',
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        // Send interview invitation
        $this->sendInterviewInvitation($interview);
        
        return $interview;
    }
    
    /**
     * Update interview results
     */
    public function updateInterviewResults($interviewId, $data)
    {
        $interview = Interview::find($interviewId);
        
        if (!$interview) {
            throw new ValidationException('Interview not found');
        }
        
        $interview->rating = $data['rating'];
        $interview->feedback = $data['feedback'];
        $interview->recommendation = $data['recommendation'];
        $interview->status = 'completed';
        $interview->updated_at = date('Y-m-d H:i:s');
        
        $interview->save();
        
        // Update applicant status based on recommendation
        if ($data['recommendation'] === 'hire') {
            $this->updateApplicantStatus($interview->applicant_id, 'shortlisted');
        } elseif ($data['recommendation'] === 'reject') {
            $this->updateApplicantStatus($interview->applicant_id, 'rejected');
        }
        
        return $interview;
    }
    
    /**
     * Get recruitment statistics
     */
    public function getRecruitmentStatistics()
    {
        $sql = "SELECT 
                    COUNT(DISTINCT jp.id) as total_job_postings,
                    COUNT(a.id) as total_applications,
                    COUNT(CASE WHEN a.status = 'applied' THEN 1 END) as applied_count,
                    COUNT(CASE WHEN a.status = 'shortlisted' THEN 1 END) as shortlisted_count,
                    COUNT(CASE WHEN a.status = 'rejected' THEN 1 END) as rejected_count,
                    COUNT(CASE WHEN a.status = 'hired' THEN 1 END) as hired_count
                FROM job_postings jp
                LEFT JOIN applicants a ON jp.id = a.job_posting_id";
        
        return $this->connection->fetchOne($sql);
    }
    
    /**
     * Get department-wise recruitment statistics
     */
    public function getDepartmentWiseRecruitmentStatistics()
    {
        $sql = "SELECT 
                    d.name as department_name,
                    COUNT(DISTINCT jp.id) as job_postings,
                    COUNT(a.id) as applications,
                    COUNT(CASE WHEN a.status = 'hired' THEN 1 END) as hired_count
                FROM departments d
                LEFT JOIN job_postings jp ON d.id = jp.department_id
                LEFT JOIN applicants a ON jp.id = a.job_posting_id
                GROUP BY d.id, d.name
                ORDER BY applications DESC";
        
        return $this->connection->fetchAll($sql);
    }
    
    /**
     * Send application confirmation
     */
    private function sendApplicationConfirmation($applicant)
    {
        $subject = "Application Received - {$applicant->job_posting->title}";
        $message = "Dear {$applicant->first_name},\n\n";
        $message .= "Thank you for your interest in the position of {$applicant->job_posting->title}.\n\n";
        $message .= "We have received your application and will review it carefully.\n";
        $message .= "You will be contacted if you are selected for the next stage of the recruitment process.\n\n";
        $message .= "Application Details:\n";
        $message .= "Position: {$applicant->job_posting->title}\n";
        $message .= "Department: {$applicant->job_posting->department->name}\n";
        $message .= "Applied Date: {$applicant->applied_at}\n\n";
        $message .= "Best regards,\nHR Department";
        
        $this->emailService->send($applicant->email, $subject, $message);
    }
    
    /**
     * Send status update notification
     */
    private function sendStatusUpdateNotification($applicant)
    {
        $subject = "Application Status Update - {$applicant->job_posting->title}";
        $message = "Dear {$applicant->first_name},\n\n";
        $message .= "Your application status for the position of {$applicant->job_posting->title} has been updated.\n\n";
        $message .= "New Status: " . ucfirst($applicant->status) . "\n";
        
        if ($applicant->notes) {
            $message .= "Notes: {$applicant->notes}\n";
        }
        
        $message .= "\nBest regards,\nHR Department";
        
        $this->emailService->send($applicant->email, $subject, $message);
    }
    
    /**
     * Send interview invitation
     */
    private function sendInterviewInvitation($interview)
    {
        $applicant = $interview->applicant;
        $interviewer = $interview->interviewer;
        
        $subject = "Interview Invitation - {$applicant->job_posting->title}";
        $message = "Dear {$applicant->first_name},\n\n";
        $message .= "Congratulations! You have been shortlisted for the position of {$applicant->job_posting->title}.\n\n";
        $message .= "Interview Details:\n";
        $message .= "Date: {$interview->interview_date}\n";
        $message .= "Time: {$interview->interview_time}\n";
        $message .= "Type: {$interview->interview_type}\n";
        $message .= "Location: {$interview->location}\n";
        $message .= "Interviewer: {$interviewer->first_name} {$interviewer->last_name}\n\n";
        
        if ($interview->notes) {
            $message .= "Additional Notes: {$interview->notes}\n\n";
        }
        
        $message .= "Please confirm your attendance by replying to this email.\n\n";
        $message .= "Best regards,\nHR Department";
        
        $this->emailService->send($applicant->email, $subject, $message);
    }
    
    /**
     * Validate job posting data
     */
    private function validateJobPostingData($data)
    {
        $required = ['title', 'description', 'requirements', 'department_id', 'designation_id', 'location', 'employment_type', 'application_deadline'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new ValidationException("Field {$field} is required");
            }
        }
        
        if (strtotime($data['application_deadline']) < time()) {
            throw new ValidationException('Application deadline cannot be in the past');
        }
    }
    
    /**
     * Validate application data
     */
    private function validateApplicationData($data)
    {
        $required = ['job_posting_id', 'first_name', 'last_name', 'email', 'phone', 'resume_path'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new ValidationException("Field {$field} is required");
            }
        }
        
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Invalid email format');
        }
    }
    
    /**
     * Validate interview data
     */
    private function validateInterviewData($data)
    {
        $required = ['applicant_id', 'interviewer_id', 'interview_date', 'interview_time', 'interview_type', 'location'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new ValidationException("Field {$field} is required");
            }
        }
        
        if (strtotime($data['interview_date']) < time()) {
            throw new ValidationException('Interview date cannot be in the past');
        }
    }
    
    /**
     * Get recruitment analytics
     */
    public function getRecruitmentAnalytics($startDate = null, $endDate = null)
    {
        $startDate = $startDate ?? date('Y-m-01');
        $endDate = $endDate ?? date('Y-m-t');
        
        $sql = "SELECT 
                    DATE(a.applied_at) as application_date,
                    COUNT(*) as application_count,
                    COUNT(CASE WHEN a.status = 'hired' THEN 1 END) as hired_count
                FROM applicants a
                WHERE a.applied_at BETWEEN ? AND ?
                GROUP BY DATE(a.applied_at)
                ORDER BY application_date";
        
        return $this->connection->fetchAll($sql, [$startDate, $endDate]);
    }
    
    /**
     * Export applications to CSV
     */
    public function exportApplicationsToCSV($jobPostingId = null)
    {
        $whereClause = "1=1";
        $params = [];
        
        if ($jobPostingId) {
            $whereClause .= " AND a.job_posting_id = ?";
            $params[] = $jobPostingId;
        }
        
        $sql = "SELECT 
                    a.first_name,
                    a.last_name,
                    a.email,
                    a.phone,
                    jp.title as job_title,
                    d.name as department_name,
                    a.experience_years,
                    a.education,
                    a.status,
                    a.applied_at
                FROM applicants a
                LEFT JOIN job_postings jp ON a.job_posting_id = jp.id
                LEFT JOIN departments d ON jp.department_id = d.id
                WHERE {$whereClause}
                ORDER BY a.applied_at DESC";
        
        $applications = $this->connection->fetchAll($sql, $params);
        
        $filename = 'applications_' . date('Y-m-d') . '.csv';
        $filepath = storage_path('exports/' . $filename);
        
        $file = fopen($filepath, 'w');
        
        // Write headers
        fputcsv($file, [
            'Name', 'Email', 'Phone', 'Job Title', 'Department', 'Experience', 'Education', 'Status', 'Applied Date'
        ]);
        
        // Write data
        foreach ($applications as $application) {
            fputcsv($file, [
                $application['first_name'] . ' ' . $application['last_name'],
                $application['email'],
                $application['phone'],
                $application['job_title'],
                $application['department_name'],
                $application['experience_years'],
                $application['education'],
                $application['status'],
                $application['applied_at']
            ]);
        }
        
        fclose($file);
        
        return $filepath;
    }
}