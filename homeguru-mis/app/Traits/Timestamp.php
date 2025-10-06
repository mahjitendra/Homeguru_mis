<?php

namespace App\Traits;

trait Timestamp
{
    /**
     * Set timestamp attributes
     */
    protected function setTimestamps()
    {
        $now = date('Y-m-d H:i:s');
        
        if (!$this->exists) {
            $this->setAttribute('created_at', $now);
        }
        
        $this->setAttribute('updated_at', $now);
    }
    
    /**
     * Get created at timestamp
     */
    public function getCreatedAtAttribute()
    {
        return $this->getAttribute('created_at');
    }
    
    /**
     * Get updated at timestamp
     */
    public function getUpdatedAtAttribute()
    {
        return $this->getAttribute('updated_at');
    }
    
    /**
     * Get formatted created at
     */
    public function getFormattedCreatedAtAttribute()
    {
        $createdAt = $this->getAttribute('created_at');
        return $createdAt ? date('M d, Y H:i', strtotime($createdAt)) : null;
    }
    
    /**
     * Get formatted updated at
     */
    public function getFormattedUpdatedAtAttribute()
    {
        $updatedAt = $this->getAttribute('updated_at');
        return $updatedAt ? date('M d, Y H:i', strtotime($updatedAt)) : null;
    }
    
    /**
     * Get human readable time difference
     */
    public function getTimeAgoAttribute()
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
     * Check if record is recent (within specified minutes)
     */
    public function isRecent($minutes = 5)
    {
        $createdAt = $this->getAttribute('created_at');
        if (!$createdAt) {
            return false;
        }
        
        $time = time() - strtotime($createdAt);
        return $time < ($minutes * 60);
    }
    
    /**
     * Check if record was created today
     */
    public function isCreatedToday()
    {
        $createdAt = $this->getAttribute('created_at');
        if (!$createdAt) {
            return false;
        }
        
        return date('Y-m-d', strtotime($createdAt)) === date('Y-m-d');
    }
    
    /**
     * Check if record was created this week
     */
    public function isCreatedThisWeek()
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
     * Check if record was created this month
     */
    public function isCreatedThisMonth()
    {
        $createdAt = $this->getAttribute('created_at');
        if (!$createdAt) {
            return false;
        }
        
        return date('Y-m', strtotime($createdAt)) === date('Y-m');
    }
    
    /**
     * Check if record was created this year
     */
    public function isCreatedThisYear()
    {
        $createdAt = $this->getAttribute('created_at');
        if (!$createdAt) {
            return false;
        }
        
        return date('Y', strtotime($createdAt)) === date('Y');
    }
    
    /**
     * Get age in days
     */
    public function getAgeInDays()
    {
        $createdAt = $this->getAttribute('created_at');
        if (!$createdAt) {
            return null;
        }
        
        return floor((time() - strtotime($createdAt)) / 86400);
    }
    
    /**
     * Get age in hours
     */
    public function getAgeInHours()
    {
        $createdAt = $this->getAttribute('created_at');
        if (!$createdAt) {
            return null;
        }
        
        return floor((time() - strtotime($createdAt)) / 3600);
    }
    
    /**
     * Get age in minutes
     */
    public function getAgeInMinutes()
    {
        $createdAt = $this->getAttribute('created_at');
        if (!$createdAt) {
            return null;
        }
        
        return floor((time() - strtotime($createdAt)) / 60);
    }
}