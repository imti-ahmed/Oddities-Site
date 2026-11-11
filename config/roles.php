<?php
// THE ROLES WE LIST, and the rules the AI follows when it checks a job.
// Plain-English file: edit the wording, add or remove roles, change a rule. The AI prompt (config/groq_prompt.txt) is built from this.
//
// 'tag'    = the name of the role (what the AI decides the job is).
// 'category' = the label shown on the site in the Tag column. Several roles share one category (see 'categories' below).
// 'means'  = what the person actually does (the AI judges by this, not only by the title).
// 'titles' = example titles that usually mean this role.
// 'not'    = things that look similar but do NOT count.

return [

    // THE TAG SHOWN ON THE SITE. Every listed job gets exactly one of these.
    'categories' => [
        ['name' => 'Product',    'means' => 'designing digital or physical products: UI and UX, product design, interaction design, design systems, CMF (colour, material, finish)'],
        ['name' => 'Brand',      'means' => 'brand identity and branding work, including packaging design'],
        ['name' => 'Creative',   'means' => 'art direction and creative craft, including illustration, motion design and imagineering'],
        ['name' => 'Visual',     'means' => 'visual and graphic design: layouts, graphics, game UI art'],
        ['name' => 'Research',   'means' => 'UX, user and design research'],
        ['name' => 'Technology', 'means' => 'design combined with code: design engineering, creative technology, creative coding'],
        ['name' => 'AI',         'means' => 'the work itself is about AI: generative image or video, designing AI or agent products and interfaces, prompt or vibe coding'],
    ],

    'tags' => [
        ['tag' => 'UI/UX Designer',
         'category' => 'Product',
         'means'  => 'designs the screens, flows and look of apps and websites',
         'titles' => 'UI Designer, UX Designer, UI/UX Designer, Web Designer, App Designer',
         'not'    => 'a front-end developer who does no design'],

        ['tag' => 'Product Designer',
         'category' => 'Product',
         'means'  => 'designs a digital product end to end (research, flows, interface, shipping with engineers); includes design leads and heads of product design',
         'titles' => 'Product Designer, Senior / Staff / Principal / Lead Product Designer, Head of Product Design, Design Lead',
         'not'    => 'a Product Manager, who is not a designer'],

        ['tag' => 'UX Researcher',
         'category' => 'Research',
         'means'  => 'studies users to guide design: interviews, usability tests, surveys',
         'titles' => 'UX Researcher, User Researcher, Design Researcher, Research Lead (design)',
         'not'    => 'market research, data analyst or data scientist jobs'],

        ['tag' => 'Design Engineer',
         'category' => 'Technology',
         'means'  => 'works as both designer and front-end engineer, building polished interfaces and prototypes in code',
         'titles' => 'Design Engineer, UX Engineer, Design Technologist (interface focus)',
         'not'    => 'a general software engineer'],

        ['tag' => 'Creative Technologist',
         'category' => 'Technology',
         'means'  => 'mixes creativity and technology to prototype new experiences (interactive, AR/VR, installations, brand tech)',
         'titles' => 'Creative Technologist, Design Technologist (experimental focus)',
         'not'    => 'IT support or a general developer'],

        ['tag' => 'Visual Designer',
         'category' => 'Visual',
         'means'  => 'owns the visual look: layout, colour, type and imagery for products or campaigns',
         'titles' => 'Visual Designer, Digital Designer, Visual Communication Designer',
         'not'    => 'a pure marketing manager'],

        ['tag' => 'Graphic Designer',
         'category' => 'Visual',
         'means'  => 'makes graphics for print and digital: marketing, social, presentations, editorial',
         'titles' => 'Graphic Designer, Communication Designer, Marketing Designer, Social Media Designer, Presentation Designer',
         'not'    => 'a social media manager who does no design'],

        ['tag' => 'Illustrator',
         'category' => 'Creative',
         'means'  => 'draws original illustrations (digital or traditional) as the main part of the job',
         'titles' => 'Illustrator, Digital Illustrator, Character Illustrator',
         'not'    => 'concept artists for games and film unless the posting calls it illustration'],

        ['tag' => 'Motion Designer',
         'category' => 'Creative',
         'means'  => 'makes motion graphics and animation: animated brand and marketing visuals, explainer and social animations, title design, product and UI animation',
         'titles' => 'Motion Designer, Motion Graphics Designer, Animator (motion graphics), Senior / Lead Motion Designer',
         'not'    => 'a pure video editor, a 3D or game animator, or a film VFX artist unless the posting calls it motion design'],

        ['tag' => 'Imagineer',
         'category' => 'Creative',
         'means'  => 'imagines and designs immersive experiences, themed environments, attractions or story-led concepts, blending design, storytelling and technology',
         'titles' => 'Imagineer, Concept Imagineer, Creative Imagineer, Experience Imagineer',
         'not'    => 'a construction, civil or ride-maintenance engineer, or a theme park operations job'],

        ['tag' => 'Dreamer',
         'category' => 'Creative',
         'means'  => 'a blue-sky creative who dreams up new ideas, worlds and concepts and shapes them into visual or story-led directions',
         'titles' => 'Dreamer, Creative Dreamer, Concept Dreamer, Ideation Lead',
         'not'    => 'a vague posting that only uses the word "dream" (for example "dream job") without real creative design work'],

        ['tag' => 'Interaction Designer',
         'category' => 'Product',
         'means'  => 'designs how an interface behaves: transitions, micro-interactions, prototypes of behaviour',
         'titles' => 'Interaction Designer, IxD, UI Motion Designer',
         'not'    => 'general motion graphics or video animation'],

        ['tag' => 'Design System Designer',
         'category' => 'Product',
         'means'  => 'builds and looks after a design system: components, tokens, guidelines (hands-on design work)',
         'titles' => 'Design Systems Designer, Design System Lead, Design Systems Manager',
         'not'    => 'a general project or operations manager'],

        ['tag' => 'Art Director',
         'category' => 'Creative',
         'means'  => 'leads the visual direction of campaigns, brands, products or games',
         'titles' => 'Art Director, Senior / Associate Art Director; Creative Director only when the role is clearly visual-design led',
         'not'    => 'a marketing or account director'],

        ['tag' => 'Packaging Designer',
         'category' => 'Brand',
         'means'  => 'designs product packaging, its structure or its graphics',
         'titles' => 'Packaging Designer, Packaging Graphic Designer, Structural Packaging Designer',
         'not'    => 'logistics or supply-chain packaging jobs'],

        ['tag' => 'Brand Designer',
         'category' => 'Brand',
         'means'  => 'creates and applies a brand identity: logo, visual system, brand guidelines, brand creative',
         'titles' => 'Brand Designer, Brand Identity Designer, Brand Creative, Head of Brand (design led)',
         'not'    => 'a Brand Manager or marketing role with no design work'],

        ['tag' => 'Game UI Artist',
         'category' => 'Visual',
         'means'  => 'designs and builds the interface of video games: HUD, menus, icons, UX for games',
         'titles' => 'Game UI Artist, UI Artist, Game UI Designer, Game UX Designer',
         'not'    => 'a gameplay programmer, level designer or 3D artist'],

        ['tag' => 'CMF Designer',
         'category' => 'Product',
         'means'  => 'chooses colour, material and finish for physical products (devices, vehicles, appliances, furniture)',
         'titles' => 'CMF Designer, Colour & Materials Designer, Colour Material Finish Designer',
         'not'    => 'fashion stylists or interior decorators'],

        ['tag' => 'Vibe Coder',
         'category' => 'AI',
         'means'  => 'builds working products or prototypes mainly by directing AI coding tools ("vibe coding"); the posting must say so',
         'titles' => 'Vibe Coder, AI Prototyper, AI Product Builder',
         'not'    => 'an AI/ML engineer, or any job that only mentions AI in passing'],

        ['tag' => 'Creative Coder',
         'category' => 'Technology',
         'means'  => 'uses code as a creative medium: generative art, WebGL / three.js / shaders, interactive visuals',
         'titles' => 'Creative Coder, Creative Developer, Generative Artist, Creative Technologist (code heavy)',
         'not'    => 'a general front-end or back-end developer'],

        ['tag' => 'Internship',
         'means'  => 'any internship, trainee or apprenticeship place in one of the roles above',
         'titles' => 'Design Intern, UX Intern, Product Design Intern, Illustration Intern, Graphic Design Trainee',
         'not'    => 'an internship in sales, marketing, engineering or any field outside the roles above'],
    ],

    // ---- rules the code applies after the AI answers
    'rules' => [
        'min_confidence'               => 0.6,    // below this the AI is "unsure": the job is NOT listed
        'global_remote_counts_as_india' => false, // "Remote, worldwide" jobs: false = listed as Abroad, true = listed as India
        'max_page_chars'               => 14000,  // how much of the page text is sent to the AI
        'batch_size'                   => 8,      // how many jobs are checked in ONE AI call (fewer calls = faster, and Groq asks us to wait less)
        'batch_page_chars'             => 1200,   // text per job when several are sent together
        'fast_path'                    => true,   // true = jobs with a clear title ("Product Designer", "Brand Designer", ...) whose board gives the
                                                  // location are listed by plain code, without asking the AI. false = the AI checks every job.
    ],
];
