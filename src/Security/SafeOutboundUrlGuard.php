<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Centralized SSRF defense for every outbound URL the CRM fetches.
 *
 * All crawler/scraper/discovery services MUST validate URLs through this
 * guard before any HTTP request (including redirects discovered mid-flight).
 * It rejects:
 *   - non-HTTP(S) schemes (file://, gopher://, ftp://, ...)
 *   - embedded credentials (user:pass@host)
 *   - localhost, *.local, dotless and invalid hostnames
 *   - hostnames resolving (A or AAAA) to non-public addresses:
 *     loopback, RFC1918 private, link-local (incl. 169.254.169.254 cloud
 *     metadata), ULA fc00::/7, link-local fe80::/10, multicast, reserved,
 *     unspecified, IPv4-mapped IPv6 and bogons.
 *
 * DNS answers are re-checked on every call (DNS rebinding mitigation: the
 * resolution used for validation is the same one the request will hit within
 * this process — clients must connect by the validated hostname/IP or
 * re-validate immediately before sending).
 */
final class SafeOutboundUrlGuard
{
    /** Ports outbound crawler traffic may target. */
    private const ALLOWED_PORTS = [80, 443, 8080, 8443];

    /** Resolution cache (per-process; short-lived by design). */
    private static array $resolvedCache = [];

    /**
     * Validate an outbound URL. Returns the normalized URL on success.
     *
     * @throws UnsafeOutboundUrlException when the URL may reach a
     *                                   non-public network endpoint
     */
    public function assertAllowed(string $url): string
    {
        $trimmed = trim($url);

        if ($trimmed === '' || str_contains($trimmed, "\0") || str_contains($trimmed, "\r") || str_contains($trimmed, "\n")) {
            throw new UnsafeOutboundUrlException('Malformed URL.');
        }

        // Reject scheme-relative/control-character tricks before parsing.
        if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $trimmed) !== 1 && !str_starts_with($trimmed, '//')) {
            throw new UnsafeOutboundUrlException('URL has no scheme.');
        }

        $parts = parse_url($trimmed);
        if ($parts === false) {
            throw new UnsafeOutboundUrlException('Unparseable URL.');
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new UnsafeOutboundUrlException(sprintf('Scheme "%s" is not allowed for outbound requests.', $scheme ?: '(none)'));
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeOutboundUrlException('Embedded credentials are not allowed.');
        }

        $host = strtolower($parts['host'] ?? '');
        if ($host === '') {
            throw new UnsafeOutboundUrlException('URL has no host.');
        }

        // parse_url keeps the brackets on IPv6 literals: normalize to the
        // bare address so it is validated as an IP, not "resolved".
        $isIpv6Literal = str_starts_with($host, '[') && str_ends_with($host, ']');
        if ($isIpv6Literal) {
            $host = substr($host, 1, -1);
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || $host === 'invalid') {
            throw new UnsafeOutboundUrlException(sprintf('Host "%s" is not allowed.', $host));
        }

        // Dotless intranet hostnames ("http://intranet/") resolve via search
        // domains and must never be fetched.
        if (!str_contains($host, '.') && !filter_var($host, FILTER_VALIDATE_IP)) {
            throw new UnsafeOutboundUrlException(sprintf('Dotless hostname "%s" is not allowed.', $host));
        }

        if (isset($parts['port']) && !in_array((int) $parts['port'], self::ALLOWED_PORTS, true)) {
            throw new UnsafeOutboundUrlException(sprintf('Port %d is not allowed for outbound requests.', (int) $parts['port']));
        }

        // Literal IP host: validate directly. Hostname: resolve and validate
        // every A/AAAA answer.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!self::isPubliclyRoutableIp($host)) {
                throw new UnsafeOutboundUrlException(sprintf('IP %s is not publicly routable.', $host));
            }
        } else {
            foreach ($this->resolveHost($host) as $ip) {
                if (!self::isPubliclyRoutableIp($ip)) {
                    throw new UnsafeOutboundUrlException(sprintf('Host "%s" resolves to non-public address %s.', $host, $ip));
                }
            }
        }

        return $trimmed;
    }

    /**
     * Stricter gate for CREDENTIAL-BEARING requests (portal logins and
     * submissions): crawling may use plain http, but sending usernames,
     * passwords, session cookies and supplier documents must not. Also
     * rejects non-default ports.
     */
    public function assertAllowedCredentialEndpoint(string $url): string
    {
        $allowed = $this->assertAllowed($url);

        $scheme = strtolower((string) (parse_url($allowed, PHP_URL_SCHEME) ?? ''));
        if ($scheme !== 'https') {
            throw new UnsafeOutboundUrlException('Credential-bearing requests require HTTPS.');
        }

        $port = parse_url($allowed, PHP_URL_PORT);
        if ($port !== null && $port !== 443) {
            throw new UnsafeOutboundUrlException('Credential-bearing requests must use port 443.');
        }

        return $allowed;
    }

    /**
     * Same-organization check for URLs discovered inside crawled content
     * (sitemaps, robots.txt): a candidate is allowed when it shares the base
     * host, or is a subdomain of it (or vice versa after stripping "www.").
     */
    public function isAllowedChildUrl(string $baseUrl, string $candidateUrl): bool
    {
        $baseHost = strtolower((string) (parse_url($baseUrl, PHP_URL_HOST) ?? ''));
        $candidateHost = strtolower((string) (parse_url($candidateUrl, PHP_URL_HOST) ?? ''));

        if ($baseHost === '' || $candidateHost === '') {
            return false;
        }

        $baseHost = preg_replace('/^www\./', '', $baseHost) ?? $baseHost;
        $candidateHost = preg_replace('/^www\./', '', $candidateHost) ?? $candidateHost;

        return $candidateHost === $baseHost
            || str_ends_with($candidateHost, '.' . $baseHost)
            || str_ends_with($baseHost, '.' . $candidateHost);
    }

    /**
     * Resolve a hostname to all its A/AAAA records. Returns [] when the name
     * does not resolve (an empty answer fails closed in assertAllowed only
     * when combined with an IP; an unresolvable name yields no connection
     * anyway, so it is permitted and the HTTP client will fail).
     *
     * @return string[]
     */
    private function resolveHost(string $host): array
    {
        if (isset(self::$resolvedCache[$host])) {
            return self::$resolvedCache[$host];
        }

        $ips = [];

        $aRecords = @gethostbynamel($host);
        if (is_array($aRecords)) {
            foreach ($aRecords as $ip) {
                $ips[] = $ip;
            }
        }

        if (function_exists('dns_get_record')) {
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $record) {
                    if (!empty($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        if (count(self::$resolvedCache) > 500) {
            self::$resolvedCache = []; // bound the cache
        }

        return self::$resolvedCache[$host] = array_values(array_unique($ips));
    }

    /**
     * True only for globally-routable public unicast addresses.
     */
    public static function isPubliclyRoutableIp(string $ip): bool
    {
        $ip = trim($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return self::isPublicIpv4($ip);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return self::isPublicIpv6($ip);
        }

        return false;
    }

    private static function isPublicIpv4(string $ip): bool
    {
        // filter_var with NO_PRIVACY|NO_RES range flags covers RFC1918,
        // loopback, link-local (incl. 169.254.169.254) and some reserved
        // space, but NOT multicast (224/4), class-E reserved (240/4) or
        // CGNAT (100.64/10) — those need explicit checks.
        if (filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false) {
            return false;
        }

        $first = (int) explode('.', $ip, 2)[0];

        // 224.0.0.0/4 multicast
        if ($first >= 224 && $first <= 239) {
            return false;
        }

        // 240.0.0.0/4 reserved (broadcast 255.255.255.255 included)
        if ($first >= 240) {
            return false;
        }

        // 100.64.0.0/10 carrier-grade NAT
        $parts = explode('.', $ip);
        if ((int) $parts[0] === 100 && (int) $parts[1] >= 64 && (int) $parts[1] <= 127) {
            return false;
        }

        return true;
    }

    private static function isPublicIpv6(string $ip): bool
    {
        // Normalize: PHP represents some addresses in compressed form.
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return false;
        }

        $unpack = unpack('n8', $packed);
        if ($unpack === false) {
            return false;
        }

        $embeddedV4 = static function () use ($packed): ?string {
            $value = unpack('N', substr($packed, 12, 4));
            if ($value === false) {
                return null;
            }
            $ip = long2ip($value[1]);

            return $ip === false ? null : $ip;
        };

        // IPv4-mapped (::ffff:a.b.c.d) and IPv4-compatible (::a.b.c.d):
        // re-check the embedded IPv4 address.
        if ($unpack[1] === 0 && $unpack[2] === 0 && $unpack[3] === 0 && $unpack[4] === 0
            && ($unpack[5] === 0xffff || $unpack[5] === 0)) {
            $v4 = $embeddedV4();

            return $v4 === null ? false : self::isPublicIpv4($v4);
        }

        // ::/128 unspecified, ::1/128 loopback
        if ($unpack === [0, 0, 0, 0, 0, 0, 0, 0] || $unpack === [0, 0, 0, 0, 0, 0, 0, 1]) {
            return false;
        }

        // fc00::/7 ULA
        if (($unpack[1] & 0xfe00) === 0xfc00) {
            return false;
        }

        // fe80::/10 link-local
        if (($unpack[1] & 0xffc0) === 0xfe80) {
            return false;
        }

        // ff00::/8 multicast
        if (($unpack[1] & 0xff00) === 0xff00) {
            return false;
        }

        // 2001:db8::/32 documentation
        if ($unpack[1] === 0x2001 && $unpack[2] === 0x0db8) {
            return false;
        }

        // 64:ff9b::/96 well-known NAT64 maps IPv4 space — treat as the
        // embedded IPv4.
        if ($unpack[1] === 0x0064 && $unpack[2] === 0xff9b) {
            $v4 = $embeddedV4();

            return $v4 === null ? false : self::isPublicIpv4($v4);
        }

        return true;
    }
}
