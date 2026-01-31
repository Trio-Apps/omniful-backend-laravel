<?php

return [
    'webhook_signature_header' => env('OMNIFUL_WEBHOOK_SIGNATURE_HEADER', 'X-Omniful-Signature'),
    'webhook_signature_algo' => env('OMNIFUL_WEBHOOK_SIGNATURE_ALGO', 'sha256'),
];
