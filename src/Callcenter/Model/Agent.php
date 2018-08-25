<?php
declare(strict_types=1);

namespace Callcenter\Model;

class Agent implements \JsonSerializable
{
    /**
     * @var string
     */
    public $agentid;

    /**
     * @var string
     */
    public $status = "NA";

    /**
     * @var string
     */
    public $queue = "";

    /**
     * @var int
     */
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

    /**
     * @var string $queue
     */
    public function setQueue(string $queue) : void
    {
        $this->queue = $queue;
    }

    /**
     * @param string $status
     * @return bool
     */
    public function setStatus(string $status) : bool
    {
        $status = strtoupper($status);

        if ($this->status == $status) {
            return false;
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
    public function __toString() : string
    {
        return "{$this->agentid}|{$this->status}";
    }

    /**
     * @return array
     */
    public function jsonSerialize()
    {
        return [
            'id' => $this->agentid,
            'status' => $this->status,
            'queue' => $this->queue,
            'time' => $this->time,
        ];
    }

    /**
     * @return string
     */
    public function getReportLine() : string
    {
        $ts = time();

        $duration = $ts - $this->time;
        $at = $ts - $duration;

        return date('Y-m-d H:i:s', $at).";AGENT;{$this->agentid};{$this->status};$duration;{$this->queue}";
    }
}
