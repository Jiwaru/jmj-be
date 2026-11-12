<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ActivityLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'auditable_type',
        'auditable_id',
        'action',
        'user_id',
        'description',
        'old_values',
        'new_values',
        'logged_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'logged_at' => 'datetime',
        ];
    }

    /**
     * Catat satu jejak. Ini penulis pertama tabel ini — sebelumnya tabel, model, dan
     * morph map sudah ada tapi nol baris kode yang mengisinya.
     *
     * YANG DICATAT SENGAJA SEDIKIT. Sahkan dan tolak BA TIDAK lewat sini: `validated_by`,
     * `validated_at`, dan `rejection_reason` sudah menyimpan fakta yang persis sama di
     * kolomnya sendiri, dan baris log kedua yang mengatakan hal yang sama cuma menunggu
     * untuk melenceng. Yang masuk sini justru yang TIDAK punya kolom: koreksi data oleh
     * MCS (kolom mana berubah dari apa jadi apa) dan penekanan tombol eskalasi WA —
     * yang terakhir itu satu-satunya jejak bahwa eskalasi pernah terjadi, karena wa.me
     * cuma MEMBUKA WhatsApp dan sistem tidak pernah tahu pesannya terkirim.
     */
    public static function record(
        Model $subject,
        string $action,
        User $user,
        ?array $old = null,
        ?array $new = null,
        ?string $description = null,
    ): self {
        return self::create([
            // getMorphClass(), bukan ::class — supaya ikut morph map yang sudah
            // di-enforceMorphMap di AppServiceProvider dan yang tersimpan nama tabel
            // pendek ('incident_report'), sama dengan yang ditulis seluruh SQL di
            // dokumen alur. FQCN membuat kelas tidak bisa dipindah tanpa migrasi data.
            'auditable_type' => $subject->getMorphClass(),
            // Di-cast string: kolomnya varchar(36) justru supaya muat PK bigint
            // maupun PK uuid. Ini kolom polymorphic, jadi tidak ada FK yang protes
            // kalau isinya salah bentuk.
            'auditable_id' => (string) $subject->getKey(),
            'action' => $action,
            'user_id' => $user->id,
            'description' => $description,
            'old_values' => $old,
            'new_values' => $new,
            'logged_at' => Carbon::now(),
        ]);
    }

    public function auditable()
    {
        return $this->morphTo();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
