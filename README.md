# JobBoard

A simple website (HTML + CSS + PHP + a little JavaScript) that lists creative jobs in a clean table.
Style: a clean table with early-2000s Mac touches (a menu bar, mini-window cards with traffic-light dots in a pink / orange / blue rotation (add `pink` or `orange` to a card's class in `index.php`; no class = blue), glossy blue pill buttons, faint dotted background) and small comic-style NEW / UPDATED pills (halftone dots, thick outline).

## What's on the page

- **Menu bar** at the top with the **tabs** (all on the same page, no reloading): All, India, Abroad, Remote, In-office (Hybrid appears automatically if any job is hybrid), **Dead zone**, **Update log**, **About**.
- **Table** with # / Job / Company & location / Tag / Posted / pin star. Each job has exactly **one tag** (the kind of job, e.g. Product Design, Brand, Motion), shown as plain text (same size and boldness as the role name). No search box and no filters: just the list.
- **Pinned box** at the side: click the star on a job to pin it. Pins are saved in the visitor's own browser (localStorage), nothing is stored on a server.
- **NEW / UPDATED pills**: small ovals in capitals with black text and a thick coloured outline, right after the role name. The fill is a comic-book halftone (rows of little dots): green for NEW, amber for UPDATED. They blink gently (still for people who turned motion off in their device). Jobs added or updated in the last few days get one and sit at the top. To hide them, set `show_badges` to `false` in `config/settings.php`; the list is then simply newest first.
- **Dead zone**: jobs that are `closed`, or *inactive* (not posted/updated for 90+ days). Kept for reference, never deleted.
- **Update log**: generated automatically from the jobs' dates (added / updated / closed / inactive). One block per day with a full-width line after it. Inside a day there is one pill per kind of change (ADDED green, UPDATED amber, CLOSED red, INACTIVE grey, same halftone style as the NEW / UPDATED pills), with all the jobs for that change listed beside it, separated by commas.
- **About page**: five cards. The last two are a contact card (`contact_email` in `config/settings.php`) and a stats card: total jobs, last update, and **views**. Views come from a free public counter service (abacus.jasoncameron.dev); each visitor counts once per browser session, and visits from `localhost` are never counted. If the service is down, the number shows a dash. To restart the count from zero, change `views_counter.key` in `config/settings.php`.

## Files

- `index.php` – the page. `style.css` – the look (change colours at the top). `assets/app.js` – tabs, page numbers, pins.
- `lib/jobs.php` – works out each job's tab, tags, badge and Dead-zone status.
- `config/settings.php` – site name, tagline, rows per page, `new_days` (badge window), `stale_days` (inactive after).
- `data/jobs.json` – the jobs. No database.
- `config/roles.php` – the list of roles we accept (UI/UX Designer ... Internship) and the rules (`min_confidence`, whether worldwide remote counts as India). **Edit this to add or remove a role.**
- `config/groq_prompt.txt` – the instructions given to the Groq AI, in plain English. Read and edit freely; `{{TAGS}}` is filled in from `config/roles.php`.
- `config/sources.php` – the websites the automatic collector will read (not built yet). `config/companies.php` – companies with job boards we can read (Figma, Canva, ...).
- `lib/pagefetch.php`, `lib/groq.php`, `lib/github_store.php`, `lib/intake.php` – the engine: read a job page, ask the AI, double-check its answer in code, save.
- `api/add-job.php` – the endpoint behind the `/admin` card. ``api/index.php` shows the admin card when the address is `/admin`.

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

Only those five are required. You can write `location` in any format (for example `"San Francisco, CA • New York, NY"`, `"Bangalore, Karnataka"` or `"Remote UK"`): the page always shows just one place as **City, Country** (`lib/location.php` does the tidying, and knows the common cities, US states and Indian states). If it can't tell the country, only the city is shown. Optional extras:

| Field | What it does |
|---|---|
| `region` | `india` or `abroad`. If missing, guessed from `location`. |
| `mode` | `remote`, `hybrid` or `in-office`. If missing, guessed from the text (default in-office). |
| `tags` | A list like `["Brand"]`. Only the first one is used (one tag per job). If missing, the kind of job is guessed from the title. |
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

- **Plain address:** the web address stays the plain domain whatever tab or page you are on (no `#india`, no `?page=2`). The tab and page you are on live in the browser's history, so the Back button still steps through them. An old `#about` style link still opens the right place once, then the address is tidied.
- **Favicon:** the smiley icon lives in `assets/icons/` (`favicon.ico`, `favicon-32.png`, `apple-touch-icon.png`). Vercel doesn't serve plain files by itself, so `api/icon.php` hands out exactly those three files (and nothing else) through the routes in `vercel.json`.
- **Site name:** change `site_name` in `config/settings.php` (top-left header and browser tab title).

## Adding jobs with the AI (the /admin card)

Open `/admin` on your site. Above the Pinned box you get a card: paste a job link and press **Add job**. The page is read, Groq decides whether it is one of our roles (and whether it is an internship), India or Abroad (by the job's own location, not the company's home city), and remote / hybrid / in-office. The answer is double-checked in code (the AI's quotes must really be on the page, doubtful jobs are refused, jobs older than `stale_days` are refused, duplicates are skipped). If it passes, the job is saved to `data/jobs.json` and the site updates about a minute later.

There is no password: anyone who knows the `/admin` address can add jobs, so keep that address to yourself. The AI still only lists real design jobs that pass the rules.

Set these in Vercel, Settings, Environment Variables (Preview and Production), then redeploy:

| Name | What it is |
|---|---|
| `GROQ_API_KEY` | Your Groq key. Never put it in code. |
| `JOBS_GITHUB_TOKEN` | A GitHub token with **Contents: Read and write** on this repository. Needed because Vercel cannot save files, so each new job is saved by committing `data/jobs.json`. |

Optional: `GROQ_MODEL` (default `llama-3.3-70b-versatile`). On your own computer (no token) the local file is edited directly; open `http://localhost:8000/admin.php`.

## Coming next

The automatic collector (cron) that visits the sources in `config/sources.php` and the companies in `config/companies.php`, and sends every link through the same `intake_job()` check. The Update log and the About page's "added manually" line will need rewording once that exists.
