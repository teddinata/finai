<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageLog extends Model
{
    protected $fillable = [
        'household_id',
        'user_id',
        'feature',
        'count',
        'date',
        'metadata',
    ];

    protected $casts = [
        'count' => 'integer',
        'date' => 'date',
        'metadata' => 'array',
    ];

    // Relationships
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopeForFeature($query, string $feature)
    {
        return $query->where('feature', $feature);
    }

    public function scopeForDate($query, $date)
    {
        return $query->whereDate('date', $date);
    }

    public function scopeForMonth($query, int $year, int $month)
    {
        return $query->whereYear('date', $year)
            ->whereMonth('date', $month);
    }

    public function scopeThisMonth($query)
    {
        return $query->whereYear('date', now()->year)
            ->whereMonth('date', now()->month);
    }

    // Helper methods
    public static function logUsage(
        int $householdId,
        string $feature,
        int $count = 1,
        ?int $userId = null,
        ?array $metadata = null
        ): self
    {
        $date = now()->toDateString();

        // 1. Cari data hari ini
        $log = static::where('household_id', $householdId)
            ->where('feature', $feature)
            ->whereDate('date', $date)
            ->first();

        if (!$log) {
            try {
                $log = static::create([
                    'household_id' => $householdId,
                    'feature' => $feature,
                    'date' => $date,
                    'count' => 0,
                    'user_id' => $userId,
                    'metadata' => $metadata,
                ]);
            }
            catch (\Illuminate\Database\QueryException $e) {
                // Handle race condition: jika gagal create karena unique constraint, coba ambil lagi
                if ($e->getCode() === '23000') {
                    $log = static::where('household_id', $householdId)
                        ->where('feature', $feature)
                        ->whereDate('date', $date)
                        ->firstOrFail();
                }
                else {
                    throw $e;
                }
            }
        }

        // 2. Tambahkan count (Increment otomatis handle matematika & save)
        $log->increment('count', $count);

        // 3. (Opsional) Update metadata/user terakhir jika diperlukan
        if ($userId || $metadata) {
            $updates = [];
            if ($userId)
                $updates['user_id'] = $userId;
            if ($metadata)
                $updates['metadata'] = $metadata;

            if (!empty($updates)) {
                $log->update($updates);
            }
        }

        return $log;
    }

    public static function getMonthlyUsage(int $householdId, string $feature): int
    {
        return static::where('household_id', $householdId)
            ->where('feature', $feature)
            ->thisMonth()
            ->sum('count');
    }

    public static function hasReachedLimit(int $householdId, string $feature, int $limit): bool
    {
        if ($limit === -1) {
            return false; // Unlimited
        }

        $currentUsage = static::getMonthlyUsage($householdId, $feature);
        return $currentUsage >= $limit;
    }
}