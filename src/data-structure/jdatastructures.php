<?php
abstract class jDataStructures
{
    protected $_data;

    public function __construct($data)
    {
        $this->_data = $data;
    }

    public abstract function push($elem);
    public abstract function peek();
    public abstract function isEmpty();
    public abstract function pop();
    public abstract function get($key);
}