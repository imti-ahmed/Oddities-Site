<?php
// Small shared tools used by the website.

// Reads a setting from the environment (Vercel "Environment Variables", or your computer). Empty text when it is not set.
function env(string $name): string
{
    $v = getenv($name);
    if ($v === false || $v === '') $v = $_ENV[$name] ?? $_SERVER[$name] ?? '';
    return (string)$v;
}

function h(?string $s): string   // makes text safe to show on a web page
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function time_ago(?int $ts): string
{
    if (!$ts) return '';
    $d = time() - $ts;
    if ($d < 3600)     return 'just now';
    if ($d < 86400)    { $n = (int)floor($d / 3600);  return $n . ($n == 1 ? ' hour' : ' hours') . ' ago'; }
    if ($d < 86400*60) { $n = (int)floor($d / 86400); return $n . ($n == 1 ? ' day' : ' days') . ' ago'; }
    $n = (int)floor($d / (86400*30)); return $n . ' months ago';
}

function domain_of(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST) ?: '';
    return preg_replace('/^www\./', '', $host);
}

function is_safe_url(string $url): bool   // only allow normal web links
{
    return (bool)preg_match('#^https?://#i', $url);
}

// India or Abroad? Uses the job's "region" if it has one; otherwise guesses from the location text.
function region_of(array $job): string
{
    $r = strtolower((string)($job['region'] ?? ''));
    if ($r === 'india' || $r === 'abroad') return $r;
    $india = '/\b(india|bengaluru|bangalore|mumbai|delhi|gurgaon|gurugram|noida|hyderabad|pune|chennai|kolkata|ahmedabad|jaipur|kochi|chandigarh|indore)\b/i';
    return preg_match($india, (string)($job['location'] ?? '')) ? 'india' : 'abroad';
}

