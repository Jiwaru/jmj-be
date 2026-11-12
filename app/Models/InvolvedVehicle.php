<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvolvedVehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'incident_report_id',
        'vehicle_type',
        'vehicle_group',
        'plate_number',
    ];

    public function incidentReport()
    {
        return $this->belongsTo(IncidentReport::class, 'incident_report_id', 'uuid');
    }

    /**
     * Orang yang mengemudikannya. hasMany, bukan hasOne: satu kartu "Pihak" bisa
     * memuat pemilik dan pengemudi sebagai dua baris untuk kendaraan yang sama.
     */
    public function culprits()
    {
        return $this->hasMany(Culprit::class, 'involved_vehicle_id');
    }
}
