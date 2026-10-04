<?php

namespace App\Services\IntegrateCore;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Cache;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Server-side access to a File Browser account scoped to the client library. */
class FileLibrary
{
    public function __construct(private ?HttpClient $http = null) {}

    private function tokenKey(): string
    {
        return 'integratecore-file-token-' . hash('sha256', (string) config('integratecore.url') . config('integratecore.username'));
    }

    public static function visiblePath(string $path): bool
    {
        if ($path === '' || str_contains($path, '\\') || preg_match('/[\x00-\x1f]/', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || str_starts_with($part, '.')) {
                return false;
            }
        }
        return true;
    }

    public static function inside(string $path, string $folder): bool
    {
        return self::visiblePath($path) && self::visiblePath($folder)
            && str_starts_with($path, $folder . '/');
    }

    private function token(): string
    {
        return Cache::remember($this->tokenKey(), 1800, function () {
            $response = ($this->http ?? new HttpClient(['timeout' => 15]))->post(rtrim(config('integratecore.url'), '/') . '/api/login', [
                'json' => ['username' => config('integratecore.username'), 'password' => config('integratecore.password')],
            ]);
            return (string) $response->getBody();
        });
    }

    public function request(string $method, string $endpoint, array $options = []): ResponseInterface
    {
        if (!config('integratecore.enabled')) {
            throw new \RuntimeException('The client file library is not enabled.');
        }
        $body = array_key_exists('body', $options) ? Utils::streamFor($options['body']) : null;
        if ($body) {
            $options['body'] = $body;
        }
        $options['headers']['X-Auth'] = $this->token();
        $http = $this->http ?? new HttpClient(['connect_timeout' => 10, 'timeout' => 120]);
        $url = rtrim(config('integratecore.url'), '/') . '/api/' . $endpoint;
        try {
            return $http->request($method, $url, $options);
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() !== 401) {
                throw $e;
            }
            Cache::forget($this->tokenKey());
            // Retry only an explicit authentication rejection. Ambiguous failures
            // must never replay a write that may already have succeeded remotely.
            if ($body) {
                if (!$body->isSeekable()) {
                    throw $e;
                }
                $body->rewind();
            }
            $options['headers']['X-Auth'] = $this->token();
            return $http->request($method, $url, $options);
        }
    }

    private function encoded(string $path): string
    {
        if (!self::visiblePath($path)) {
            throw new \InvalidArgumentException('Invalid file library path.');
        }
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    public function entries(string $folder = ''): array
    {
        $suffix = $folder === '' ? '' : $this->encoded($folder);
        $data = json_decode((string) $this->request('GET', 'resources/' . $suffix)->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['isDir'] ?? null) !== true || !array_key_exists('items', $data) || !is_array($data['items'])) {
            throw new \RuntimeException('The selected library folder is not a directory.');
        }
        $entries = [];
        foreach ($data['items'] as $item) {
            if (!is_array($item) || !is_string($item['name'] ?? null) || !is_bool($item['isDir'] ?? null)) {
                throw new \RuntimeException('The file library returned an invalid directory listing.');
            }
            if (!self::visiblePath($item['name']) || str_contains($item['name'], '/') || ($item['isSymlink'] ?? false)) {
                continue;
            }
            if (!$item['isDir'] && (!is_int($item['size'] ?? null) || $item['size'] < 0)) {
                throw new \RuntimeException('The file library returned an invalid file size.');
            }
            $entries[] = $item;
        }
        return $entries;
    }

    public function files(string $folder): array
    {
        $result = [];
        $queue = [$folder];
        $visited = 0;
        $discovered = 0;
        // Bound traversal to avoid an accidentally selected enormous folder exhausting the server.
        while ($queue !== []) {
            if (++$visited > 10000) {
                throw new \RuntimeException('The client folder exceeds the directory limit.');
            }
            $directory = array_shift($queue);
            foreach ($this->entries($directory) as $entry) {
                if (++$discovered > 10000) {
                    throw new \RuntimeException('The client folder exceeds the 10,000 entry limit.');
                }
                $path = $directory . '/' . $entry['name'];
                if ($entry['isDir']) {
                    $queue[] = $path;
                } else {
                    $entry['library_path'] = $path;
                    $result[] = $entry;
                }
            }
        }
        return $result;
    }

    public function read(string $path): ResponseInterface
    {
        return $this->request('GET', 'raw/' . $this->encoded($path), ['stream' => true]);
    }

    public function upload(string $path, $stream): void
    {
        // File Browser rejects existing destinations. Never silently overwrite business files.
        $this->request('POST', 'resources/' . $this->encoded($path), ['body' => $stream]);
    }

    public function delete(string $path): void
    {
        $this->request('DELETE', 'resources/' . $this->encoded($path));
    }

    public function checksum(string $path): string
    {
        $stream = $this->read($path)->getBody();
        $context = hash_init('sha256');
        try {
            while (!$stream->eof()) {
                hash_update($context, $stream->read(65536));
            }
            return hash_final($context);
        } finally {
            $stream->close();
        }
    }

    public function temporaryPath(string $path, ?int $maximumBytes = null): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'integratecore-');
        if ($temporary === false) {
            throw new \RuntimeException('A temporary document could not be created.');
        }
        register_shutdown_function(static fn () => is_file($temporary) ? unlink($temporary) : null);
        $output = fopen($temporary, 'wb');
        $input = null;
        try {
            if ($output === false) {
                throw new \RuntimeException('A temporary document could not be opened.');
            }
            $input = $this->read($path)->getBody();
            $bytes = 0;
            while (!$input->eof()) {
                $chunk = $input->read(65536);
                $bytes += strlen($chunk);
                if ($maximumBytes !== null && $bytes > $maximumBytes) {
                    throw new \RuntimeException('The document exceeds the download size limit.');
                }
                while ($chunk !== '') {
                    $written = fwrite($output, $chunk);
                    if ($written === false || $written === 0) {
                        throw new \RuntimeException('A temporary document could not be written.');
                    }
                    $chunk = substr($chunk, $written);
                }
            }
        } catch (\Throwable $e) {
            @unlink($temporary);
            throw $e;
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
            $input?->close();
        }
        return $temporary;
    }

    public function download(string $path, string $name, string $mime, bool $inline = false): StreamedResponse
    {
        $input = $this->read($path)->getBody();
        $response = response()->streamDownload(function () use ($input) {
            try {
                while (!$input->eof()) {
                    echo $input->read(65536);
                }
            } finally {
                $input->close();
            }
        }, basename($name), ['Content-Type' => $mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'], $inline ? 'inline' : 'attachment');
        return $response;
    }
}
