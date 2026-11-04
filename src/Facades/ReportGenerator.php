<?php

namespace Abdalla\ReportGenerator\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Abdalla\ReportGenerator\ReportGenerator policy(string $dataset, \Closure|string|\Abdalla\ReportGenerator\Contracts\RowPolicy $policy)
 * @method static \Abdalla\ReportGenerator\ReportGenerator unrestricted(array $datasets)
 * @method static \Abdalla\ReportGenerator\ReportGenerator source(string|\Abdalla\ReportGenerator\Contracts\ReportSource $source)
 * @method static \Abdalla\ReportGenerator\ReportGenerator authorizeUsing(\Closure $callback)
 * @method static \Abdalla\ReportGenerator\ReportGenerator rolesUsing(\Closure $callback)
 * @method static bool allows(?\Illuminate\Contracts\Auth\Authenticatable $user, \Abdalla\ReportGenerator\Enums\Ability|string $ability, array $arguments = [])
 * @method static \Abdalla\ReportGenerator\Security\PolicyRegistry policies()
 * @method static \Abdalla\ReportGenerator\Sources\SourceRegistry sources()
 *
 * @see \Abdalla\ReportGenerator\ReportGenerator
 */
class ReportGenerator extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Abdalla\ReportGenerator\ReportGenerator::class;
    }
}
