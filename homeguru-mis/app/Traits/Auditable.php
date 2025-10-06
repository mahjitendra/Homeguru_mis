<?php

namespace App\Traits;

trait Auditable
{
    /**
     * Set audit attributes
     */
    protected function setAuditAttributes()
    {
        $userId = $this->getCurrentUserId();
        
        if (!$this->exists) {
            $this->setAttribute('created_by', $userId);
        }
        
        $this->setAttribute('updated_by', $userId);
    }
    
    /**
     * Get current user ID from session or context
     */
    protected function getCurrentUserId()
    {
        // This should be implemented based on your authentication system
        // For now, return null or get from session
        return $_SESSION['user_id'] ?? null;
    }
    
    /**
     * Get created by user ID
     */
    public function getCreatedByAttribute()
    {
        return $this->getAttribute('created_by');
    }
    
    /**
     * Get updated by user ID
     */
    public function getUpdatedByAttribute()
    {
        return $this->getAttribute('updated_by');
    }
    
    /**
     * Get created by user
     */
    public function getCreatedByUser()
    {
        $createdBy = $this->getAttribute('created_by');
        
        if (!$createdBy) {
            return null;
        }
        
        // This should return the actual user model
        // For now, return a simple array
        return [
            'id' => $createdBy,
            'name' => 'User ' . $createdBy
        ];
    }
    
    /**
     * Get updated by user
     */
    public function getUpdatedByUser()
    {
        $updatedBy = $this->getAttribute('updated_by');
        
        if (!$updatedBy) {
            return null;
        }
        
        // This should return the actual user model
        // For now, return a simple array
        return [
            'id' => $updatedBy,
            'name' => 'User ' . $updatedBy
        ];
    }
    
    /**
     * Get created by user name
     */
    public function getCreatedByNameAttribute()
    {
        $user = $this->getCreatedByUser();
        return $user ? $user['name'] : 'Unknown';
    }
    
    /**
     * Get updated by user name
     */
    public function getUpdatedByNameAttribute()
    {
        $user = $this->getUpdatedByUser();
        return $user ? $user['name'] : 'Unknown';
    }
    
    /**
     * Check if record was created by specific user
     */
    public function wasCreatedBy($userId)
    {
        return $this->getAttribute('created_by') == $userId;
    }
    
    /**
     * Check if record was updated by specific user
     */
    public function wasUpdatedBy($userId)
    {
        return $this->getAttribute('updated_by') == $userId;
    }
    
    /**
     * Check if record was modified by specific user
     */
    public function wasModifiedBy($userId)
    {
        return $this->wasCreatedBy($userId) || $this->wasUpdatedBy($userId);
    }
    
    /**
     * Get audit trail
     */
    public function getAuditTrail()
    {
        // This should return the audit trail for this record
        // For now, return basic information
        return [
            'created_at' => $this->getAttribute('created_at'),
            'created_by' => $this->getCreatedByUser(),
            'updated_at' => $this->getAttribute('updated_at'),
            'updated_by' => $this->getUpdatedByUser()
        ];
    }
    
    /**
     * Get formatted audit trail
     */
    public function getFormattedAuditTrail()
    {
        $trail = $this->getAuditTrail();
        
        return [
            'created' => [
                'at' => $trail['created_at'] ? date('M d, Y H:i', strtotime($trail['created_at'])) : 'Unknown',
                'by' => $trail['created_by']['name'] ?? 'Unknown'
            ],
            'updated' => [
                'at' => $trail['updated_at'] ? date('M d, Y H:i', strtotime($trail['updated_at'])) : 'Unknown',
                'by' => $trail['updated_by']['name'] ?? 'Unknown'
            ]
        ];
    }
    
    /**
     * Check if record was created today
     */
    public function wasCreatedToday()
    {
        $createdAt = $this->getAttribute('created_at');
        if (!$createdAt) {
            return false;
        }
        
        return date('Y-m-d', strtotime($createdAt)) === date('Y-m-d');
    }
    
    /**
     * Check if record was updated today
     */
    public function wasUpdatedToday()
    {
        $updatedAt = $this->getAttribute('updated_at');
        if (!$updatedAt) {
            return false;
        }
        
        return date('Y-m-d', strtotime($updatedAt)) === date('Y-m-d');
    }
    
    /**
     * Check if record was created this week
     */
    public function wasCreatedThisWeek()
    {
        $createdAt = $this->getAttribute('created_at');
        if (!$createdAt) {
            return false;
        }
        
        $weekStart = strtotime('monday this week');
        $weekEnd = strtotime('sunday this week');
        $createdTime = strtotime($createdAt);
        
        return $createdTime >= $weekStart && $createdTime <= $weekEnd;
    }
    
