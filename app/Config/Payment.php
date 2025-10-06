<?php

/**
 * Payment Gateway Configuration
 *
 * This file contains the settings for the payment gateways integrated
 * into the HomeGuru application, such as Stripe, PayPal, and Razorpay.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Default Payment Gateway
    |--------------------------------------------------------------------------
    |
    | This option defines the default payment gateway to be used for
    | processing transactions. You can set this to your primary provider.
    |
    */

    'default' => 'stripe',

    /*
    |--------------------------------------------------------------------------
    | Currency Settings
    |--------------------------------------------------------------------------
    |
    | Define the default currency code (e.g., USD, INR, EUR) and symbol
    | to be used throughout the application for financial transactions.
    |
    */

    'currency' => 'USD',
    'currency_symbol' => '$',

    /*
    |--------------------------------------------------------------------------
    | Payment Gateway Credentials
    |--------------------------------------------------------------------------
    |
    | Below are the configuration settings for each supported payment gateway.
    | Enter your API keys and other required credentials for each service
    | you plan to use. It is highly recommended to use environment
    | variables (.env file) for these sensitive values.
    |
    */

    'gateways' => [

        'stripe' => [
            'driver' => 'stripe',
            'key' => '', // Your Stripe Publishable Key
            'secret' => '', // Your Stripe Secret Key
            'webhook_secret' => '', // Your Stripe Webhook Signing Secret
        ],

        'paypal' => [
            'driver' => 'paypal',
            'client_id' => '', // Your PayPal Client ID
            'secret' => '', // Your PayPal Secret
            'settings' => [
                'mode' => 'sandbox', // 'sandbox' or 'live'
                'log.LogEnabled' => true,
                'log.FileName' => ROOT_PATH . '/storage/logs/paypal.log',
                'log.LogLevel' => 'FINE', // 'FINE', 'INFO', 'WARN', or 'ERROR'
            ],
        ],

        'razorpay' => [
            'driver' => 'razorpay',
            'key_id' => '', // Your Razorpay Key ID
            'key_secret' => '', // Your Razorpay Key Secret
            'webhook_secret' => '', // Your Razorpay Webhook Secret
        ],

        // Add other gateways like Paytm, etc. here.

    ],

];