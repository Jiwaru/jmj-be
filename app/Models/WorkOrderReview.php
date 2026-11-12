<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkOrderReview extends Model
{
    use HasFactory;

    /**
     * Hasil yang MENUTUP work order. Sisanya mengembalikannya ke regu.
     *
     * Const bernama untuk SATU nilai, bukan salinan daftarnya: daftar lengkap
     * `outcome` ada di `config('enums.wo_review_outcome')` — dibaca CHECK constraint dan
     * Rule::in() dari sumber yang sama. Yang di sini cuma nilai yang kodenya harus
     * mengenali untuk memutuskan WO ditutup atau dikembalikan.
     */
    public const OUTCOME_DITERIMA = 'Diterima';

    public $timestamps = false;

    protected $fillable = [
        'work_order_id',
        'reviewer_id',
        'meets_quality_standard',
        'quantity_matches_log',
        'outcome',
        'rejection_notes',
        'reviewed_at',
        'revision_deadline',
    ];

    protected function casts(): array
    {
        return [
            'meets_quality_standard' => 'boolean',
            'quantity_matches_log' => 'boolean',
            'reviewed_at' => 'datetime',
            'revision_deadline' => 'datetime',
        ];
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
