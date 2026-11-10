<?php

return [

    /*
    |--------------------------------------------------------------------------
    | V1 cut-over timestamp
    |--------------------------------------------------------------------------
    |
    | Canonical imported-V1 boundary. The value lives on ReservationOrigin so
    | origin stays independent of user_id and is not overridden by env.
    | Heuristic reconstruction still also requires user_id null. Attributed
    | V1 rows leave that pool. Official V2 statistics use the same boundary
    | to exclude imported rows after user_id is set.
    |
    */
    'v1_cutover_at' => \App\Support\ReservationOrigin::IMPORTED_BEFORE,

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
