<?php
// Neon Object Storage (S3-compatible) for the images: the bucket "uploads" lives in the same Neon project as the database.
// A tiny S3 client with no libraries (AWS Signature V4, path-style addressing, which is what Neon Object Storage speaks).
//
// It reads the standard variables that "neon deploy" / "neon env pull" write into .env (and that you add in Vercel):
//   AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_ENDPOINT_URL_S3, AWS_REGION      (optional: STORAGE_BUCKET, default "uploads")
// The bucket is private: pictures reach visitors through api/img.php, which reads them from the bucket.

require_once __DIR__ . '/helpers.php';

function storage_enabled(): bool
{
    return env('AWS_ACCESS_KEY_ID') !== '' && env('AWS_SECRET_ACCESS_KEY') !== '' && env('AWS_ENDPOINT_URL_S3') !== '';
}

function storage_bucket(): string { return env('STORAGE_BUCKET') ?: 'uploads'; }

// One signed request. Returns ['code' => HTTP status, 'body' => string, 'type' => content-type, 'error' => curl error text]
function storage_request(string $method, string $key, string $body = '', string $contentType = ''): array
{
    $u = parse_url(rtrim(env('AWS_ENDPOINT_URL_S3'), '/'));
    $host = ($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : '');
    $region = env('AWS_REGION') ?: 'us-east-1';
    $path = ($u['path'] ?? '') . '/' . storage_bucket() . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));

    $amz = gmdate('Ymd\THis\Z'); $day = gmdate('Ymd');
    $payload = hash('sha256', $body);
    $h = ['host' => $host, 'x-amz-content-sha256' => $payload, 'x-amz-date' => $amz];
    if ($contentType !== '') $h['content-type'] = $contentType;
    ksort($h);
    $canonHeaders = ''; foreach ($h as $k => $v) $canonHeaders .= "$k:$v\n";
    $signed = implode(';', array_keys($h));
    $canonical = "$method\n$path\n\n$canonHeaders\n$signed\n$payload";
    $scope = "$day/$region/s3/aws4_request";
    $toSign = "AWS4-HMAC-SHA256\n$amz\n$scope\n" . hash('sha256', $canonical);
    $kDate = hash_hmac('sha256', $day, 'AWS4' . env('AWS_SECRET_ACCESS_KEY'), true);
    $kSign = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $region, $kDate, true), true), true);
    $auth = 'AWS4-HMAC-SHA256 Credential=' . env('AWS_ACCESS_KEY_ID') . "/$scope, SignedHeaders=$signed, Signature=" . hash_hmac('sha256', $toSign, $kSign);

    $headers = ['Authorization: ' . $auth];
    foreach ($h as $k => $v) if ($k !== 'host') $headers[] = "$k: $v";
    $ch = curl_init(($u['scheme'] ?? 'https') . '://' . $host . $path);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => $headers]);
    if ($method === 'PUT') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $out = (string)curl_exec($ch);
    return ['code' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => $out, 'type' => (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE), 'error' => curl_error($ch)];
}

function storage_put(string $key, string $bytes, string $contentType): bool
{
    if (!storage_enabled()) return false;
    $r = storage_request('PUT', $key, $bytes, $contentType);
    return $r['code'] >= 200 && $r['code'] < 300;
}

// ['body' => bytes, 'type' => content type], or null when it is not there.
function storage_get(string $key): ?array
{
    if (!storage_enabled()) return null;
    $r = storage_request('GET', $key);
    return $r['code'] === 200 ? ['body' => $r['body'], 'type' => $r['type']] : null;
}

function storage_delete(string $key): bool
{
    if (!storage_enabled()) return false;
    $r = storage_request('DELETE', $key);
    return $r['code'] >= 200 && $r['code'] < 300;
}
