<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DamagedItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'incident_report_id',
        'asset_catalog_id',
        'catalog_price_id',
        'quantity',
        'damage_percentage',
        'subtotal',
        'work_order_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'damage_percentage' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function incidentReport()
    {
        return $this->belongsTo(IncidentReport::class, 'incident_report_id', 'uuid');
    }

    public function assetCatalog()
    {
        return $this->belongsTo(AssetCatalog::class);
    }

    public function catalogPrice()
    {
        return $this->belongsTo(CatalogPrice::class);
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
