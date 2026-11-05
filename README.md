# Design Jobs

A simple website (HTML + CSS + PHP + a little JavaScript) that lists creative jobs in a clean table.
Style: a clean, rounded table with soft blue tones, pill tabs and tags, and comic-style NEW!/UPDATED! stickers.

## What's on the page

- **Tabs** (all on the same page, no reloading): All, India, Abroad, Remote, In-office (Hybrid appears automatically if any job is hybrid), **Dead zone**, **Update log**, **About**.
- **Table** with # / Job / Company & location / Tags / Posted / pin star. Search box + Tag and Company filters. Click a tag to filter by it.
- **Pinned box** at the side: click the star on a job to pin it. Pins are saved in the visitor's own browser (localStorage), nothing is stored on a server.
- **Comic-style NEW! / UPDATED! stickers**: jobs added or updated in the last few days sit at the top (static, no blinking).
- **Dead zone**: jobs that are `closed`, or *inactive* (not posted/updated for 90+ days). Kept for reference, never deleted.
- **Update log**: generated automatically from the jobs' dates (added / updated / closed / inactive).
- A banner header with a scrolling ticker, a status bar, and a footer.

## Files

- `index.php` – the page. `style.css` – the look (change colours at the top). `assets/app.js` – tabs, search, filters, pins.
- `lib/jobs.php` – works out each job's tab, tags, badge and Dead-zone status.
- `config/settings.php` – site name, tagline, rows per page, `new_days` (badge window), `stale_days` (inactive after).
- `data/jobs.json` – the jobs. No database, no admin page.

## The jobs file

Each job in `data/jobs.json`:

```json
{
  "title": "Senior Product Designer",
  "company": "Acme",
  "location": "Bengaluru, India",
  "url": "https://...",
  "posted_at": "2026-11-03"
}
```

Only those five are required. Optional extras:

| Field | What it does |
|---|---|
| `region` | `india` or `abroad`. If missing, guessed from `location`. |
| `mode` | `remote`, `hybrid` or `in-office`. If missing, guessed from the text (default in-office). |
| `tags` | A list like `["Brand", "Senior"]`. If missing, tags are guessed from the title. |
| `added_at` | Date added to the site. If missing, `posted_at` is used. A job added in the last 3 days gets a **NEW** badge. |
| `updated_at` | Date it was changed. A job updated in the last 3 days gets an **UPDATED** badge. |
| `status` | Set to `"closed"` to move it to the Dead zone. Optionally add `closed_at` and `closed_reason`. |

## See it on your computer

```bash
php -S localhost:8000
```

Then open http://localhost:8000

## On Vercel

`vercel.json` makes Vercel run the PHP code (via the `vercel-php` add-on). Nothing else to set up.

## Coming next

Use the Groq AI API to read job links, understand the posts (remote/hybrid, level, tags), and generate the job data automatically.
