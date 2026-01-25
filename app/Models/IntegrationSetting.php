<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationSetting extends Model
{
    protected $fillable = [
        'sap_service_layer_url',
        'sap_company_db',
        'sap_username',
        'sap_password',
        'sap_ssl_verify',
        'omniful_api_url',
        'omniful_api_key',
        'omniful_api_secret',
        'omniful_webhook_secret',
    ];

    protected $casts = [
        'sap_password' => 'encrypted',
        'sap_ssl_verify' => 'boolean',
        'omniful_api_key' => 'encrypted',
        'omniful_api_secret' => 'encrypted',
        'omniful_webhook_secret' => 'encrypted',
    ];
}
