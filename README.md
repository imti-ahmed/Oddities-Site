# Design Jobs

A simple website (HTML + CSS + PHP) that lists creative jobs in the style of Hacker News,
and keeps itself clean by re-checking every job link to make sure the job is still open.

## How it works (the simple version)

There are 3 pieces:

1. **The website** (`index.php` + `style.css`) – shows the table of jobs.
2. **The finder** (`cron/fetch.php`) – visits each place in your list, grabs the creative jobs, saves them.
3. **The tester** (`cron/check.php`) – visits every saved job link and asks "is this job still there?".
   If the page is gone, says "no longer available", or quietly redirects to the company homepage,
   the job is marked **closed** and disappears from the website.

Jobs are stored in a small file, `data/jobs.sqlite`. You don't need to install any database.

## Try it on your computer

```bash
php cron/fetch.php        # find jobs
php cron/check.php        # test that they're still open
php -S localhost:8000     # start the website, then open http://localhost:8000
```

## Adding the places you want jobs from

Open `config/sources.php` and add one line per place. Supported types:

| Type | When to use it | Example |
|---|---|---|
| `greenhouse` | The company's jobs live at `boards.greenhouse.io/NAME` | `['name'=>'Figma','type'=>'greenhouse','token'=>'figma']` |
| `lever` | …at `jobs.lever.co/NAME` | `['name'=>'Spotify','type'=>'lever','token'=>'spotify']` |
| `ashby` | …at `jobs.ashbyhq.com/NAME` | `['name'=>'Ramp','type'=>'ashby','token'=>'ramp']` |
| `rss` | The site has an RSS/Atom feed | `['name'=>'Some Board','type'=>'rss','url'=>'https://…/feed']` |

The three entries in the file now are **examples** – replace them with your own list.

## Choosing which jobs count as "creative"

`config/settings.php` has two word lists: `creative_keywords` (a job title must contain one)
and `exclude_keywords` (a job title must NOT contain any). Edit them any time.

## Running it on Vercel

Vercel doesn't run PHP or keep files by default, so this project is set up for it in `vercel.json`
(PHP add-on `vercel-php`) and uses an online Postgres database instead of the local file.

One-time setup in the Vercel dashboard:

1. **Add a database:** project > **Storage** > create/connect a **Postgres** database (e.g. Neon).
   Make sure it's connected to the **Preview** environment too. This sets `DATABASE_URL` for you.
2. **Add a password for the finder/tester:** project > Settings > **Environment Variables** >
   add `CRON_SECRET` with any long random text (enable it for Production *and* Preview).
3. **Redeploy** (push a new commit, or "Redeploy" on the latest deployment).
4. **Fill the list** by opening these in your browser (replace the domain and secret):
   - `https://YOUR-PREVIEW-DOMAIN/api/fetch?key=YOUR_CRON_SECRET`  (finds jobs)
   - `https://YOUR-PREVIEW-DOMAIN/api/check?key=YOUR_CRON_SECRET`  (tests the links)

Good to know:
- Each run stops after ~40 seconds (Vercel's limit) and picks up where it left off next time.
- Vercel's automatic schedule (`crons` in `vercel.json`) only runs on the **production** site, not on previews,
  so on a preview you open the two links above yourself. Free (Hobby) accounts can schedule once a day.

## Keeping it updated automatically (regular web host)

On your web host, set up two scheduled tasks ("cron jobs") that run the scripts:

```
0 */6 * * *   php /path/to/Design-Jobs/cron/fetch.php   # find new jobs every 6 hours
30 * * * *    php /path/to/Design-Jobs/cron/check.php   # test links every hour
```

Each tester run checks up to 150 links, oldest-checked first, so every job gets re-tested regularly.
