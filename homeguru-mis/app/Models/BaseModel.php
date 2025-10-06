<?php

namespace App\Models;

use App\Libraries\Database\Connection;
use App\Traits\Timestamp;
use App\Traits\SoftDelete;
use App\Traits\Uuid;
use App\Traits\Auditable;
use PDO;
use PDOStatement;

abstract class BaseModel
{
    use Timestamp, SoftDelete, Uuid, Auditable;
    
    protected $connection;
    protected $table;
    protected $primaryKey = 'id';
    protected $fillable = [];
    protected $hidden = [];
    protected $casts = [];
    protected $dates = ['created_at', 'updated_at', 'deleted_at'];
    protected $attributes = [];
    protected $original = [];
    protected $exists = false;
    protected $wasRecentlyCreated = false;
    
    // Query builder properties
    protected $select = [];
    protected $where = [];
    protected $orderBy = [];
    protected $groupBy = [];
    protected $having = [];
    protected $limit = null;
    protected $offset = null;
    protected $joins = [];
    
    public function __construct(array $attributes = [])
    {
        $this->connection = Connection::getInstance();
        $this->fill($attributes);
    }
    
    /**
     * Fill the model with an array of attributes
     */
    public function fill(array $attributes)
    {
        foreach ($attributes as $key => $value) {
            if (in_array($key, $this->fillable) || empty($this->fillable)) {
                $this->setAttribute($key, $value);
            }
        }
        return $this;
    }
    
    /**
     * Set a given attribute on the model
     */
    public function setAttribute($key, $value)
    {
        // Apply casts
        if (isset($this->casts[$key])) {
            $value = $this->castAttribute($key, $value);
        }
        
        $this->attributes[$key] = $value;
        return $this;
    }
    
    /**
     * Get an attribute from the model
     */
    public function getAttribute($key)
    {
        if (array_key_exists($key, $this->attributes)) {
            return $this->attributes[$key];
        }
        
        // Check for accessor methods
        $method = 'get' . studly_case($key) . 'Attribute';
        if (method_exists($this, $method)) {
            return $this->$method();
        }
        
        return null;
    }
    
    /**
     * Cast an attribute to a native PHP type
     */
    protected function castAttribute($key, $value)
    {
        if (is_null($value)) {
            return $value;
        }
        
        switch ($this->casts[$key]) {
            case 'int':
            case 'integer':
                return (int) $value;
            case 'real':
            case 'float':
            case 'double':
                return (float) $value;
            case 'string':
                return (string) $value;
            case 'bool':
            case 'boolean':
                return (bool) $value;
            case 'object':
                return json_decode($value, true);
            case 'array':
            case 'json':
                return json_decode($value, true);
            case 'date':
                return $this->asDate($value);
            case 'datetime':
                return $this->asDateTime($value);
            case 'timestamp':
                return $this->asTimestamp($value);
            default:
                return $value;
        }
    }
    
    /**
     * Save the model to the database
     */
    public function save(array $options = [])
    {
        if ($this->exists) {
            return $this->performUpdate();
        } else {
            return $this->performInsert();
        }
    }
    
    /**
     * Perform a model insert operation
     */
    protected function performInsert()
    {
        $this->setTimestamps();
        $this->setUuid();
        
        $attributes = $this->getAttributes();
        $attributes = $this->getDirtyAttributes($attributes);
        
        if (empty($attributes)) {
            return true;
        }
        
        $sql = $this->buildInsertQuery($attributes);
        $values = array_values($attributes);
        
        $stmt = $this->connection->prepare($sql);
        $result = $stmt->execute($values);
        
        if ($result) {
            $this->exists = true;
            $this->wasRecentlyCreated = true;
            $this->setAttribute($this->primaryKey, $this->connection->lastInsertId());
            $this->syncOriginal();
        }
        
        return $result;
    }
    
    /**
     * Perform a model update operation
     */
    protected function performUpdate()
    {
        $this->setTimestamps();
        
        $attributes = $this->getAttributes();
        $attributes = $this->getDirtyAttributes($attributes);
        
        if (empty($attributes)) {
            return true;
        }
        
        $sql = $this->buildUpdateQuery($attributes);
        $values = array_values($attributes);
        $values[] = $this->getAttribute($this->primaryKey);
        
        $stmt = $this->connection->prepare($sql);
        $result = $stmt->execute($values);
        
        if ($result) {
            $this->syncOriginal();
        }
        
        return $result;
    }
    
