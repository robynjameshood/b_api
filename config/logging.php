<?php

use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Rollbar\Laravel\MonologHandler;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that gets used when writing
    | messages to the logs. The name specified in this option should match
    | one of the channels defined in the "channels" configuration array.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Out of
    | the box, Laravel uses the Monolog PHP logging library. This gives
    | you a variety of powerful log handlers / formatters to utilize.
    |
    | Available Drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog",
    |                    "custom", "stack"
    |
    */

    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => ['rollbar','daily'],
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => 'debug',
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => 'debug',
            'days' => 14,
            'permission' => 0664
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => 'Laravel Log',
            'emoji' => ':boom:',
            'level' => 'critical',
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => 'debug',
            'handler' => SyslogUdpHandler::class,
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
            ],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => 'debug',
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => 'debug',
        ],

        'rollbar' => [
            'driver' => 'monolog',
            'handler' => \Rollbar\Laravel\MonologHandler::class,
            'access_token' => env('ROLLBAR_TOKEN'),
            'level' => 'debug',
            'scrub_fields' => [
                'Authorization',
                'HTTP_AUTHORIZATION',
                'HTTP_POSTMAN_TOKEN',
                'REDIRECT_HTTP_AUTHORIZATION',
                'postman-token',
                'Postman-Token',
                'auth',
                'order_notification_key',
                'order_content',
                'description',
                'merchant_code',
                'billing_country_code',
                'billing_address_line_1',
                'shipping_postal_code',
                'shipping_country_code',
                'shipping_address_line_1',
                'shipping_city',
                'responses',
                'authenticated_shopper_id',
                'references',
                'X_CSRF_TOKEN',
                'X-CSRF-TOKEN',
                'Persons',
                'passwd',
                'password',
                'secret',
                'confirm_password',
                'password_confirmation',
                'auth_token',
                'csrf_token',
                'national_id',
                'national_id_number',
                'national_insurance_number',
                'nationalInsuranceNumber',
                'driver_license',
                'driver_license_number',
                'birth_date',
                'birthdate',
                'birth_date',
                'birthdate',
                'birth_year',
                'birthyear',
                'birth_month',
                'birthmonth',
                'birth_day',
                'birthday',
                'email',
                'EMAIL',
                'Email',
                'email_address',
                'email-address',
                'emailAddress',
                'e-mail',
                'E-mail',
                'name',
                'forename',
                'Forename',
                'foreName',
                'ForeName',
                'FirstName',
                'firstName',
                'firstname',
                'FirstName',
                'surname',
                'Surname',
                'postcode',
                'post_code',
                'post-code',
                'Postcode',
                'PostCode',
                'number',
                'telephone',
                'phoneNumber',
                'telephoneNumber',
                'address',
                'homeAddress',
                'placeholders',
                'county',
                'line1',
                'address1',
                'addressLine1',
                'line2',
                'address2',
                'addressLine2',
                'line3',
                'address3',
                'addressLine3',
                'address_line',
                'address_line1',
                'address_line2',
                'address_line3',
                'address_street',
                'address_city',
                'address_state',
                'address_province',
                'address_country',
                'address_zip',
                'address_zipcode',
                'address_postal_code',
                'address_postcode',
                'address_zip_code',
                'address_zipcode',
                'addresspostalcode',
                'addresspostcode',
                'addresszipcode',
                'addresszip',
                'addresszip_code',
                'addresszip_code',
                'addresspostalcode',
                'addresspostcode',
                'addresszipcode',
                'addresszip',
                'addresszip_code',
                'addresszip_code',
                'addresspostalcode',
                'addresspostcode',
                'addresszipcode',
                'addresszip',
                'addresszip_code',
                'addresszip_code',
                'addresspostalcode',
                'addresspostcode',
                'addresszipcode',
                'billing_address',
                'billing_line1',
                'billingLine1',
                'billing_address1',
                'billing_line2',
                'billingLine2',
                'billing_address2',
                'billing_line3',
                'billing_address3',
                'billingLine3',
                'billing_street_address',
                'billing_street',
                'billing_city',
                'billing_state',
                'billing_province',
                'billing_country',
                'billing_zip',
                'billing_postal_code',
                'billing_postcode',
                'billing_zip_code',
                'billing_zipcode',
                'billing_postalcode',
                'billingpostcode',
                'billingzipcode',
                'billingzip',
                'registration',
                'reg',
                'vehicleRegistration',
                'vehicles',
                'Vehicles',
                'keyVRM'
            ]
        ],
    ],

];
