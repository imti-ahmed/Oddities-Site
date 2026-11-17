<?php
// The site's versions. The NEWEST release goes on TOP. The top one is the number shown beside the clock (as "V.2.4"), and every release
// also appears in the Update log as a "SITE UPDATE" line: the version, then the one short line in brackets.
//
//   X (before the dot) = MAJOR update: a big new part of the site or a redesign (for example a new section).
//   Y (after the dot)  = MINOR update: a smaller feature, change or fix people can notice.
//
// To release a new version: add one line at the top, with today's date (YYYY-MM-DD) and ONE short line saying what is new
// (plain words, no full stop, no em dash). Everything else updates by itself. Versions up to 1.3 were written afterwards from the project history.
return [
    ['version' => '2.11', 'date' => '2026-11-17', 'note' => 'Screenshots are kept in a local cache on the server, so they load faster'],
    ['version' => '2.10', 'date' => '2026-11-17', 'note' => 'Page views now come from the imticounter service'],
    ['version' => '2.9', 'date' => '2026-11-13', 'note' => 'Replaced screenshots now show within a minute instead of a day'],
    ['version' => '2.8', 'date' => '2026-11-13', 'note' => 'Admin mode: a cross on every card and row to remove portfolios, tools, companies and dead entries'],
    ['version' => '2.7', 'date' => '2026-11-13', 'note' => 'Companies tab renamed Cool Companies, and it shows 20 per page with page buttons, like the jobs'],
    ['version' => '2.6', 'date' => '2026-11-13', 'note' => 'Custom scrollbar, and Portfolios and Tools galleries show 15 cards per page'],
    ['version' => '2.5', 'date' => '2026-11-13', 'note' => 'Add several portfolios, tools or companies at once with commas'],
    ['version' => '2.4', 'date' => '2026-11-13', 'note' => 'Version numbers, Site Update lines in the Update log, safer pins'],
    ['version' => '2.3', 'date' => '2026-11-13', 'note' => 'NEW and UPDATED stamps on portfolios, tools and companies, lasting 36 hours'],
    ['version' => '2.2', 'date' => '2026-11-13', 'note' => 'Counts in the top bar, Total Oddities, new About page and rules'],
    ['version' => '2.1', 'date' => '2026-11-13', 'note' => 'Companies tab with short AI-written descriptions'],
    ['version' => '2.0', 'date' => '2026-11-13', 'note' => 'Oddities!: Portfolios and Tools galleries, one Dead Zone, screenshots in a Neon bucket'],
    ['version' => '1.3', 'date' => '2026-11-11', 'note' => 'Motion Designer, Imagineer and Dreamer added to the roles'],
    ['version' => '1.2', 'date' => '2026-11-05', 'note' => 'Automatic job collector and a nightly check that moves closed jobs to the Dead Zone'],
    ['version' => '1.1', 'date' => '2026-11-05', 'note' => 'Admin card with an AI job checker, jobs saved in a Neon database'],
    ['version' => '1.0', 'date' => '2026-11-05', 'note' => 'The job board: tabs, pinned jobs, Dead Zone and Update log'],
];
