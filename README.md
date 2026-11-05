# Design Jobs

A very simple website (HTML + CSS + PHP) that lists creative jobs, styled like the Hacker News jobs page, in blue.
Two tabs on the same page, **India** and **Abroad**, plus a live search box. Switching tabs never reloads the page.

## How it works

- `index.php` + `style.css` – the website (change colours at the top of `style.css`).
- `data/jobs.json` – the list of jobs. No database, no admin page. Each job looks like:
  `{ "title": "...", "company": "...", "location": "...", "url": "https://...", "posted_at": "2026-11-03", "region": "india" }`
  (`region` is `india` or `abroad`. If it's left out, it's guessed from the location.)

To change the jobs, edit `data/jobs.json`.

## See it on your computer

```bash
php -S localhost:8000
```

Then open http://localhost:8000

## On Vercel

`vercel.json` makes Vercel run the PHP code (via the `vercel-php` add-on). Nothing else to set up.

## Coming next

Use the Groq AI API to read job links, understand the posts, and generate the job data automatically.
