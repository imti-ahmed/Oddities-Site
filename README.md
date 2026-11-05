# Design Jobs

A very simple website (HTML + CSS + PHP) that lists creative jobs, styled like the Hacker News jobs page, in blue.
Two tabs: **India** and **Abroad**, plus a search box.

## How it works

- `index.php` + `style.css` – the public website (change colours at the top of `style.css`).
- `data/jobs.json` – the list of jobs. No database. Each job looks like:
  `{ "title": "...", "company": "...", "location": "...", "url": "https://...", "posted_at": "2026-11-03", "region": "india" }`
  (`region` is `india` or `abroad`. If it's left out, it's guessed from the location.)
- `admin.php` – a hidden, password-protected page to add and remove jobs (open `/admin`). Not linked from the site.

## See it on your computer

```bash
ADMIN_PASSWORD=pick-a-password php -S localhost:8000
```

Site: http://localhost:8000   Admin: http://localhost:8000/admin.php
(Locally, the admin edits `data/jobs.json` directly.)

## On Vercel

`vercel.json` makes Vercel run the PHP code (via the `vercel-php` add-on).

Vercel can't save files, so the admin page saves by **committing `data/jobs.json` to GitHub**;
Vercel then rebuilds the site by itself (about a minute). To turn the admin on, add these
in Vercel > Settings > Environment Variables (enable for Production and Preview):

| Name | What to put |
|---|---|
| `ADMIN_PASSWORD` | Any long password you choose. This is your admin login. |
| `JOBS_GITHUB_TOKEN` | A GitHub "fine-grained personal access token" limited to this one repository, with **Contents: Read and write**. Create at GitHub > Settings > Developer settings > Personal access tokens. |

It saves to the same branch Vercel deployed (a preview deploy saves to its preview branch; production saves to `main`),
and works out the repository by itself. Optional overrides: `JOBS_GITHUB_REPO` (`owner/name`), `JOBS_GITHUB_BRANCH`.

## Coming next

Use the Groq AI API to read job links, understand the posts, and fill in the "Add a job" form automatically.
(Earlier finder/tester scripts and database code are saved in this project's git history if we want them back.)
