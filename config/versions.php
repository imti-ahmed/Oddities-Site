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
    ['version' => '2.22', 'date' => '2026-11-26', 'note' => 'New Todays Spotlights card: a job, a company, a portfolio and a tool picked fresh every day'],
    ['version' => '2.21', 'date' => '2026-11-19', 'note' => 'The +N pill for companies with several jobs now also shows on phones'],
    ['version' => '2.20', 'date' => '2026-11-19', 'note' => 'A little more room between the tabs in the top bar'],
    ['version' => '2.19', 'date' => '2026-11-19', 'note' => 'Tabs and pages now show in the address (for example ?tab=india&page=2) so links open the right tab, a +N pill on companies with several jobs, new welcome card headlines, less empty space at the bottom'],
    ['version' => '2.18', 'date' => '2026-11-19', 'note' => 'Same company now always has the same name across its jobs, remote jobs simply say Remote, and a new tab title'],
    ['version' => '2.16', 'date' => '2026-11-19', 'note' => 'A pop-up when you pin or unpin something. Phone menu: dimmed page behind it, a My Pinned page with the Pinned card, and the Product Hunt badge inside it, and the last view count shows instantly'],
    ['version' => '2.15', 'date' => '2026-11-19', 'note' => 'Welcome loader while the page and pictures get ready, plus quick reveal and tab change animations'],
    ['version' => '2.13', 'date' => '2026-11-18', 'note' => 'Product Hunt badge in the top bar, Update log is now Updates, tighter tabs, no clock'],
    ['version' => '2.11', 'date' => '2026-11-17', 'note' => 'Screenshots are kept in a local cache on the server, so they load faster'],
    ['version' => '2.10', 'date' => '2026-11-17', 'note' => 'Page views now come from the imticounter service'],
    ['version' => '2.9', 'date' => '2026-11-13', 'note' => 'Replaced screenshots now show within a minute instead of a day'],
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
