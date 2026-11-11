<?php
declare(strict_types=1);

/*
 * Small dependency-free SMTP client.
 *   secure=true  + port 465 → implicit TLS (like nodemailer `secure: true`)
 *   otherwise               → plain connection, upgraded with STARTTLS when offered
 * AUTH PLAIN / LOGIN; multipart/alternative (text + HTML) bodies.
 */

function smtp_send(array $cfg, array $m): void
{
    $host = $cfg['host'];
    $port = (int) $cfg['port'];
    $implicitTls = $cfg['secure'] && $port !== 587 && $port !== 25;
    $ctx = stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true]]);
    $remote = ($implicitTls ? 'ssl://' : 'tcp://') . $host . ':' . $port;

    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new RuntimeException("SMTP connect failed: $errstr ($errno)");
    stream_set_timeout($fp, 20);

    $read = function () use ($fp): array {
        $data = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return [(int) substr($data, 0, 3), $data];
    };
    $cmd = function (string $line, array $expect) use ($fp, $read): string {
        fwrite($fp, $line . "\r\n");
        [$code, $data] = $read();
        if (!in_array($code, $expect, true)) {
            $shown = str_starts_with($line, 'AUTH') || strlen($line) > 60 && !str_contains($line, ' ') ? '(credentials)' : $line;
            throw new RuntimeException("SMTP error after $shown: " . trim($data));
        }
        return $data;
    };

    try {
        [$code, $greet] = $read();
        if ($code !== 220) throw new RuntimeException('SMTP greeting failed: ' . trim($greet));
        $ehloHost = parse_url(app_url(), PHP_URL_HOST) ?: 'localhost';
        $caps = $cmd("EHLO $ehloHost", [250]);

        if (!$implicitTls && stripos($caps, 'STARTTLS') !== false) {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS negotiation failed');
            }
            $caps = $cmd("EHLO $ehloHost", [250]);
        } elseif (!$implicitTls && $cfg['secure']) {
            throw new RuntimeException('Server does not offer STARTTLS but TLS is required');
        }

        if (!empty($cfg['user'])) {
            if (preg_match('/AUTH[ =][^\r\n]*PLAIN/i', $caps)) {
                $cmd('AUTH PLAIN ' . base64_encode("\0{$cfg['user']}\0{$cfg['pass']}"), [235]);
            } else {
                $cmd('AUTH LOGIN', [334]);
                $cmd(base64_encode($cfg['user']), [334]);
                $cmd(base64_encode((string) $cfg['pass']), [235]);
            }
        }

        $cmd('MAIL FROM:<' . $m['fromEmail'] . '>', [250]);
        $cmd('RCPT TO:<' . $m['to'] . '>', [250, 251]);
        $cmd('DATA', [354]);

        $boundary = 'b' . bin2hex(random_bytes(12));
        $enc = fn (string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
        $from = $m['fromName'] ? $enc($m['fromName']) . ' <' . $m['fromEmail'] . '>' : $m['fromEmail'];
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $from,
            'To: <' . $m['to'] . '>',
            'Subject: ' . $enc($m['subject']),
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . (explode('@', $m['fromEmail'])[1] ?? 'localhost') . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $body = implode("\r\n", $headers) . "\r\n\r\n"
            . "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($m['text']))
            . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($m['html']))
            . "--$boundary--\r\n";
        $cmd($body . '.', [250]);
        @fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
}
