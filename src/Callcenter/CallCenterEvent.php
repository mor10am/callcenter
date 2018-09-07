<?php

namespace Callcenter;

class CallCenterEvent
{
    private $type;
    private $data;

    public function __construct(string $type, array $data)
    {
        $this->type = $type;
        $this->data = $data;
    }

    public function getType()
    {
        return $this->type;
    }

    public function __get($field)
    {
        if (!isset($this->data[$field])) {
            throw new \OutOfBoundsException("Field $field not found in event");
        }

        return $this->data[$field];
    }
}