<?php

return [
    'admin_user' => env('ADMIN_USER', 'admin'),
    'admin_password_hash' => env('ADMIN_PASSWORD_HASH', ''),
    // token rahasia untuk menjalankan tugas pemeliharaan lewat URL (cadangan bila cron belum dipasang)
    'maintenance_token' => env('MAINTENANCE_TOKEN', ''),
];
