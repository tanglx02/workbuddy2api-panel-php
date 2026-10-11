<?php
declare(strict_types=1);
namespace WB2A\Core;

/**
 * cURL-based HTTP client with SSE streaming support.
 * Streaming: $onLine callback invoked per SSE line; returns response headers.
 */
final class Http
{
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 120, ?callable $onLine = null, array $opts = []): array
    {
        $ch = curl_init($url);
        $hdrs = [];
        foreach ($headers as $k => $v) { $hdrs[] = $k . ': ' . $v; }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
        ]);
        // Shared hosts often ship PHP without a CA bundle → all outbound HTTPS
        // fails with "unable to get local issuer certificate". Use the bundled
        // CA file (app/cacert.pem, from curl.se) whenever it is present.
        $caFile = WB2A_ROOT . '/app/cacert.pem';
        if (is_readable($caFile)) { curl_setopt($ch, CURLOPT_CAINFO, $caFile); }
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        if ($onLine !== null) {
            // Streaming mode: no total timeout (long thinking allowed), bounded by
            // idle watchdog (CURLOPT_LOW_SPEED_*) + PHP max_execution_time of the host.
            curl_setopt($ch, CURLOPT_TIMEOUT, 0);
            $idle = (int)($opts['idle_timeout'] ?? 0);
            if ($idle > 0) {
                // abort when <1 byte/s for $idle seconds → CURLE_OPERATION_TIMEDOUT
                curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1);
                curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, $idle);
            }
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use ($onLine) {
                static $buf = '';
                $buf .= $chunk;
                while (($pos = strpos($buf, "\n")) !== false) {
                    $line = rtrim(substr($buf, 0, $pos), "\r");
                    $buf = substr($buf, $pos + 1);
                    if ($line !== '') { $onLine($line); }
                }
                return strlen($chunk);
            });
        } else {
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        }
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => $err, 'errno' => $errno, 'headers' => []];
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $rawHeaders = substr((string)$resp, 0, $hsize);
        $respBody = substr((string)$resp, $hsize);
        $headers = [];
        foreach (explode("\r\n", $rawHeaders) as $h) {
            if (($p = strpos($h, ':')) !== false) {
                $headers[strtolower(trim(substr($h, 0, $p)))] = trim(substr($h, $p + 1));
            }
        }
        return ['ok' => true, 'status' => $status, 'body' => $respBody, 'headers' => $headers, 'error' => ''];
    }
}
