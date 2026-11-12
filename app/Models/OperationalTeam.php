<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OperationalTeam extends Model
{
    use HasFactory;

    protected $table = 'operational_team';

    protected $fillable = [
        'user_id',
        'incident_report_id',
        'work_order_id',
        'is_coordinator',
    ];

    protected function casts(): array
    {
        return ['is_coordinator' => 'boolean'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function incidentReport()
    {
        return $this->belongsTo(IncidentReport::class, 'incident_report_id', 'uuid');
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
