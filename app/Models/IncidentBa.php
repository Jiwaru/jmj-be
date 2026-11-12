<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IncidentBa extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'incident_ba';

    protected $primaryKey = 'uuid';

    public $timestamps = false;

    protected $fillable = [
        'incident_ba_number',
        'incident_report_id',
        'officer_1_id',
        'officer_2_id',
        'duty_period',
        'shift',
        'validation_status',
        'validated_by',
        'validated_at',
        'rejection_reason',
        'pdf_path',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'validated_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function incidentReport()
    {
        return $this->belongsTo(IncidentReport::class, 'incident_report_id', 'uuid');
    }

    public function officer1()
    {
        return $this->belongsTo(User::class, 'officer_1_id');
    }

    public function officer2()
    {
        return $this->belongsTo(User::class, 'officer_2_id');
    }

    public function validator()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function damageBa()
    {
        return $this->hasOne(DamageBa::class, 'damage_report_id', 'uuid');
    }
}
