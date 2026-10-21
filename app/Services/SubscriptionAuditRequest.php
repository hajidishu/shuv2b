<?php

namespace App\Services;

use Illuminate\Http\Request;

/** Bounded HTTP metadata; never serialize the Request or its merged user model. */
class SubscriptionAuditRequest
{
    private $secrets;
    private $remaining = 12000;
    private $truncated = false;

    public function __construct(Request $request, $user)
    {
        $this->secrets = [];
        foreach ([(string)$user->token, (string)$request->input('token')] as $secret) {
            if ($secret !== '') $this->secrets = array_merge($this->secrets, [$secret, rawurlencode($secret), urlencode($secret)]);
        }
        $this->secrets = array_unique($this->secrets);
    }

    public function text($value, $limit, $preserveSubscriptionToken = false)
    {
        $value = (string)$value;
        if (!$preserveSubscriptionToken) $value = str_replace($this->secrets, '[redacted]', $value);
        // URLs can also occur in Referer or custom header values.
        $value = preg_replace_callback('~([?&]([^=&\s]*(?:token|secret|password|auth|key|signature|session)[^=&\s]*)=)[^&#\s]*~i', function ($match) use ($preserveSubscriptionToken) {
            return $preserveSubscriptionToken && $match[2] === 'token' ? $match[0] : $match[1] . '[redacted]';
        }, $value);
        $value = preg_replace('~(https?://)[^/@\s]+:[^/@\s]+@~i', '$1[redacted]@', $value);
        return mb_strcut(mb_convert_encoding($value, 'UTF-8', 'UTF-8'), 0, $limit, 'UTF-8');
    }

    private function sensitive($key)
    {
        return preg_match('/auth|cookie|token|secret|password|passwd|credential|session|signature|csrf|key|(^|[-_])(sid|code|ticket|sign)([-_]|$)/i', $key);
    }

    private function value($value, $depth = 0, $preserveSubscriptionToken = false)
    {
        if ($depth > 3 || $this->remaining <= 0) { $this->truncated = true; return '[truncated]'; }
        if (is_array($value)) {
            $out = [];
            if (count($value) > 32) $this->truncated = true;
            foreach (array_slice($value, 0, 32, true) as $key => $item) {
                if ((string)$key === 'user') continue;
                $name = $this->text($key, 80);
                $this->remaining -= strlen($name);
                $keepToken = $preserveSubscriptionToken && $depth === 0 && (string)$key === 'token' && is_scalar($item);
                $out[$name] = !$keepToken && $this->sensitive($key) ? '[redacted]' : $this->value($item, $depth + 1, $keepToken);
            }
            return $out;
        }
        if (!is_scalar($value) && $value !== null) return '[redacted]';
        $safe = $this->text($value, min(1024, max(0, $this->remaining)), $preserveSubscriptionToken);
        if (strlen((string)$value) > strlen($safe) && strpos($safe, '[redacted]') === false) $this->truncated = true;
        $this->remaining -= strlen($safe);
        return $safe;
    }

    public function capture(Request $request)
    {
        $query = $request->query->all();
        unset($query['user']);
        // Preserve the actual subscription credential, including temporary subscription tokens.
        // Process the query before headers so normal subscription URLs keep their token budget.
        $query = $this->value($query, 0, true);
        if (!is_array($query)) $query = ['_truncated' => '[truncated]'];
        $headers = [];
        $rawHeaders = $request->headers->all();
        if (count($rawHeaders) > 64) $this->truncated = true;
        foreach (array_slice($rawHeaders, 0, 64, true) as $key => $values) {
            $headers[$this->text($key, 80)] = $this->sensitive($key) ? '[redacted]' : $this->value(implode(', ', $values));
        }
        $scheme = $this->text($request->getScheme(), 16);
        $host = $this->text($request->getHttpHost(), 255);
        $path = $this->text($request->getPathInfo(), 1024);
        return [
            'method' => $this->text($request->getMethod(), 16), 'protocol' => $this->text($request->server('SERVER_PROTOCOL', ''), 32),
            'scheme' => $scheme, 'host' => $host, 'path' => $path,
            'url' => $this->text($scheme . '://' . $host . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : ''), 4096, true),
            'remote_ip' => $this->text($request->server('REMOTE_ADDR', ''), 45),
            'query' => $query, 'headers' => $headers, 'truncated' => $this->truncated,
        ];
    }

    public function encode(array $details)
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        $json = json_encode($details, $flags);
        if (strlen($json) > 24000) {
            $details['headers'] = ['_truncated' => '[truncated]'];
            $details['query'] = ['_truncated' => '[truncated]'];
            $details['url'] = $this->text($details['scheme'] . '://' . $details['host'] . $details['path'], 1024);
            $details['truncated'] = true;
            $json = json_encode($details, $flags);
        }
        return $json;
    }
}
