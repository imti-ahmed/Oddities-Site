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
- **The jobs live in a Neon (Postgres) database**, in a table called `jobs` that the site creates by itself the first time it runs. `data/jobs.json` is only the starting list (copied into the database once, when the table is first created) and the fallback when there is no database (your own computer, or the database being down).
- `lib/db.php` – talks to Neon. `lib/store.php` – decides where jobs are read and saved.
- `config/roles.php` – the list of roles we accept (UI/UX Designer ... Internship) and the rules (`min_confidence`, whether worldwide remote counts as India). **Edit this to add or remove a role.**
- `config/groq_prompt.txt` – the instructions given to the Groq AI, in plain English. Read and edit freely; `{{TAGS}}` is filled in from `config/roles.php`.
- `config/sources.php` – the job sites the collector reads. `config/companies.php` – companies with job boards we can read (Figma, Canva, ...).
- `lib/pagefetch.php`, `lib/groq.php`, `lib/intake.php` – the engine: read a job page, ask the AI, double-check its answer in code, save.
- `lib/ats.php`, `lib/collector.php`, `scripts/collect.php` – the collector (reads company boards and job sites into a queue, then checks each job).
- `lib/add_job_endpoint.php` – the endpoint behind the `/admin` card (reached through `api/index.php?add=1`). `api/index.php` shows the admin card when the address is `/admin`.

## The jobs file

Each job (in the database, or in `data/jobs.json` for the starting list and local use) looks like this:

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
- **Cursor:** the glossy black arrow is built into `style.css` (search for "Custom cursor"). It replaces the normal arrow; links, buttons and text boxes keep the hand and text cursors. To change it, replace the two `url(data:image/png;base64,...)` pictures there (22 pixels tall, and a 44 pixel one for sharp screens) and the click point `1 1`.
- **Site name:** change `site_name` in `config/settings.php` (top-left header and browser tab title).

## Adding jobs with the AI (the /admin card)

Open `/admin` on your site (for example `creativejobs.vercel.app/admin`). Above the Pinned box you get a card: paste a job link and press **Add job**. You can also paste a page that lists many jobs (a job board page, a company's openings page): the app finds the job links on it, asks the AI which look like our roles, then checks each one separately with the same strict rules. The card shows progress and a result line for every job. Pages that only show their jobs after JavaScript runs cannot be read. The page is read, Groq decides whether it is one of our roles (and whether it is an internship), India or Abroad (by the job's own location, not the company's home city), and remote / hybrid / in-office. The answer is double-checked in code (the AI's quotes must really be on the page, doubtful jobs are refused, jobs older than `stale_days` are refused, duplicates are skipped). If it passes, the job is saved to the database straight away (no rebuild of the site is needed) and the page shows it within about a minute, because Vercel keeps each page for 60 seconds.

There is no password: anyone who knows the `/admin` address can add jobs, so keep that address to yourself. The AI still only lists real design jobs that pass the rules.

Set these in Vercel, Settings, Environment Variables (Preview and Production), then redeploy:

| Name | What it is |
|---|---|
| `GROQ_API_KEY` | Your Groq key. Never put it in code. |
| `DATABASE_URL` | The Neon connection string (starts with `postgresql://`). It contains your database password, so it lives only here, never in the code or on GitHub. |

Optional: `GROQ_MODEL` (default `llama-3.3-70b-versatile`). If Groq says that model is gone, the app asks Groq which models your key can use and picks one from the list in `lib/groq.php` by itself; if none fit, the error message lists what is available so you can set `GROQ_MODEL`. On your own computer (no `DATABASE_URL`) the local file `data/jobs.json` is edited directly; open `http://localhost:8000/admin.php`.

## Filling the board automatically (the collector)

The **Collect jobs** button on `/admin` (and the same thing as a command, see below) fills the board from our own lists, using the AI:

1. **Gather:** it reads the public job boards of the companies in `config/companies.php` (Greenhouse, Lever, Ashby, SmartRecruiters) and the sites in `config/sources.php`. Every opening whose *title* could fit one of our roles is put in a queue (a database table called `job_queue`). Titles like "Software Engineer" are dropped here, without using the AI.
2. **Check:** one queued job at a time goes to Groq with the rules in `config/groq_prompt.txt`, the answer is double-checked in code (same strict checks as the single-link card), and the job is listed or refused. Jobs that look like India jobs are checked first, then the newest.

The queue remembers every job it has checked, so a run can be **stopped and continued** at any time and never asks the AI about the same job twice (a refused job is looked at again after 30 days). If Groq's limit is reached the run pauses or stops by itself; press the button again later.

**Groq's free limits matter.** Each check costs a few thousand AI "tokens", and the first run queues several hundred jobs. On Groq's free plan a daily token limit may mean the first fill takes several days of runs; a paid Groq plan removes that. Nothing breaks: it simply continues the next time.

**As a command / on a schedule:** `php scripts/collect.php` (options at the top of that file). `.github/workflows/collect.yml` runs it every 6 hours on GitHub, and you can run it by hand under GitHub, Actions, "Collect jobs". It needs two GitHub secrets, `GROQ_API_KEY` and `DATABASE_URL` (the same values as in Vercel). The schedule and the Run button only work once the file is on your `main` branch.

**Starting from an empty board:** `data/jobs.json` is empty, so a new database starts empty. To empty an existing Neon database, run this once in Neon's SQL Editor: `TRUNCATE TABLE jobs RESTART IDENTITY;` (the Update log is built from the jobs, so it empties too).

## Coming next

Rewording the About page line "hand picked and added manually", a way to close or remove a job from the site, and tuning the rules after we see real results.
