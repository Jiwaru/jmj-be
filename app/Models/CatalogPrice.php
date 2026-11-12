<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CatalogPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'asset_catalog_id',
        'price',
        'min_price',
        'max_price',
        'effective_from',
        'effective_to',
        'change_reason',
        'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'min_price' => 'decimal:2',
            'max_price' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function assetCatalog()
    {
        return $this->belongsTo(AssetCatalog::class);
    }

    public function changer()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function damagedItems()
    {
        return $this->hasMany(DamagedItem::class);
    }
}
