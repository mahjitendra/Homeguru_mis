<?php

/**
 * Third-Party Services Configuration
 *
 * This file is for storing the credentials for third-party services such
 * as Mailgun, Postmark, AWS, and others. This file provides a central
 * location for managing all of your external service credentials.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Mailgun
    |--------------------------------------------------------------------------
    |
    | Mailgun is a transactional email service. You can get your API keys
    | from your Mailgun dashboard.
    |
    */

    'mailgun' => [
        'domain' => '', // Your Mailgun domain
        'secret' => '', // Your Mailgun secret
        'endpoint' => 'api.mailgun.net',
    ],

    /*
    |--------------------------------------------------------------------------
    | Postmark
    |--------------------------------------------------------------------------
    |
    | Postmark is another transactional email service.
    |
    */

    'postmark' => [
        'token' => '', // Your Postmark server token
    ],

    /*
    |--------------------------------------------------------------------------
    | Amazon Web Services (AWS)
    |--------------------------------------------------------------------------
    |
    | This section is for configuring your AWS SDK credentials.
    |
    */

    'ses' => [
        'key' => '', // Your AWS Access Key ID
        'secret' => '', // Your AWS Secret Access Key
        'region' => 'us-east-1', // Your AWS region
    ],

    /*
    |--------------------------------------------------------------------------
    | Video Conferencing Services
    |--------------------------------------------------------------------------
    |
    | Credentials for services like Zoom or Google Meet.
    |
    */

    'zoom' => [
        'client_id' => '',
        'client_secret' => '',
        'account_id' => '',
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Services
    |--------------------------------------------------------------------------
    |
    | Credentials for Google APIs (e.g., Maps, Analytics, reCAPTCHA).
    |
    */

    'google' => [
        'maps_api_key' => '',
        'recaptcha_site_key' => '',
        'recaptcha_secret_key' => '',
    ],

];