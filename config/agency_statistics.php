<?php

return [

    /*
    |--------------------------------------------------------------------------
    | V1 cut-over timestamp
    |--------------------------------------------------------------------------
    |
    | Reservations with user_id = null and created_at strictly before this
    | moment are treated as the unmigrated V1 historical pool for heuristic
    | reconstruction on the agency detail page. Post-cut-over guest reservations
    | are excluded from that pool.
    |
    */
    'v1_cutover_at' => env('AGENCY_STATS_V1_CUTOVER_AT', '2026-06-19 00:00:00'),

    /*
    |--------------------------------------------------------------------------
    | Heuristic cache TTL (seconds)
    |--------------------------------------------------------------------------
    */
    'heuristic_cache_ttl' => (int) env('AGENCY_STATS_HEURISTIC_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Name similarity threshold (0–100, similar_text percent)
    |--------------------------------------------------------------------------
    */
    'name_similarity_threshold' => 80,

    /*
    |--------------------------------------------------------------------------
    | Public/free email domains (ignore for domain heuristic matching)
    |--------------------------------------------------------------------------
    |
    | Domain match is a medium-confidence heuristic intended only for business
    | domains (e.g. booking@montetravel.me vs info@montetravel.me).
    | Public/free email providers must NOT be used for domain matching nor SQL
    | prefiltering (e.g. gmail.com would create massive false positives).
    |
    */
    'public_email_domains' => [
        'gmail.com',
        'googlemail.com',
        'yahoo.com',
        'hotmail.com',
        'outlook.com',
        'live.com',
        'icloud.com',
        'me.com',
        'aol.com',
        'proton.me',
        'protonmail.com',
        'tutanota.com',
        'mail.com',
        'gmx.com',
        'gmx.de',
        't-com.me',
        't-com.hr',
        't-com.rs',
        'mts.rs',
        'yandex.com',
        'yandex.ru',
        'mail.ru',
        'bk.ru',
    ],

];
