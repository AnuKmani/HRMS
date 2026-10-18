<?php

namespace App\Support\Storage;

use Illuminate\Support\Facades\DB;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\MimeTypeDetection\FinfoMimeTypeDetector;

/**
 * Keeps private files (selfies, report photos, documents, receipts) in a
 * database table instead of on disk.
 *
 * For hosts with no lasting disk — Vercel and other serverless platforms
 * keep only /tmp, and only for one instance — so an uploaded file would
 * vanish minutes later. Contents are stored base64-encoded so the same
 * text column works on Postgres, MySQL and SQLite without binary quirks.
 */
class DatabaseFilesystemAdapter implements FilesystemAdapter
{
    public function __construct(private readonly string $table = 'stored_files') {}

    private function query()
    {
        return DB::table($this->table);
    }

    private function row(string $path): ?object
    {
        return $this->query()->where('path', $path)->first();
    }

    public function fileExists(string $path): bool
    {
        return $this->query()->where('path', $path)->exists();
    }

    public function directoryExists(string $path): bool
    {
        return $this->query()->where('path', 'like', rtrim($path, '/').'/%')->exists();
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $mime = (new FinfoMimeTypeDetector)->detectMimeType($path, $contents) ?? 'application/octet-stream';

        $this->query()->updateOrInsert(['path' => $path], [
            'contents' => base64_encode($contents),
            'mime_type' => $mime,
            'size' => strlen($contents),
            'last_modified' => time(),
        ]);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->write($path, (string) stream_get_contents($contents), $config);
    }

    public function read(string $path): string
    {
        $row = $this->row($path);

        if ($row === null) {
            throw UnableToReadFile::fromLocation($path, 'No such file.');
        }

        return (string) base64_decode((string) $row->contents);
    }

    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $this->read($path));
        rewind($stream);

        return $stream;
    }

    public function delete(string $path): void
    {
        $this->query()->where('path', $path)->delete();
    }

    public function deleteDirectory(string $path): void
    {
        $this->query()->where('path', 'like', rtrim($path, '/').'/%')->delete();
    }

    public function createDirectory(string $path, Config $config): void
    {
        // Directories are implied by file paths.
    }

    public function setVisibility(string $path, string $visibility): void
    {
        // Every file here is private and served through the API.
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, 'private');
    }

    private function meta(string $path): object
    {
        $row = $this->query()->where('path', $path)->select('mime_type', 'size', 'last_modified')->first();

        if ($row === null) {
            throw UnableToRetrieveMetadata::create($path, 'metadata', 'No such file.');
        }

        return $row;
    }

    public function mimeType(string $path): FileAttributes
    {
        return new FileAttributes($path, null, null, null, $this->meta($path)->mime_type);
    }

    public function lastModified(string $path): FileAttributes
    {
        return new FileAttributes($path, null, null, (int) $this->meta($path)->last_modified);
    }

    public function fileSize(string $path): FileAttributes
    {
        return new FileAttributes($path, (int) $this->meta($path)->size);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $prefix = trim($path, '/');
        $rows = $this->query()
            ->when($prefix !== '', fn ($q) => $q->where('path', 'like', $prefix.'/%'))
            ->select('path', 'size', 'last_modified')
            ->orderBy('path')
            ->get();

        $dirs = [];
        foreach ($rows as $row) {
            $rest = $prefix === '' ? $row->path : substr($row->path, strlen($prefix) + 1);
            if (! $deep && str_contains($rest, '/')) {
                $dir = ($prefix === '' ? '' : $prefix.'/').strstr($rest, '/', true);
                if (! isset($dirs[$dir])) {
                    $dirs[$dir] = true;
                    yield new DirectoryAttributes($dir);
                }

                continue;
            }
            yield new FileAttributes($row->path, (int) $row->size, null, (int) $row->last_modified);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->query()->where('path', $destination)->delete();
        $this->query()->where('path', $source)->update(['path' => $destination]);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->write($destination, $this->read($source), $config);
    }
}
