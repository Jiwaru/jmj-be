<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssetCatalog extends Model
{
    use HasFactory;

    protected $table = 'asset_catalog';

    protected $fillable = [
        'name',
        'asset_type_id',
        'component',
        'description',
        'unit_of_measurement',
        'standard_photo_id',
        'specification',
        'designlife_years',
        'primary_vendor',
    ];

    protected function casts(): array
    {
        return [
            'specification' => 'array',
            'designlife_years' => 'integer',
        ];
    }

    public function assetType()
    {
        return $this->belongsTo(AssetType::class);
    }

    public function standardPhoto()
    {
        return $this->belongsTo(Document::class, 'standard_photo_id');
    }

    public function catalogPrices()
    {
        return $this->hasMany(CatalogPrice::class);
    }

    public function damagedItems()
    {
        return $this->hasMany(DamagedItem::class);
    }

    public function workOrders()
    {
        return $this->hasMany(WorkOrder::class);
    }
}
