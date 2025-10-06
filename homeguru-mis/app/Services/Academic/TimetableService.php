<?php

namespace App\Services\Academic;

use App\Models\Academic\Timetable;
use App\Models\Academic\Class;
use App\Models\Academic\Subject;
use App\Models\Academic\Teacher;
use App\Libraries\Database\Connection;
use App\Exceptions\ValidationException;

class TimetableService
{
    private $connection;
    
    public function __construct()
    {
        $this->connection = Connection::getInstance();
    }
    
    /**
     * Create timetable
     */
    public function createTimetable($data)
    {
        $this->validateTimetableData($data);
        
        $timetable = Timetable::create([
            'class_id' => $data['class_id'],
            'section_id' => $data['section_id'] ?? null,
            'academic_year' => $data['academic_year'],
            'term' => $data['term'] ?? 'annual',
            'status' => 'draft',
            'created_by' => $data['created_by'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        return $timetable;
    }
    
    /**
     * Add period to timetable
     */
    public function addPeriod($timetableId, $periodData)
    {
        $this->validatePeriodData($periodData);
        
        // Check for conflicts
        if ($this->hasConflict($timetableId, $periodData)) {
            throw new ValidationException('Period conflicts with existing schedule');
        }
        
        $sql = "INSERT INTO timetable_periods (
            timetable_id, day_of_week, period_number, subject_id, 
            teacher_id, room, start_time, end_time, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $this->connection->execute($sql, [
            $timetableId,
            $periodData['day_of_week'],
            $periodData['period_number'],
            $periodData['subject_id'],
            $periodData['teacher_id'],
            $periodData['room'] ?? null,
            $periodData['start_time'],
            $periodData['end_time'],
            date('Y-m-d H:i:s')
        ]);
        
        return $this->connection->lastInsertId();
    }
    
    /**
     * Update period
     */
    public function updatePeriod($periodId, $periodData)
    {
        $this->validatePeriodData($periodData);
        
        // Get timetable ID
        $sql = "SELECT timetable_id FROM timetable_periods WHERE id = ?";
        $period = $this->connection->fetchOne($sql, [$periodId]);
        
        if (!$period) {
            throw new ValidationException('Period not found');
        }
        
        // Check for conflicts (excluding current period)
        if ($this->hasConflict($period['timetable_id'], $periodData, $periodId)) {
            throw new ValidationException('Period conflicts with existing schedule');
        }
        
        $sql = "UPDATE timetable_periods SET 
                day_of_week = ?, 
                period_number = ?, 
                subject_id = ?, 
                teacher_id = ?, 
                room = ?, 
                start_time = ?, 
                end_time = ?, 
                updated_at = ? 
                WHERE id = ?";
        
        return $this->connection->execute($sql, [
            $periodData['day_of_week'],
            $periodData['period_number'],
            $periodData['subject_id'],
            $periodData['teacher_id'],
            $periodData['room'] ?? null,
            $periodData['start_time'],
            $periodData['end_time'],
            date('Y-m-d H:i:s'),
            $periodId
        ]);
    }
    
    /**
     * Delete period
     */
    public function deletePeriod($periodId)
    {
        $sql = "DELETE FROM timetable_periods WHERE id = ?";
        return $this->connection->execute($sql, [$periodId]);
    }
    
    /**
     * Get timetable for class
     */
    public function getTimetableForClass($classId, $sectionId = null, $academicYear = null)
    {
        $academicYear = $academicYear ?? date('Y');
        
        $sql = "SELECT 
                    tp.*,
                    s.name as subject_name,
                    s.code as subject_code,
                    t.first_name as teacher_first_name,
                    t.last_name as teacher_last_name,
                    c.name as class_name
                FROM timetable_periods tp
                LEFT JOIN subjects s ON tp.subject_id = s.id
                LEFT JOIN teachers t ON tp.teacher_id = t.id
                LEFT JOIN classes c ON tp.timetable_id = (
                    SELECT id FROM timetables 
                    WHERE class_id = ? AND section_id = ? AND academic_year = ?
                )
                WHERE tp.timetable_id = (
                    SELECT id FROM timetables 
                    WHERE class_id = ? AND section_id = ? AND academic_year = ?
                )
                ORDER BY tp.day_of_week, tp.period_number";
        
        $params = [$classId, $sectionId, $academicYear, $classId, $sectionId, $academicYear];
        
        return $this->connection->fetchAll($sql, $params);
    }
    
    /**
     * Get timetable for teacher
     */
    public function getTimetableForTeacher($teacherId, $academicYear = null)
    {
        $academicYear = $academicYear ?? date('Y');
        
        $sql = "SELECT 
                    tp.*,
                    s.name as subject_name,
                    s.code as subject_code,
                    c.name as class_name,
                    sec.name as section_name
                FROM timetable_periods tp
                LEFT JOIN subjects s ON tp.subject_id = s.id
                LEFT JOIN timetables t ON tp.timetable_id = t.id
                LEFT JOIN classes c ON t.class_id = c.id
                LEFT JOIN sections sec ON t.section_id = sec.id
                WHERE tp.teacher_id = ? AND t.academic_year = ?
                ORDER BY tp.day_of_week, tp.period_number";
        
        return $this->connection->fetchAll($sql, [$teacherId, $academicYear]);
    }
    
    /**
     * Get timetable for room
     */
    public function getTimetableForRoom($room, $academicYear = null)
    {
        $academicYear = $academicYear ?? date('Y');
        
        $sql = "SELECT 
                    tp.*,
                    s.name as subject_name,
                    s.code as subject_code,
                    t.first_name as teacher_first_name,
                    t.last_name as teacher_last_name,
                    c.name as class_name,
                    sec.name as section_name
                FROM timetable_periods tp
                LEFT JOIN subjects s ON tp.subject_id = s.id
                LEFT JOIN teachers t ON tp.teacher_id = t.id
                LEFT JOIN timetables tt ON tp.timetable_id = tt.id
                LEFT JOIN classes c ON tt.class_id = c.id
                LEFT JOIN sections sec ON tt.section_id = sec.id
                WHERE tp.room = ? AND tt.academic_year = ?
                ORDER BY tp.day_of_week, tp.period_number";
        
        return $this->connection->fetchAll($sql, [$room, $academicYear]);
    }
    
    /**
     * Check for conflicts
     */
    public function hasConflict($timetableId, $periodData, $excludePeriodId = null)
    {
        $sql = "SELECT COUNT(*) as count FROM timetable_periods 
                WHERE timetable_id = ? 
                AND day_of_week = ? 
                AND period_number = ?";
        
        $params = [$timetableId, $periodData['day_of_week'], $periodData['period_number']];
        
        if ($excludePeriodId) {
            $sql .= " AND id != ?";
            $params[] = $excludePeriodId;
        }
        
        $result = $this->connection->fetchOne($sql, $params);
        
        return $result['count'] > 0;
    }
    
    /**
     * Check teacher availability
     */
    public function isTeacherAvailable($teacherId, $dayOfWeek, $periodNumber, $academicYear = null, $excludePeriodId = null)
    {
        $academicYear = $academicYear ?? date('Y');
        
        $sql = "SELECT COUNT(*) as count FROM timetable_periods tp
                INNER JOIN timetables t ON tp.timetable_id = t.id
                WHERE tp.teacher_id = ? 
                AND tp.day_of_week = ? 
                AND tp.period_number = ?
                AND t.academic_year = ?";
        
        $params = [$teacherId, $dayOfWeek, $periodNumber, $academicYear];
        
        if ($excludePeriodId) {
            $sql .= " AND tp.id != ?";
            $params[] = $excludePeriodId;
        }
        
        $result = $this->connection->fetchOne($sql, $params);
        
        return $result['count'] === 0;
    }
    
    /**
     * Check room availability
     */
    public function isRoomAvailable($room, $dayOfWeek, $periodNumber, $academicYear = null, $excludePeriodId = null)
    {
        $academicYear = $academicYear ?? date('Y');
        
        $sql = "SELECT COUNT(*) as count FROM timetable_periods tp
                INNER JOIN timetables t ON tp.timetable_id = t.id
                WHERE tp.room = ? 
                AND tp.day_of_week = ? 
                AND tp.period_number = ?
                AND t.academic_year = ?";
        
        $params = [$room, $dayOfWeek, $periodNumber, $academicYear];
        
        if ($excludePeriodId) {
            $sql .= " AND tp.id != ?";
            $params[] = $excludePeriodId;
        }
        
        $result = $this->connection->fetchOne($sql, $params);
        
        return $result['count'] === 0;
    }
    
    /**
     * Generate timetable automatically
     */
    public function generateTimetable($classId, $sectionId, $academicYear, $options = [])
    {
        // Get class subjects and teachers
        $subjects = $this->getClassSubjects($classId);
        $teachers = $this->getClassTeachers($classId);
        $rooms = $this->getAvailableRooms();
        
        if (empty($subjects) || empty($teachers)) {
            throw new ValidationException('No subjects or teachers found for the class');
        }
        
        // Create timetable
        $timetable = $this->createTimetable([
            'class_id' => $classId,
            'section_id' => $sectionId,
            'academic_year' => $academicYear,
            'created_by' => $options['created_by'] ?? null
        ]);
        
        $periodsPerDay = $options['periods_per_day'] ?? 8;
        $daysPerWeek = $options['days_per_week'] ?? 6;
        
        $generatedPeriods = [];
        
        foreach ($subjects as $subject) {
            $periodsNeeded = $subject['periods_per_week'];
            $periodsAssigned = 0;
            
            for ($day = 1; $day <= $daysPerWeek && $periodsAssigned < $periodsNeeded; $day++) {
                for ($period = 1; $period <= $periodsPerDay && $periodsAssigned < $periodsNeeded; $period++) {
                    $teacher = $this->findAvailableTeacher($subject['id'], $day, $period, $academicYear);
                    $room = $this->findAvailableRoom($day, $period, $academicYear);
                    
                    if ($teacher && $room) {
                        $periodData = [
                            'day_of_week' => $day,
                            'period_number' => $period,
                            'subject_id' => $subject['id'],
                            'teacher_id' => $teacher['id'],
                            'room' => $room,
                            'start_time' => $this->getPeriodStartTime($period),
                            'end_time' => $this->getPeriodEndTime($period)
                        ];
                        
                        $this->addPeriod($timetable->id, $periodData);
                        $generatedPeriods[] = $periodData;
                        $periodsAssigned++;
                    }
                }
            }
        }
        
        return [
            'timetable_id' => $timetable->id,
            'generated_periods' => count($generatedPeriods),
            'periods' => $generatedPeriods
        ];
    }
    
    /**
     * Get class subjects
     */
    private function getClassSubjects($classId)
    {
        $sql = "SELECT 
                    cs.subject_id,
                    s.name,
                    s.code,
                    cs.periods_per_week
                FROM class_subjects cs
                INNER JOIN subjects s ON cs.subject_id = s.id
                WHERE cs.class_id = ? AND cs.status = 'active'
                ORDER BY cs.periods_per_week DESC";
        
        return $this->connection->fetchAll($sql, [$classId]);
    }
    
    /**
     * Get class teachers
     */
    private function getClassTeachers($classId)
    {
        $sql = "SELECT 
                    ts.teacher_id,
                    t.first_name,
                    t.last_name,
                    ts.subject_id
                FROM teacher_subjects ts
                INNER JOIN teachers t ON ts.teacher_id = t.id
                INNER JOIN class_subjects cs ON ts.subject_id = cs.subject_id
                WHERE cs.class_id = ? AND ts.status = 'active' AND cs.status = 'active'
                ORDER BY t.first_name";
        
        return $this->connection->fetchAll($sql, [$classId]);
    }
    
    /**
     * Get available rooms
     */
    private function getAvailableRooms()
    {
        $sql = "SELECT DISTINCT room FROM timetable_periods WHERE room IS NOT NULL ORDER BY room";
        $result = $this->connection->fetchAll($sql);
        
        $rooms = [];
        foreach ($result as $row) {
            $rooms[] = $row['room'];
        }
        
        return $rooms;
    }
    
    /**
     * Find available teacher
     */
    private function findAvailableTeacher($subjectId, $dayOfWeek, $periodNumber, $academicYear)
    {
        $sql = "SELECT 
                    ts.teacher_id,
                    t.first_name,
                    t.last_name
                FROM teacher_subjects ts
                INNER JOIN teachers t ON ts.teacher_id = t.id
                WHERE ts.subject_id = ? AND ts.status = 'active'
                AND NOT EXISTS (
                    SELECT 1 FROM timetable_periods tp
                    INNER JOIN timetables tt ON tp.timetable_id = tt.id
                    WHERE tp.teacher_id = ts.teacher_id
                    AND tp.day_of_week = ? AND tp.period_number = ?
                    AND tt.academic_year = ?
                )
                LIMIT 1";
        
        return $this->connection->fetchOne($sql, [$subjectId, $dayOfWeek, $periodNumber, $academicYear]);
    }
    
    /**
     * Find available room
     */
    private function findAvailableRoom($dayOfWeek, $periodNumber, $academicYear)
    {
        $sql = "SELECT room FROM rooms 
                WHERE status = 'active'
                AND NOT EXISTS (
                    SELECT 1 FROM timetable_periods tp
                    INNER JOIN timetables tt ON tp.timetable_id = tt.id
                    WHERE tp.room = rooms.room
                    AND tp.day_of_week = ? AND tp.period_number = ?
                    AND tt.academic_year = ?
                )
                LIMIT 1";
        
        $result = $this->connection->fetchOne($sql, [$dayOfWeek, $periodNumber, $academicYear]);
        
        return $result ? $result['room'] : null;
    }
    
    /**
     * Get period start time
     */
    private function getPeriodStartTime($periodNumber)
    {
        $startTimes = [
            1 => '08:00:00',
            2 => '08:45:00',
            3 => '09:30:00',
            4 => '10:15:00',
            5 => '11:00:00',
            6 => '11:45:00',
            7 => '12:30:00',
            8 => '13:15:00'
        ];
        
        return $startTimes[$periodNumber] ?? '08:00:00';
    }
    
    /**
     * Get period end time
     */
    private function getPeriodEndTime($periodNumber)
    {
        $endTimes = [
            1 => '08:40:00',
            2 => '09:25:00',
            3 => '10:10:00',
            4 => '10:55:00',
            5 => '11:40:00',
            6 => '12:25:00',
            7 => '13:10:00',
            8 => '13:55:00'
        ];
        
        return $endTimes[$periodNumber] ?? '08:40:00';
    }
    
    /**
     * Validate timetable data
     */
    private function validateTimetableData($data)
    {
        $required = ['class_id', 'academic_year'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new ValidationException("Field {$field} is required");
            }
        }
    }
    
    /**
     * Validate period data
     */
    private function validatePeriodData($data)
    {
        $required = ['day_of_week', 'period_number', 'subject_id', 'teacher_id', 'start_time', 'end_time'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new ValidationException("Field {$field} is required");
            }
        }
        
        if ($data['day_of_week'] < 1 || $data['day_of_week'] > 7) {
            throw new ValidationException('Day of week must be between 1 and 7');
        }
        
        if ($data['period_number'] < 1 || $data['period_number'] > 10) {
            throw new ValidationException('Period number must be between 1 and 10');
        }
    }
    
    /**
     * Get timetable statistics
     */
    public function getTimetableStatistics($academicYear = null)
    {
        $academicYear = $academicYear ?? date('Y');
        
        $sql = "SELECT 
                    COUNT(DISTINCT t.id) as total_timetables,
                    COUNT(tp.id) as total_periods,
                    COUNT(DISTINCT tp.teacher_id) as teachers_scheduled,
                    COUNT(DISTINCT tp.subject_id) as subjects_scheduled
                FROM timetables t
                LEFT JOIN timetable_periods tp ON t.id = tp.timetable_id
                WHERE t.academic_year = ?";
        
        return $this->connection->fetchOne($sql, [$academicYear]);
    }
    
    /**
     * Export timetable
     */
    public function exportTimetable($timetableId, $format = 'json')
    {
        $timetable = $this->getTimetableForClass($timetableId);
        
        if ($format === 'json') {
            return json_encode($timetable, JSON_PRETTY_PRINT);
        }
        
        // Add other export formats as needed
        return $timetable;
    }
}