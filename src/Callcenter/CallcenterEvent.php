<?php declare(strict_types=1);

namespace Callcenter;

final class CallcenterEvent
{
    /**
     * @var string
     */
    private $type;

    /**
     * @var array
     */
    private $data;

    /**
     * @param string $type
     * @param array $data
     */
    public function __construct(string $type, array $data)
    {
        $this->type = $type;
        $this->data = $data;
    }

    /**
     * @return string
     */
    public function getType() : string
    {
        return $this->type;
    }

    /**
     * @param string $field
     * @return mixed
     */
    public function __get(string $field)
    {
        if (!isset($this->data[$field])) {
            throw new \OutOfBoundsException("Field $field not found in event");
        }

        return $this->data[$field];
    }

    /**
     * @param string $field
     * @param mixed $default
     * @return mixed
     */
    public function get(string $field, $default = null)
    {
        if (!isset($this->data[$field])) {
            return $default;
        }

        return $this->data[$field];
    }
}