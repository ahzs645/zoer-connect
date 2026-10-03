<?php
namespace ZoerConnect;
require_once __DIR__.'/TransferStorage.php';

/** Private, bounded staging. This class never writes into a live WordPress tree. */
final class StorageUnavailable extends \RuntimeException {
    public function __construct(public readonly string $reason) { parent::__construct($reason); }
}

final class StageStore {
    public const CHUNK = 262144;
    public const MAX_BYTES = TransferStorage::FILE_BYTES;
    public const MAX_FILES = 20000;
    private string $root;

    public function __construct(string $root, array $publicRoots) {
        if (!$publicRoots || @is_link($root)) throw new StorageUnavailable('unsafe_path');
        $parent = @realpath(dirname($root));
        if (!$parent) throw new StorageUnavailable('parent_unavailable');
        $candidate = $parent . '/' . basename($root);
        foreach ($publicRoots as $public) {
            $resolved = @realpath($public);
            if (!$resolved || $candidate === $resolved || str_starts_with($candidate, $resolved . '/')) {
                throw new StorageUnavailable(!$resolved ? 'public_root_unavailable' : 'inside_public_root');
            }
        }
        if (!@is_dir($candidate) && !@mkdir($candidate, 0700)) throw new StorageUnavailable('create_denied');
        if (@realpath($candidate) !== $candidate || !@is_writable($candidate)) throw new StorageUnavailable('not_writable');
        $this->root = $candidate;
    }

    public static function validateManifest(array $manifest, string $target): array {
        if (($manifest['version'] ?? null) !== 1 || ($manifest['target'] ?? null) !== $target) throw new \InvalidArgumentException('Manifest version or target mismatch.');
        $files = $manifest['files'] ?? null;
        if (!is_array($files) || !array_is_list($files) || !$files || count($files) > self::MAX_FILES) throw new \InvalidArgumentException('Invalid file count.');
        $total = 0; $paths = []; $clean = [];
        foreach ($files as $file) {
            $path = $file['path'] ?? '';
            if (!is_string($path) || strlen($path) > 500 || !preg_match('~^wp-content/(themes|plugins|uploads)/[\p{L}\p{N}\p{M}\p{Zs}_./ ,()@+\~!&\'=\[\]#-]+$~Du', $path)) throw new \InvalidArgumentException('Unsupported file path.');
            foreach (explode('/', $path) as $part) {
                if (!$part || $part === '.' || $part === '..' || $part[0] === '.') throw new \InvalidArgumentException('Unsafe file path.');
            }
            if (preg_match('~^wp-content/plugins/zoer-connect(?:/|$)~i', $path) || preg_match('~(?:^|/)(?:wp-config\.php|\.htaccess)$~i', $path)) throw new \InvalidArgumentException('Protected file.');
            if (str_starts_with($path, 'wp-content/uploads/') && preg_match('~\.(?:php\d*|phtml|phar|cgi|pl|sh)(?:\.|$)~i', $path)) throw new \InvalidArgumentException('Executable upload rejected.');
            $key = strtolower($path);
            if (isset($paths[$key])) throw new \InvalidArgumentException('Duplicate path.');
            $paths[$key] = true;
            $bytes = $file['bytes'] ?? null; $sha = $file['sha256'] ?? '';
            if (!is_int($bytes) || $bytes < 0 || $bytes > self::MAX_BYTES || !is_string($sha) || !preg_match('/^[a-f0-9]{64}$/D', $sha)) throw new \InvalidArgumentException('Invalid file metadata.');
            $total += $bytes;
            if ($total > TransferStorage::quota()) throw new \InvalidArgumentException('Bundle too large.');
            $clean[] = ['path' => $path, 'bytes' => $bytes, 'sha256' => $sha];
        }
        // Reject file/directory collisions as well as case-insensitive duplicates.
        foreach (array_keys($paths) as $path) {
            $parent = dirname($path);
            while ($parent !== '.') {
                if (isset($paths[$parent])) throw new \InvalidArgumentException('File/directory collision.');
                $parent = dirname($parent);
            }
        }
        return ['version' => 1, 'target' => $target, 'files' => $clean, 'bytes' => $total];
    }

