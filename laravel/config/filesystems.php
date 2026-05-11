<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // Cold-tier storage for archived log_messages rows. Hetzner
        // Object Storage is S3-compatible, so we reuse Laravel's
        // built-in `s3` driver with a custom `endpoint` override.
        // The endpoint shape is `https://<region>.your-objectstorage.com`
        // (e.g. `https://nbg1.your-objectstorage.com` for Nuremberg).
        // Path-style URLs are required because Hetzner doesn't issue
        // per-bucket DNS names — virtual-hosted style requests would
        // 404 at the load balancer.
        //
        // The `bex:archive-cold` command writes here; the
        // {@see App\Services\ColdLogReader} reads from here when the
        // Logs UI queries a date range that extends into archived
        // territory. Tests fake this disk via `Storage::fake('cold-logs')`.
        'cold-logs' => [
            'driver' => 's3',
            'key' => env('HETZNER_S3_KEY'),
            'secret' => env('HETZNER_S3_SECRET'),
            'region' => env('HETZNER_S3_REGION', 'nbg1'),
            'bucket' => env('HETZNER_S3_BUCKET'),
            'endpoint' => env('HETZNER_S3_ENDPOINT'),
            'use_path_style_endpoint' => env('HETZNER_S3_USE_PATH_STYLE_ENDPOINT', true),
            // We write `.jsonl.gz` blobs that the cold reader pulls
            // via a single GetObject per (sub, day). `throw => true`
            // so a failed PutObject during `bex:archive-cold` raises
            // and aborts the transaction BEFORE we delete the source
            // rows from log_messages — silent failures here would
            // mean data loss.
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
