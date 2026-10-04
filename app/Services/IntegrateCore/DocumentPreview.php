<?php

namespace App\Services\IntegrateCore;

use App\Models\Document;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocumentPreview
{
    public const TEXT_LIMIT = 1024 * 1024;
    public const MEDIA_LIMIT = 25 * 1024 * 1024;
    public const IMAGE_TYPES = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp', 'avif' => 'image/avif',
    ];
    public const TEXT_TYPES = [
        'txt', 'text', 'md', 'markdown', 'csv', 'tsv', 'log', 'json', 'yaml', 'yml',
        'xml', 'html', 'htm', 'svg', 'css', 'js', 'mjs', 'cjs', 'ts', 'tsx', 'jsx',
        'py', 'pyw', 'deluge', 'dg', 'sql', 'sh', 'bash', 'zsh', 'fish', 'ps1',
        'php', 'rb', 'go', 'rs', 'java', 'c', 'h', 'cpp', 'hpp', 'cs', 'ini',
        'conf', 'cfg', 'toml', 'env', 'bat', 'cmd', 'r', 'vue', 'svelte', 'dart', 'pl', 'lua',
    ];

    public function __construct(private ClientFiles $files, private FileLibrary $storage) {}

    public function describe(Document $document): array
    {
        $path = $this->path($document);
        $extension = $this->extension($document);
        $result = ['kind' => 'unsupported', 'text' => null, 'truncated' => false, 'notice' => 'unsupported'];
        if ($this->mediaMime($extension)) {
            if ($document->size > self::MEDIA_LIMIT) {
                return array_replace($result, ['notice' => 'media_size']);
            }
            $stream = $this->storage->read($path)->getBody();
            try {
                $prefix = $stream->read(65536);
            } finally {
                $stream->close();
            }
            if (!$this->matchesMime($prefix, $this->mediaMime($extension))) {
                return $result;
            }
            return ['kind' => $extension === 'pdf' ? 'pdf' : 'image', 'text' => null, 'truncated' => false, 'notice' => null];
        }
        if (!in_array($extension, self::TEXT_TYPES, true)) {
            return $result;
        }
        $stream = $this->storage->read($path)->getBody();
        $text = '';
        try {
            while (!$stream->eof() && strlen($text) <= self::TEXT_LIMIT) {
                $chunk = $stream->read(min(65536, self::TEXT_LIMIT + 1 - strlen($text)));
                if ($chunk === '' && !$stream->eof()) {
                    throw new \RuntimeException('The document preview could not be read.');
                }
                $text .= $chunk;
            }
        } finally {
            $stream->close();
        }
        $truncated = strlen($text) > self::TEXT_LIMIT;
        $text = substr($text, 0, self::TEXT_LIMIT);
        // A bounded read may split the last UTF-8 character. Trim only its suffix.
        if ($truncated) {
            for ($trim = 0; $trim < 3 && !mb_check_encoding($text, 'UTF-8'); ++$trim) {
                $text = substr($text, 0, -1);
            }
        }
        if (!mb_check_encoding($text, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0e-\x1f]/', $text)) {
            return array_replace($result, ['notice' => 'binary']);
        }
        return ['kind' => 'text', 'text' => $text, 'truncated' => $truncated, 'notice' => $truncated ? 'text_truncated' : null];
    }

    public function content(Document $document): BinaryFileResponse
    {
        $path = $this->path($document);
        $mime = $this->mediaMime($this->extension($document));
        abort_unless($mime, 415);
        abort_if($document->size > self::MEDIA_LIMIT, 413);
        try {
            $temporary = $this->storage->temporaryPath($path, self::MEDIA_LIMIT);
        } catch (FileLibrarySizeException $e) {
            abort(413, 'The document exceeds the preview size limit.');
        }
        try {
            $prefix = file_get_contents($temporary, false, null, 0, 65536);
            abort_unless($prefix !== false && $this->matchesMime($prefix, $mime), 415);
            return response()->file($temporary, [
                'Content-Type' => $mime, 'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ])->setContentDisposition('inline', basename($document->name))->setPrivate()->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            @unlink($temporary);
            throw $e;
        }
    }

    private function extension(Document $document): string
    {
        return strtolower(pathinfo($document->name, PATHINFO_EXTENSION));
    }

    private function mediaMime(string $extension): ?string
    {
        return $extension === 'pdf' ? 'application/pdf' : (self::IMAGE_TYPES[$extension] ?? null);
    }

    private function matchesMime(string $prefix, string $expected): bool
    {
        $actual = (new \finfo(FILEINFO_MIME_TYPE))->buffer($prefix);
        if ($actual === 'image/x-ms-bmp') {
            $actual = 'image/bmp';
        }
        return $actual === $expected;
    }

    private function path(Document $document): string
    {
        abort_unless($document->disk === 'integratecore', 404);
        return $this->files->path($document);
    }
}
