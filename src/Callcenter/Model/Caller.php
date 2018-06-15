<?php

namespace Callcenter\Model;

class Caller
{
    public $callerid;
    public $uid;
    public $hash;
    public $queue;
    public $status = "NA";

    /**
     * Caller constructor.
     * @param string $callerid
     * @param string $uid
     */
    public function __construct(string $callerid, string $uid)
    {
        $this->callerid = $callerid;
        $this->uid = $uid;

        $this->hash = sha1($callerid.$uid);

        $this->time = time();
    }

    /**
     * @param string $queue
     */
    public function setQueue(string $queue)
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
     */
    public function setStatus(string $status)
    {
        $status = strtoupper($status);

        if ($this->status == $status) {
            return;
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
        return (($this->callerid)?:"anonymous")."|{$this->status}|{$this->hash}";
    }

    public function getReportLine()
    {
        $duration = time() - $this->time;

        return date('Y-m-d H:i:s').";CALLER;{$this->callerid};{$this->status};$duration;{$this->queue}";
    }
}