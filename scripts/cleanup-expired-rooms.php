<?php
declare(strict_types=1);

// Nightly maintenance for Firebase Realtime Database. This script intentionally
// accepts credentials only from a private server-side file outside the web root.
const DATABASE_URL = 'https://mtg-timer-3f28c-default-rtdb.firebaseio.com';

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function base64Url(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function request(string $method, string $url, array $headers = [], ?string $body = null): array {
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($response === false) fail('HTTP request failed: ' . curl_error($curl));
    curl_close($curl);
    if ($status < 200 || $status >= 300) fail("Firebase request returned HTTP $status: $response");
    return [$status, $response];
}

$credentialPath = getenv('GOOGLE_APPLICATION_CREDENTIALS');
if (!$credentialPath || !is_readable($credentialPath)) fail('GOOGLE_APPLICATION_CREDENTIALS is missing or unreadable.');
$credential = json_decode((string) file_get_contents($credentialPath), true, flags: JSON_THROW_ON_ERROR);
$now = time();
$header = base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
$claims = base64Url(json_encode([
    'iss' => $credential['client_email'],
    'scope' => 'https://www.googleapis.com/auth/firebase.database https://www.googleapis.com/auth/userinfo.email',
    'aud' => $credential['token_uri'] ?? 'https://oauth2.googleapis.com/token',
    'iat' => $now,
    'exp' => $now + 3600,
], JSON_THROW_ON_ERROR));
$unsigned = "$header.$claims";
if (!openssl_sign($unsigned, $signature, $credential['private_key'], OPENSSL_ALGO_SHA256)) fail('Unable to sign OAuth token.');
$assertion = "$unsigned." . base64Url($signature);

[, $tokenResponse] = request('POST', $credential['token_uri'] ?? 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
    'assertion' => $assertion,
]));
$accessToken = json_decode($tokenResponse, true, flags: JSON_THROW_ON_ERROR)['access_token'] ?? fail('OAuth response contained no access token.');
$query = http_build_query(['orderBy' => '"expiresAt"', 'endAt' => (int) round(microtime(true) * 1000)]);
[, $roomsResponse] = request('GET', DATABASE_URL . '/rooms.json?' . $query, ["Authorization: Bearer $accessToken"]);
$rooms = json_decode($roomsResponse, true, flags: JSON_THROW_ON_ERROR) ?: [];
$deleted = 0;
foreach ($rooms as $room => $data) {
    // Queries can sort legacy records without expiresAt before timestamped rooms.
    // Never remove one unless it contains an explicit, elapsed expiration value.
    if (!isset($data['expiresAt']) || !is_numeric($data['expiresAt']) || $data['expiresAt'] > round(microtime(true) * 1000)) continue;
    request('DELETE', DATABASE_URL . '/rooms/' . rawurlencode((string) $room) . '.json', ["Authorization: Bearer $accessToken"]);
    $deleted++;
}
fwrite(STDOUT, sprintf("[%s] Deleted %d expired room(s).\n", gmdate(DATE_ATOM), $deleted));
