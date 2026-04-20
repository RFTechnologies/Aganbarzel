<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Production checklist (tags on the order in Shopify Admin)
    |--------------------------------------------------------------------------
    |
    | Each step is complete when its `tag` appears on the order (case-insensitive).
    | `eta_days` is summed for steps not yet done (unless payment is blocked).
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
    | When financial_status matches, customer sees payment_message_he and ETA
    | for remaining steps is not counted toward the "days left" summary.
    |
    */
    'payment_blocking_financial_statuses' => [
        'pending',
        'partially_paid',
        'authorized',
    ],

    'payment_message_he' => 'קיימת יתרת תשלום. שחרור המוצר או המשלוח יתאפשרו לאחר סגירת החשבון במלואו.',

    /*
    |--------------------------------------------------------------------------
    | Pickup vs delivery (tags on the order)
    |--------------------------------------------------------------------------
    */
    'pickup_tag' => 'fulfillment-pickup',
    'delivery_tag' => 'fulfillment-delivery',

    /*
    |--------------------------------------------------------------------------
    | When all checklist steps are done, payment is OK, and branch is known
    |--------------------------------------------------------------------------
    */
    'fulfillment_message_pickup_he' => 'ההזמנה מוכנה לאיסוף עצמי. תקבלו מהחנות הודעה עם הוראות לאיסוף.',

    'fulfillment_message_delivery_he' => 'ההזמנה מוכנה למשלוח. תקבלו עדכון כשהמשלוח יישלח.',

];
