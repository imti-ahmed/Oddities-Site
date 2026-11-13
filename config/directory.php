<?php
// Portfolios and Tools. Edit freely.
//
// The kinds of tool (the "Tag" of a tool). When you add a tool on /admin, the AI picks ONE of these. Add or remove a line to change the list.
// (Portfolios have no tag.)
return [
    'tool_tags' => [
        'Design'       => 'general design and UI tools, design apps, plugins',
        'Prototyping'  => 'prototypes, wireframes, app mockups, interaction design',
        'Color'        => 'colour palettes, gradients, contrast checkers',
        'Typography'   => 'fonts, type pairing, font finders, type tools',
        'Icons'        => 'icon sets and icon tools',
        'Illustration' => 'illustrations, drawing tools, vectors, doodles',
        'Images'       => 'photo editing, stock images, mockups, image generators, screenshots',
        'Motion'       => 'animation, video, Lottie, motion design',
        '3D'           => '3D tools, scenes, models, WebGL',
        'Code'         => 'code, CSS and web tools for builders and design engineers',
        'AI'           => 'tools built around AI models',
        'Inspiration'  => 'galleries, references, mood boards, things to look at',
        'Productivity' => 'writing, notes, planning, handoff, organising work',
        'Learning'     => 'courses, guides, tutorials, practice',
        'Fun'          => 'toys, experiments, games, little internet gems',
        'Other'        => 'anything that fits none of the above',
    ],
    'recheck_days'  => 14,   // every site is checked for "still working?" this often
    'dead_after'    => 2,    // checks in a row that say "gone" before a site moves to the Dead Zone
];
