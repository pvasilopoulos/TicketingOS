<?php
declare(strict_types=1);

/*
 * Development mail catcher: a tiny SMTP server that accepts every message and
 * saves it to storage/mail/*.eml instead of delivering it. View the messages at
 * /dev/mail (admins, APP_DEBUG=1 only).
 *
 *   php bin/mail-catcher.php            (listens on 127.0.0.1:1025)
 *   php bin/mail-catcher.php 2525       (another port)
 *
 * Point a workspace at it in Settings → Email: SMTP, host 127.0.0.1, port 1025,
 * no TLS, no username. Not for production.
 */

$port = (int) ($argv[1] ?? 1025);
$dir = __DIR__ . '/../storage/mail';
if (!is_dir($dir)) mkdir($dir, 0775, true);

$server = @stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "Cannot listen on 127.0.0.1:$port: $errstr\n");
    exit(1);
}
echo "Mail catcher listening on 127.0.0.1:$port, saving to " . realpath($dir) . "\n";

while (true) {
    $c = @stream_socket_accept($server, -1);
    if (!$c) continue;
    stream_set_timeout($c, 30);
    $say = fn (string $l) => fwrite($c, $l . "\r\n");
    $say('220 localhost TicketingOS mail catcher');
    $from = '';
    $to = [];
    while (($line = fgets($c)) !== false) {
        $cmd = strtoupper(substr(trim($line), 0, 4));
        if ($cmd === 'EHLO' || $cmd === 'HELO') {
            $say('250-localhost');
            $say('250 8BITMIME');
        } elseif ($cmd === 'MAIL') {
            $from = trim(substr(trim($line), 10), '<> ');
            $to = [];
            $say('250 OK');
        } elseif ($cmd === 'RCPT') {
            $to[] = trim(substr(trim($line), 8), '<> ');
            $say('250 OK');
        } elseif ($cmd === 'DATA') {
            $say('354 End data with <CR><LF>.<CR><LF>');
            $data = '';
            while (($l = fgets($c)) !== false) {
                if (rtrim($l, "\r\n") === '.') break;
                $data .= str_starts_with($l, '..') ? substr($l, 1) : $l;
            }
            $name = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml';
            file_put_contents("$dir/$name", "X-Envelope-From: $from\r\nX-Envelope-To: " . implode(', ', $to) . "\r\n" . $data);
            echo gmdate('H:i:s') . " saved $name to " . implode(', ', $to) . "\n";
            $say('250 OK saved');
        } elseif ($cmd === 'RSET' || $cmd === 'NOOP') {
            $say('250 OK');
        } elseif ($cmd === 'QUIT') {
            $say('221 Bye');
            break;
        } else {
            // AUTH, STARTTLS etc. are not offered, so anything else is unexpected.
            $say('502 Command not implemented');
        }
    }
    fclose($c);
}
