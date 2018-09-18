<?php
declare(strict_types=1);

namespace Callcenter\Report;

final class File implements \Callcenter\ReportInterface
{
    public $filename;

    /**
     * FileReport constructor.
     * @param string $filename
     */
    public function __construct(string $filename)
    {
        $this->filename = $filename;
    }

    /**
     * @param Model\Report $report
     */
    public function write(\Callcenter\Model\Report $report)
    {
        $string = $report->timestamp.";".$report->type.";".$report->id.";".$report->status.";";
        $string .= $report->duration.";".$report->queue;

        file_put_contents(
            $this->filename,
            $string."\n",
            FILE_APPEND
        );
    }
}