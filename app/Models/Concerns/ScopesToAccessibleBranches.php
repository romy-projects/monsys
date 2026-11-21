<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

trait ScopesToAccessibleBranches
{
    protected static function bootScopesToAccessibleBranches(): void
    {
        static::addGlobalScope('accessible_branches', static function (Builder $query): void {
            $user = auth()->user();

            if ($user instanceof User) {
                $model = $query->getModel();
                $user->applyAccessibleBranchScope(
                    $query,
                    $model->qualifyColumn('branch_id'),
                    $model->includesUnassignedBranchRecords()
                );
            }
        });
    }

    public function includesUnassignedBranchRecords(): bool
    {
        return false;
    }
}
