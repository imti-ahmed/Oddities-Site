<?php
// General settings. Safe to edit.

return [
    'site_name'     => 'Design Jobs',
    'jobs_per_page' => 30,

    // A job is only kept if its TITLE matches one of these words (so you get creative roles only).
    // Each entry is a "pattern": \b means "start of a word". Add or remove freely.
    'creative_keywords' => [
        'design', 'designer', 'art director', 'creative', 'illustrat', 'animat', 'motion',
        'brand', 'visual', 'typograph', 'graphic', 'video', 'photograph', 'copywrit',
        'concept art', '3d', 'ux', 'ui', 'product design', 'storyboard', 'cinematograph',
        'content creator', 'editor',
    ],

    // ...but it is dropped if the title ALSO contains one of these (e.g. "Software Engineer - Interaction Design").
    'exclude_keywords' => [
        'software engineer', 'software engineering', 'recruit', 'people partner', 'sales',
        'account executive', 'counsel', 'legal', 'finance', 'accountant', 'security engineer',
    ],

    // --- Tester settings ---
    'check_batch_size'   => 150,  // how many job links the tester visits per run
    'check_delay_ms'     => 400,  // pause between visits so we are polite to websites
    'close_after_fails'  => 3,    // page unreachable this many runs in a row => mark closed
    'purge_closed_days'  => 30,   // closed jobs are deleted from the database after this long

    // If a job page (HTTP 200) contains any of these phrases, we treat the job as gone.
    'closed_phrases' => [
        'no longer available',
        'no longer accepting applications',
        'position has been filled',
        'this job has expired',
        'job is no longer open',
        'this position is closed',
        'this job is closed',
        'job not found',
        'posting has been removed',
        'we could not find the job',
    ],
];
