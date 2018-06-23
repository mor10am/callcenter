<?php
declare(strict_types=1);

namespace Callcenter\Model;

class Connection
{
    /**
     @var \Callcenter\Model\Agent
     */
    public $agent;

    /**
     * @var \Callcenter\Model\Caller
     */
    public $caller;

    /**
     * @var int
     */
    public $time;

    /**
     * Bridge constructor.
     * @param \Callcenter\Model\Caller $caller
     * @param \Callcenter\Model\Agent $agent
     */
    public function __construct(Caller $caller, Agent $agent)
    {
        $this->caller = $caller;
        $this->agent = $agent;

        $this->time = time();
    }

    /**
     * @return string
     */
    public function __toString() : string
    {
        return "{$this->agent}:{$this->caller}";
    }
}
