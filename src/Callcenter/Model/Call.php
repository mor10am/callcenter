<?php
declare(strict_types=1);

namespace Callcenter\Model;

final class Call implements \JsonSerializable
{
    /**
     * @var string
     */
    public $callerid;

    /**
     * @var string
     */
    public $uid;

    /**
     * @var string
     */
    public $queue = '';

    /**
     * @var string
     */
    public $status = "NA";

    /**
     * @var int
     */
    public $time;

    /**
     * Caller constructor.
     * @param string $callerid
     * @param string $uid
     */
    public function __construct(string $callerid, string $uid)
    {
        $this->callerid = $callerid;
        $this->uid = $uid;

        $this->time = time();
    }

    /**
     * @param string $queue
     */
    public function setQueue(string $queue) : void
    {
        $this->queue = $queue;
        $this->setStatus('QUEUED');
    }

    /**
     * @return string
     */
    public function getStatus() : string
    {
        return $this->status;
    }

    /**
     * @return string
     */
    public function getQueue() : string
    {
        return $this->queue;
    }

    /**
     * @return int
     */
    public function getDuration() : int
    {
        return time() - $this->time;
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

        $this->status = $status;
        $this->time = time();

        return true;
    }

    /**
     * @return string
     */
    public function __toString() : string
    {
        $str = (($this->callerid)?:"anonymous")."|{$this->status}";
        
        if ($this->queue) {
            $str .= "|{$this->queue}";
        }
        
        return $str;
    }

    /**
     * @return array
     */
    public function jsonSerialize()
    {
        return [
            'type' => 'CALL',
            'id' => $this->uid,
            'callerid' => $this->callerid,
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

        return date('Y-m-d H:i:s', $at).";CALL;{$this->callerid};{$this->status};$duration;{$this->queue}";
    }
}
