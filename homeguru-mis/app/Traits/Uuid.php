<?php

namespace App\Traits;

use Ramsey\Uuid\Uuid as RamseyUuid;

trait Uuid
{
    /**
     * Set UUID attribute
     */
    protected function setUuid()
    {
        if (!$this->getAttribute('uuid')) {
            $this->setAttribute('uuid', $this->generateUuid());
        }
    }
    
    /**
     * Generate a new UUID
     */
    protected function generateUuid()
    {
        return RamseyUuid::uuid4()->toString();
    }
    
    /**
     * Get UUID attribute
     */
    public function getUuidAttribute()
    {
        return $this->getAttribute('uuid');
    }
    
    /**
     * Find model by UUID
     */
    public static function findByUuid($uuid)
    {
        $instance = new static();
        $sql = "SELECT * FROM {$instance->table} WHERE uuid = ?";
        
        $stmt = $instance->connection->prepare($sql);
        $stmt->execute([$uuid]);
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if ($result) {
            $instance->fill($result);
            $instance->exists = true;
            $instance->syncOriginal();
            return $instance;
        }
        
        return null;
    }
    
    /**
     * Find model by UUID or fail
     */
    public static function findByUuidOrFail($uuid)
    {
        $model = static::findByUuid($uuid);
        
        if (!$model) {
            throw new \Exception("Model with UUID '{$uuid}' not found.");
        }
        
        return $model;
    }
    
    /**
     * Get short UUID (first 8 characters)
     */
    public function getShortUuidAttribute()
    {
        $uuid = $this->getAttribute('uuid');
        return $uuid ? substr($uuid, 0, 8) : null;
    }
    
    /**
     * Get UUID without hyphens
     */
    public function getUuidWithoutHyphensAttribute()
    {
        $uuid = $this->getAttribute('uuid');
        return $uuid ? str_replace('-', '', $uuid) : null;
    }
    
    /**
     * Check if UUID is valid
     */
    public function isValidUuid($uuid = null)
    {
        $uuid = $uuid ?: $this->getAttribute('uuid');
        
        if (!$uuid) {
            return false;
        }
        
        return RamseyUuid::isValid($uuid);
    }
    
    /**
     * Generate UUID v1 (time-based)
     */
    public function generateUuidV1()
    {
        return RamseyUuid::uuid1()->toString();
    }
    
    /**
     * Generate UUID v3 (name-based with MD5)
     */
    public function generateUuidV3($namespace, $name)
    {
        return RamseyUuid::uuid3($namespace, $name)->toString();
    }
    
    /**
     * Generate UUID v4 (random)
     */
    public function generateUuidV4()
    {
        return RamseyUuid::uuid4()->toString();
    }
    
    /**
     * Generate UUID v5 (name-based with SHA-1)
     */
    public function generateUuidV5($namespace, $name)
    {
        return RamseyUuid::uuid5($namespace, $name)->toString();
    }
    
    /**
     * Generate ordered UUID (time-based with random data)
     */
    public function generateOrderedUuid()
    {
        return RamseyUuid::uuid6()->toString();
    }
    
    /**
     * Get UUID version
     */
    public function getUuidVersion()
    {
        $uuid = $this->getAttribute('uuid');
        
        if (!$uuid) {
            return null;
        }
        
        try {
            $uuidObject = RamseyUuid::fromString($uuid);
            return $uuidObject->getVersion();
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Get UUID timestamp (for v1 and v6)
     */
    public function getUuidTimestamp()
    {
        $uuid = $this->getAttribute('uuid');
        
        if (!$uuid) {
            return null;
        }
        
        try {
            $uuidObject = RamseyUuid::fromString($uuid);
            $version = $uuidObject->getVersion();
            
            if (in_array($version, [1, 6])) {
                return $uuidObject->getDateTime();
            }
            
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Get UUID node (for v1)
     */
    public function getUuidNode()
    {
        $uuid = $this->getAttribute('uuid');
        
        if (!$uuid) {
            return null;
        }
        
        try {
            $uuidObject = RamseyUuid::fromString($uuid);
            
            if ($uuidObject->getVersion() === 1) {
                return $uuidObject->getNode();
            }
            
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Get UUID clock sequence (for v1)
     */
    public function getUuidClockSequence()
    {
        $uuid = $this->getAttribute('uuid');
        
        if (!$uuid) {
            return null;
        }
        
        try {
            $uuidObject = RamseyUuid::fromString($uuid);
            
            if ($uuidObject->getVersion() === 1) {
                return $uuidObject->getClockSequence();
            }
            
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Check if UUID is nil (all zeros)
     */
    public function isNilUuid()
    {
        $uuid = $this->getAttribute('uuid');
        
        if (!$uuid) {
            return false;
        }
        
        try {
            $uuidObject = RamseyUuid::fromString($uuid);
            return $uuidObject->equals(RamseyUuid::NIL);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Check if UUID is max (all ones)
     */
    public function isMaxUuid()
    {
        $uuid = $this->getAttribute('uuid');
        
        if (!$uuid) {
            return false;
        }
        
        try {
            $uuidObject = RamseyUuid::fromString($uuid);
            return $uuidObject->equals(RamseyUuid::MAX);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Compare UUIDs
     */
    public function compareUuid($otherUuid)
    {
        $uuid = $this->getAttribute('uuid');
        
        if (!$uuid || !$otherUuid) {
            return null;
        }
        
        try {
            $uuid1 = RamseyUuid::fromString($uuid);
            $uuid2 = RamseyUuid::fromString($otherUuid);
            
            return $uuid1->compareTo($uuid2);
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Get UUID as binary
     */
    public function getUuidAsBinary()
    {
        $uuid = $this->getAttribute('uuid');
        
        if (!$uuid) {
            return null;
        }
        
        try {
            $uuidObject = RamseyUuid::fromString($uuid);
            return $uuidObject->getBytes();
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Set UUID from binary
     */
    public function setUuidFromBinary($binary)
    {
        try {
            $uuidObject = RamseyUuid::fromBytes($binary);
            $this->setAttribute('uuid', $uuidObject->toString());
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}