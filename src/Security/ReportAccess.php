<?php

namespace Abdalla\ReportGenerator\Security;

use Abdalla\ReportGenerator\Enums\Ability;
use Abdalla\ReportGenerator\Models\Report;
use Abdalla\ReportGenerator\ReportGenerator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may open and change saved reports. Opening a report never widens
 * what its rows show: it always runs with the viewer's own row policies
 * and row scope.
 *
 * A published report can be opened by everyone holding its permission,
 * including people who cannot build reports themselves.
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
            || $this->holdsPermission($report, $user)
            || $this->generator->allows($user, Ability::ViewAllReports);
    }

    public function holdsPermission(Report $report, ?Authenticatable $user): bool
    {
        return $report->permission !== null && $user !== null && $this->generator->allows($user, $report->permission);
    }

    public function canPublish(?Authenticatable $user): bool
    {
        return $this->generator->allows($user, Ability::PublishReports);
    }

    /**
     * Published reports this user holds the permission of.
     *
     * @return list<string> permission names
     */
    public function heldPermissions(?Authenticatable $user): array
    {
        if ($user === null) {
            return [];
        }
        $permissions = Report::query()->whereNotNull('permission')->distinct()->pluck('permission');

        return $permissions->filter(fn (string $p) => $this->generator->allows($user, $p))->values()->all();
    }

    /**
     * Dashboards: the owner, everyone when public, holders of its permission,
     * and view-all users. Each widget still needs access to its report.
     */
    public function canViewDashboard(\Abdalla\ReportGenerator\Models\Dashboard $dashboard, ?Authenticatable $user): bool
    {
        return ($user !== null && (string) $dashboard->owner_id === (string) $user->getAuthIdentifier())
            || $dashboard->visibility === Report::PUBLIC
            || ($dashboard->permission !== null && $user !== null && $this->generator->allows($user, $dashboard->permission))
            || $this->generator->allows($user, Ability::ViewAllReports);
    }

    public function canEditDashboard(\Abdalla\ReportGenerator\Models\Dashboard $dashboard, ?Authenticatable $user): bool
    {
        return $user !== null && (string) $dashboard->owner_id === (string) $user->getAuthIdentifier();
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

        $held = $viewAll ? [] : $this->heldPermissions($user);
        $shared = function (Builder $q) use ($id, $roles, $held) {
            $q->where('visibility', Report::PUBLIC)
                ->when($held !== [], fn ($w) => $w->orWhereIn('permission', $held))
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
