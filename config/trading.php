<?php

declare(strict_types=1);

return [

    'market_buy_buffer' => env('TRADING_MARKET_BUY_BUFFER', '0.05'),

    'price_max_age_seconds' => (int) env('TRADING_PRICE_MAX_AGE_SECONDS', 30),

    'default_limit_ttl_minutes' => 60 * 24,

    'order_rate_per_minute' => (int) env('TRADING_ORDER_RATE_PER_MINUTE', 30),

    'wallet_currency' => 'USD',

    'summary' => [
        'ttl_seconds' => 60,
        'refresh_debounce_seconds' => 5,
        'lock_seconds' => 10,
    ],

];
