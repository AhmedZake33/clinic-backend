<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database / file backups (php artisan backup:database)
    |--------------------------------------------------------------------------
    */

    // Path to mysqldump. On XAMPP/Windows: C:\xampp\mysql\bin\mysqldump.exe
    'mysqldump_path' => env('BACKUP_MYSQLDUMP_PATH', 'mysqldump'),

    // Days to keep old backups in storage/app/backups
    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),

    // Optional off-site filesystem disk (e.g. "s3") to copy every backup to
    'copy_disk' => env('BACKUP_COPY_DISK'),

];
