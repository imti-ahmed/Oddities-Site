<?php
// General settings. Safe to edit.

return [
    'site_name'     => 'JobBoard',
    'tagline'       => 'Hand-picked creative jobs in India and abroad.',
    'jobs_per_page' => 20,     // how many rows show on each page (the page buttons appear below the table)

    // NEW / UPDATED pills. Set this to false to hide them (the list is then simply newest first).
    // While they are on, recently added/updated jobs also move to the top of the list.
    'show_badges' => true,
    'new_days'   => 3,         // a job added/updated within this many days gets a NEW / UPDATED pill
    'stale_days' => 90,        // a job not posted or updated for this many days moves to the Dead zone as "inactive"
    'dead_limit' => 20,        // the Dead zone shows only this many entries (the most recently removed); older ones drop off the list

    'contact_email' => 'designer.imtiyaz@gmail.com',   // shown on the About page

    // Page views come from a free public counter service (abacus.jasoncameron.dev), counted once per visitor session.
    // 'ns' + 'key' name your counter. To start the count again from zero, change 'key' to a new word (e.g. 'views2').
    'views_counter' => ['ns' => 'designjobs-imtiyaz-7c2e9f', 'key' => 'views'],
];
