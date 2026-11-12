<?php

/*
 * Klien resminya PWA di browser (Next.js), bukan APK — jadi jawaban CORS server ini
 * benar-benar dipakai sebagai penjaga, bukan sekadar formalitas.
 *
 * Default framework adalah allowed_origins => ['*']. Dengan bearer token itu belum
 * bisa langsung dieksploitasi (situs jahat tidak punya tokennya), tapi dikunci
 * sekarang selagi murah: kalau nanti pindah ke Sanctum stateful, '*' bersama
 * supports_credentials ditolak browser, dan yang buru-buru biasanya
 * "menyelesaikannya" dengan memantulkan Origin apa pun — itu lubang penuh.
 */
return [

    // 'sanctum/csrf-cookie' sengaja tidak ada: sekarang bearer token, tidak ada
    // cookie sesi. Kalau pindah ke stateful, path itu wajib ditambahkan di sini.
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // Dipisah koma untuk menampung origin dev (:3000) dan :3001 dari
    // docker-compose.override.yml tim FE sekaligus.
    'allowed_origins' => array_filter(explode(',', (string) env('FRONTEND_URL', 'http://localhost:3000'))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // false selama autentikasinya bearer token. Dinyalakan hanya bersamaan dengan
    // pindah ke cookie httpOnly, dan saat itu allowed_origins wajib sudah eksplisit.
    'supports_credentials' => false,

];
