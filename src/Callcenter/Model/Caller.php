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
     * @param string $status
     */
    public function setStatus(string $status)
    {
        $this->status = $status;
        $this->time = time();
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return (($this->callerid)?:"anonymous")."|{$this->status}|{$this->hash}";
    }
}