    /**
     * Build insert query
     */
    protected function buildInsertQuery(array $attributes)
    {
        $columns = implode(', ', array_keys($attributes));
        $placeholders = implode(', ', array_fill(0, count($attributes), '?'));
        
        return "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})";
    }
    
    /**
     * Build update query
     */
    protected function buildUpdateQuery(array $attributes)
    {
        $set = [];
        foreach (array_keys($attributes) as $column) {
            $set[] = "{$column} = ?";
        }
        $setClause = implode(', ', $set);
        
        return "UPDATE {$this->table} SET {$setClause} WHERE {$this->primaryKey} = ?";
    }
    
    /**
     * Get dirty attributes
     */
    protected function getDirtyAttributes(array $attributes)
    {
        $dirty = [];
        
        foreach ($attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }
        
        return $dirty;
    }
    
    /**
     * Sync the original attributes with the current
     */
    public function syncOriginal()
    {
        $this->original = $this->attributes;
        return $this;
    }
    
    /**
     * Get all attributes
     */
    public function getAttributes()
    {
        return $this->attributes;
    }
    
    /**
     * Get the model's attributes as an array
     */
    public function toArray()
    {
        $array = $this->attributes;
        
        // Remove hidden attributes
        foreach ($this->hidden as $key) {
            unset($array[$key]);
        }
        
        // Apply accessors
        foreach ($this->attributes as $key => $value) {
            $method = 'get' . studly_case($key) . 'Attribute';
            if (method_exists($this, $method)) {
                $array[$key] = $this->$method();
            }
        }
        
        return $array;
    }
    
    /**
     * Convert the model to its string representation
     */
    public function toJson($options = 0)
    {
        return json_encode($this->toArray(), $options);
    }
    
    /**
     * Find a model by its primary key
     */
    public static function find($id)
    {
        $instance = new static();
        $sql = "SELECT * FROM {$instance->table} WHERE {$instance->primaryKey} = ?";
        
        $stmt = $instance->connection->prepare($sql);
        $stmt->execute([$id]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $instance->fill($result);
            $instance->exists = true;
            $instance->syncOriginal();
            return $instance;
        }
        
        return null;
    }
    
    /**
     * Find all models
     */
    public static function all()
    {
        $instance = new static();
        $sql = "SELECT * FROM {$instance->table}";
        
        $stmt = $instance->connection->prepare($sql);
        $stmt->execute();
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $models = [];
        
        foreach ($results as $result) {
            $model = new static();
            $model->fill($result);
            $model->exists = true;
            $model->syncOriginal();
            $models[] = $model;
        }
        
        return $models;
    }
    
    /**
     * Create a new model instance
     */
    public static function create(array $attributes = [])
    {
        $model = new static($attributes);
        $model->save();
        return $model;
    }
    
    /**
     * Update a model
     */
    public function update(array $attributes = [])
    {
        $this->fill($attributes);
        return $this->save();
    }
    
    /**
     * Delete the model from the database
     */
    public function delete()
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
     * Begin querying the model
     */
    public static function query()
    {
        return new static();
    }
    
