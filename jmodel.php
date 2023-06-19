<?php
class JamburaValidationError extends Exception{};
class JamburaSystemError extends Exception{};
class jModel
{
    protected $table;
    protected $tableName;
    protected $count = false;
    protected $eachCount = 0;
    protected $relations = array();

    private $reldata = array();
    private $changed = false;
    private $changeLog = array();

    public function __construct($id = 0)
    {
        $child = get_class($this);
        # FIXME this will cause problems if a table name comes
        # with underscore(_), explode should be replaced by a 
        # better logic considering underscores in table names
        $tableName = $this->tableName ? $this->tableName : explode('_', $child)[1];
        if ($id) {
            $this->table = ORM::for_table($tableName)->find_one($id);
            $this->loadRelations($id);
        } else {
            $this->table = ORM::for_table($tableName)->create();
        }
    }

    protected function validation()
    {
        return [];
    }

    /**
     * Loads ORM models if relations are defined.
     *
     * Loads ORM objects based on configuration. There can have two types of
     * directions :
     * 1. p2c (parent to child): The primary key of the current row is used as a foriegn
     * key in another table. p2c can be of two types:
     * 1-1 (one to one)  : loads only one column of destination table. 
     * 1-M (one to many ): loads all matching rows in destination column.
     * 2. c2p (child to parent): This table is using the primary key of of another table.
     * No type field required for this direction.
     *
     * @param int $id id of column
     */
    protected function loadRelations($id)
    {
        if (empty($this->relations)) {
            return;
        }

        foreach ($this->relations as $key => $info) {
            $direction = isset($info['direction']) ? $info['direction'] : 'p2c';
            if ($direction == 'p2c') {
                $table = ORM::for_table($info['table'])
                    ->where($info['column'], $id);
            } elseif ($direction == 'c2p') {
                $this->reldata[$key] = isset($info['dest'])
                    ? ORM::for_table($info['table'])
                        ->where($info['dest'], $this->table->{$info['column']})
                        ->find_one()
                    : ORM::for_table($info['table'])
                        ->find_one($this->table->{$info['column']});
                continue;
            } else {
                continue;
            }

            if ($info['type'] == '1-1') {
                $data = $table->find_one();
            } elseif ($info['type'] == '1-M') {
                $data = $table->find_many();
            }
            $this->reldata[$key] = $data;
        }
    }

    public function __get($column)
    {
        if (isset($this->table->$column)) {
            return $this->table->$column;
        } elseif (isset($this->reldata[$column])) {
            return $this->reldata[$column];
        }
        return false;
    }

    public function __set($column, $value)
    {
        if (isset($this->validation()[$column])) {
            $this->validateColumn($column, $value);
        }
        if ($this->table->id() && ($this->table->$column != $value)) {
            $this->changed = true;
            $this->changeLog[$column] = [
                'oldValue' => $this->table->$column,
                'newValue' => $value
            ];
        }

        $this->table->$column = $value;
    }

    public function set($col, $value)
    {
        $this->table->$col = $value;
        return $this;
    }

    public function isChanged()
    {
        return $this->changed;
    }

    public function diff()
    {
        return $this->changeLog;
    }

    public function __isset($column)
    {
        return isset($this->table->$column);
    }

    public function __call($name, $args)
    {
        if (preg_match('/^loadBy([a-zA-Z_]+)$/', $name, $matches)) {
            return $this->loadBy($matches[1], $args[0]);
        }
    }

    public static function instance($table, $id = 0)
    {
        $class = 'Model_' . $table;
        if (!class_exists($class)) {
            throw new Exception("No model for the table $table found");
        }
        if ($id) {
            $model = new $class($id);
        } else {
            $model = new $class();
        }
        return $model;
    }

    public static function factory($table, $id = 0)
    {
        return self::instance($table, $id);
    }

