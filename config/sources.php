<?php
// WHERE ELSE JOBS CAN BE FOUND (job sites, besides the company list in config/companies.php).
//
// 'use'     = sites the collector reads. RIGHT NOW THIS IS EMPTY: the board is fed only by the companies in config/companies.php.
//             To switch a site back on, move its line from 'paused' to 'use'.
// 'paused'  = sites we chose not to read for now.
// 'skip'    = sites left out on purpose.
//
// 'how' says the way a site is read:
//   rss  = the site publishes a feed (easiest, clean)
//   api  = the site has an official free API
//   page = we read the normal web page (slower, can break if the site changes its design)
// Every job found goes through the same AI check (config/groq_prompt.txt) before it is listed.
// We keep only a title, company, location, date and a link back to the original page.

return [
    'use' => [
    ],

    'paused' => [
        ['name' => 'Design Jobs Board',        'url' => 'https://www.designjobsboard.com/',                 'how' => 'rss',  'feed' => 'https://www.designjobsboard.com/feed'],
        ['name' => 'Hacker News jobs',         'url' => 'https://news.ycombinator.com/jobs',                'how' => 'api',  'api'  => 'https://hacker-news.firebaseio.com/v0/jobstories.json', 'note' => 'official API; the site asks for a 30 second pause between page requests, the API has no such limit'],
        ['name' => 'Young Designers India',    'url' => 'https://www.youngdesignersindia.com/Jobs',         'how' => 'page'],
        ['name' => 'Creative Boom jobs',       'url' => 'https://www.creativeboom.com/jobs/',               'how' => 'page', 'note' => 'its rules file blocks only a small part of the site (/portal/)'],
        ['name' => 'UI UX Jobs Board (India)', 'url' => 'https://uiuxjobsboard.com/design-jobs/india',      'how' => 'page', 'note' => 'its feed is blocked, so we read the page'],
        ['name' => 'UI UX Jobs Board (global)', 'url' => 'https://uiuxjobsboard.com/design-jobs',          'how' => 'page'],
    ],

    'skip' => [
        ['name' => 'YC jobs (ycombinator.com/jobs)', 'why' => 'YC\'s job site (Work at a Startup) refuses automated visitors, so it is left out on purpose'],
    ],
];
