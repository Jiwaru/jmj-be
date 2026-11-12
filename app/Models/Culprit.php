<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Culprit extends Model
{
    use HasFactory;

    protected $table = 'culprit';

    public $timestamps = false;

    protected $fillable = [
        'incident_report_id',
        'involved_vehicle_id',
        'full_name',
        'party_role',
        'national_id',
        'phone_number',
        'address',
        'age',
        'occupation',
        'driver_license_number',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'age' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function incidentReport()
    {
        return $this->belongsTo(IncidentReport::class, 'incident_report_id', 'uuid');
    }

    /** Kendaraan yang dikemudikannya. Null kalau tidak diketahui atau tidak dicatat. */
    public function involvedVehicle()
    {
        return $this->belongsTo(InvolvedVehicle::class, 'involved_vehicle_id');
    }
}
