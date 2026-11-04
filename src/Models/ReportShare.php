<?php

namespace Abdalla\ReportGenerator\Models;

use Abdalla\ReportGenerator\Support\Tables;

/**
 * @property int $report_id
 * @property string $shareable_type  user | role
 * @property string $shareable_id
 * @property string $permission  view | edit
 */
class ReportShare extends RgModel
{
    protected const TABLE = Tables::REPORT_SHARES;
}
