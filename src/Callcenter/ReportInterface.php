<?php
declare(strict_types=1);

namespace Callcenter;

interface ReportInterface
{
    public function write(\Callcenter\Model\Report $report);
}