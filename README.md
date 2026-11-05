# Jobbies!

A simple website (HTML + CSS + PHP + a little JavaScript) that lists creative jobs in a clean table.
Style: a clean table with early-2000s Mac touches (a menu bar, mini-window cards with traffic-light dots in a pink / orange / blue rotation (add `pink` or `orange` to a card's class in `index.php`; no class = blue), glossy blue pill buttons, faint dotted background) and small comic-style NEW / UPDATED pills (halftone dots, thick outline).

## What's on the page

- **Menu bar** at the top with the **tabs** (all on the same page, no reloading): All, India, Abroad, Remote, In-office (Hybrid appears automatically if any job is hybrid), **Dead Zone**, **Update log**, **About**.
- **Table** with # / Job / Company & location / Tag / Posted / pin star. Each job has exactly **one tag** (the kind of job, e.g. Product Design, Brand, Motion), shown as plain text (same size and boldness as the role name). No search box and no filters: just the list.
- **Pinned box** at the side: click the star on a job to pin it. Pins are saved in the visitor's own browser (localStorage), nothing is stored on a server.
- **NEW / UPDATED pills**: small ovals in capitals with black text and a thick coloured outline, right after the role name. The fill is a comic-book halftone (rows of little dots): green for NEW, amber for UPDATED. They blink gently (still for people who turned motion off in their device). Jobs added or updated in the last few days get one and sit at the top. To hide them, set `show_badges` to `false` in `config/settings.php`; the list is then simply newest first.
- **Dead Zone**: jobs that are `closed`, or *inactive* (not posted/updated for 90+ days). Kept for reference, never deleted, and shown 20 per page with page numbers (no limit on how many).
- **Update log**: generated automatically from the jobs' dates (added / updated / closed / inactive). One block per day with a full-width line after it. Inside a day there is one pill per kind of change (ADDED green, UPDATED amber, CLOSED red, INACTIVE grey, same halftone style as the NEW / UPDATED pills), with all the jobs for that change listed beside it, separated by commas.
- **About page**: five cards. The last two are a contact card (`contact_email` in `config/settings.php`) and a stats card: total jobs, last update, and **views**. Views come from a free public counter service (abacus.jasoncameron.dev); each visitor counts once per browser session, and visits from `localhost` are never counted. If the service is down, the number shows a dash. To restart the count from zero, change `views_counter.key` in `config/settings.php`.

## Files

- `index.php` – the page. `style.css` – the look (change colours at the top). `assets/app.js` – tabs, page numbers, pins.
- `lib/jobs.php` – works out each job's tab, tags, badge and Dead Zone status.
- `config/settings.php` – site name, tagline, rows per page, `new_days` (badge window), `stale_days` (inactive after).
- **The jobs live in a Neon (Postgres) database**, in a table called `jobs` that the site creates by itself the first time it runs. `data/jobs.json` is only the starting list (copied into the database once, when the table is first created) and the fallback when there is no database (your own computer, or the database being down).
- `lib/db.php` – talks to Neon. `lib/store.php` – decides where jobs are read and saved.
- `config/roles.php` – the list of roles we accept (UI/UX Designer ... Internship) and the rules (`min_confidence`, whether worldwide remote counts as India). **Edit this to add or remove a role.**
- `config/groq_prompt.txt` – the instructions given to the Groq AI, in plain English. Read and edit freely; `{{TAGS}}` is filled in from `config/roles.php`.
- `config/sources.php` – the job sites the collector reads. `config/companies.php` – companies with job boards we can read (Figma, Canva, ...).
- `lib/pagefetch.php`, `lib/groq.php`, `lib/intake.php` – the engine: read a job page, ask the AI, double-check its answer in code, save.
- `lib/ats.php`, `lib/collector.php`, `scripts/collect.php` – the collector (reads company boards and job sites into a queue, then settles the jobs in batches).
- `lib/liveness.php`, `scripts/check_live.php` – the nightly "is it still open?" check (no AI).
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
| `tags` | A list with one category: `["Product"]`, `["Brand"]`, `["Creative"]` (includes illustration), `["Visual"]`, `["Research"]`, `["Technology"]` or `["AI"]`. An internship is written `"Product · Intern"`. Only the first one is used (one tag per job). If missing, the category is guessed from the title. The categories, and which role belongs to which, are in `config/roles.php`. |
| `added_at` | Date added to the site. If missing, `posted_at` is used. A job added in the last 3 days gets a **NEW** badge. |
| `updated_at` | Date it was changed. A job updated in the last 3 days gets an **UPDATED** badge. |
| `status` | Set to `"closed"` to move it to the Dead Zone. Optionally add `closed_at` and `closed_reason`. |

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

## Keeping the board filled and clean (two jobs)

**1. Finding NEW jobs, every 3 days** (the **Collect jobs** button on `/admin`, or `scripts/collect.php`, or the GitHub job at 04:00 India time):
1. **Gather (no AI):** it reads the public job boards of the companies in `config/companies.php` (Greenhouse, Lever, Ashby, SmartRecruiters) and the sites in `config/sources.php`. Every opening whose *title* could fit one of our roles is put in a queue (the `job_queue` table). Titles like "Software Engineer" are dropped here.
2. **Settle, with as few AI calls as possible.** For every queued job, in this order:
   - the link, or the same title + company + place, is already listed: skipped (no AI);
   - the title is plainly one of our roles ("Senior Product Designer, Payments") and the board gives the place: listed by plain code (no AI). Switch this off with `'fast_path' => false` in `config/roles.php` to make the AI check everything;
   - everything else goes to the AI **together**, 8 jobs in one call (`'batch_size'` in `config/roles.php`), with the rules in `config/groq_prompt.txt`. The answer is double-checked in code like before.
   In a test on real company boards, 160 jobs were settled with 20 AI calls instead of 160.

The queue remembers every job it has settled, so a run can be **stopped and continued** at any time and never asks about the same job twice (a refused job is looked at again after 30 days). If Groq's limit is reached the run pauses or stops by itself, and the jobs stay queued. Jobs that look like India jobs are settled first, then the newest.

**2. Checking that listed jobs are still open, every night at 02:00 India time** (the **Check open jobs** button, `scripts/check_live.php`, or the GitHub job). No AI, only web requests:
- a job from a company board: if it is no longer on the company's current list of openings, it is gone;
- any other job: its link is opened. A "not found" page, a page that says "no longer available / position filled", or a redirect to the general job list means it is gone;
- only clear signals count. A blocked, slow or broken page changes nothing (tried again next night). A job is closed (moves to the Dead Zone) only after it is gone **two nights in a row**;
- jobs older than 90 days are not checked: they move to the Dead Zone by age, even if still open.

**Groq's limits.** On Groq's free plan a daily token limit may still slow the very first fill, because the first run queues several hundred jobs. Nothing breaks: it continues the next time.

**The schedules** are the two files in `.github/workflows/` (`collect.yml` every 3 days, `check-live.yml` every night). GitHub uses UTC, so the files say 22:30 UTC and 20:30 UTC. They need the secrets `GROQ_API_KEY` and `DATABASE_URL` (the second only for the nightly check) under GitHub, Settings, Secrets and variables, Actions, and they only run once the files are on your `main` branch. You can also run each by hand under GitHub, Actions.

**Starting from an empty board:** `data/jobs.json` is empty, so a new database starts empty. To empty an existing Neon database, run this once in Neon's SQL Editor: `TRUNCATE TABLE jobs RESTART IDENTITY;` (the Update log is built from the jobs, so it empties too).

## Coming next

More company boards (Workable, Recruitee, Personio and similar), a "maybe" list for jobs the AI is unsure about, and tuning the rules after we see real results.
