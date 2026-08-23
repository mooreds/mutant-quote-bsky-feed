<?php

declare(strict_types=1);

function extract_quoted_uri(array $record): ?string
{
    $embed = $record['embed'] ?? null;
    if (!is_array($embed)) {
        return null;
    }
    foreach (
        [
            $embed['record']['uri'] ?? null,
            $embed['record']['record']['uri'] ?? null,
        ] as $candidate
    ) {
        if (is_string($candidate) && str_starts_with($candidate, 'at://')) {
            return $candidate;
        }
    }
    return null;
}

final class JetstreamClient
{
    public const OP_CONT = 0x0;
    public const OP_TEXT = 0x1;
    public const OP_CLOSE = 0x8;
    public const OP_PING = 0x9;
    public const OP_PONG = 0xA;

    private string $host;
    private int $port;
    private bool $tls;
    private string $path;

    /** @var resource|null */
    private $stream = null;
    private string $fragmentBuffer = '';
    private int $fragmentOpcode = 0;
    public bool $closed = true;
    public string $lastError = '';

    public function __construct(string $url)
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'], $parts['scheme'])) {
            throw new InvalidArgumentException("invalid jetstream url: {$url}");
        }
        $this->tls = strtolower($parts['scheme']) === 'wss';
        $this->host = $parts['host'];
        $this->port = (int) ($parts['port'] ?? ($this->tls ? 443 : 80));
        $this->path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    public function connect(int $cursorUs, int $timeoutSec = 15): void
    {
        $query = $this->path;
        $query .= (str_contains($query, '?') ? '&' : '?') . 'wantedCollections=app.bsky.feed.post';
        if ($cursorUs > 0) {
            $query .= '&cursor=' . $cursorUs;
        }

        $target = sprintf(
            '%s://%s:%d%s',
            $this->tls ? 'ssl' : 'tcp',
            $this->host,
            $this->port,
            $query
        );
        $context = stream_context_create(['ssl' => ['SNI_enabled' => true, 'verify_peer' => true]]);
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client($target, $errno, $errstr, $timeoutSec, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            throw new RuntimeException("jetstream connect failed: {$errstr} ({$errno})");
        }

        $key = base64_encode(random_bytes(16));
        $request =
            "GET {$query} HTTP/1.1\r\n" .
            "Host: {$this->host}\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "Sec-WebSocket-Key: {$key}\r\n" .
            "Sec-WebSocket-Version: 13\r\n" .
            "User-Agent: mutant-quotes-php/1.0\r\n\r\n";
        fwrite($stream, $request);

        $response = '';
        while (!str_contains($response, "\r\n\r\n")) {
            $line = fgets($stream);
            if ($line === false) {
                fclose($stream);
                throw new RuntimeException('jetstream handshake failed: no response');
            }
            $response .= $line;
        }
        if (!preg_match('#^HTTP/1\.[01] 101#i', $response)) {
            fclose($stream);
            $first = strtok($response, "\r\n") ?: 'unknown';
            throw new RuntimeException("jetstream handshake rejected: {$first}");
        }

        $this->stream = $stream;
        $this->closed = false;
        $this->fragmentBuffer = '';
        $this->fragmentOpcode = 0;
        stream_set_timeout($stream, 10);
    }

    /**
     * Returns the next decoded JSON message, or null when the connection
     * has ended (close frame, EOF, or read timeout). Pings are answered
     * transparently.
     */
    public function nextMessage(): ?array
    {
        while (true) {
            if ($this->closed || $this->stream === null) {
                return null;
            }
            $frame = $this->readFrame();
            if ($frame === null) {
                return null;
            }
            [$opcode, $payload] = $frame;

            switch ($opcode) {
                case 0x8:
                    $this->sendClose();
                    return null;
                case 0x9:
                    $this->sendControl(0xA, $payload);
                    continue 2;
                case 0xA:
                    continue 2;
                case 0x0:
                    $this->fragmentBuffer .= $payload;
                    if ($this->fragmentOpcode === 0) {
                        continue 2;
                    }
                    $msg = $this->fragmentBuffer;
                    $this->fragmentBuffer = '';
                    $opcode = $this->fragmentOpcode;
                    $this->fragmentOpcode = 0;
                    return $this->decode($opcode, $msg);
                case 0x1:
                case 0x2:
                    return $this->decode($opcode, $payload);
                default:
                    continue 2;
            }
        }
    }

    /** @return array{0:int,1:string}|null */
    private function readFrame(): ?array
    {
        $header = $this->readExact(2);
        if ($header === null) {
            return null;
        }
        $fin = ((ord($header[0]) & 0x80) !== 0);
        $opcode = ord($header[0]) & 0x0F;
        $masked = ((ord($header[1]) & 0x80) !== 0);
        $len = ord($header[1]) & 0x7F;

        if ($len === 126) {
            $ext = $this->readExact(2);
            if ($ext === null) {
                return null;
            }
            $len = unpack('n', $ext)[1];
        } elseif ($len === 127) {
            $ext = $this->readExact(8);
            if ($ext === null) {
                return null;
            }
            $len = unpack('J', $ext)[1];
        }

        $maskKey = null;
        if ($masked) {
            $maskKey = $this->readExact(4);
            if ($maskKey === null) {
                return null;
            }
        }

        $payload = $this->readExact((int) $len);
        if ($payload === null) {
            return null;
        }
        if ($maskKey !== null) {
            for ($i = 0; $i < strlen($payload); $i++) {
                $payload[$i] = $payload[$i] ^ $maskKey[$i % 4];
            }
        }

        if (!$fin && ($opcode === 0x1 || $opcode === 0x2)) {
            $this->fragmentOpcode = $opcode;
            $this->fragmentBuffer = $payload;
            return [0x100, ''];
        }
        if (!$fin && $opcode === 0x0 && $this->fragmentOpcode !== 0) {
            $this->fragmentBuffer .= $payload;
            return [0x100, ''];
        }

        return [$opcode, $payload];
    }

    /** @return array|null */
    private function decode(int $opcode, string $payload): ?array
    {
        if ($opcode !== 0x1) {
            return [];
        }
        try {
            $msg = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        return is_array($msg) ? $msg : [];
    }

    private function sendControl(int $opcode, string $payload = ''): void
    {
        if ($this->stream === null) {
            return;
        }
        $len = strlen($payload);
        $frame = chr(0x80 | $opcode);
        $maskKey = random_bytes(4);
        if ($len < 126) {
            $frame .= chr(0x80 | $len);
        } elseif ($len < 65536) {
            $frame .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $len);
        }
        $frame .= $maskKey;
        for ($i = 0; $i < $len; $i++) {
            $payload[$i] = $payload[$i] ^ $maskKey[$i % 4];
        }
        @fwrite($this->stream, $frame . $payload);
    }

    private function sendClose(): void
    {
        $this->sendControl(0x8, '');
        $this->closed = true;
        $this->disconnect();
    }

    public function disconnect(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
        }
        $this->closed = true;
    }

    private function readExact(int $bytes): ?string
    {
        if ($this->stream === null) {
            return null;
        }
        $buf = '';
        while (strlen($buf) < $bytes) {
            $chunk = @fread($this->stream, $bytes - strlen($buf));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->stream);
                if (($meta['timed_out'] ?? false)) {
                    $this->lastError = 'read timeout';
                } elseif (($meta['eof'] ?? false)) {
                    $this->lastError = 'eof';
                } else {
                    $this->lastError = 'empty read';
                    return null;
                }
                $this->disconnect();
                return null;
            }
            $buf .= $chunk;
        }
        return $buf;
    }
}
