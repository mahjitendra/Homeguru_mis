<?php

namespace App\Config;

class Database
{
    // Database Configuration
    const DEFAULT_CONNECTION = 'mysql';
    const DEFAULT_HOST = 'localhost';
    const DEFAULT_PORT = 3306;
    const DEFAULT_CHARSET = 'utf8mb4';
    const DEFAULT_COLLATION = 'utf8mb4_unicode_ci';
    
    // Connection Pool Settings
    const MAX_CONNECTIONS = 100;
    const MIN_CONNECTIONS = 5;
    const CONNECTION_TIMEOUT = 30;
    const IDLE_TIMEOUT = 300;
    
    // Query Settings
    const QUERY_TIMEOUT = 30;
    const MAX_QUERY_SIZE = 1048576; // 1MB
    const SLOW_QUERY_THRESHOLD = 2; // seconds
    
    // Transaction Settings
    const TRANSACTION_TIMEOUT = 60;
    const MAX_RETRY_ATTEMPTS = 3;
    
    // Cache Settings
    const QUERY_CACHE_TTL = 3600;
    const SCHEMA_CACHE_TTL = 86400;
    
    /**
     * Get database configuration
     */
    public static function getConfig()
    {
        return [
            'default' => $_ENV['DB_CONNECTION'] ?? self::DEFAULT_CONNECTION,
            'connections' => [
                'mysql' => [
                    'driver' => 'mysql',
                    'host' => $_ENV['DB_HOST'] ?? self::DEFAULT_HOST,
                    'port' => $_ENV['DB_PORT'] ?? self::DEFAULT_PORT,
                    'database' => $_ENV['DB_DATABASE'] ?? 'homeguru_mis',
                    'username' => $_ENV['DB_USERNAME'] ?? 'root',
                    'password' => $_ENV['DB_PASSWORD'] ?? '',
                    'charset' => self::DEFAULT_CHARSET,
                    'collation' => self::DEFAULT_COLLATION,
                    'prefix' => '',
                    'strict' => true,
                    'engine' => 'InnoDB',
                    'options' => [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . self::DEFAULT_CHARSET
                    ]
                ],
                'pgsql' => [
                    'driver' => 'pgsql',
                    'host' => $_ENV['DB_HOST'] ?? 'localhost',
                    'port' => $_ENV['DB_PORT'] ?? 5432,
                    'database' => $_ENV['DB_DATABASE'] ?? 'homeguru_mis',
                    'username' => $_ENV['DB_USERNAME'] ?? 'postgres',
                    'password' => $_ENV['DB_PASSWORD'] ?? '',
                    'charset' => 'utf8',
                    'prefix' => '',
                    'schema' => 'public'
                ],
                'sqlite' => [
                    'driver' => 'sqlite',
                    'database' => $_ENV['DB_DATABASE'] ?? storage_path('database.sqlite'),
                    'prefix' => ''
                ]
            ],
            'migration_table' => 'migrations',
            'migration_path' => base_path('database/migrations'),
            'seeder_path' => base_path('database/seeds'),
            'pool' => [
                'max_connections' => self::MAX_CONNECTIONS,
                'min_connections' => self::MIN_CONNECTIONS,
                'connection_timeout' => self::CONNECTION_TIMEOUT,
                'idle_timeout' => self::IDLE_TIMEOUT
            ],
            'query' => [
                'timeout' => self::QUERY_TIMEOUT,
                'max_size' => self::MAX_QUERY_SIZE,
                'slow_threshold' => self::SLOW_QUERY_THRESHOLD
            ],
            'transaction' => [
                'timeout' => self::TRANSACTION_TIMEOUT,
                'max_retry_attempts' => self::MAX_RETRY_ATTEMPTS
            ],
            'cache' => [
                'query_ttl' => self::QUERY_CACHE_TTL,
                'schema_ttl' => self::SCHEMA_CACHE_TTL
            ]
        ];
    }
    
    /**
     * Get connection configuration by name
     */
    public static function getConnection($name = null)
    {
        $config = self::getConfig();
        $connectionName = $name ?? $config['default'];
        
        if (!isset($config['connections'][$connectionName])) {
            throw new \InvalidArgumentException("Database connection '{$connectionName}' not found.");
        }
        
        return $config['connections'][$connectionName];
    }
    
    /**
     * Get DSN string for PDO connection
     */
    public static function getDsn($connection = null)
    {
        $config = self::getConnection($connection);
        
        switch ($config['driver']) {
            case 'mysql':
                return "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset={$config['charset']}";
            
            case 'pgsql':
                return "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}";
            
            case 'sqlite':
                return "sqlite:{$config['database']}";
            
            default:
                throw new \InvalidArgumentException("Unsupported database driver: {$config['driver']}");
        }
    }
    
    /**
     * Get PDO options for connection
     */
    public static function getPdoOptions($connection = null)
    {
        $config = self::getConnection($connection);
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ];
        
        if (isset($config['options'])) {
            $options = array_merge($options, $config['options']);
        }
        
        return $options;
    }
    
    /**
     * Check if database connection is available
     */
    public static function isAvailable($connection = null)
    {
        try {
            $dsn = self::getDsn($connection);
            $config = self::getConnection($connection);
            $options = self::getPdoOptions($connection);
            
            $pdo = new \PDO($dsn, $config['username'], $config['password'], $options);
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }
    
    /**
     * Get database version
     */
    public static function getVersion($connection = null)
    {
        try {
            $dsn = self::getDsn($connection);
            $config = self::getConnection($connection);
            $options = self::getPdoOptions($connection);
            
            $pdo = new \PDO($dsn, $config['username'], $config['password'], $options);
            
            switch ($config['driver']) {
                case 'mysql':
                    $stmt = $pdo->query('SELECT VERSION() as version');
                    break;
                case 'pgsql':
                    $stmt = $pdo->query('SELECT version() as version');
                    break;
                case 'sqlite':
                    $stmt = $pdo->query('SELECT sqlite_version() as version');
                    break;
                default:
                    return 'Unknown';
            }
            
            $result = $stmt->fetch();
            return $result['version'] ?? 'Unknown';
        } catch (\PDOException $e) {
            return 'Unknown';
        }
    }
}