<?php

declare(strict_types=1);

namespace Adelia;

final class RemoteHttp
{
    public static function uri(string $url): \Uri\Rfc3986\Uri
    {
        if (strlen($url) > 8192 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new BoardMessage('A valid HTTP or HTTPS URL is required.');
        }
        try {
            $uri = new \Uri\Rfc3986\Uri($url);
        } catch (\Throwable) {
            throw new BoardMessage('A valid HTTP or HTTPS URL is required.');
        }
        if (!in_array($uri->getScheme(), ['http', 'https'], true) || !$uri->getHost() || $uri->getUserInfo() !== null) {
            throw new BoardMessage('A valid HTTP or HTTPS URL is required.');
        }
        $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);
        if ($port !== ($uri->getScheme() === 'https' ? 443 : 80)) {
            throw new BoardMessage('Remote URLs must use the standard HTTP or HTTPS port.');
        }
        return $uri;
    }

    public static function isPublicAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }
        $packed = inet_pton($address);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 16) {
            // Native global unicast only; exclude IPv4 translation/tunneling ranges.
            return (ord($packed[0]) & 0xe0) === 0x20
                && substr($packed, 0, 2) !== "\x20\x02"
                && substr($packed, 0, 4) !== "\x20\x01\x00\x00";
        }
        return ord($packed[0]) < 224;
    }

    /** @param null|\Closure(string): list<string> $resolve
     * @return array{url: string, host: string, port: int, addresses: list<string>, literal: bool} */
    public static function target(string $url, ?\Closure $resolve = null): array
    {
        $uri = self::uri($url);
        $host = strtolower(trim($uri->getHost() ?? '', '[]'));
        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if ($literal) {
            $addresses = [$host];
        } else {
            // Do not let cURL reinterpret noncanonical numeric hosts as IPv4 addresses.
            if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$/D', $host) || strlen($host) > 253) {
                throw new BoardMessage('Remote URLs need a public hostname or IP address.');
            }
            if ($resolve !== null) {
                $addresses = $resolve($host);
            } else {
                try {
                    $records = dns_get_record($host, DNS_A | DNS_AAAA);
                } catch (\Throwable) {
                    throw new BoardMessage('The remote hostname could not be resolved.');
                }
                $addresses = [];
                foreach ($records ?: [] as $record) {
                    $address = $record['ip'] ?? $record['ipv6'] ?? null;
                    if (is_string($address)) {
                        $addresses[] = $address;
                    }
                }
            }
        }
        if ($addresses === [] || array_any($addresses, static fn(string $address): bool => !self::isPublicAddress($address))) {
            throw new BoardMessage('Remote downloads must resolve only to public Internet addresses.');
        }
        return [
            'url' => $uri->toString(), 'host' => $host,
            'port' => $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80),
            'addresses' => array_values(array_unique($addresses)), 'literal' => $literal,
        ];
    }

    public static function fetch(string $url, int $maximum): string
    {
        $target = self::target($url);
        $maximum = max(1, min($maximum, 16 * 1024 * 1024));
        $curl = curl_init($target['url']);
        if ($curl === false) {
            throw new BoardMessage('The remote request could not start.');
        }
        $body = '';
        $addresses = array_map(static fn(string $address): string => str_contains($address, ':') ? '[' . $address . ']' : $address, $target['addresses']);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_PROTOCOLS_STR => 'http,https',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_RESOLVE => $target['literal'] ? [] : [$target['host'] . ':' . $target['port'] . ':' . implode(',', $addresses)],
            CURLOPT_USERAGENT => 'Adelia/8.5',
            CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $chunk) use (&$body, $maximum): int {
                if (strlen($body) + strlen($chunk) > $maximum) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        return curl_exec($curl) !== false && curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200 ? $body : '';
    }
}
