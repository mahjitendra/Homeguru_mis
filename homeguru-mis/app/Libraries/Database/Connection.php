<?php

namespace App\Libraries\Database;

use App\Config\Database;
use PDO;
use PDOException;
use Exception;

class Connection
{
    private static $instance = null;
    private $pdo;
    private $config;
    private $queryLog = [];
    private $queryCount = 0;
    private $totalQueryTime = 0;
    
    private function __construct()
    {
        $this->config = Database::getConfig();
        $this->connect();
    }
    
    /**
     * Get singleton instance
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Establish database connection
     */
    private function connect()
    {
        try {
            $dsn = Database::getDsn();
            $config = Database::getConnection();
            $options = Database::getPdoOptions();
            
            $this->pdo = new PDO($dsn, $config['username'], $config['password'], $options);
            
            // Set additional PDO attributes
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            
        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }
    
    /**
     * Get PDO instance
     */
    public function getPdo()
    {
        return $this->pdo;
    }
    
    /**
     * Prepare a statement
     */
    public function prepare($sql)
    {
        $startTime = microtime(true);
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $this->logQuery($sql, [], microtime(true) - $startTime);
            return $stmt;
        } catch (PDOException $e) {
            $this->logQuery($sql, [], microtime(true) - $startTime, $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Execute a query
     */
    public function query($sql)
    {
        $startTime = microtime(true);
        
        try {
            $result = $this->pdo->query($sql);
            $this->logQuery($sql, [], microtime(true) - $startTime);
            return $result;
        } catch (PDOException $e) {
            $this->logQuery($sql, [], microtime(true) - $startTime, $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Execute a prepared statement
     */
    public function execute($sql, $params = [])
    {
        $startTime = microtime(true);
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute($params);
            $this->logQuery($sql, $params, microtime(true) - $startTime);
            return $stmt;
        } catch (PDOException $e) {
            $this->logQuery($sql, $params, microtime(true) - $startTime, $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Begin a transaction
     */
    public function beginTransaction()
    {
        return $this->pdo->beginTransaction();
    }
    
    /**
     * Commit a transaction
     */
    public function commit()
    {
        return $this->pdo->commit();
    }
    
    /**
     * Rollback a transaction
     */
    public function rollback()
    {
        return $this->pdo->rollback();
    }
    
    /**
     * Check if in transaction
     */
    public function inTransaction()
    {
        return $this->pdo->inTransaction();
    }
    
    /**
     * Get last insert ID
     */
    public function lastInsertId($name = null)
    {
        return $this->pdo->lastInsertId($name);
    }
    
    /**
     * Get row count
     */
    public function rowCount()
    {
        return $this->pdo->rowCount();
    }
    
    /**
     * Quote a string
     */
    public function quote($string, $type = PDO::PARAM_STR)
    {
        return $this->pdo->quote($string, $type);
    }
    
    /**
     * Get database version
     */
    public function getVersion()
    {
        return Database::getVersion();
    }
    
    /**
     * Check if connection is alive
     */
    public function isAlive()
    {
        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Reconnect to database
     */
    public function reconnect()
    {
        $this->pdo = null;
        $this->connect();
    }
    
    /**
     * Log a query
     */
    private function logQuery($sql, $params = [], $time = 0, $error = null)
    {
        $this->queryCount++;
        $this->totalQueryTime += $time;
        
        $this->queryLog[] = [
            'sql' => $sql,
            'params' => $params,
            'time' => $time,
            'error' => $error,
            'timestamp' => microtime(true)
        ];
        
        // Log slow queries
        if ($time > $this->config['query']['slow_threshold']) {
            error_log("Slow query detected ({$time}s): {$sql}");
        }
    }
    
    /**
     * Get query log
     */
    public function getQueryLog()
    {
        return $this->queryLog;
    }
    
    /**
     * Get query count
     */
    public function getQueryCount()
    {
        return $this->queryCount;
    }
    
    /**
     * Get total query time
     */
    public function getTotalQueryTime()
    {
        return $this->totalQueryTime;
    }
    
    /**
     * Clear query log
     */
    public function clearQueryLog()
    {
        $this->queryLog = [];
        $this->queryCount = 0;
        $this->totalQueryTime = 0;
    }
    
    /**
     * Get database configuration
     */
    public function getConfig()
    {
        return $this->config;
    }
    
    /**
     * Run a raw SQL query
     */
    public function raw($sql, $params = [])
    {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get a single row
     */
    public function fetchOne($sql, $params = [])
    {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get all rows
     */
    public function fetchAll($sql, $params = [])
    {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get a single column
     */
    public function fetchColumn($sql, $params = [], $column = 0)
    {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetchColumn($column);
    }
    
    /**
     * Get a single value
     */
    public function fetchValue($sql, $params = [])
    {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetchColumn(0);
    }
    
    /**
     * Insert a record
     */
    public function insert($table, $data)
    {
        $columns = array_keys($data);
        $values = array_values($data);
        $placeholders = str_repeat('?,', count($values) - 1) . '?';
        
        $sql = "INSERT INTO {$table} (" . implode(',', $columns) . ") VALUES ({$placeholders})";
        
        $stmt = $this->execute($sql, $values);
        return $this->lastInsertId();
    }
    
    /**
     * Update records
     */
    public function update($table, $data, $where, $whereParams = [])
    {
        $set = [];
        $values = [];
        
        foreach ($data as $column => $value) {
            $set[] = "{$column} = ?";
            $values[] = $value;
        }
        
        $sql = "UPDATE {$table} SET " . implode(', ', $set) . " WHERE {$where}";
        $values = array_merge($values, $whereParams);
        
        $stmt = $this->execute($sql, $values);
        return $stmt->rowCount();
    }
    
    /**
     * Delete records
     */
    public function delete($table, $where, $whereParams = [])
    {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        
        $stmt = $this->execute($sql, $whereParams);
        return $stmt->rowCount();
    }
    
    /**
     * Check if table exists
     */
    public function tableExists($table)
    {
        $sql = "SHOW TABLES LIKE ?";
        $result = $this->fetchOne($sql, [$table]);
        return !empty($result);
    }
    
    /**
     * Get table columns
     */
    public function getTableColumns($table)
    {
        $sql = "DESCRIBE {$table}";
        return $this->fetchAll($sql);
    }
    
    /**
     * Get table indexes
     */
    public function getTableIndexes($table)
    {
        $sql = "SHOW INDEX FROM {$table}";
        return $this->fetchAll($sql);
    }
    
    /**
     * Get database size
     */
    public function getDatabaseSize()
    {
        $sql = "SELECT 
                    ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'DB Size in MB'
                FROM information_schema.tables 
                WHERE table_schema = ?";
        
        return $this->fetchValue($sql, [$this->config['connections']['mysql']['database']]);
    }
    
    /**
     * Get table size
     */
    public function getTableSize($table)
    {
        $sql = "SELECT 
                    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'Table Size in MB'
                FROM information_schema.TABLES 
                WHERE table_schema = ? AND table_name = ?";
        
        return $this->fetchValue($sql, [$this->config['connections']['mysql']['database'], $table]);
    }
    
    /**
     * Optimize table
     */
    public function optimizeTable($table)
    {
        $sql = "OPTIMIZE TABLE {$table}";
        return $this->execute($sql);
    }
    
    /**
     * Repair table
     */
    public function repairTable($table)
    {
        $sql = "REPAIR TABLE {$table}";
        return $this->execute($sql);
    }
    
    /**
     * Check table status
     */
    public function checkTable($table)
    {
        $sql = "CHECK TABLE {$table}";
        return $this->fetchAll($sql);
    }
    
    /**
     * Get process list
     */
    public function getProcessList()
    {
        $sql = "SHOW PROCESSLIST";
        return $this->fetchAll($sql);
    }
    
    /**
     * Kill a process
     */
    public function killProcess($processId)
    {
        $sql = "KILL ?";
        return $this->execute($sql, [$processId]);
    }
    
    /**
     * Get server status
     */
    public function getServerStatus()
    {
        $sql = "SHOW STATUS";
        $result = $this->fetchAll($sql);
        
        $status = [];
        foreach ($result as $row) {
            $status[$row['Variable_name']] = $row['Value'];
        }
        
        return $status;
    }
    
    /**
     * Get server variables
     */
    public function getServerVariables()
    {
        $sql = "SHOW VARIABLES";
        $result = $this->fetchAll($sql);
        
        $variables = [];
        foreach ($result as $row) {
            $variables[$row['Variable_name']] = $row['Value'];
        }
        
        return $variables;
    }
    
    /**
     * Prevent cloning
     */
    private function __clone() {}
    
    /**
     * Prevent unserialization
     */
    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton");
    }
}