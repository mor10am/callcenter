<?php

namespace Callcenter\Model;

class Agent
{
    public $agentid;
    public $status = "NA";
    public $time;

    /**
     * Agent constructor.
     * @param string $agentid
     */
    public function __construct(string $agentid)
    {
        $this->agentid = $agentid;
        $this->time = time();
    }

    /**
     * @param string $status
     */
    public function setStatus(string $status)
    {
        $status = strtoupper($status);

        if ($this->status == $status) {
            return;
        }

        $this->status = $status;
        $this->time = time();
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return "{$this->agentid}|{$this->status}";
    }
}