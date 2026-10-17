<?php

return [
    'currency' => 'TND',
    'owner_subscription_monthly_tnd' => env('OWNER_SUBSCRIPTION_MONTHLY_TND', '20.000'),
    'student_service_fee_tnd' => env('STUDENT_SERVICE_FEE_TND', '20.000'),
    'listing_margin_tnd' => env('SAKANI_LISTING_MARGIN_TND', '20.000'),
    'maintenance_repair_policy' => [
        'default_covered' => env('MAINTENANCE_DEFAULT_COVERED', false),
        'owner_approval_required' => env('MAINTENANCE_OWNER_APPROVAL_REQUIRED', true),
        'max_photo_kb' => 5120,
    ],
];
