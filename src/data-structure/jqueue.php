<?php
class jQueue extends jDataStructures
{
    private $_head;
    public function __construct($data)
    {
        parent::__construct($data);
        $this->_head = array_key_first($data);
    }

    /**
     * put the element to the first position
     * @param mixed $elem
     * @return jQueue
     */
    public function push($elem)
    {
        array_push($this->_data, $elem);
        return $this;
    }

    /**
     * returns the element in the first position
     * @throws Exception
     * @return mixed
     */
    public function peek()
    {
        if ($this->isEmpty()) {
            throw new Exception('The Queue is Empty');
        }

        return $this->_data[$this->_head];
    }

    /**
     * remove the first element and returns it
     * @throws Exception
     * @return mixed
     */
    public function pop()
    {
        if ($this->isEmpty()) {
            throw new Exception('The Queue is Empty');
        }

        $head = $this->_data[$this->_head];
        unset($this->_data[$this->_head]);
        $this->_head = array_key_first($this->_data);

        return $head;
    }

    /**
     * check if the queue is empty
     * @return bool
     */
    public function isEmpty()
    {
        return empty($this->_data);
    }

    /**
     * returns the element against the key | index
     * @param mixed $key
     * @throws Exception
     * @return mixed
     */
    public function get($key)
    {
        if (!isset($this->_data[$key])) {
            throw new Exception('No value against the index/key');
        }

        return $this->_data[$key];
    }
}