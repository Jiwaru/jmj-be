<?php

use App\Http\Controllers\Api\Shared\AuthController;
use App\Http\Controllers\Api\Mcs\BaPdfController;
use App\Http\Controllers\Api\Shared\DocumentController;
use App\Http\Controllers\Api\Shared\IncidentReportController;
use App\Http\Controllers\Api\Shared\NotificationController;
use App\Http\Controllers\Api\Shared\PatrolReportController;
use App\Http\Controllers\Api\Mcs\IncidentVerificationController;
use App\Http\Controllers\Api\Maintenance\MaintenanceDashboardController;
use App\Http\Controllers\Api\Mcs\McsDashboardController;
use App\Http\Controllers\Api\Shared\SyncController;
use App\Http\Controllers\Api\Shared\WorkOrderController;
use App\Http\Controllers\Api\MaintenanceOfficer\WorkOrderLogController;
use App\Http\Controllers\Api\Maintenance\AssetTypeController;
use App\Http\Controllers\Api\Maintenance\AssetCatalogController;
use App\Http\Controllers\Api\Maintenance\CatalogPriceController;
use Illuminate\Support\Facades\Route;

// cache.headers:no_store, bukan default Symfony 'no-cache, private'. no-cache masih
// MENYIMPAN respons ke disk cache browser dan cuma mewajibkan revalidasi — jadi NIK,
// alamat, dan nomor SIM di respons laporan tetap tertinggal di tablet setelah logout.
// no-store melarang penyimpanannya sama sekali. Pakai alias bawaan Laravel, tidak
// perlu middleware sendiri. Hanya kena GET/HEAD — SetCacheHeaders melewati method
// yang tidak cacheable, dan itu memang cukup: respons POST login tidak disimpan
// browser tanpa header kesegaran eksplisit, yang tidak pernah kita kirim.
Route::prefix('v1')->middleware('cache.headers:no_store')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    // `active` menemani `auth:sanctum` di SELURUH grup, bukan per role: token milik akun
    // yang di-flip `is_active = false` harus mati seketika, termasuk untuk `me`, `logout`,
    // dan `sync/bootstrap` yang tidak punya penjaga role.
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);

        // Bekal offline Fase 0. Semua role boleh — isinya katalog dan daftar petugas,
        // tidak ada data pribadi pihak ketiga.
        Route::get('sync/bootstrap', [SyncController::class, 'bootstrap']);

        // Lonceng notifikasi. Semua role boleh — isinya selalu milik akun itu sendiri.
        // Sekarang yang menerima baru petugas maintenance (WO ditugaskan/dikembalikan/
        // diterima), tapi route-nya tidak perlu tahu itu.
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'markRead'])->whereUuid('id');

        // Dipakai DUA role, dengan batas yang berbeda di dalamnya. Route-nya sama
        // supaya FE tidak punya dua alamat untuk satu layar; yang membedakan
        // IncidentReport::scopeVisibleTo() (petugas cuma laporannya sendiri, MCS
        // semuanya) dan Policy-nya.
        Route::middleware('role:field_officer,mcs_supervisor')->group(function () {
            Route::get('incident-reports', [IncidentReportController::class, 'index']);
            Route::get('incident-reports/{uuid}', [IncidentReportController::class, 'show'])->whereUuid('uuid');

        });

        // Laporan patroli — dipakai DUA role, pola sama dengan incident-reports di atas:
        // field_officer melihat laporannya sendiri, maintenance_unit semuanya (dia yang
        // menindaklanjutinya lewat maintenance/patrol-queue). mcs_supervisor TIDAK ikut:
        // tidak ada BA di jalur ini, jadi tidak ada yang perlu diverifikasinya.
        Route::middleware('role:field_officer,maintenance_unit')->group(function () {
            Route::get('patrol-reports', [PatrolReportController::class, 'index']);
            Route::get('patrol-reports/{uuid}', [PatrolReportController::class, 'show'])->whereUuid('uuid');
        });

        // Satu-satunya jalan keluar berkas dari disk 'local'. Tidak ada URL statis —
        // isinya foto KTP dan NIK pihak ketiga, dan sejak Fase 5 juga foto bukti
        // perbaikan.
        //
        // KEEMPAT role ada di sini, dan yang benar-benar menjaga DocumentPolicy: petugas
        // melihat dokumen laporannya sendiri, MCS melihat dokumen laporan mana pun,
        // Unit Maintenance melihat seluruh bukti Work Order, petugas maintenance cuma
        // bukti pekerjaan yang ditugaskan kepadanya. Role di route cuma gerbang kasar —
        // "punya siapa" tidak bisa dijawab daftar role.
        //
        // whereNumber: id-nya bigint, dan Postgres melempar QueryException (500) untuk
        // 'abc', bukan 404 — batasannya di router supaya tidak sampai query.
        Route::get('documents/{document}', [DocumentController::class, 'show'])
            ->whereNumber('document')
            ->middleware('role:field_officer,mcs_supervisor,maintenance_unit,maintenance_officer');

        // Fase 1 — Petugas Lapangan. Allowlist: role lain tidak dapat izin apa pun.
        Route::middleware('role:field_officer')->group(function () {
            Route::put('incident-reports/{uuid}', [IncidentReportController::class, 'sync'])->whereUuid('uuid');
            // Throttle: satu berkas boleh 10 MB dan tidak ada batas jumlah per laporan,
            // jadi satu akun bisa menghabiskan disk NAS. 60/menit masih longgar untuk
            // petugas yang mengunggah belasan foto TKP sekaligus.
            Route::post('incident-reports/{uuid}/documents', [IncidentReportController::class, 'uploadDocument'])
                ->whereUuid('uuid')->middleware('throttle:60,1');

            // Menghapus salah unggah tetap milik petugas saja. MCS melihat berkasnya,
            // tidak membuangnya — yang salah unggah dia yang tahu.
            Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->whereNumber('document');

            // PUT, bukan POST, dan uuid-nya lahir di HP — pola sama persis dengan
            // PUT /incident-reports: sinkronisasi offline-first yang boleh diulang.
            Route::put('patrol-reports/{uuid}', [PatrolReportController::class, 'sync'])->whereUuid('uuid');

            // Sama pola dengan upload foto insiden: throttle karena satu berkas boleh
            // 10 MB dan tidak ada batas jumlah per laporan.
            Route::post('patrol-reports/{uuid}/documents', [PatrolReportController::class, 'uploadDocument'])
                ->whereUuid('uuid')->middleware('throttle:60,1');
        });

        // Fase 2 — MCS Supervisor.
        Route::middleware('role:mcs_supervisor')->group(function () {
            Route::get('mcs/summary', [McsDashboardController::class, 'summary']);

            Route::prefix('incident-reports/{incidentReport}')->whereUuid('incidentReport')->group(function () {
                Route::patch('correction', [IncidentVerificationController::class, 'correct']);

                // DUA BA, DUA PENGESAHAN TERPISAH — bukan satu aksi yang menerbitkan
                // keduanya. BA Kejadian menyangkut kronologi, BA Kerusakan menyangkut
                // kalkulasi; yang satu bisa benar sementara yang lain salah, dan
                // alasan penolakannya memang berbeda.
                //
                // URUTANNYA MENGIKAT: BA Kejadian dulu. damage_ba.damage_report_id itu
                // FK NOT NULL ke incident_ba.uuid — BA Kerusakan tidak punya tempat
                // berdiri sebelum BA Kejadian ada. Dijawab 422 berpesan oleh Service,
                // bukan dibiarkan mati di FK violation.
                Route::post('incident-ba/validate', [IncidentVerificationController::class, 'validateIncidentBa']);
                Route::post('incident-ba/reject', [IncidentVerificationController::class, 'rejectIncidentBa']);
                Route::post('damage-ba/validate', [IncidentVerificationController::class, 'validateDamageBa']);
                Route::post('damage-ba/reject', [IncidentVerificationController::class, 'rejectDamageBa']);

                // POST, bukan GET: menulis satu baris activity_logs, dan itu
                // satu-satunya jejak bahwa eskalasi pernah dilakukan.
                Route::post('escalation-link', [IncidentVerificationController::class, 'escalationLink']);

                // Surat permohonan keringanan yang dibawa pengguna jalan ke kantor.
                // Route TERPISAH dari incident-reports/{uuid}/documents yang tetap milik
                // field_officer: di sana document_type dipilih klien, dan membukanya
                // untuk MCS berarti MCS bisa mengganti foto lampiran BA yang diteken.
                // Throttle sama dengan unggahan petugas — berkasnya boleh 10 MB.
                Route::post('relief-letter', [IncidentVerificationController::class, 'uploadReliefLetter'])
                    ->middleware('throttle:60,1');
            });
        });

        // Satu endpoint per BA, dua perilaku: yang belum disahkan dirender saat itu
        // juga dan bertanda DRAFT, yang sudah disahkan distream dari disk. Itu yang
        // memberi kartu "BA_Kejadian.pdf · DRAFT" di layar isinya tanpa endpoint kedua.
        //
        // DIBUKA untuk field_officer: petugas boleh lihat PDF BA laporannya sendiri,
        // sama seperti GET /incident-reports/{uuid} — penjaganya IncidentBaPolicy /
        // DamageBaPolicy, keduanya mendelegasi ke IncidentReportPolicy::view. Role di
        // sini cuma gerbang kasar, sama seperti documents/{document} di atas.
        Route::middleware('role:field_officer,mcs_supervisor')->group(function () {
            Route::get('incident-bas/{incidentBa}/pdf', [BaPdfController::class, 'incidentBa'])->whereUuid('incidentBa');
            Route::get('damage-bas/{damageBa}/pdf', [BaPdfController::class, 'damageBa'])->whereUuid('damageBa');

            // Sama, tapi dari uuid LAPORAN: laporan yang belum disentuh MCS belum punya
            // baris BA (lahir saat Sahkan/Tolak), jadi dua route di atas tidak bisa
            // memberi pratinjau sebelum ACC. Ini yang dipakai kartu Otorisasi BA.
            Route::prefix('incident-reports/{incidentReport}')->whereUuid('incidentReport')->group(function () {
                Route::get('incident-ba/pdf', [BaPdfController::class, 'incidentBaPreview']);
                Route::get('damage-ba/pdf', [BaPdfController::class, 'damageBaPreview']);
            });
        });

        // Fase 4 + Fase 6 — Unit Maintenance. Dua fase, satu role: dia yang menerbitkan
        // Work Order dari antrean DAN yang memeriksa buktinya lalu menutupnya. Fase 5 yang
        // di antaranya (unggah bukti Before-During-After) milik `maintenance_officer` dan
        // belum ada — Mentok #7 belum dijawab, dan itu yang menentukan foto BDA nempel ke
        // work_order_logs atau ke work_orders.
        Route::middleware('role:maintenance_unit')->group(function () {
            Route::get('maintenance/summary', [MaintenanceDashboardController::class, 'summary']);

            // Antrean itu sendiri yang jadi notifikasinya — tidak ada tabel notifikasi.
            // Isinya damaged_items dari BA Kerusakan tervalidasi yang work_order_id-nya
            // masih NULL, dikelompokkan per titik lokasi.
            Route::get('maintenance/queue', [MaintenanceDashboardController::class, 'queue']);

            // Satu baris antrean, bentuk sama. Pengganti GET /incident-reports/{uuid} untuk
            // Unit — yang itu membawa NIK dan syarat pembayaran, dan Unit tidak butuh.
            Route::get('maintenance/queue/{uuid}', [MaintenanceDashboardController::class, 'queueItem'])->whereUuid('uuid');

            // Antrean temuan patroli — sejajar dengan maintenance/queue, tapi sumbernya
            // patrol_report bukan damaged_items tervalidasi BA. Tidak lewat mcs_supervisor
            // sama sekali: tidak ada BA di jalur ini.
            Route::get('maintenance/patrol-queue', [MaintenanceDashboardController::class, 'patrolQueue']);

            // Isi dropdown penunjukan regu. Penjaganya sengaja SAMA dengan aksi yang dia
            // suapi (POST /work-orders dan PUT /work-orders/{id}/team) — daftar nama dan
            // NIP pegawai JMTM tidak punya kegunaan untuk role yang tidak bisa menugaskan
            // siapa pun. MCS yang perlu tahu siapa yang ditugaskan membacanya dari detail
            // WO, bukan dari picker.
            Route::get('maintenance/officers', [MaintenanceDashboardController::class, 'officers']);

            // whereNumber: PK-nya bigint, dan Postgres melempar QueryException (500) untuk
            // 'abc', bukan 404 — batasannya di router supaya tidak sampai query. Alasan
            // yang sama dengan documents/{document}.
            Route::post('work-orders', [WorkOrderController::class, 'store']);

            Route::prefix('work-orders/{workOrder}')->whereNumber('workOrder')->group(function () {
                Route::put('team', [WorkOrderController::class, 'updateTeam']);

                // POST, bukan PATCH ke work-orders/{id}: yang ditulis baris BARU di
                // work_order_reviews, bukan kolom yang ditimpa. Putaran evaluasi
                // sebelumnya harus tetap ada — berapa kali sebuah pekerjaan dikembalikan
                // itu justru angka yang paling berguna untuk menilai mutu regu.
                Route::post('review', [WorkOrderController::class, 'review']);
            });

            // Manajemen master data aset (Unit Maintenance) — dipisah dari
            // GET /sync/bootstrap, yang cuma ngedump semua baris datar tanpa halaman
            // untuk bekal luring dan tidak berhubungan dengan grup ini.
            Route::get('asset-types', [AssetTypeController::class, 'index']);
            Route::post('asset-types', [AssetTypeController::class, 'store']);
            Route::get('asset-types/{assetType}', [AssetTypeController::class, 'show'])->whereNumber('assetType');
            Route::put('asset-types/{assetType}', [AssetTypeController::class, 'update'])->whereNumber('assetType');
            Route::delete('asset-types/{assetType}', [AssetTypeController::class, 'destroy'])->whereNumber('assetType');

            // Foto standar — satu slot, unggahan baru menggantikan yang lama. Lihat
            // DocumentStorageService::storeForAssetType(). Dilihat semua role lewat
            // GET /documents/{id} (DocumentPolicy::view()), bukan di sini.
            Route::post('asset-types/{assetType}/photo', [AssetTypeController::class, 'uploadPhoto'])
                ->whereNumber('assetType')->middleware('throttle:60,1');

            Route::get('asset-catalog', [AssetCatalogController::class, 'index']);
            Route::post('asset-catalog', [AssetCatalogController::class, 'store']);
            Route::get('asset-catalog/{assetCatalog}', [AssetCatalogController::class, 'show'])->whereNumber('assetCatalog');
            Route::put('asset-catalog/{assetCatalog}', [AssetCatalogController::class, 'update'])->whereNumber('assetCatalog');
            Route::delete('asset-catalog/{assetCatalog}', [AssetCatalogController::class, 'destroy'])->whereNumber('assetCatalog');

            // Foto standar komponen — pasangan foto jenis aset di atas, slot sendiri
            // (`asset_catalog.standard_photo_id`).
            Route::post('asset-catalog/{assetCatalog}/photo', [AssetCatalogController::class, 'uploadPhoto'])
                ->whereNumber('assetCatalog')->middleware('throttle:60,1');

            // Histori harga — CREATE + READ saja, lihat catatan di CatalogPriceController.
            Route::prefix('asset-catalog/{assetCatalog}')->whereNumber('assetCatalog')->group(function () {
                Route::get('prices', [CatalogPriceController::class, 'index']);
                Route::post('prices', [CatalogPriceController::class, 'store']);
            });
        });

        // Dipakai DUA role dengan batas berbeda di dalamnya, persis pola
        // `incident-reports` di atas: route-nya sama supaya FE tidak punya dua alamat
        // untuk satu layar, dan yang membedakan WorkOrder::scopeVisibleTo() (Unit
        // Maintenance semuanya, petugas cuma yang ditugaskan kepadanya) plus
        // WorkOrderPolicy pada detailnya.
        Route::middleware('role:maintenance_unit,maintenance_officer')->group(function () {
            Route::get('work-orders', [WorkOrderController::class, 'index']);
            Route::get('work-orders/{workOrder}', [WorkOrderController::class, 'show'])->whereNumber('workOrder');
        });

        // Fase 5 — Petugas Maintenance. Yang MENULIS garis waktu cuma dia; Unit
        // Maintenance sengaja tidak dapat walau boleh melihat semuanya, karena bukti
        // yang dia evaluasi di Fase 6 tidak boleh jadi bukti yang dia tulis sendiri.
        Route::middleware('role:maintenance_officer')->group(function () {
            // PUT, bukan POST, dan uuid-nya lahir di HP. Di offline-first "kirim" itu
            // sync, dan sync yang gagal di tengah akan diulang — POST melahirkan baris
            // kedua tiap kali sinyal putus. Sama persis dengan PUT /incident-reports.
            Route::put('work-order-logs/{uuid}', [WorkOrderLogController::class, 'sync'])->whereUuid('uuid');

            // Foto menyusul barisnya, tidak digabung jadi satu multipart: baris log
            // ditulis saat kejadian di bahu jalan, fotonya terkirim saat ada sinyal.
            // Throttle karena satu berkas boleh 10 MB dan tidak ada batas jumlah foto
            // per pekerjaan — alasan yang sama dengan unggahan Petugas Lapangan.
            Route::post('work-order-logs/{workOrderLog}/documents', [WorkOrderLogController::class, 'uploadDocument'])
                ->whereUuid('workOrderLog')->middleware('throttle:60,1');
        });
    });
});
