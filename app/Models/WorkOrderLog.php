<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu baris di garis waktu sebuah Work Order: terima tugas, verifikasi APD, bukti
 * Before-During-After, serah terima antar shift, lapor kendala, ajukan selesai.
 *
 * SATU tabel untuk semuanya, bukan satu tabel per jenis kejadian. Layar Activity Log
 * menampilkannya sebagai SATU aliran urut waktu; tabel terpisah berarti tiga garis
 * waktu yang harus digabung ulang tiap kali dibaca.
 *
 * `timestamps = false` disengaja: `logged_at` yang berlaku, dan dia datang dari HP —
 * kapan kejadiannya, bukan kapan barisnya sampai ke server. Di aplikasi luring dua
 * waktu itu bisa berjarak berjam-jam.
 */
class WorkOrderLog extends Model
{
    use HasFactory, HasUuids;

    /**
     * PK uuid yang LAHIR DI HP, bukan id bigint dari server. Foto bukti menempel ke
     * baris log lewat relasi morph `documents`, dan di lapangan foto diambil sebelum
     * ada sinyal — kalau identitas barisnya baru ada setelah sync, tidak ada apa pun
     * untuk ditempeli saat itu.
     *
     * Efek keduanya: PUT ke uuid yang sama jadi idempoten, jadi kiriman ulang karena
     * sinyal putus tidak melahirkan baris log kedua.
     */
    protected $primaryKey = 'uuid';

    public $timestamps = false;

    protected $fillable = [
        // uuid WAJIB fillable — HP yang membuatnya, dan itulah kunci idempotensinya.
        // Tanpa ini fill() membuangnya diam-diam dan HasUuids membuatkan uuid baru,
        // jadi tiap retry menambah satu baris.
        'uuid',
        'work_order_id',
        'entry_type',
        'bda_stage',
        'user_id',
        'shift',
        'day_number',
        'logged_at',
        'notes',
        'apd_items',
        'reason',
        'title',
        'handover_to_id',
    ];

    protected function casts(): array
    {
        return [
            'logged_at' => 'datetime',
            'day_number' => 'integer',
            'apd_items' => 'array',
        ];
    }

    public function documents()
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Penerima oper shift — cuma terisi di baris `shift_handover`. */
    public function handoverTo()
    {
        return $this->belongsTo(User::class, 'handover_to_id');
    }
}
