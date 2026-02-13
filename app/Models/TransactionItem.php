<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionItem extends Model
{
    protected $fillable = [
        'transaction_id',
        'nama',
        'qty',
        'harga_satuan',
        'harga_total',
    ];

    protected $casts = [
        'qty' => 'integer',
        'harga_satuan' => 'integer',
        'harga_total' => 'integer',
    ];

    // Relationships
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    // Helper methods
    public function getFormattedHargaSatuan(): string
    {
        return 'Rp ' . number_format($this->harga_satuan , 0, ',', '.');
    }

    public function getFormattedHargaTotal(): string
    {
        return 'Rp ' . number_format($this->harga_total , 0, ',', '.');
    }

    protected static function boot()
    {
        parent::boot();

        // Auto-calculate harga_total when creating/updating
        static::saving(function ($item) {
            if ($item->qty && $item->harga_satuan) {
                $item->harga_total = $item->qty * $item->harga_satuan;
            }
        });
    }
}
