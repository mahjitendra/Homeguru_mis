<?php

namespace App\Traits;

trait SoftDelete
{
    /**
     * Soft delete the model
     */
    public function delete()
    {
        if (!$this->exists) {
            return false;
        }
        
        $this->setAttribute('deleted_at', date('Y-m-d H:i:s'));
        return $this->save();
    }
    
    /**
     * Force delete the model (permanent deletion)
     */
    public function forceDelete()
    {
        if (!$this->exists) {
            return false;
        }
        
        $sql = "DELETE FROM {$this->table} WHERE {$this->primaryKey} = ?";
        $stmt = $this->connection->prepare($sql);
        $result = $stmt->execute([$this->getAttribute($this->primaryKey)]);
        
        if ($result) {
            $this->exists = false;
        }
        
        return $result;
    }
    
    /**
     * Restore a soft deleted model
     */
    public function restore()
    {
        if (!$this->exists) {
            return false;
        }
        
        $this->setAttribute('deleted_at', null);
        return $this->save();
    }
    
    /**
     * Check if model is soft deleted
     */
    public function isDeleted()
    {
        return !is_null($this->getAttribute('deleted_at'));
    }
    
    /**
     * Check if model is not soft deleted
     */
    public function isNotDeleted()
    {
        return is_null($this->getAttribute('deleted_at'));
    }
    
    /**
     * Get deleted at timestamp
     */
    public function getDeletedAtAttribute()
    {
        return $this->getAttribute('deleted_at');
    }
    
    /**
     * Get formatted deleted at
     */
    public function getFormattedDeletedAtAttribute()
    {
        $deletedAt = $this->getAttribute('deleted_at');
        return $deletedAt ? date('M d, Y H:i', strtotime($deletedAt)) : null;
    }
    
    /**
     * Get time since deletion
     */
    public function getTimeSinceDeletionAttribute()
    {
        $deletedAt = $this->getAttribute('deleted_at');
        if (!$deletedAt) {
            return null;
        }
        
        $time = time() - strtotime($deletedAt);
        
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
     * Get days since deletion
     */
    public function getDaysSinceDeletion()
    {
        $deletedAt = $this->getAttribute('deleted_at');
        if (!$deletedAt) {
            return null;
        }
        
        return floor((time() - strtotime($deletedAt)) / 86400);
    }
    
    /**
     * Check if model was deleted recently (within specified days)
     */
    public function wasDeletedRecently($days = 7)
    {
        $deletedAt = $this->getAttribute('deleted_at');
        if (!$deletedAt) {
            return false;
        }
        
        $time = time() - strtotime($deletedAt);
        return $time < ($days * 86400);
    }
    
    /**
     * Check if model was deleted today
     */
    public function wasDeletedToday()
    {
        $deletedAt = $this->getAttribute('deleted_at');
        if (!$deletedAt) {
            return false;
        }
        
        return date('Y-m-d', strtotime($deletedAt)) === date('Y-m-d');
    }
    
    /**
     * Check if model was deleted this week
     */
    public function wasDeletedThisWeek()
    {
        $deletedAt = $this->getAttribute('deleted_at');
        if (!$deletedAt) {
            return false;
        }
        
        $weekStart = strtotime('monday this week');
        $weekEnd = strtotime('sunday this week');
        $deletedTime = strtotime($deletedAt);
        
        return $deletedTime >= $weekStart && $deletedTime <= $weekEnd;
    }
    
    /**
     * Check if model was deleted this month
     */
    public function wasDeletedThisMonth()
    {
        $deletedAt = $this->getAttribute('deleted_at');
        if (!$deletedAt) {
            return false;
        }
        
        return date('Y-m', strtotime($deletedAt)) === date('Y-m');
    }
    
    /**
     * Check if model was deleted this year
     */
    public function wasDeletedThisYear()
    {
        $deletedAt = $this->getAttribute('deleted_at');
        if (!$deletedAt) {
            return false;
        }
        
        return date('Y', strtotime($deletedAt)) === date('Y');
    }
    
    /**
     * Override the base model's get method to exclude soft deleted records
     */
    public function get()
    {
        // Add where clause to exclude soft deleted records
        $this->where('deleted_at', 'IS', null);
        
        return parent::get();
    }
    
    /**
     * Get only soft deleted records
     */
    public function onlyTrashed()
    {
        $this->where('deleted_at', 'IS NOT', null);
        return $this->get();
    }
    
    /**
     * Get all records including soft deleted
     */
    public function withTrashed()
    {
        // Remove any existing deleted_at where clause
        $this->where = array_filter($this->where, function($where) {
            return $where['column'] !== 'deleted_at';
        });
        
        return $this->get();
    }
    
    /**
     * Get only trashed records (alias for onlyTrashed)
     */
    public function trashed()
    {
        return $this->onlyTrashed();
    }
    
    /**
     * Restore all soft deleted records
     */
    public function restoreAll()
    {
        $sql = "UPDATE {$this->table} SET deleted_at = NULL WHERE deleted_at IS NOT NULL";
        $stmt = $this->connection->prepare($sql);
        return $stmt->execute();
    }
    
    /**
     * Permanently delete all soft deleted records
     */
    public function forceDeleteAll()
    {
        $sql = "DELETE FROM {$this->table} WHERE deleted_at IS NOT NULL";
        $stmt = $this->connection->prepare($sql);
        return $stmt->execute();
    }
    
    /**
     * Get count of soft deleted records
     */
    public function getTrashedCount()
    {
        $sql = "SELECT COUNT(*) as count FROM {$this->table} WHERE deleted_at IS NOT NULL";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();
        return $result['count'] ?? 0;
    }
    
    /**
     * Get count of non-deleted records
     */
    public function getActiveCount()
    {
        $sql = "SELECT COUNT(*) as count FROM {$this->table} WHERE deleted_at IS NULL";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();
        return $result['count'] ?? 0;
    }
    
    /**
     * Get total count including soft deleted
     */
    public function getTotalCount()
    {
        $sql = "SELECT COUNT(*) as count FROM {$this->table}";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();
        return $result['count'] ?? 0;
    }
}