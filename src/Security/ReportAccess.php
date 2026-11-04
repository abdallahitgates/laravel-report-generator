<?php

namespace Abdalla\ReportGenerator\Security;

use Abdalla\ReportGenerator\Enums\Ability;
use Abdalla\ReportGenerator\Models\Report;
use Abdalla\ReportGenerator\ReportGenerator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may open and change saved reports. Opening a report never widens
 * what its rows show: it always runs with the viewer's own row policies.
 */
final class ReportAccess
{
    public function __construct(private readonly ReportGenerator $generator)
    {
    }

    public function canView(Report $report, ?Authenticatable $user): bool
    {
        return $this->isOwner($report, $user)
            || $report->visibility === Report::PUBLIC
            || $this->share($report, $user) !== null
            || $this->generator->allows($user, Ability::ViewAllReports);
    }

    public function canEdit(Report $report, ?Authenticatable $user): bool
    {
        return $this->isOwner($report, $user) || $this->share($report, $user) === 'edit';
    }

    public function isOwner(Report $report, ?Authenticatable $user): bool
    {
        return $user !== null && (string) $report->owner_id === (string) $user->getAuthIdentifier();
    }

    /**
     * Reports this user may open.
     *
     * @param  string  $scope  mine | shared | all
     */
    public function visible(Builder $query, ?Authenticatable $user, string $scope): Builder
    {
        $id = $user?->getAuthIdentifier();
        $roles = $this->generator->rolesOf($user);
        $viewAll = $this->generator->allows($user, Ability::ViewAllReports);

        $shared = function (Builder $q) use ($id, $roles) {
            $q->where('visibility', Report::PUBLIC)
                ->orWhereHas('shares', function (Builder $s) use ($id, $roles) {
                    $s->where(fn ($w) => $w->where('shareable_type', 'user')->where('shareable_id', (string) $id))
                        ->orWhere(fn ($w) => $w->where('shareable_type', 'role')->whereIn('shareable_id', $roles ?: ['__none__']));
                });
        };

        return match ($scope) {
            'mine' => $query->where('owner_id', $id),
            'shared' => $query->where(fn ($q) => $q->where('owner_id', '!=', $id)->orWhereNull('owner_id'))
                ->when(! $viewAll, fn ($q) => $q->where($shared)),
            default => $viewAll ? $query : $query->where(fn ($q) => $q->where('owner_id', $id)->orWhere($shared)),
        };
    }

    /** The permission ("view" or "edit") this user has through shares, if any. */
    private function share(Report $report, ?Authenticatable $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $roles = $this->generator->rolesOf($user);
        $best = null;
        foreach ($report->shares as $share) {
            $matches = ($share->shareable_type === 'user' && (string) $share->shareable_id === (string) $user->getAuthIdentifier())
                || ($share->shareable_type === 'role' && in_array($share->shareable_id, $roles, true));
            if ($matches) {
                $best = $share->permission === 'edit' ? 'edit' : ($best ?? 'view');
            }
        }

        return $best;
    }
}
