<?php
class jStack extends jDataStructures
{
    /**
     * put the element to the last position
     * @param mixed $elem
     * @return jStack
     */
    public function push($elem)
    {
        array_push($this->_data, $elem);
        return $this;
    }

    /**
     * returns the element in the last position
     * @throws Exception
     * @return mixed
     */
    public function peek()
    {
        if ($this->isEmpty()) {
            throw new Exception('The Stack is Empty');
        }

        return $this->_data[array_key_last($this->_data)];
    }

    /**
     * remove the last element and returns it
     * @throws Exception
     * @return mixed
     */
    public function pop()
    {
        if ($this->isEmpty()) {
            throw new Exception('The Stack is Empty');
        }

        return array_pop($this->_data);
    }

    /**
     * check if the stack is empty
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