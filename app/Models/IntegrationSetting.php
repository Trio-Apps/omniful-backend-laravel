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
        'omniful_tenant_code',
        'omniful_seller_code',
        'omniful_access_token',
        'omniful_refresh_token',
        'omniful_token_expires_in',
        'omniful_access_token_expires_at',
        'omniful_seller_api_key',
        'omniful_seller_api_secret',
        'omniful_seller_webhook_secret',
        'omniful_seller_access_token',
        'omniful_seller_refresh_token',
        'omniful_seller_token_expires_in',
        'omniful_seller_access_token_expires_at',
    ];

    protected $casts = [
        'sap_password' => 'encrypted',
        'sap_ssl_verify' => 'boolean',
        'omniful_api_key' => 'encrypted',
        'omniful_api_secret' => 'encrypted',
        'omniful_webhook_secret' => 'encrypted',
        'omniful_access_token' => 'encrypted',
        'omniful_refresh_token' => 'encrypted',
        'omniful_token_expires_in' => 'integer',
        'omniful_access_token_expires_at' => 'datetime',
        'omniful_seller_api_key' => 'encrypted',
        'omniful_seller_api_secret' => 'encrypted',
        'omniful_seller_webhook_secret' => 'encrypted',
        'omniful_seller_access_token' => 'encrypted',
        'omniful_seller_refresh_token' => 'encrypted',
        'omniful_seller_token_expires_in' => 'integer',
        'omniful_seller_access_token_expires_at' => 'datetime',
    ];
}
