<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Production checklist steps (merchant-defined tags on the order)
    |--------------------------------------------------------------------------
    |
    | Each step is complete when its `tag` appears on the order (case-insensitive).
    | Adjust tag strings to match exactly what staff use in Shopify Admin.
    |
    */
    'steps' => [
        [
            'key' => 'suppliers_ordered',
            'label_he' => 'הוזמנו חלקים אצל הספקים',
            'tag' => 'stage-suppliers-ordered',
            'eta_days' => 3,
        ],
        [
            'key' => 'aluminum_received',
            'label_he' => 'התקבל אלומיניום מהלייזר',
            'tag' => 'stage-aluminum-received',
            'eta_days' => 2,
        ],
        [
            'key' => 'mirror_received',
            'label_he' => 'התקבלה הזכוכית',
            'tag' => 'stage-mirror-received',
            'eta_days' => 2,
        ],
        [
            'key' => 'frame_sent_paint',
            'label_he' => 'המסגרת נשלחה לצביעה',
            'tag' => 'stage-frame-paint-sent',
            'eta_days' => 3,
        ],
        [
            'key' => 'frame_back_paint',
            'label_he' => 'המסגרת חזרה מהצביעה',
            'tag' => 'stage-frame-paint-back',
            'eta_days' => 2,
        ],
        [
            'key' => 'product_ready',
            'label_he' => 'המוצר מוכן',
            'tag' => 'stage-product-ready',
            'eta_days' => 1,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment gate
    |--------------------------------------------------------------------------
    |
    | If financial_status is one of these, customer sees a payment-hold message
    | and ETA for shipping/release may be suppressed or shown as blocked.
    |
    */
    'payment_blocking_financial_statuses' => [
        'pending',
        'partially_paid',
        'authorized',
    ],

    /*
    |--------------------------------------------------------------------------
    | Pickup vs delivery (optional tags)
    |--------------------------------------------------------------------------
    */
    'pickup_tag' => 'fulfillment-pickup',
    'delivery_tag' => 'fulfillment-delivery',

];
