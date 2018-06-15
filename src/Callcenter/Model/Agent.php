<?php

namespace Callcenter\Model;

class Agent
{
    public $agentid;
    public $status = "NA";
    public $queue;
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
     * @return string
     */
    public function getStatus() : string
    {
        return $this->status;
    }

    /**
     * @return int
     */
    public function getDuration() : int
    {
        return time() - $this->time;
    }

    /**
     * @return string
     */
    public function getAgentId() : string
    {
        return $this->agentid;
    }

    /**
     * @return string
     */
    public function getQueue() : string
    {
        return $this->queue;
    }

    public function setQueue($queue)
    {
        $this->queue = $queue;
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

        if ($status != 'INCALL') {
            $this->queue = "";
        }

        $this->status = $status;
        $this->time = time();

        return true;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return "{$this->agentid}|{$this->status}";
    }

    public function getReportLine()
    {
        $duration = time() - $this->time;

        return date('Y-m-d H:i:s').";AGENT;{$this->agentid};{$this->status};$duration;{$this->queue}";
    }
}