    private function locked(callable $fn) {
        $handle = fopen($this->root . '/lock', 'c');
        if (!$handle || !flock($handle, LOCK_EX)) throw new \RuntimeException('Cannot lock staging.');
        try { return $fn(); } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
    private function save(string $id, array $job): void {
        $tmp = $this->root . '/' . $id . '/state.tmp';
        $json = json_encode($job, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($tmp, $json) !== strlen($json) || !rename($tmp, $this->root . '/' . $id . '/state.json')) throw new \RuntimeException('Cannot save staging state.');
    }
    private function read(string $id): array {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new \InvalidArgumentException('Invalid job ID.');
        $path = $this->root . '/' . $id . '/state.json';
        if (!is_file($path)) throw new \InvalidArgumentException('Job not found.');
        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
    public function jobs(): array {
        return $this->locked(function () {
            $result = [];
            foreach (glob($this->root . '/*/state.json') as $path) {
                $job = $this->read(basename(dirname($path)));
                $result[] = ['id' => $job['id'], 'status' => $job['status'], 'createdAt' => $job['createdAt'], 'target' => $job['manifest']['target']];
            }
            return $result;
        });
    }
    /** Explicit expiry operation; never removes a job with a publication journal. */
    public function expire(int $now): array {
        return $this->locked(function () use ($now) {
            $removed = [];
            foreach (glob($this->root . '/*/state.json') as $path) {
                $id = basename(dirname($path)); $job = $this->read($id);
                if (strtotime($job['createdAt']) + 86400 > $now || is_file(dirname($path) . '/publication.json')) continue;
                foreach (glob(dirname($path) . '/*') as $file) {
                    if (!is_file($file) || !unlink($file)) throw new \RuntimeException('Expiry cleanup failed.');
                }
                if (!rmdir(dirname($path))) throw new \RuntimeException('Expiry cleanup failed.');
                $removed[] = $id;
            }
            return $removed;
        });
    }
    public function create(array $manifest, string $target): array {
        $manifest = self::validateManifest($manifest, $target);
        return $this->locked(function () use ($manifest) {
            // Reserve at most one bundle; cancellation explicitly releases its storage.
            if (glob($this->root . '/*/state.json')) throw new \RuntimeException('An existing staging job must be cancelled first.');
            if (disk_free_space($this->root) < $manifest['bytes'] + 67108864) throw new \RuntimeException('Insufficient staging space.');
            $id = bin2hex(random_bytes(16));
            if (!mkdir($this->root . '/' . $id, 0700)) throw new \RuntimeException('Cannot create job.');
            $job = ['id' => $id, 'status' => 'uploading', 'createdAt' => gmdate('c'), 'manifest' => $manifest];
            $this->save($id, $job);
            return $job;
        });
    }
    public function status(string $id): array {
        return $this->locked(function () use ($id) {
            $job = $this->read($id); $offsets = [];
            foreach ($job['manifest']['files'] as $i => $file) {
                $path = $this->root . '/' . $id . '/' . $i . '.part';
                clearstatcache(true, $path);
                $offsets[] = is_file($path) ? filesize($path) : 0;
            }
            $job['offsets'] = $offsets;
            return $job;
        });
    }
    public function chunk(string $id, int $index, int $offset, string $data): array {
        if ($offset < 0 || strlen($data) > self::CHUNK) throw new \InvalidArgumentException('Invalid chunk.');
        return $this->locked(function () use ($id, $index, $offset, $data) {
            $job = $this->read($id);
            if ($job['status'] !== 'uploading' || !isset($job['manifest']['files'][$index])) throw new \InvalidArgumentException('Job is not writable.');
            $file = $job['manifest']['files'][$index];
            $path = $this->root . '/' . $id . '/' . $index . '.part';
            clearstatcache(true, $path);
            $size = is_file($path) ? filesize($path) : 0;
            if ($offset + strlen($data) > $file['bytes']) throw new \InvalidArgumentException('Chunk exceeds declared file size.');
            if ($offset < $size && $offset + strlen($data) <= $size) {
                $h = fopen($path, 'rb'); fseek($h, $offset); $old = fread($h, max(1, strlen($data))); fclose($h);
                if (!hash_equals($old, $data)) throw new \InvalidArgumentException('Retry data differs.');
                return ['offset' => $size];
            }
            if ($offset !== $size) throw new \InvalidArgumentException('Offset mismatch. Read job status before resuming.');
            $h = fopen($path, 'ab');
            if (!$h) throw new \RuntimeException('Cannot open staged file.');
            try {
                if (fwrite($h, $data) !== strlen($data) || !fflush($h)) throw new \RuntimeException('Cannot write chunk.');
            } finally { fclose($h); }
            return ['offset' => $offset + strlen($data)];
        });
    }
    public function verify(string $id): array {
        return $this->locked(function () use ($id) {
            $job = $this->read($id);
            foreach ($job['manifest']['files'] as $i => $file) {
                $path = $this->root . '/' . $id . '/' . $i . '.part';
                if (!is_file($path) || filesize($path) !== $file['bytes'] || !hash_equals($file['sha256'], hash_file('sha256', $path))) throw new \InvalidArgumentException('File verification failed at index ' . $i . '.');
            }
            $job['status'] = 'staged'; $this->save($id, $job); return $job;
        });
    }
    public function cancel(string $id): array {
        return $this->locked(function () use ($id) {
            $this->read($id);
            if (is_file($this->root . '/' . $id . '/publication.json')) throw new \RuntimeException('Publication recovery data must be retained.');
            foreach (glob($this->root . '/' . $id . '/*') as $path) {
                if (!is_file($path) || !unlink($path)) throw new \RuntimeException('Cannot remove staged data.');
            }
            if (!rmdir($this->root . '/' . $id)) throw new \RuntimeException('Cannot remove staging directory.');
            return ['id' => $id, 'status' => 'cancelled'];
        });
    }
}
