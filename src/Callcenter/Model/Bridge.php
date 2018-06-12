<?php

namespace Callcenter\Model;


class Bridge
{
    /* @var Agent $agent */
    public $agent;

    /* @var Caller $caller */
    public $caller;

    public $time;

    /**
     * Bridge constructor.
     * @param Caller $caller
     * @param Agent $agent
     */
    public function __construct(Caller $caller, Agent $agent)
    {
        $this->caller = $caller;
        $this->agent = $agent;

        $this->time = time();
    }
}