    /**
     * Check if record was updated this week
     */
    public function wasUpdatedThisWeek()
    {
        $updatedAt = $this->getAttribute('updated_at');
        if (!$updatedAt) {
            return false;
        }
        
        $weekStart = strtotime('monday this week');
        $weekEnd = strtotime('sunday this week');
        $updatedTime = strtotime($updatedAt);
        
        return $updatedTime >= $weekStart && $updatedTime <= $weekEnd;
    }
    
    /**
     * Check if record was created this month
     */
    public function wasCreatedThisMonth()
    {
        $createdAt = $this->getAttribute('created_at');
        if (!$createdAt) {
            return false;
        }
        
        return date('Y-m', strtotime($createdAt)) === date('Y-m');
    }
    
    /**
     * Check if record was updated this month
     */
    public function wasUpdatedThisMonth()
    {
        $updatedAt = $this->getAttribute('updated_at');
        if (!$updatedAt) {
            return false;
        }
        
        return date('Y-m', strtotime($updatedAt)) === date('Y-m');
    }
    
    /**
     * Check if record was created this year
     */
    public function wasCreatedThisYear()
    {
        $createdAt = $this->getAttribute('created_at');
        if (!$createdAt) {
            return false;
        }
        
        return date('Y', strtotime($createdAt)) === date('Y');
    }
    
    /**
     * Check if record was updated this year
     */
    public function wasUpdatedThisYear()
    {
        $updatedAt = $this->getAttribute('updated_at');
        if (!$updatedAt) {
            return false;
        }
        
        return date('Y', strtotime($updatedAt)) === date('Y');
    }
    
    /**
     * Get time since creation
     */
    public function getTimeSinceCreation()
    {
        $createdAt = $this->getAttribute('created_at');
        if (!$createdAt) {
            return null;
        }
        
        $time = time() - strtotime($createdAt);
        
        if ($time < 60) {
            return 'just now';
        } elseif ($time < 3600) {
            $minutes = floor($time / 60);
            return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
        } elseif ($time < 86400) {
            $hours = floor($time / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($time < 2592000) {
            $days = floor($time / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } elseif ($time < 31536000) {
            $months = floor($time / 2592000);
            return $months . ' month' . ($months > 1 ? 's' : '') . ' ago';
        } else {
            $years = floor($time / 31536000);
            return $years . ' year' . ($years > 1 ? 's' : '') . ' ago';
        }
    }
    
    /**
     * Get time since last update
     */
    public function getTimeSinceLastUpdate()
    {
        $updatedAt = $this->getAttribute('updated_at');
        if (!$updatedAt) {
            return null;
        }
        
        $time = time() - strtotime($updatedAt);
        
        if ($time < 60) {
            return 'just now';
        } elseif ($time < 3600) {
            $minutes = floor($time / 60);
            return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
        } elseif ($time < 86400) {
            $hours = floor($time / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($time < 2592000) {
            $days = floor($time / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } elseif ($time < 31536000) {
            $months = floor($time / 2592000);
            return $months . ' month' . ($months > 1 ? 's' : '') . ' ago';
        } else {
            $years = floor($time / 31536000);
            return $years . ' year' . ($years > 1 ? 's' : '') . ' ago';
        }
    }
    
    /**
     * Check if record was recently created (within specified minutes)
     */
    public function wasCreatedRecently($minutes = 5)
    {
        $createdAt = $this->getAttribute('created_at');
        if (!$createdAt) {
            return false;
        }
        
        $time = time() - strtotime($createdAt);
        return $time < ($minutes * 60);
    }
    
    /**
     * Check if record was recently updated (within specified minutes)
     */
    public function wasUpdatedRecently($minutes = 5)
    {
        $updatedAt = $this->getAttribute('updated_at');
        if (!$updatedAt) {
            return false;
        }
        
        $time = time() - strtotime($updatedAt);
        return $time < ($minutes * 60);
    }
    
    /**
     * Get creation date in different formats
     */
    public function getCreationDate($format = 'Y-m-d H:i:s')
    {
        $createdAt = $this->getAttribute('created_at');
        return $createdAt ? date($format, strtotime($createdAt)) : null;
    }
    
    /**
     * Get update date in different formats
     */
    public function getUpdateDate($format = 'Y-m-d H:i:s')
    {
        $updatedAt = $this->getAttribute('updated_at');
        return $updatedAt ? date($format, strtotime($updatedAt)) : null;
    }
}