    public function loadBy($col, $value)
    {
        $row = $this->table->where($col, $value)->find_one();
        if (is_object($row)) {
            $this->count = 1;
            $this->table = $row;
            $this->loadRelations($row->id);
        } else {
            $this->count = 0;
        }
        return $this;
    }

    public function get($col)
    {
        return $this->table->$col;
    }

    // FIXME this probably wont give right result
    // need to fix it
    public function count()
    {
        if ($this->count === false) {
            if (is_object($this->table)) {
                $this->count = 1;
            } elseif (is_array($this->table)) {
                $this->count = count($this->table);
            } else {
                $this->count = 0;
            }
        }
        return $this->count;
    }

    public function loaded()
    {
        if ($this->count()) {
            return true;
        }
        return false;
    }

    public function each()
    {
        if ($this->count() && is_array($this->table)) {
            $col = each($this->table);
            //print_r($col);
            return $col === false ? $col : $col[1];
        } elseif (
            $this->count()
            && is_object($this->table)
            && ($this->count - $this->eachCount++)
        ) {
            return $this->table;
        }
        return false;
    }

    public function reset()
    {
        if (is_array($this->table)) {
            reset($this->table);
        }
    }

    public function add($data)
    {
        if (!is_array($data)) {
            throw new Exception('Data must be supplied as array');
        }
        $this->table->create();
        foreach ($data as $column => $value) {
            // If passed as array then we consider that it need to execute mysql function
            // first argument will be column and second argument will be expression
            if (is_array($value)) {
                $this->table->set_expr($value[0], $value[1]);
            } else {
                $this->table->$column = $value;
            }
        }
        $this->table->save();
    }

    public function delete()
    {
        $this->table->delete();
    }

    protected function beforeSave()
    {
        return;
    }

    protected function afterSave()
    {
        return;
    }

    private function validateColumn($column, $value)
    {
        $validationType = $this->validation()[$column][0];

        switch ($validationType) {
            case 'regex':
                $pattern = $this->validation()[$column][1];
                if (!preg_match($pattern, $value)) {
                    $errorMessage = isset($this->validation()[$column][2])
                        ? $this->validation()[$column][2]
                        : "$column value $value does not match required pattern $pattern";
                    throw new JamburaValidationError($errorMessage);
                }
                break;
            case 'function':
                $functionName = $this->validation()[$column][1];
                if (!$this->$functionName($value)) {
                    $errorMessage = isset($this->validation()[$column][2])
                        ? $this->validation()[$column][2]
                        : "$column value $value failed to validate";
                    throw new JamburaValidationError($errorMessage);
                }
                break;
            default:
                throw new JamburaSystemError("Unknown validation type $validationType");
        }
    }

    public function save()
    {
        $this->beforeSave();
        if ($this->table->save()) {
            $this->afterSave();
            return $this->table->id();
        }
        return false;
    }
    /**
     * checks if the record already exists in the table or not
     * 
     * if the record exists, it returns false
     * if a new record is added, it returns true
     * 
     * @return bool
     */
    public function isNewRecord(): bool
    {
        return $this->table->id() === null;
    }
    
    public function resetCounter()
    {
        $this->count = false;
    }
    /**
     * Set expression to execute value as row function of sql
     * @param string $key column name
     * @param string $value string with expression
     */
    public function set_expr($key, $value)
    {
        $this->table->set_expr($key, $value);
    }

    /**
     * find and return all the rows according to the given type.
     * 
     * if nothing is given in the params, it will simply return an Idiorm Object.
     * if the given type is stack, it will return jStack containing the found data.
     * if the given type is queue, it will return jQueue containing the found data.
     * 
     * @param string $type
     * 
     * @return mixed
     */
    public function findAll($type = 'array')
    {
        $data = $this->table->find_many();
        switch (strtolower($type)) {
            case 'stack':
                $data = new jStack($data);
                break;
            case 'queue':
                $data = new jQueue($data);
                break;
        }
        return $data;
    }
}