    /**
     * Add a basic where clause to the query
     */
    public function where($column, $operator = null, $value = null)
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }
        
        $this->where[] = [
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => 'AND'
        ];
        
        return $this;
    }
    
    /**
     * Add an "or where" clause to the query
     */
    public function orWhere($column, $operator = null, $value = null)
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }
        
        $this->where[] = [
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => 'OR'
        ];
        
        return $this;
    }
    
    /**
     * Add an order by clause to the query
     */
    public function orderBy($column, $direction = 'asc')
    {
        $this->orderBy[] = [
            'column' => $column,
            'direction' => strtoupper($direction)
        ];
        
        return $this;
    }
    
    /**
     * Set the limit and offset for the query
     */
    public function limit($value)
    {
        $this->limit = $value;
        return $this;
    }
    
    /**
     * Set the offset for the query
     */
    public function offset($value)
    {
        $this->offset = $value;
        return $this;
    }
    
    /**
     * Execute the query and get the results
     */
    public function get()
    {
        $sql = $this->buildSelectQuery();
        $values = $this->getWhereValues();
        
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($values);
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $models = [];
        
        foreach ($results as $result) {
            $model = new static();
            $model->fill($result);
            $model->exists = true;
            $model->syncOriginal();
            $models[] = $model;
        }
        
        return $models;
    }
    
    /**
     * Execute the query and get the first result
     */
    public function first()
    {
        $this->limit(1);
        $results = $this->get();
        return !empty($results) ? $results[0] : null;
    }
    
    /**
     * Build the select query
     */
    protected function buildSelectQuery()
    {
        $select = !empty($this->select) ? implode(', ', $this->select) : '*';
        $sql = "SELECT {$select} FROM {$this->table}";
        
        if (!empty($this->joins)) {
            foreach ($this->joins as $join) {
                $sql .= " {$join['type']} JOIN {$join['table']} ON {$join['first']} {$join['operator']} {$join['second']}";
            }
        }
        
        if (!empty($this->where)) {
            $sql .= ' WHERE ' . $this->buildWhereClause();
        }
        
        if (!empty($this->groupBy)) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }
        
        if (!empty($this->having)) {
            $sql .= ' HAVING ' . $this->buildHavingClause();
        }
        
        if (!empty($this->orderBy)) {
            $orderBy = [];
            foreach ($this->orderBy as $order) {
                $orderBy[] = "{$order['column']} {$order['direction']}";
            }
            $sql .= ' ORDER BY ' . implode(', ', $orderBy);
        }
        
        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }
        
        if ($this->offset !== null) {
            $sql .= " OFFSET {$this->offset}";
        }
        
        return $sql;
    }
    
    /**
     * Build the where clause
     */
    protected function buildWhereClause()
    {
        $clauses = [];
        
        foreach ($this->where as $index => $where) {
            if ($index === 0) {
                $clauses[] = "{$where['column']} {$where['operator']} ?";
            } else {
                $clauses[] = "{$where['boolean']} {$where['column']} {$where['operator']} ?";
            }
        }
        
        return implode(' ', $clauses);
    }
    
    /**
     * Build the having clause
     */
    protected function buildHavingClause()
    {
        $clauses = [];
        
        foreach ($this->having as $index => $having) {
            if ($index === 0) {
                $clauses[] = "{$having['column']} {$having['operator']} ?";
            } else {
                $clauses[] = "{$having['boolean']} {$having['column']} {$having['operator']} ?";
            }
        }
        
        return implode(' ', $clauses);
    }
    
    /**
     * Get where values
     */
    protected function getWhereValues()
    {
        $values = [];
        
        foreach ($this->where as $where) {
            $values[] = $where['value'];
        }
        
        return $values;
    }
    
    /**
     * Get the table name
     */
    public function getTable()
    {
        return $this->table;
    }
    
    /**
     * Get the primary key
     */
    public function getKeyName()
    {
        return $this->primaryKey;
    }
    
    /**
     * Get the primary key value
     */
    public function getKey()
    {
        return $this->getAttribute($this->primaryKey);
    }
    
    /**
     * Check if the model exists
     */
    public function exists()
    {
        return $this->exists;
    }
    
    /**
     * Magic method to get attributes
     */
    public function __get($key)
    {
        return $this->getAttribute($key);
    }
    
    /**
     * Magic method to set attributes
     */
    public function __set($key, $value)
    {
        $this->setAttribute($key, $value);
    }
    
    /**
     * Magic method to check if attribute exists
     */
    public function __isset($key)
    {
        return isset($this->attributes[$key]);
    }
    
    /**
     * Magic method to unset attribute
     */
    public function __unset($key)
    {
        unset($this->attributes[$key]);
    }
    
    /**
     * Magic method for method calls
     */
    public function __call($method, $parameters)
    {
        // Handle dynamic method calls
        if (strpos($method, 'where') === 0) {
            $column = snake_case(substr($method, 5));
            return $this->where($column, $parameters[0] ?? null);
        }
        
        throw new \BadMethodCallException("Method {$method} not found on " . get_class($this));
    }
    
    /**
     * Magic method for static method calls
     */
    public static function __callStatic($method, $parameters)
    {
        $instance = new static();
        return call_user_func_array([$instance, $method], $parameters);
    }
}

/**
 * Helper function to convert string to snake_case
 */
function snake_case($value)
{
    return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $value));
}

/**
 * Helper function to convert string to studly_case
 */
function studly_case($value)
{
    return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
}