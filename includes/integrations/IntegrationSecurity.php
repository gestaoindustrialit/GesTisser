<?php
class IntegrationSecurity
{
    private static function key(): string { $seed = getenv('APP_KEY') ?: (__DIR__ . php_uname('n')); return hash('sha256', $seed, true); }
    public static function encrypt(string $value): string
    {
        if ($value === '') return '';
        $iv = random_bytes(12); $tag = '';
        $encrypted = openssl_encrypt($value, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false) throw new RuntimeException('Não foi possível cifrar a credencial.');
        return base64_encode($iv . $tag . $encrypted);
    }
    public static function decrypt(string $value): string
    {
        $raw = base64_decode($value, true); if ($raw === false || strlen($raw) < 29) return '';
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw,0,12), substr($raw,12,16));
        return $plain === false ? '' : $plain;
    }
    public static function safeUrl(string $url): bool
    {
        $parts = parse_url($url); if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http','https'], true) || empty($parts['host'])) return false;
        $host = strtolower($parts['host']); if ($host === 'localhost' || substr($host,-6) === '.local' || $host === '169.254.169.254') return false;
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        foreach ($ips as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        return true;
    }
    public static function redact($value)
    {
        $text = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
        $text = preg_replace('/(Authorization\s*[:=]\s*)([^\r\n,}]+)/i', '$1********', (string)$text);
        $text = preg_replace('/("?(?:password|client_secret|access_token|refresh_token|token)"?\s*[:=]\s*"?)[^",\s}]+/i', '$1********', $text);
        return preg_replace('/Bearer\s+[^\s",}]+/i', 'Bearer ********', $text);
    }
}
