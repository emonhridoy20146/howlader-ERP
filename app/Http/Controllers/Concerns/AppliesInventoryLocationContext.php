<?php

namespace App\Http\Controllers\Concerns;

use App\Support\InventoryLocationContext;
use Illuminate\Database\Eloquent\Builder;

trait AppliesInventoryLocationContext
{
    protected function accessibleLocationIds(): array
    {
        $user = auth()->user();

        return $user ? $user->accessibleLocationIds() : [];
    }

    protected function contextLocationId(): ?int
    {
        return app(InventoryLocationContext::class)->currentId();
    }

    protected function scopeContextBranch(Builder $query, string $column = 'branch_id'): Builder
    {
        $id = $this->contextLocationId();
        if ($id !== null) {
            $query->where($column, $id);
        } elseif ($ids = $this->accessibleLocationIds()) {
            $query->whereIn($column, $ids);
        }

        return $query;
    }

    protected function scopeContextDestination(Builder $query, string $column = 'destination_location_id'): Builder
    {
        $id = $this->contextLocationId();
        if ($id !== null) {
            $query->where($column, $id);
        } elseif ($ids = $this->accessibleLocationIds()) {
            $query->whereIn($column, $ids);
        }

        return $query;
    }

    protected function scopeContextLocation(Builder $query, string $column = 'location_id'): Builder
    {
        $id = $this->contextLocationId();
        if ($id !== null) {
            $query->where($column, $id);
        } elseif ($ids = $this->accessibleLocationIds()) {
            $query->whereIn($column, $ids);
        }

        return $query;
    }

    protected function scopeContextTransfer(Builder $query): Builder
    {
        $id = $this->contextLocationId();
        if ($id !== null) {
            $query->where(function ($q) use ($id): void {
                $q->where('source_location_id', $id)
                    ->orWhere('destination_location_id', $id);
            });
        } elseif ($ids = $this->accessibleLocationIds()) {
            $query->where(function ($q) use ($ids): void {
                $q->whereIn('source_location_id', $ids)
                    ->orWhereIn('destination_location_id', $ids);
            });
        }

        return $query;
    }
}
