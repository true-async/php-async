<?php
/**
 * ToxiproxyClient — a minimal HTTP-API client for Toxiproxy (Shopify).
 *
 * Toxiproxy is an external TCP proxy that injects transport-level faults a
 * pure-PHP EvilPeer cannot reproduce precisely: real bandwidth throttling,
 * latency with jitter, TCP-segment slicing, byte-counted truncation. It sits
 * between the client coroutine and the EvilPeer:
 *
 *   client ──▶ Toxiproxy proxy (listen) ──▶ EvilPeer (upstream)
 *                  └── toxics: latency / bandwidth / slicer / limit_data ...
 *
 * The harness creates one proxy per fronted peer, points its upstream at the
 * peer's real listening socket, and hands the proxy's listen address to the
 * client instead — transparently, via Context::$evilPeerAddr.
 *
 * Toxiproxy is opt-in: its admin endpoint is read from the CHAOS_TOXIPROXY
 * env var (default 127.0.0.1:8474). When it is not reachable the generated
 * .phpt files skip (see SKIP_RULES['toxiproxy'] in generate.php), so the
 * suite stays inert on dev machines and per-PR CI — it only runs where a
 * Toxiproxy instance is actually up (the nightly job, or a local Docker run).
 *
 * The client speaks just enough HTTP/1.1 to avoid an ext/curl dependency:
 * one request per call, Connection: close, body read to EOF.
 */

namespace Async\Chaos;

final class ToxiproxyClient {
    private string $host;
    private int    $port;

    public function __construct(?string $endpoint = null) {
        $endpoint ??= getenv('CHAOS_TOXIPROXY') ?: '127.0.0.1:8474';
        $colon = strrpos($endpoint, ':');
        if ($colon === false) {
            $this->host = $endpoint;
            $this->port = 8474;
        } else {
            $this->host = substr($endpoint, 0, $colon);
            $this->port = (int) substr($endpoint, $colon + 1);
        }
    }

    /**
     * True when a Toxiproxy admin endpoint answers GET /version. Used both by
     * the harness (to decide whether to set proxies up) and, in spirit, by
     * the generated --SKIPIF-- probe.
     */
    public static function isAvailable(?string $endpoint = null): bool {
        try {
            $self = new self($endpoint);
            [$status, ] = $self->request('GET', '/version');
            return $status === 200;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The admin endpoint this client talks to, as "host:port". */
    public function endpoint(): string {
        return $this->host . ':' . $this->port;
    }

    /**
     * Create a proxy. `listen` may be "127.0.0.1:0" — Toxiproxy then picks a
     * free port and reports it back. Returns the *resolved* listen address
     * the client must connect to.
     */
    public function createProxy(string $name, string $listen, string $upstream): string {
        [$status, $body] = $this->request('POST', '/proxies', [
            'name'     => $name,
            'listen'   => $listen,
            'upstream' => $upstream,
            'enabled'  => true,
        ]);
        // A stale proxy from a crashed run collides — replace it and retry.
        if ($status === 409) {
            $this->deleteProxy($name);
            [$status, $body] = $this->request('POST', '/proxies', [
                'name'     => $name,
                'listen'   => $listen,
                'upstream' => $upstream,
                'enabled'  => true,
            ]);
        }
        if ($status !== 201) {
            throw new \RuntimeException("Toxiproxy: createProxy $name failed (HTTP $status): $body");
        }
        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['listen'])) {
            throw new \RuntimeException("Toxiproxy: createProxy $name returned no listen address");
        }
        return $json['listen'];
    }

    /**
     * Attach one toxic to a proxy.
     *
     * @param string $stream     'downstream' (server→client) or 'upstream'
     * @param array  $attributes toxic-type-specific parameters
     */
    public function addToxic(
        string $proxy,
        string $toxicName,
        string $type,
        string $stream,
        array  $attributes,
        float  $toxicity = 1.0
    ): void {
        [$status, $body] = $this->request('POST', "/proxies/$proxy/toxics", [
            'name'       => $toxicName,
            'type'       => $type,
            'stream'     => $stream,
            'toxicity'   => $toxicity,
            'attributes' => (object) $attributes,
        ]);
        if ($status !== 200 && $status !== 201) {
            throw new \RuntimeException("Toxiproxy: addToxic $type on $proxy failed (HTTP $status): $body");
        }
    }

    /** Delete a proxy (and all its toxics). Idempotent — a missing proxy is fine. */
    public function deleteProxy(string $name): void {
        try {
            $this->request('DELETE', "/proxies/$name");
        } catch (\Throwable $e) {
            // Teardown best-effort: a gone proxy or a gone Toxiproxy is harmless.
        }
    }

    /**
     * Issue one HTTP/1.1 request, return [statusCode, body].
     *
     * @return array{0:int,1:string}
     */
    private function request(string $method, string $path, ?array $body = null): array {
        $payload = $body !== null ? json_encode($body) : '';
        $req  = "$method $path HTTP/1.1\r\n";
        $req .= "Host: {$this->host}:{$this->port}\r\n";
        $req .= "Connection: close\r\n";
        $req .= "Accept: application/json\r\n";
        if ($body !== null) {
            $req .= "Content-Type: application/json\r\n";
            $req .= "Content-Length: " . strlen($payload) . "\r\n";
        }
        $req .= "\r\n" . $payload;

        $sock = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}", $errno, $errstr, 5);
        if ($sock === false) {
            throw new \RuntimeException(
                "Toxiproxy: cannot connect to {$this->host}:{$this->port}: $errstr");
        }
        try {
            @fwrite($sock, $req);
            $raw = '';
            while (!feof($sock)) {
                $chunk = @fread($sock, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $raw .= $chunk;
            }
        } finally {
            @fclose($sock);
        }

        $split = strpos($raw, "\r\n\r\n");
        if ($split === false) {
            throw new \RuntimeException("Toxiproxy: malformed HTTP response");
        }
        $head = substr($raw, 0, $split);
        $resp = substr($raw, $split + 4);
        if (!preg_match('#^HTTP/\d\.\d\s+(\d+)#', $head, $m)) {
            throw new \RuntimeException("Toxiproxy: no HTTP status line");
        }
        // Connection: close → the server closes after the body, so what we
        // read up to EOF is the whole body. Toxiproxy never uses chunked
        // encoding for these endpoints.
        return [(int) $m[1], $resp];
    }
}
