<?php
// The hidden admin page: add or remove jobs without touching any code.
// Protected by the ADMIN_PASSWORD setting. Not linked from the public site.

require_once __DIR__ . '/lib/store.php';
$settings = require __DIR__ . '/config/settings.php';

$secret   = env('ADMIN_PASSWORD');
$cookie   = 'dj_admin';
$https    = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

function sign(string $s, string $secret): string { return hash_hmac('sha256', $s, $secret); }

// "Logged in" = a cookie that only we could have made, valid for 12 hours.
function logged_in(string $secret, string $cookie): bool
{
    $c = (string)($_COOKIE[$cookie] ?? '');
    if ($secret === '' || !preg_match('/^(\d+)\.([0-9a-f]{64})$/', $c, $m)) return false;
    return (int)$m[1] > time() && hash_equals(sign('login' . $m[1], $secret), $m[2]);
}
function csrf(string $secret): string { return sign('csrf' . ($_COOKIE['dj_admin'] ?? ''), $secret); }
function back(string $msg = '', string $kind = 'ok'): never
{
    header('Location: ?' . ($msg !== '' ? http_build_query(['msg' => $msg, 'kind' => $kind]) : ''));
    exit;
}

$authed = logged_in($secret, $cookie);
$error  = '';

if ($secret !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        if (hash_equals($secret, (string)($_POST['password'] ?? ''))) {
            $exp = time() + 12 * 3600;
            setcookie($cookie, $exp . '.' . sign('login' . $exp, $secret),
                ['expires' => $exp, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
            back();
        }
        usleep(700000);   // slow down password guessing
        $error = 'Wrong password.';
    } elseif ($authed && hash_equals(csrf($secret), (string)($_POST['csrf'] ?? ''))) {
        try {
            if ($action === 'logout') {
                setcookie($cookie, '', ['expires' => 1, 'path' => '/']);
                back();
            }
            if ($action === 'add') {
                $title    = trim((string)($_POST['title'] ?? ''));
                $company  = trim((string)($_POST['company'] ?? ''));
                $url      = trim((string)($_POST['url'] ?? ''));
                $location = trim((string)($_POST['location'] ?? ''));
                $region   = (string)($_POST['region'] ?? 'auto');
                $date     = (string)($_POST['posted_at'] ?? '');
                if ($title === '' || $company === '' || !is_safe_url($url)) throw new RuntimeException('Title, company and a link starting with http:// or https:// are required.');
                if (!in_array($region, ['india', 'abroad'], true)) $region = region_of(['location' => $location]);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) $date = date('Y-m-d');
                $job = ['title' => mb_substr($title, 0, 300), 'company' => mb_substr($company, 0, 120),
                        'location' => mb_substr($location, 0, 200), 'url' => $url, 'posted_at' => $date, 'region' => $region];

                $dupe = false;
                update_jobs(function ($jobs) use ($job, &$dupe) {
                    foreach ($jobs as $j) if (($j['url'] ?? '') === $job['url']) { $dupe = true; return $jobs; }
                    $jobs[] = $job;
                    return $jobs;
                }, "Add job: {$job['company']} - {$job['title']}");
                back($dupe ? 'That link is already in the list.' : 'Job added.', $dupe ? 'err' : 'ok');
            }
            if ($action === 'delete') {
                $key = (string)($_POST['key'] ?? '');
                update_jobs(fn($jobs) => array_values(array_filter($jobs, fn($j) => job_key($j) !== $key)), 'Remove a job');
                back('Job removed.');
            }
        } catch (Throwable $e) {
            back($e->getMessage(), 'err');
        }
    } else {
        $error = 'Please log in again.';
    }
}
$flash = isset($_GET['msg']) ? [(string)$_GET['msg'], ($_GET['kind'] ?? '') === 'err' ? 'err' : 'ok'] : null;
$mode  = store_mode();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Admin - <?= h($settings['site_name']) ?></title>
  <style><?= file_get_contents(__DIR__ . '/style.css') ?>
    .box { padding: 10px 14px 16px; }
    .box h3 { margin: 14px 0 6px; color: #333; font-size: 10pt; }
    .box label { display: block; margin: 6px 0 2px; font-size: 8pt; }
    .box input[type=text], .box input[type=url], .box input[type=password], .box input[type=date], .box select {
      font-family: Verdana, sans-serif; font-size: 9pt; padding: 4px 6px; border: 1px solid #c7d3ea; background: #fff; width: 100%; max-width: 460px; box-sizing: border-box; }
    .box button { font-family: Verdana, sans-serif; font-size: 9pt; padding: 4px 12px; border: 1px solid var(--blue); background: var(--blue); color: #fff; cursor: pointer; margin-top: 10px; }
    .box button.small { padding: 1px 7px; margin: 0 0 0 8px; background: #fff; color: #b00020; border-color: #d9a0a8; font-size: 8pt; }
    .flash { padding: 6px 14px; font-size: 9pt; } .flash.ok { background: #dff3e3; color: #14532d; } .flash.err { background: #fde2e2; color: #7f1d1d; }
    .note { font-size: 8pt; max-width: 560px; line-height: 1.5; }
    .row { padding: 3px 0; font-size: 9pt; color: #333; } .row small { color: var(--grey); }
    code { background: #e6ecf8; padding: 0 3px; }
  </style>
</head>
<body>
<div id="page">
  <div id="topbar"><span class="logo">D</span><span class="name"><?= h($settings['site_name']) ?> admin</span>
    <a href="./">view site</a>
    <?php if ($authed): ?>
      <form method="post" style="display:inline;float:right"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?= h(csrf($secret)) ?>">
        <button style="background:none;border:0;color:#dbe6ff;cursor:pointer;font:inherit;text-decoration:underline">log out</button></form>
    <?php endif; ?>
  </div>
  <?php if ($flash): ?><div class="flash <?= $flash[1] ?>"><?= h($flash[0]) ?></div><?php endif; ?>
  <?php if ($authed && $error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>

<?php if ($secret === ''): ?>
  <div class="box note"><b>The admin page is turned off.</b><br>Set a password to turn it on: add an environment variable <code>ADMIN_PASSWORD</code>
  (on Vercel: Settings &gt; Environment Variables; on your computer: <code>ADMIN_PASSWORD=yourpassword php -S localhost:8000</code>).</div>

<?php elseif (!$authed): ?>
  <form method="post" class="box">
    <input type="hidden" name="action" value="login">
    <label>Password</label><input type="password" name="password" autofocus required>
    <?php if ($error): ?><div class="flash err" style="padding:4px 0;background:none"><?= h($error) ?></div><?php endif; ?>
    <button type="submit">Log in</button>
  </form>

<?php else:
  try { $jobs = load_jobs(true); $loadError = ''; } catch (Throwable $e) { $jobs = []; $loadError = $e->getMessage(); }
  usort($jobs, fn($a, $b) => strcmp($b['posted_at'] ?? '', $a['posted_at'] ?? ''));
?>
  <div class="box">
    <?php if ($mode === 'none'): ?>
      <div class="flash err">Saving is not set up. Add <code>JOBS_GITHUB_TOKEN</code> in Vercel (see README), then redeploy.</div>
    <?php elseif ($mode === 'github'): ?>
      <div class="note">Changes are saved to GitHub, and the live site updates about a minute later.</div>
    <?php endif; ?>
    <?php if ($loadError): ?><div class="flash err"><?= h($loadError) ?></div><?php endif; ?>

    <h3>Add a job</h3>
    <form method="post">
      <input type="hidden" name="action" value="add"><input type="hidden" name="csrf" value="<?= h(csrf($secret)) ?>">
      <label>Job title *</label><input type="text" name="title" required>
      <label>Company *</label><input type="text" name="company" required>
      <label>Link to the job *</label><input type="url" name="url" placeholder="https://" required>
      <label>Location (city, country)</label><input type="text" name="location">
      <label>Tab</label>
      <select name="region"><option value="auto">Decide from the location</option><option value="india">India</option><option value="abroad">Abroad</option></select>
      <label>Date posted</label><input type="date" name="posted_at" value="<?= date('Y-m-d') ?>">
      <button type="submit">Add job</button>
    </form>

    <h3>All jobs (<?= count($jobs) ?>)</h3>
    <?php foreach ($jobs as $j): ?>
      <form method="post" class="row">
        <input type="hidden" name="action" value="delete"><input type="hidden" name="csrf" value="<?= h(csrf($secret)) ?>"><input type="hidden" name="key" value="<?= h(job_key($j)) ?>">
        <b><?= h($j['company'] ?? '') ?></b> - <?= h($j['title'] ?? '') ?>
        <small>(<?= h(region_of($j)) ?><?= !empty($j['location']) ? ', ' . h($j['location']) : '' ?>)</small>
        <button class="small" type="submit" onclick="return confirm('Remove this job?')">remove</button>
      </form>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>
</body>
</html>
