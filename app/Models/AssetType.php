<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssetType extends Model
{
    use HasFactory;

    protected $fillable = [
        'category',
        'name',
        'standard_photo_id',
        'description',
    ];

    public function assetCatalogs()
    {
        return $this->hasMany(AssetCatalog::class);
    }

    public function standardPhoto()
    {
        return $this->belongsTo(Document::class, 'standard_photo_id');
    }
}
