<?php

/**
 * Mail Configuration
 *
 * This file is for configuring your application's email sending services.
 * You can set default mailers and configure individual mailers like SMTP,
 * Sendmail, or log drivers for testing.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send any email
    | messages sent by your application. Alternative mailers may be setup
    | and used as needed; however, this mailer will be used by default.
    |
    */

    'default' => 'smtp',

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    */

    'mailers' => [

        'smtp' => [
            'transport'   => 'smtp',
            'host'        => 'smtp.mailtrap.io',
            'port'        => 2525,
            'encryption'  => 'tls',
            'username'    => null,
            'password'    => null,
            'timeout'     => null,
            'auth_mode'   => null,
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path'      => '/usr/sbin/sendmail -bs',
        ],

        'log' => [
            'transport' => 'log',
            'channel'   => 'mail', // Corresponds to a log channel
        ],

        'array' => [
            'transport' => 'array',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all e-mails sent by your application to be sent from
    | the same address. Here, you may specify a name and address that is
    | used globally for all e-mails that are sent by your application.
    |
    */

    'from' => [
        'address' => 'hello@example.com',
        'name'    => 'HomeGuru',
    ],

];