<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DamageBa extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'damage_ba';

    protected $primaryKey = 'uuid';

    public $timestamps = false;

    protected $fillable = [
        'damage_ba_number',
        'damage_report_id',
        'supervisor_id',
        'shift',
        'total_amount',
        'amount_in_words',
        'validation_status',
        'validated_by',
        'validated_at',
        'rejection_reason',
        'pdf_path',
        'created_at',
    ];

    /**
     * Ikut ke setiap JSON BA Kerusakan supaya FE berhenti menulis `+7` sendiri —
     * angkanya cuma ada di satu tempat, `config('toll.relief_grace_days')`.
     */
    protected $appends = ['relief_deadline'];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'validated_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Tenggat surat permohonan keringanan: BA Kerusakan disahkan + masa tenggang.
     * DITURUNKAN, bukan kolom — keputusan 1 Sep "masa tenggang dihitung, bukan
     * disimpan". Null selama BA belum disahkan.
     *
     * Ikut terisi walau laporannya tidak mengajukan keringanan: yang memutuskan
     * relevan atau tidak itu `incident_report.relief_requested`, bukan BA-nya.
     */
    protected function reliefDeadline(): Attribute
    {
        return Attribute::get(fn () => $this->validated_at?->copy()->addDays((int) config('toll.relief_grace_days')));
    }

    public function incidentBa()
    {
        return $this->belongsTo(IncidentBa::class, 'damage_report_id', 'uuid');
    }

    public function supervisor()
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function validator()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function workOrders()
    {
        return $this->hasMany(WorkOrder::class, 'damage_minutes_id', 'uuid');
    }
}
