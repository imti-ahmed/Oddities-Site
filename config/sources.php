<?php
// THE LIST OF PLACES WE GET JOBS FROM.  Add one block per place.
//
// Types you can use:
//   'greenhouse'  -> company uses boards.greenhouse.io/NAME      => 'token' => 'NAME'
//   'lever'       -> company uses jobs.lever.co/NAME             => 'token' => 'NAME'
//   'ashby'       -> company uses jobs.ashbyhq.com/NAME          => 'token' => 'NAME'
//   'rss'         -> any RSS/Atom feed                            => 'url'   => 'https://...'
//
// 'name' is what shows in the "company" column.
// Add  'filter' => false  to skip the creative-keyword filter (use for boards that are 100% creative jobs).

return [
    // ---- EXAMPLES ONLY: replace these with your own list ----
    ['name' => 'Figma',   'type' => 'greenhouse', 'token' => 'figma'],
    ['name' => 'Spotify', 'type' => 'lever',      'token' => 'spotify'],
    ['name' => 'Ramp',    'type' => 'ashby',      'token' => 'ramp'],
];
