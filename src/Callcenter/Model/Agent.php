<?php
declare(strict_types=1);

namespace Callcenter\Model;

final class Agent implements \JsonSerializable
{
    /**
     * @var string
     */
    public $agentid;

    /**
     * @var string
     */
    public $member;

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
     *
     * @param string $agentid
     * @param string $member
     */
    public function __construct(string $agentid, string $member = null)
    {
        $this->agentid = $agentid;

        if ($member) {
            $this->member = $member;
        }

        $this->time = time();
    }

    /**
     * @param string $member
     */
    public function setMember(string $member) : void
    {
        $this->member = $member;
    }

    /**
     * @return string
     */
    public function getMember() : string
    {
        return $this->member;
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
            'type' => 'AGENT',
            'agentid' => $this->agentid,
            'status' => $this->status,
            'queue' => $this->queue,
            'time' => $this->time,
        ];
    }

    /**
     * @return Report
     */
    public function getReport() : Report
    {
        $ts = time();

        $duration = $ts - $this->time;
        $at = $ts - $duration;

        $report = new Report();
        $report->type = 'AGENT';
        $report->id = $this->agentid;
        $report->status = $this->status;
        $report->timestamp = date('Y-m-d H:i:s', $at);
        $report->duration = $duration;
        $report->queue = $this->queue;

        return $report;
    }
}
