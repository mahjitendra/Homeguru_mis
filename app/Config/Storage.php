<?php

/**
 * Filesystem Configuration
 *
 * Here you may configure the file storage "disks" for your application.
 * You may configure as many disks as you wish, and you may even
 * configure multiple disks of the same driver.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available for your choosing.
    |
    */

    'default' => 'local',

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Here are each of the filesystem disks setup for your application.
    | Of course, examples of configuring each driver is shown below.
    | You are free to add your own disks as required.
    |
    | Supported Drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => ROOT_PATH . '/storage/app',
        ],

        'public' => [
            'driver' => 'local',
            'root' => ROOT_PATH . '/public/uploads',
            'url' => APP_URL . '/uploads',
            'visibility' => 'public',
        ],

        's3' => [
            'driver' => 's3',
            'key' => '', // Your AWS Access Key ID
            'secret' => '', // Your AWS Secret Access Key
            'region' => '', // Your AWS Region (e.g., 'us-east-1')
            'bucket' => '', // Your AWS S3 Bucket Name
            'url' => '', // Optional: Custom URL endpoint
        ],

    ],

];