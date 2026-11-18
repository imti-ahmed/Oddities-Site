<?php
// COMPANIES WE WATCH for design jobs. Edit freely: add a line to add a company, delete a line to drop one.
//
// Every company here has a job board that can be read automatically (Greenhouse, Lever, Ashby or SmartRecruiters).
// 'ats' + 'token' is how to find it. The token is the company's name in its job board address, for example
// jobs.ashbyhq.com/cursor has the token "cursor".
// (A company with its own career site that cannot be read automatically cannot be listed here. Jobs from such a
// company can still be added by hand: paste the job link into "Add job" on /meow.)

return [
    'checked' => '2026-11-06',

    'readable' => [
        // ---- Product & tools
        ['name' => 'Figma',      'group' => 'Product & tools', 'ats' => 'greenhouse', 'token' => 'figma'],
        ['name' => 'Notion',     'group' => 'Product & tools', 'ats' => 'ashby',      'token' => 'notion'],
        ['name' => 'Vercel',     'group' => 'Product & tools', 'ats' => 'greenhouse', 'token' => 'vercel'],

        // ---- AI
        ['name' => 'Cursor',     'group' => 'AI',              'ats' => 'ashby',      'token' => 'cursor'],
        ['name' => 'Lovable',    'group' => 'AI',              'ats' => 'ashby',      'token' => 'lovable'],
        ['name' => 'Mistral AI', 'group' => 'AI',              'ats' => 'ashby',      'token' => 'mistral.ai'],

        // ---- India
        ['name' => 'Zomato',     'group' => 'India',           'ats' => 'lever',      'token' => 'eternal'],
    ],
];
