<?php
declare(strict_types=1);

namespace Callcenter\Model;

class Report
{
    /**
     * @var string
     */
    public $type;

    /**
     * @var string
     */
    public $id;

    /**
     * @var string
     */
    public $status;

    /**
     * @var string
     */
    public $timestamp;

    /**
     * @var int
     */
    public $duration;

    /**
     * @var string
     */
    public $queue;
}