<?php
declare(strict_types=1);

namespace Callcenter\Model;

final class Statistics implements \JsonSerializable
{
    public $calls_received = 0;
    public $calls_abandoned = 0;
    public $calls_answered = 0;

    public $agents_online = 0;
    public $calls_online = 0;
    public $connections_online = 0;

    public $average_handle_time = 0;
    public $average_queue_time = 0;
    public $average_abandoned_time = 0;

    public $queue_time = 0;
    public $abandoned_queue_time = 0;
    public $incall_time = 0;

    public $inside_sla = 0;

    const SLA_SECONDS = 10;

    /**
     *
     */
    public function addCallReceived() : void
    {
        $this->calls_received += 1;
    }

    /**
     * @param int $duration
     */
    public function addAnsweredCall(int $duration) : void
    {
        $this->calls_answered += 1;
        $this->queue_time += $duration;

        if ($duration <= self::SLA_SECONDS) {
            $this->inside_sla += 1;
        }

        $this->average_queue_time = round($this->queue_time / $this->calls_answered);
    }

    /**
     * @param int $duration
     */
    public function addAbandonedCall(int $duration) : void
    {
        $this->calls_abandoned += 1;
        $this->abandoned_queue_time += $duration;

        $this->average_abandoned_time = round($this->abandoned_queue_time / $this->calls_abandoned);
    }

    /**
     * @param int $duration
     */
    public function addHandledCall(int $duration) : void
    {
        $this->incall_time += $duration;

        if ($this->calls_answered) {
            $this->average_handle_time = round($this->incall_time / $this->calls_answered);
        }
    }

    /**
     * @return array
     */
    public function jsonSerialize()
    {
        return [
            'type' => 'STATS',
            'calls_received' => $this->calls_received,
            'calls_abandoned' => $this->calls_abandoned,
            'calls_answered' => $this->calls_answered,
            'agents_online' => $this->agents_online,
            'calls_online' => $this->calls_online,
            'connections_online' => $this->connections_online,
            'average_handle_time' => $this->average_handle_time,
            'average_queue_time' => $this->average_queue_time,
            'average_abandoned_time' => $this->average_abandoned_time,
            'sla' => ($this->calls_answered)?round($this->inside_sla / $this->calls_answered * 100, 2):"0",
        ];
    }
}