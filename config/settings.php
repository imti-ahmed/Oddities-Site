<?php
// General settings. Safe to edit.

return [
    'site_name'     => 'Oddities!',
    'tagline'       => 'Hand-picked creative jobs in India and abroad.',
    'jobs_per_page' => 20,     // how many rows show on each page (the page buttons appear below the table)

    // NEW / UPDATED pills. Set this to false to hide them (the list is then simply newest first).
    // While they are on, recently added/updated jobs also move to the top of the list.
    'show_badges' => true,
    'new_hours'  => 36,        // an entry (job, portfolio, tool, company) added or updated within this many hours gets a NEW / UPDATED pill
    'new_badge_from' => '2026-11-05 20:30:00 UTC',   // jobs saved BEFORE this exact moment never get a NEW pill (the jobs that were already on the site). Jobs saved after it do. Use '' to switch this off.
    'stale_days' => 90,        // a job not posted or updated for this many days moves to the Dead Zone as "inactive"

    'contact_email' => 'designer.imtiyaz@gmail.com',   // shown on the About page
];
