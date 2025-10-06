<?php

/**
 * SMS Gateway Configuration
 *
 * This file is for configuring the SMS gateways used to send text messages
 * from your application, such as Twilio, MSG91, or others.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Default SMS Gateway
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default SMS gateway that should be used by
    | the application. This gateway will be used when no specific gateway
    | is specified for a message.
    |
    */

    'default' => 'twilio',

    /*
    |--------------------------------------------------------------------------
    | SMS Gateway Configurations
    |--------------------------------------------------------------------------
    |
    | Below are the configurations for the various SMS gateways supported
    | by the application. You should fill in your credentials for the
    | services you intend to use. Using environment variables for
    | sensitive data is strongly recommended.
    |
    */

    'gateways' => [

        'twilio' => [
            'driver' => 'twilio',
            'sid' => '', // Your Twilio Account SID
            'token' => '', // Your Twilio Auth Token
            'from' => '', // Your Twilio phone number
        ],

        'msg91' => [
            'driver' => 'msg91',
            'auth_key' => '', // Your MSG91 Auth Key
            'sender_id' => '', // Your approved Sender ID
        ],

        'log' => [
            'driver' => 'log',
            'channel' => 'sms', // A specific log channel
        ],

        // Add other custom SMS gateway configurations here.
    ],

    /*
    |--------------------------------------------------------------------------
    | Default "From" Name/Number
    |--------------------------------------------------------------------------
    |
    | You may wish to specify a default "from" number or alphanumeric sender
    | ID to be used for all outgoing messages when not using a service that
    | has a specific number tied to it (like Twilio).
    |
    */

    'from' => 'HMEGRU',

];