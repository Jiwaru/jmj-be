<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkOrder extends Model
{
    use HasFactory;

    /**
     * Status yang masih boleh diganti regunya.
     *
     * Sampai `in_progress` (keputusan PO 5 Okt): Unit perlu menambah tenaga atau
     * mengganti teknisi yang berhalangan saat pekerjaan berjalan. Log tidak terganggu —
     * tiap baris menyimpan `user_id` penulisnya sendiri dan tidak ikut terhapus.
     *
     * Dilarang saat `submitted` (sedang dievaluasi — buktinya sedang diperiksa) dan
     * `completed`. Koordinatornya sendiri terkunci sejak lewat `new`; lihat
     * WorkOrderService::replaceTeam().
     *
     * SOP, bukan aturan server: anggota yang dikeluarkan harus mengirim log luringnya
     * dulu — kirimannya sesudah dikeluarkan dijawab 403 (WorkOrderPolicy::log).
     */
    public const TEAM_EDITABLE_STATUSES = ['new', 'preparing', 'in_progress'];

    /**
     * Status yang boleh dievaluasi Unit Maintenance.
     *
     * Cuma `submitted` — regu yang mengajukan selesai. Yang masih `in_progress` belum
     * ada yang bisa diperiksa, dan yang sudah `completed` sudah ditutup: mengevaluasinya
     * lagi berarti membuka kembali pekerjaan yang biayanya sudah dibukukan.
     */
    public const REVIEWABLE_STATUSES = ['submitted'];

    /**
     * Status yang berarti pekerjaannya belum selesai — dipakai kartu "terlambat" dan
     * penjaga penutupan laporan.
     */
    public const OPEN_STATUSES = ['new', 'preparing', 'in_progress', 'submitted', 'rework'];

    /**
     * Transisi status yang dipicu sebuah baris log Fase 5.
     *
     * entry_type => [status yang dibolehkan SEBELUM, status SESUDAH]
     *
     * Satu tabel, satu tempat. Tersebar jadi lima `if` di Service, urutannya bisa
     * dilanggar dari satu cabang tanpa cabang lain tahu — dan yang rusak bukan satu
     * request, melainkan arti kolom `repair_status` untuk semua baris setelahnya.
     *
     * Yang TIDAK ada di sini (`progress`, `shift_handover`, `obstacle`) memang tidak
     * mengubah status apa pun: mencatat kemajuan, mengoper shift, dan melaporkan
     * kendala tidak memindahkan pekerjaan ke tahap lain. Khusus `obstacle` itu
     * keputusan 3 Sep — kendala dilaporkan TANPA melepas tugas.
     */
    public const LOG_TRANSITIONS = [
        'start' => ['new', 'preparing'],
        'apd_check' => ['preparing', 'in_progress'],
        'complete' => ['in_progress', 'submitted'],
    ];

    /**
     * Satu-satunya nilai `trigger_source` yang berasal dari laporan insiden.
     *
     * Const bernama, bukan literal disebar: nilai ini dipakai bercabang di Service, di
     * DALAM string aturan `required_if:trigger_source,Insiden`, dan di seeder. Daftar
     * lengkapnya tetap `config('enums.wo_trigger_source')` — yang di sini cuma nama
     * untuk satu nilai yang kodenya memang harus mengenali.
     */
    public const TRIGGER_INSIDEN = 'Insiden';

    /**
     * Satu-satunya nilai `trigger_source` yang boleh menunjuk `patrol_report_id`.
     * Named const, alasan sama dengan TRIGGER_INSIDEN — dipakai bercabang di guard
     * StoreWorkOrderRequest.
     */
    public const TRIGGER_PATROLI = 'Temuan Patroli';

    protected $fillable = [
        'reference_number',
        'incident_report_id',
        'damage_minutes_id',
        'patrol_report_id',
        'asset_catalog_id',
        'trigger_source',
        'trigger_note',
        'priority',
        'damage_description',
        'location_type',
        'km',
        'm',
        'total_m',
        'route',
        'lane',
        'shift',
        'warranty_status',
        'cost_amount',
        'repair_status',
        'sla_deadline',
        'target_date',
        'created_by',
        'handed_over_at',
        'received_by',
        'closed_by',
        'closed_at',
        'latitude',
        'longitude',
    ];

    protected function casts(): array
    {
        return [
            'cost_amount' => 'decimal:2',
            'sla_deadline' => 'datetime',
            'handed_over_at' => 'datetime',
            'closed_at' => 'datetime',
            // Berjam sejak 1 Okt (desain: "04 Sep 2026, 18:00 WIB").
            'target_date' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function incidentReport()
    {
        return $this->belongsTo(IncidentReport::class, 'incident_report_id', 'uuid');
    }

    public function damageBa()
    {
        return $this->belongsTo(DamageBa::class, 'damage_minutes_id', 'uuid');
    }

    public function patrolReport()
    {
        return $this->belongsTo(PatrolReport::class, 'patrol_report_id', 'uuid');
    }

    public function assetCatalog()
    {
        return $this->belongsTo(AssetCatalog::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * Batas "Work Order siapa yang boleh dilihat", per role.
     *
     * Unit Maintenance melihat semuanya — dia yang menerbitkan dan mengevaluasi.
     * Petugas maintenance cuma yang ditugaskan kepadanya: daftar pekerjaan di HP-nya
     * adalah pekerjaannya sendiri, bukan papan pengumuman seluruh ruas.
     *
     * ALLOWLIST, bukan denylist. Role yang tidak disebut tidak melihat apa pun — bukan
     * melihat semuanya. Bentuk yang sama dengan IncidentReport::scopeVisibleTo(), dan
     * alasannya sama: role kelima nanti harus disebut dengan sengaja, bukan mewarisi
     * akses penuh di hari pertama karena kebetulan tidak cocok dengan cabang mana pun.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return match ($user->role) {
            'maintenance_unit' => $query,
            'maintenance_officer' => $query->whereHas(
                'operationalTeam', fn ($q) => $q->where('user_id', $user->id)
            ),
            default => $query->whereRaw('false'),
        };
    }

    public function operationalTeam()
    {
        return $this->hasMany(OperationalTeam::class);
    }

    /** user_id koordinator regu, atau null (WO lama sebelum koordinator wajib). */
    public function coordinatorId(): ?int
    {
        return $this->operationalTeam()->where('is_coordinator', true)->value('user_id');
    }

    public function damagedItems()
    {
        return $this->hasMany(DamagedItem::class);
    }

    /**
     * Garis waktu pekerjaan, URUT WAKTU KEJADIAN.
     *
     * Urutannya di relasi, bukan diserahkan ke tiap pemanggil: ini garis waktu, dan garis
     * waktu yang dibaca acak bukan garis waktu. Urutan default (PK) juga tidak menolong
     * sejak PK-nya uuid — uuid v7 memang naik menurut waktu PEMBUATAN di HP, tapi yang
     * diurutkan layar adalah `logged_at`, dan di aplikasi luring dua hal itu berbeda.
     */
    public function logs()
    {
        return $this->hasMany(WorkOrderLog::class)->orderBy('logged_at');
    }

    public function reviews()
    {
        return $this->hasMany(WorkOrderReview::class);
    }
}
