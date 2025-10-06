<?php

namespace App\Services\HR;

use App\Models\HR\Performance;
use App\Models\HR\Appraisal;
use App\Models\HR\Goal;
use App\Models\HR\Employee;
use App\Libraries\Database\Connection;
use App\Services\Communication\EmailService;
use App\Services\Communication\NotificationService;
use App\Exceptions\ValidationException;

class PerformanceService
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
     * Create performance appraisal
     */
    public function createAppraisal($data)
    {
        $this->validateAppraisalData($data);
        
        $appraisal = Appraisal::create([
            'employee_id' => $data['employee_id'],
            'appraiser_id' => $data['appraiser_id'],
            'appraisal_period' => $data['appraisal_period'],
            'appraisal_year' => $data['appraisal_year'],
            'overall_rating' => $data['overall_rating'],
            'goals_achieved' => $data['goals_achieved'] ?? 0,
            'goals_total' => $data['goals_total'] ?? 0,
            'strengths' => $data['strengths'] ?? null,
            'areas_for_improvement' => $data['areas_for_improvement'] ?? null,
            'development_plan' => $data['development_plan'] ?? null,
            'comments' => $data['comments'] ?? null,
            'status' => 'draft',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        // Create performance criteria ratings
        if (!empty($data['criteria_ratings'])) {
            $this->createCriteriaRatings($appraisal->id, $data['criteria_ratings']);
        }
        
        return $appraisal;
    }
    
    /**
     * Update appraisal
     */
    public function updateAppraisal($appraisalId, $data)
    {
        $appraisal = Appraisal::find($appraisalId);
        
        if (!$appraisal) {
            throw new ValidationException('Appraisal not found');
        }
        
        if ($appraisal->status === 'completed') {
            throw new ValidationException('Cannot update completed appraisal');
        }
        
        $this->validateAppraisalData($data);
        
        $appraisal->overall_rating = $data['overall_rating'];
        $appraisal->goals_achieved = $data['goals_achieved'] ?? $appraisal->goals_achieved;
        $appraisal->goals_total = $data['goals_total'] ?? $appraisal->goals_total;
        $appraisal->strengths = $data['strengths'] ?? $appraisal->strengths;
        $appraisal->areas_for_improvement = $data['areas_for_improvement'] ?? $appraisal->areas_for_improvement;
        $appraisal->development_plan = $data['development_plan'] ?? $appraisal->development_plan;
        $appraisal->comments = $data['comments'] ?? $appraisal->comments;
        $appraisal->updated_at = date('Y-m-d H:i:s');
        
        $appraisal->save();
        
        // Update criteria ratings
        if (!empty($data['criteria_ratings'])) {
            $this->updateCriteriaRatings($appraisal->id, $data['criteria_ratings']);
        }
        
        return $appraisal;
    }
    
    /**
     * Submit appraisal
     */
    public function submitAppraisal($appraisalId)
    {
        $appraisal = Appraisal::find($appraisalId);
        
        if (!$appraisal) {
            throw new ValidationException('Appraisal not found');
        }
        
        if ($appraisal->status !== 'draft') {
            throw new ValidationException('Only draft appraisals can be submitted');
        }
        
        $appraisal->status = 'submitted';
        $appraisal->submitted_at = date('Y-m-d H:i:s');
        $appraisal->updated_at = date('Y-m-d H:i:s');
        
        $appraisal->save();
        
        // Notify appraiser
        $this->notifyAppraiser($appraisal);
        
        return $appraisal;
    }
    
    /**
     * Approve appraisal
     */
    public function approveAppraisal($appraisalId, $approvedBy)
    {
        $appraisal = Appraisal::find($appraisalId);
        
        if (!$appraisal) {
            throw new ValidationException('Appraisal not found');
        }
        
        if ($appraisal->status !== 'submitted') {
            throw new ValidationException('Only submitted appraisals can be approved');
        }
        
        $appraisal->status = 'approved';
        $appraisal->approved_by = $approvedBy;
        $appraisal->approved_at = date('Y-m-d H:i:s');
        $appraisal->updated_at = date('Y-m-d H:i:s');
        
        $appraisal->save();
        
        // Notify employee
        $this->notifyEmployee($appraisal, 'approved');
        
        return $appraisal;
    }
    
    /**
     * Reject appraisal
     */
    public function rejectAppraisal($appraisalId, $rejectedBy, $reason = null)
    {
        $appraisal = Appraisal::find($appraisalId);
        
        if (!$appraisal) {
            throw new ValidationException('Appraisal not found');
        }
        
        if ($appraisal->status !== 'submitted') {
            throw new ValidationException('Only submitted appraisals can be rejected');
        }
        
        $appraisal->status = 'rejected';
        $appraisal->rejected_by = $rejectedBy;
        $appraisal->rejected_at = date('Y-m-d H:i:s');
        $appraisal->rejection_reason = $reason;
        $appraisal->updated_at = date('Y-m-d H:i:s');
        
        $appraisal->save();
        
        // Notify employee
        $this->notifyEmployee($appraisal, 'rejected');
        
        return $appraisal;
    }
    
    /**
     * Create performance goal
     */
    public function createGoal($data)
    {
        $this->validateGoalData($data);
        
        $goal = Goal::create([
            'employee_id' => $data['employee_id'],
            'title' => $data['title'],
            'description' => $data['description'],
            'category' => $data['category'],
            'priority' => $data['priority'],
            'target_date' => $data['target_date'],
            'success_criteria' => $data['success_criteria'],
            'weight' => $data['weight'] ?? 100,
            'status' => 'active',
            'created_by' => $data['created_by'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        return $goal;
    }
    
    /**
     * Update goal
     */
    public function updateGoal($goalId, $data)
    {
        $goal = Goal::find($goalId);
        
        if (!$goal) {
            throw new ValidationException('Goal not found');
        }
        
        $this->validateGoalData($data);
        
        $goal->title = $data['title'];
        $goal->description = $data['description'];
        $goal->category = $data['category'];
        $goal->priority = $data['priority'];
        $goal->target_date = $data['target_date'];
        $goal->success_criteria = $data['success_criteria'];
        $goal->weight = $data['weight'] ?? $goal->weight;
        $goal->updated_at = date('Y-m-d H:i:s');
        
        $goal->save();
        
        return $goal;
    }
    
    /**
     * Update goal progress
     */
    public function updateGoalProgress($goalId, $progress, $comments = null)
    {
        $goal = Goal::find($goalId);
        
        if (!$goal) {
            throw new ValidationException('Goal not found');
        }
        
        if ($progress < 0 || $progress > 100) {
            throw new ValidationException('Progress must be between 0 and 100');
        }
        
        $goal->progress = $progress;
        $goal->progress_comments = $comments;
        $goal->updated_at = date('Y-m-d H:i:s');
        
        if ($progress >= 100) {
            $goal->status = 'completed';
            $goal->completed_at = date('Y-m-d H:i:s');
        }
        
        $goal->save();
        
        return $goal;
    }
    
    /**
     * Get appraisals
     */
    public function getAppraisals($filters = [])
    {
        $sql = "SELECT 
                    a.*,
                    e.first_name,
                    e.last_name,
                    e.employee_id,
                    d.name as department_name,
                    app.first_name as appraiser_first_name,
                    app.last_name as appraiser_last_name
                FROM appraisals a
                LEFT JOIN employees e ON a.employee_id = e.id
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN employees app ON a.appraiser_id = app.id
                WHERE 1=1";
        
        $params = [];
        
        if (!empty($filters['employee_id'])) {
            $sql .= " AND a.employee_id = ?";
            $params[] = $filters['employee_id'];
        }
        
        if (!empty($filters['appraiser_id'])) {
            $sql .= " AND a.appraiser_id = ?";
            $params[] = $filters['appraiser_id'];
        }
        
        if (!empty($filters['status'])) {
            $sql .= " AND a.status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['appraisal_year'])) {
            $sql .= " AND a.appraisal_year = ?";
            $params[] = $filters['appraisal_year'];
        }
        
        $sql .= " ORDER BY a.created_at DESC";
        
        return $this->connection->fetchAll($sql, $params);
    }
    
    /**
     * Get goals
     */
    public function getGoals($filters = [])
    {
        $sql = "SELECT 
                    g.*,
                    e.first_name,
                    e.last_name,
                    e.employee_id,
                    d.name as department_name
                FROM goals g
                LEFT JOIN employees e ON g.employee_id = e.id
                LEFT JOIN departments d ON e.department_id = d.id
                WHERE 1=1";
        
        $params = [];
        
        if (!empty($filters['employee_id'])) {
            $sql .= " AND g.employee_id = ?";
            $params[] = $filters['employee_id'];
        }
        
        if (!empty($filters['status'])) {
            $sql .= " AND g.status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['category'])) {
            $sql .= " AND g.category = ?";
            $params[] = $filters['category'];
        }
        
        $sql .= " ORDER BY g.created_at DESC";
        
        return $this->connection->fetchAll($sql, $params);
    }
    
    /**
     * Get performance statistics
     */
    public function getPerformanceStatistics($filters = [])
    {
        $whereClause = "1=1";
        $params = [];
        
        if (!empty($filters['appraisal_year'])) {
            $whereClause .= " AND a.appraisal_year = ?";
            $params[] = $filters['appraisal_year'];
        }
        
        $sql = "SELECT 
                    COUNT(*) as total_appraisals,
                    AVG(a.overall_rating) as average_rating,
                    COUNT(CASE WHEN a.overall_rating >= 4 THEN 1 END) as excellent_count,
                    COUNT(CASE WHEN a.overall_rating >= 3 AND a.overall_rating < 4 THEN 1 END) as good_count,
                    COUNT(CASE WHEN a.overall_rating >= 2 AND a.overall_rating < 3 THEN 1 END) as satisfactory_count,
                    COUNT(CASE WHEN a.overall_rating < 2 THEN 1 END) as needs_improvement_count
                FROM appraisals a
                WHERE {$whereClause} AND a.status = 'approved'";
        
        return $this->connection->fetchOne($sql, $params);
    }
    
    /**
     * Get goal statistics
     */
    public function getGoalStatistics($filters = [])
    {
        $whereClause = "1=1";
        $params = [];
        
        if (!empty($filters['employee_id'])) {
            $whereClause .= " AND g.employee_id = ?";
            $params[] = $filters['employee_id'];
        }
        
        $sql = "SELECT 
                    COUNT(*) as total_goals,
                    AVG(g.progress) as average_progress,
                    COUNT(CASE WHEN g.status = 'completed' THEN 1 END) as completed_count,
                    COUNT(CASE WHEN g.status = 'active' THEN 1 END) as active_count,
                    COUNT(CASE WHEN g.progress >= 80 THEN 1 END) as near_completion_count
                FROM goals g
                WHERE {$whereClause}";
        
        return $this->connection->fetchOne($sql, $params);
    }
    
    /**
     * Create criteria ratings
     */
    private function createCriteriaRatings($appraisalId, $criteriaRatings)
    {
        foreach ($criteriaRatings as $rating) {
            $sql = "INSERT INTO appraisal_criteria (
                appraisal_id, criteria_name, rating, comments, created_at
            ) VALUES (?, ?, ?, ?, ?)";
            
            $this->connection->execute($sql, [
                $appraisalId,
                $rating['criteria_name'],
                $rating['rating'],
                $rating['comments'] ?? null,
                date('Y-m-d H:i:s')
            ]);
        }
    }
    
    /**
     * Update criteria ratings
     */
    private function updateCriteriaRatings($appraisalId, $criteriaRatings)
    {
        // Delete existing ratings
        $sql = "DELETE FROM appraisal_criteria WHERE appraisal_id = ?";
        $this->connection->execute($sql, [$appraisalId]);
        
        // Create new ratings
        $this->createCriteriaRatings($appraisalId, $criteriaRatings);
    }
    
    /**
     * Notify appraiser
     */
    private function notifyAppraiser($appraisal)
    {
        $employee = $appraisal->employee;
        $appraiser = $appraisal->appraiser;
        
        $this->notificationService->create([
            'user_id' => $appraiser->id,
            'type' => 'appraisal_submitted',
            'title' => 'Performance Appraisal Submitted',
            'message' => "Performance appraisal for {$employee->first_name} {$employee->last_name} has been submitted for review",
            'data' => json_encode(['appraisal_id' => $appraisal->id])
        ]);
    }
    
    /**
     * Notify employee
     */
    private function notifyEmployee($appraisal, $action)
    {
        $employee = $appraisal->employee;
        
        $this->notificationService->create([
            'user_id' => $employee->id,
            'type' => 'appraisal_' . $action,
            'title' => 'Performance Appraisal ' . ucfirst($action),
            'message' => "Your performance appraisal for {$appraisal->appraisal_period} {$appraisal->appraisal_year} has been {$action}",
            'data' => json_encode(['appraisal_id' => $appraisal->id])
        ]);
    }
    
    /**
     * Validate appraisal data
     */
    private function validateAppraisalData($data)
    {
        $required = ['employee_id', 'appraiser_id', 'appraisal_period', 'appraisal_year', 'overall_rating'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new ValidationException("Field {$field} is required");
            }
        }
        
        if ($data['overall_rating'] < 1 || $data['overall_rating'] > 5) {
            throw new ValidationException('Overall rating must be between 1 and 5');
        }
    }
    
    /**
     * Validate goal data
     */
    private function validateGoalData($data)
    {
        $required = ['employee_id', 'title', 'description', 'category', 'priority', 'target_date', 'success_criteria'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new ValidationException("Field {$field} is required");
            }
        }
        
        if (strtotime($data['target_date']) < time()) {
            throw new ValidationException('Target date cannot be in the past');
        }
    }
    
    /**
     * Get performance analytics
     */
    public function getPerformanceAnalytics($year = null)
    {
        $year = $year ?? date('Y');
        
        $sql = "SELECT 
                    MONTH(a.created_at) as month,
                    COUNT(*) as appraisal_count,
                    AVG(a.overall_rating) as average_rating
                FROM appraisals a
                WHERE YEAR(a.created_at) = ? AND a.status = 'approved'
                GROUP BY MONTH(a.created_at)
                ORDER BY month";
        
        return $this->connection->fetchAll($sql, [$year]);
    }
    
    /**
     * Export performance report
     */
    public function exportPerformanceReport($filters = [])
    {
        $appraisals = $this->getAppraisals($filters);
        
        $filename = 'performance_report_' . date('Y-m-d') . '.csv';
        $filepath = storage_path('exports/' . $filename);
        
        $file = fopen($filepath, 'w');
        
        // Write headers
        fputcsv($file, [
            'Employee ID', 'Employee Name', 'Department', 'Appraisal Period',
            'Overall Rating', 'Goals Achieved', 'Goals Total', 'Status', 'Created Date'
        ]);
        
        // Write data
        foreach ($appraisals as $appraisal) {
            fputcsv($file, [
                $appraisal['employee_id'],
                $appraisal['first_name'] . ' ' . $appraisal['last_name'],
                $appraisal['department_name'],
                $appraisal['appraisal_period'] . ' ' . $appraisal['appraisal_year'],
                $appraisal['overall_rating'],
                $appraisal['goals_achieved'],
                $appraisal['goals_total'],
                $appraisal['status'],
                $appraisal['created_at']
            ]);
        }
        
        fclose($file);
        
        return $filepath;
    }
}