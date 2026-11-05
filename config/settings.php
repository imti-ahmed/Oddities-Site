<?php
// General settings. Safe to edit.

return [
    'site_name'     => 'Design Jobs',
    'tagline'       => 'Hand-picked creative jobs in India and abroad.',
    'jobs_per_page' => 30,     // how many rows show before the "More" button

    'new_days'   => 3,         // a job added/updated within this many days gets a glowing NEW / UPDATED badge and sits at the top
    'stale_days' => 90,        // a job not posted or updated for this many days moves to the Dead zone as "inactive"

    'contact_email' => 'designer.imtiyaz@gmail.com',   // shown on the About page

    // Page views come from a free public counter service (abacus.jasoncameron.dev), counted once per visitor session.
    // 'ns' + 'key' name your counter. To start the count again from zero, change 'key' to a new word (e.g. 'views2').
    'views_counter' => ['ns' => 'designjobs-imtiyaz-7c2e9f', 'key' => 'views'],
];
