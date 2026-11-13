# Oddities!

A simple website (HTML + CSS + PHP + a little JavaScript) that lists creative jobs in a clean table.
Style: a clean table with early-2000s Mac touches (a menu bar, mini-window cards with traffic-light dots in a pink / orange / blue rotation (add `pink` or `orange` to a card's class in `index.php`; no class = blue), glossy blue pill buttons, faint dotted background) and small comic-style NEW / UPDATED pills (halftone dots, thick outline).

## What's on the page

- **Menu bar** at the top with the **tabs** (all on the same page, no reloading): All, **Highlighted**, India, Abroad, Remote (there is no In-office or Hybrid tab), **Dead Zone**, **Update log**, **About**.
- **Table** with # / Job / Company & location / Tag / Posted / pin star. Each job has exactly **one tag** (the kind of job, e.g. Product Design, Brand, Motion), shown as plain text (same size and boldness as the role name). No search box and no filters: just the list.
- **Pinned box** at the side: click the star on a job to pin it. Pins are saved in the visitor's own browser (localStorage), nothing is stored on a server.
- **Highlighted tab** (beside All): jobs you picked to show to everyone. Visitors only see the tab when at least one job is highlighted. To highlight a job, open `/admin` and click its star: on that page the star means "highlight for everyone" (saved in the database) instead of "pin for me". Click it again to remove the highlight. A highlighted job that closes or goes to the Dead Zone leaves the tab by itself.
- **Delete button** (admin only): on `/admin` each job has a small `x` beside its star. One click hides the job from the site at once, with no confirmation question. The job is **kept in the database** (marked with a `deleted_at` time, never shown), so the collector and every duplicate check (same link, or same title + company + place under a new link) remember it and never check or add it again. To bring a deleted job back, paste its link into **Add job**: it says "Restored".
- **NEW / UPDATED pills**: small ovals in capitals with black text and a thick coloured outline, right after the role name. The fill is a comic-book halftone (rows of little dots): green for NEW, amber for UPDATED. They blink gently (still for people who turned motion off in their device). Jobs added or updated in the last few days get one and sit at the top. To hide them, set `show_badges` to `false` in `config/settings.php`; the list is then simply newest first.
- **Dead Zone**: everything that stopped working, in one table (Title / Type / Removed On). Type is **Job**, **Portfolio** or **Tools**. A job shows as `Role Name (Company)`. Jobs land here when they are `closed` or *inactive* (not posted/updated for 90+ days); portfolio and tool sites land here when their website is gone (see the 2-week check below). Kept for reference, never deleted, shown 20 per page.
- **Update log**: generated automatically from the jobs' dates (added / updated / closed / inactive). Tools and portfolios are in it too: ADDED when saved, CLOSED when their website stopped working (shown as "Tools: name" and "Portfolio: name"). One block per day with a full-width line after it. Inside a day there is one pill per kind of change (ADDED green, UPDATED amber, CLOSED red, INACTIVE grey, same halftone style as the NEW / UPDATED pills), with all the jobs for that change listed beside it, separated by commas.
- **About page**: five cards. The last two are a contact card (`contact_email` in `config/settings.php`) and a stats card: total jobs, last update, and **views**. Views come from a free public counter service (abacus.jasoncameron.dev); each visitor counts once per browser session, and visits from `localhost` are never counted. If the service is down, the number shows a dash. To restart the count from zero, change `views_counter.key` in `config/settings.php`.

## Files

- `index.php` – the page. `style.css` – the look (change colours at the top). `assets/app.js` – tabs, page numbers, pins.
- `lib/jobs.php` – works out each job's tab, tags, badge and Dead Zone status.
- `config/settings.php` – site name, tagline, rows per page, `new_days` (badge window), `new_badge_from` (an exact moment: jobs saved in the database before it never get a NEW pill, so the jobs that were already on the site are not all NEW; jobs saved after it do), `stale_days` (inactive after).
- **The jobs live in a Neon (Postgres) database**, in a table called `jobs` that the site creates by itself the first time it runs. `data/jobs.json` is only the starting list (copied into the database once, when the table is first created) and the fallback when there is no database (your own computer, or the database being down).
- `lib/db.php` – talks to Neon. `lib/store.php` – decides where jobs are read and saved.
- `config/roles.php` – the list of roles we accept (UI/UX Designer ... Motion Designer, Imagineer, Dreamer ... Internship) and the rules (`min_confidence`, whether worldwide remote counts as India). **Edit this to add or remove a role.**
- `config/groq_prompt.txt` – the instructions given to the Groq AI, in plain English. Read and edit freely; `{{TAGS}}` is filled in from `config/roles.php`.
- `config/companies.php` – the companies the collector watches (right now 7: Figma, Notion, Vercel, Cursor, Lovable, Mistral AI, Zomato; each must have a job board we can read). `config/sources.php` – job sites the collector can also read (all paused right now, so the board is fed only by the companies).
- `lib/pagefetch.php`, `lib/groq.php`, `lib/intake.php` – the engine: read a job page, ask the AI, double-check its answer in code, save.
- `lib/ats.php`, `lib/collector.php`, `scripts/collect.php` – the collector (reads company boards and job sites into a queue, then settles the jobs in batches).
- `lib/liveness.php`, `scripts/check_live.php` – the nightly "is it still open?" check (no AI).
- `lib/add_job_endpoint.php` – the endpoint behind the `/admin` card (reached through `api/index.php?add=1`). `api/index.php` shows the admin card when the address is `/admin`.

## Portfolios and Tools

The top bar has **Jobs**, **Portfolios**, **Tools** (then Dead Zone, Update log, About). Portfolios and Tools each show a welcome card like Jobs, the **Pinned** card beside it, and a **gallery** of picture cards (the default view). One Pinned card serves all three tabs: a visitor's pins are kept in their own browser.

Each has its own table in the Neon database, created by itself the first time it is needed (`lib/directory.php`):

| Table | Columns |
|---|---|
| `tools` | `name`, `link` (unique), `image`, `tag` (the kind of tool), `pinned` |
| `portfolios` | `name`, `link` (unique), `image`, `pinned` (no tag) |

`image` is the picture shown on the card: a screenshot kept in the Neon bucket (below). If it is empty, a live screenshot of the `link` is shown instead (free WordPress mShots service). `pinned` is a pin made by you for everyone: pinned items come first in the gallery. Both tables also hold the site-check notes: `dead_at`, `dead_reason`, `last_checked_at`, `check_fails`.

**Adding them:** on `/admin` the card has three choices on top: **Job**, **Portfolio**, **Tools**. Paste a link and press the button. For a job nothing changed (strict rules). For a portfolio or a tool there are no rules: Groq just names the entry (`config/groq_site_prompt.txt`) and, for a tool, picks its tag from the list in `config/directory.php` (`tool_tags`; edit freely). A site that blocks our page reader (many big tools do) is still added, the AI then works from the address alone; a site that does not exist is refused.

**Screenshots and the Neon bucket.** Pictures live in a private Neon Object Storage bucket called `uploads` (declared in `neon.ts`, created with `neon deploy`). `lib/storage.php` is a tiny S3 client (no libraries); `api/img.php` hands the pictures to visitors; `lib/screenshot.php` takes the screenshot (free Microlink service, about 50 a day without a key), saves it in the bucket and writes its address into `image`. A screenshot is taken when you add an entry; the `php scripts/screenshots.php` fills in any that are missing. In Vercel add the four storage variables from `.env` (`neon deploy` wrote them): `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_ENDPOINT_URL_S3`, `AWS_REGION`.

**The 2-week website check.** `scripts/check_sites.php` (GitHub: `.github/workflows/check-sites.yml`, runs every day at 03:00 India time) asks every portfolio and tool website whether it still works, but each site only every 14 days (`recheck_days`). No AI, only web requests. Only clear signals count: a 404 / 410 page, or an address that no longer exists. Blocked, slow or broken (5xx) sites change nothing. A site that looks gone is asked again the next day, and after **2 "gone" answers in a row** (`dead_after`) it moves to the Dead Zone. Needs the secret `DATABASE_URL` on GitHub.

## Companies

A fourth section, **Companies**, in the top bar: a plain list like the jobs (Name, Website, Type, Description, and a pin star; newest first). Its table `companies` (`lib/directory.php`) holds `name`, `link` (unique), `type`, `description`, plus the same site-check notes as Tools and Portfolios. Add one on `/admin` with the **Company** choice: Groq names it, picks its Type from `company_types` in `config/directory.php` (edit freely), and writes the short description (rules in `lib/site_intake.php`). Its website is re-checked every 2 weeks like the others and moves to the Dead Zone (Type: Company) when it is gone. No screenshots. It is also in the Update log.

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
| `GROQ_API_KEY_TWO` | Optional spare Groq key. When the first key hits a limit (or is refused), the AI calls switch to this one by themselves, and go back to the first key once it has recovered. It must also be added as a GitHub secret for the scheduled fill (see below). |
| `DATABASE_URL` | The Neon connection string (starts with `postgresql://`). It contains your database password, so it lives only here, never in the code or on GitHub. |

Optional: `GROQ_MODEL` (default `llama-3.3-70b-versatile`). If Groq says that model is gone, the app asks Groq which models your key can use and picks one from the list in `lib/groq.php` by itself; if none fit, the error message lists what is available so you can set `GROQ_MODEL`. On your own computer (no `DATABASE_URL`) the local file `data/jobs.json` is edited directly; open `http://localhost:8000/admin.php`.

## Keeping the board filled and clean (two jobs)

**1. Finding NEW jobs, every 3 days** (the scheduled GitHub job at 04:00 India time, or `scripts/collect.php` by hand; there is no button for this on the site, on purpose, so nobody can use up the Groq allowance from the browser). To run it right now: GitHub, Actions, "Collect jobs", Run workflow:
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

**The schedules** are the two files in `.github/workflows/` (`collect.yml` every 3 days, `check-live.yml` every night). GitHub uses UTC, so the files say 22:30 UTC and 20:30 UTC. They need the secrets `GROQ_API_KEY`, optionally `GROQ_API_KEY_TWO`, and `DATABASE_URL` (the nightly check needs only `DATABASE_URL`) under GitHub, Settings, Secrets and variables, Actions, and they only run once the files are on your `main` branch. You can also run each by hand under GitHub, Actions.

**Starting from an empty board:** `data/jobs.json` is empty, so a new database starts empty. To empty an existing Neon database, run this once in Neon's SQL Editor: `TRUNCATE TABLE jobs RESTART IDENTITY;` (the Update log is built from the jobs, so it empties too).

## Coming next

More company boards (Workable, Recruitee, Personio and similar), a "maybe" list for jobs the AI is unsure about, and tuning the rules after we see real results.
