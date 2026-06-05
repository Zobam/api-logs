<?php

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\ApiRequestLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['endpoint', 'method', 'status_code', 'ip_address', 'requested_at'])]
class ApiRequestLog extends Model
{
    /** @use HasFactory<ApiRequestLogFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'status_code' => 'integer',
        ];
    }

    /**
     * Scope to query logs older than a given date.
     *
     * @param  Builder  $query
     * @param  Carbon  $date
     * @return Builder
     */
    public function scopeOlderThan(Builder $query, Carbon $date): Builder
    {
        return $query->where('requested_at', '<', $date);
    }
}
