<?php

namespace App\Config;

class App
{
    // Application Information
    const NAME = 'HomeGuru MIS';
    const VERSION = '1.0.0';
    const DESCRIPTION = 'Management Information System for Educational Institutions';
    
    // Environment
    const ENV_PRODUCTION = 'production';
    const ENV_DEVELOPMENT = 'development';
    const ENV_TESTING = 'testing';
    
    // Default Settings
    const DEFAULT_TIMEZONE = 'Asia/Kolkata';
    const DEFAULT_LOCALE = 'en';
    const DEFAULT_CURRENCY = 'INR';
    
    // Pagination
    const DEFAULT_PAGE_SIZE = 20;
    const MAX_PAGE_SIZE = 100;
    
    // File Upload
    const MAX_UPLOAD_SIZE = 10240; // 10MB in KB
    const ALLOWED_IMAGE_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    const ALLOWED_DOCUMENT_TYPES = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
    
    // Security
    const PASSWORD_MIN_LENGTH = 8;
    const SESSION_LIFETIME = 7200; // 2 hours
    const LOGIN_ATTEMPTS_LIMIT = 5;
    const LOGIN_LOCKOUT_TIME = 900; // 15 minutes
    
    // Cache
    const CACHE_DEFAULT_TTL = 3600; // 1 hour
    const CACHE_LONG_TTL = 86400; // 24 hours
    
    // API
    const API_VERSION = 'v1';
    const API_RATE_LIMIT = 100; // requests per minute
    
    // Notification
    const NOTIFICATION_RETENTION_DAYS = 30;
    const EMAIL_QUEUE_DELAY = 60; // seconds
    
    // Academic
    const ACADEMIC_YEAR_START_MONTH = 4; // April
    const DEFAULT_GRADE_SCALE = 100;
    const ATTENDANCE_MINIMUM_PERCENTAGE = 75;
    
    // Financial
    const FEE_DUE_DAYS = 15;
    const LATE_FEE_PERCENTAGE = 5;
    const REFUND_PROCESSING_DAYS = 7;
    
    // HR
    const PROBATION_PERIOD_MONTHS = 6;
    const NOTICE_PERIOD_DAYS = 30;
    const MAX_LEAVE_DAYS_PER_YEAR = 30;
    
    // System
    const MAINTENANCE_MODE = false;
    const DEBUG_MODE = false;
    const LOG_LEVEL = 'info';
    
    /**
     * Get application configuration
     */
    public static function getConfig()
    {
        return [
            'name' => self::NAME,
            'version' => self::VERSION,
            'description' => self::DESCRIPTION,
            'timezone' => self::DEFAULT_TIMEZONE,
            'locale' => self::DEFAULT_LOCALE,
            'currency' => self::DEFAULT_CURRENCY,
            'pagination' => [
                'default_page_size' => self::DEFAULT_PAGE_SIZE,
                'max_page_size' => self::MAX_PAGE_SIZE
            ],
            'upload' => [
                'max_size' => self::MAX_UPLOAD_SIZE,
                'allowed_image_types' => self::ALLOWED_IMAGE_TYPES,
                'allowed_document_types' => self::ALLOWED_DOCUMENT_TYPES
            ],
            'security' => [
                'password_min_length' => self::PASSWORD_MIN_LENGTH,
                'session_lifetime' => self::SESSION_LIFETIME,
                'login_attempts_limit' => self::LOGIN_ATTEMPTS_LIMIT,
                'login_lockout_time' => self::LOGIN_LOCKOUT_TIME
            ],
            'cache' => [
                'default_ttl' => self::CACHE_DEFAULT_TTL,
                'long_ttl' => self::CACHE_LONG_TTL
            ],
            'api' => [
                'version' => self::API_VERSION,
                'rate_limit' => self::API_RATE_LIMIT
            ],
            'academic' => [
                'year_start_month' => self::ACADEMIC_YEAR_START_MONTH,
                'default_grade_scale' => self::DEFAULT_GRADE_SCALE,
                'attendance_minimum_percentage' => self::ATTENDANCE_MINIMUM_PERCENTAGE
            ],
            'financial' => [
                'fee_due_days' => self::FEE_DUE_DAYS,
                'late_fee_percentage' => self::LATE_FEE_PERCENTAGE,
                'refund_processing_days' => self::REFUND_PROCESSING_DAYS
            ],
            'hr' => [
                'probation_period_months' => self::PROBATION_PERIOD_MONTHS,
                'notice_period_days' => self::NOTICE_PERIOD_DAYS,
                'max_leave_days_per_year' => self::MAX_LEAVE_DAYS_PER_YEAR
            ],
            'system' => [
                'maintenance_mode' => self::MAINTENANCE_MODE,
                'debug_mode' => self::DEBUG_MODE,
                'log_level' => self::LOG_LEVEL
            ]
        ];
    }
    
    /**
     * Check if application is in maintenance mode
     */
    public static function isMaintenanceMode()
    {
        return self::MAINTENANCE_MODE || 
               (isset($_ENV['MAINTENANCE_MODE']) && $_ENV['MAINTENANCE_MODE'] === 'true');
    }
    
    /**
     * Check if debug mode is enabled
     */
    public static function isDebugMode()
    {
        return self::DEBUG_MODE || 
               (isset($_ENV['APP_DEBUG']) && $_ENV['APP_DEBUG'] === 'true');
    }
    
    /**
     * Get environment
     */
    public static function getEnvironment()
    {
        return $_ENV['APP_ENV'] ?? self::ENV_PRODUCTION;
    }
    
    /**
     * Check if environment is production
     */
    public static function isProduction()
    {
        return self::getEnvironment() === self::ENV_PRODUCTION;
    }
    
    /**
     * Check if environment is development
     */
    public static function isDevelopment()
    {
        return self::getEnvironment() === self::ENV_DEVELOPMENT;
    }
}