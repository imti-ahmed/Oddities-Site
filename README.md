# Design Jobs

A very simple website (HTML + CSS + PHP) that lists creative jobs, styled like the Hacker News jobs page.

## How it works

- `index.php` + `style.css` – the website.
- `data/jobs.json` – the list of jobs. Each job looks like:
  `{ "title": "...", "company": "...", "location": "...", "url": "https://...", "posted_at": "2026-11-03" }`
- No database. To change what the site shows, edit `data/jobs.json`.

## See it on your computer

```bash
php -S localhost:8000
```

Then open http://localhost:8000

## On Vercel

`vercel.json` tells Vercel to run the PHP code (using the `vercel-php` add-on). Nothing else to set up.

## Coming next

Use the Groq AI API to read job links, understand the posts, and generate the job data automatically.
(The earlier finder/tester scripts and database code are saved in this project's git history if we want them back.)
