<?php

namespace App\Models;

use App\Support\Shift;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PatrolReport extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'patrol_report';

    protected $primaryKey = 'uuid';

    protected $fillable = [
        // uuid WAJIB fillable, alasan sama dengan IncidentReport: HP yang membuatnya,
        // dan itu kunci idempotensi sinkronisasi.
        'uuid',
        'officer_id',
        'patrolled_at',
        'route',
        'is_damaged',
        'asset_catalog_id',
        'location_type',
        'km',
        'm',
        'lane',
        'damage_description',
        'sync_status',
        'latitude',
        'longitude',
    ];

    protected function casts(): array
    {
        return [
            'patrolled_at' => 'datetime',
            'is_damaged' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * Batas "laporan siapa yang boleh dilihat", per role. Pola sama dengan
     * IncidentReport/WorkOrder: allowlist, bukan denylist.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return match ($user->role) {
            'field_officer' => $query->where('officer_id', $user->id),
            'maintenance_unit' => $query,
            default => $query->whereRaw('false'),
        };
    }

    /**
     * Shift saat patroli, DITURUNKAN dari patrolled_at — bukan kolom. Alasan sama
     * persis dengan IncidentReport::shift(): shift itu fungsi dari jam, menyimpannya
     * cuma membuka kemungkinan melenceng dari jam yang sebenarnya.
     */
    public function shift(): ?string
    {
        return Shift::at($this->patrolled_at);
    }

    /**
     * Sudah ada Work Order yang menunjuk laporan ini?
     */
    public function isLinkedToWorkOrder(): bool
    {
        return $this->workOrder()->exists();
    }

    /**
     * Boleh dikirim ulang petugas. Begitu ada Work Order yang menunjuknya, dikunci —
     * sama alasan IncidentReport::isEditable(): mengubah km/aset di sini setelah WO
     * terbit membuat laporan dan pekerjaannya menyebut lokasi yang berbeda.
     */
    public function isEditable(): bool
    {
        return ! $this->isLinkedToWorkOrder();
    }

    public function officer()
    {
        return $this->belongsTo(User::class, 'officer_id');
    }

    public function assetCatalog()
    {
        return $this->belongsTo(AssetCatalog::class);
    }

    public function workOrder()
    {
        return $this->hasOne(WorkOrder::class, 'patrol_report_id', 'uuid');
    }

    /** Foto bukti kerusakan, opsional. Lihat DocumentStorageService::storeForPatrol(). */
    public function documents()
    {
        return $this->morphMany(Document::class, 'documentable', 'documentable_type', 'documentable_id', 'uuid');
    }
}
