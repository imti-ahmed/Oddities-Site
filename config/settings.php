<?php
// General settings. Safe to edit.

return [
    'site_name'     => 'Jobbies!',
    'tagline'       => 'Hand-picked creative jobs in India and abroad.',
    'jobs_per_page' => 20,     // how many rows show on each page (the page buttons appear below the table)

    // NEW / UPDATED pills. Set this to false to hide them (the list is then simply newest first).
    // While they are on, recently added/updated jobs also move to the top of the list.
    'show_badges' => true,
    'new_days'   => 3,         // a job added/updated within this many days gets a NEW / UPDATED pill
    'new_badge_from' => '2026-11-06',   // jobs added BEFORE this date never get a NEW pill (so the first big fill is not all "NEW"). Later jobs do. Use '' to switch this off.
    'stale_days' => 90,        // a job not posted or updated for this many days moves to the Dead Zone as "inactive"

    'contact_email' => 'designer.imtiyaz@gmail.com',   // shown on the About page

    // Page views come from a free public counter service (abacus.jasoncameron.dev), counted once per visitor session.
    // 'ns' + 'key' name your counter. To start the count again from zero, change 'key' to a new word (e.g. 'views2').
    'views_counter' => ['ns' => 'designjobs-imtiyaz-7c2e9f', 'key' => 'views'],
];
