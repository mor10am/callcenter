<?php
declare(strict_types=1);

namespace Callcenter\Model;

final class Connection implements \JsonSerializable
{
    /**
     * @var string
     */
    public $id;

    /**
     * @var \Callcenter\Model\Agent
     */
    public $agent;

    /**
     * @var \Callcenter\Model\Call
     */
    public $call;

    /**
     * @var int
     */
    public $time;

    /**
     * Bridge constructor.
     * @param \Callcenter\Model\Call $call
     * @param \Callcenter\Model\Agent $agent
     */
    public function __construct(Call $call, Agent $agent)
    {
        $this->id = $call->uid;
        $this->call = $call;
        $this->agent = $agent;

        $this->time = time();
    }

    /**
     * @return string
     */
    public function __toString() : string
    {
        return "{$this->agent}:{$this->call}";
    }

    /**
     * @return array
     */
    public function jsonSerialize()
    {
        return [
            'type' => 'CONNECT',
            'id' => $this->id,
            'queue' => $this->call->queue,
            'time' => $this->time,
            'agent' => $this->agent,
            'call' => $this->call,
        ];
    }

}
