<?php
namespace TypechoPlugin\SoraglassGallery;

use TypechoPlugin\SoraglassGallery\Storage\Registry;

final class Upload
{
    public function __construct(private Repository $repo, private array $config, private Storage\Driver $driver) {}

    /** Prepare before uploading so failed requests leave an addressable task. */
    public function prepare(array $input, int $owner): array
    {
        $meta = Repository::metadata($input);
        if ($meta['album'] === '') {
            $album = $this->repo->albums()[0] ?? $this->repo->saveAlbum(['title' => '光影收藏']);
            $meta['album'] = $album['id'];
        }
        $this->repo->get('albums', $meta['album']);
        $name = basename(str_replace('\\', '/', (string) ($input['name'] ?? 'photo')));
        $bytes = filter_var($input['bytes'] ?? null, FILTER_VALIDATE_INT);
        if (!$bytes || $bytes < 1) { throw new \InvalidArgumentException('无法识别文件大小，请重新选择照片'); }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!preg_match('/^[a-z0-9]{1,12}$/', $extension)) { $extension = 'bin'; }
        $id = Repository::id(); $task = Repository::id(); $now = time();
        $key = trim($this->config['prefix'], '/') . '/' . date('Y/m') . '/' . $id . '.' . $extension;
        $photo = $meta + ['id' => $id, 'name' => $name, 'mime' => '', 'bytes' => $bytes, 'width' => 0, 'height' => 0, 'hash' => '', 'storage' => json_encode(Settings::snapshot($this->config)), 'object_key' => $key, 'url' => $this->driver->url($key), 'thumb' => $this->driver->thumbnail($key), 'preview' => 0, 'state' => 'pending', 'published' => 0, 'revision' => 1, 'created' => $now, 'updated' => $now];
        $this->repo->transaction(function () use ($photo, $task, $owner, $now) {
            $this->repo->insert('photos', $photo);
            $this->repo->insert('tasks', ['id' => $task, 'photo' => $photo['id'], 'owner' => $owner, 'state' => 'pending', 'attempt' => 0, 'lease' => 0, 'error' => '', 'created' => $now, 'updated' => $now]);
        });
        return ['photo' => $id, 'task' => $task];
    }

    private function target(array $task): array
    {
        $photo = $this->repo->get('photos', $task['photo']);
        if ($photo['state'] === 'removed') { throw new \RuntimeException('照片记录已移除'); }
        $snapshot = json_decode($photo['storage'], true) ?: [];
        if (($snapshot['profile'] ?? '') !== Settings::profile($this->config)) { throw new \RuntimeException('此任务属于以前的云配置，请在插件中切回原 Bucket / Region / Endpoint 后重试'); }
        return $photo;
    }

    public function send(string $taskId, string $file): void
    {
        $task = $this->repo->get('tasks', $taskId); $photo = $this->target($task);
        $hash = is_file($file) ? hash_file('sha256', $file) : '';
        if (!is_file($file) || filesize($file) !== (int) $photo['bytes'] || ($photo['hash'] !== '' && $hash !== $photo['hash'])) { throw new \InvalidArgumentException('请重新选择原照片：文件大小或内容与任务不一致'); }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream';
        $image = @getimagesize($file);
        // No plugin format whitelist: retain provider-supported originals as attachments
        // whenever their MIME type cannot be previewed safely by a browser.
        $preview = (int) ($image && in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp', 'image/x-ms-bmp'], true));
        $claimed = $this->repo->claim($taskId, (int) $this->config['timeout']);
        @set_time_limit((int) $this->config['timeout']);
        $writing = false;
        try {
            $this->repo->bindFile($photo['id'], $hash);
            $photo['hash'] = $hash;
            $this->repo->update('photos', $photo['id'], ['mime' => $mime, 'width' => $image[0] ?? 0, 'height' => $image[1] ?? 0, 'preview' => $preview]);
            $existing = $this->driver->inspect($photo['object_key']);
            if ($existing !== null) { $this->verify($photo, $existing); }
            else {
                $writing = true;
                $this->repo->phase($taskId, (int) $claimed['attempt'], 'writing');
                $this->driver->upload($file, $photo['object_key'], $mime, $photo['hash']);
                $this->repo->phase($taskId, (int) $claimed['attempt'], 'verifying');
                $this->verify($photo, $this->driver->inspect($photo['object_key']));
            }
            $this->repo->update('photos', $photo['id'], ['mime' => $mime, 'width' => $image[0] ?? 0, 'height' => $image[1] ?? 0, 'preview' => $preview, 'thumb' => $preview ? $this->driver->thumbnail($photo['object_key']) : $this->driver->url($photo['object_key'])]);
            $this->repo->finish($taskId, (int) $claimed['attempt'], 'uploaded');
        } catch (\Throwable $e) {
            // A lost response does not mean the write failed. Verify the same key
            // before exposing an uncertain result; never repeat the upload here.
            if ($writing) {
                for ($n = 0; $n < 2; $n++) {
                    try {
                        $this->verify($photo, $this->driver->inspect($photo['object_key']));
                        $this->repo->finish($taskId, (int) $claimed['attempt'], 'uploaded');
                        return;
                    } catch (\Throwable $checkError) {
                        if ($n === 0) { usleep(250000); }
                    }
                }
            }
            $message = self::error($e);
            try { $this->repo->finish($taskId, (int) $claimed['attempt'], $writing ? 'unknown' : 'failed', $message); } catch (\Throwable $ignored) {}
            throw new \RuntimeException($message . ($writing ? '。云端结果待核查，请使用“核查云端”后再重试' : ''));
        }
    }

    private function verify(array $photo, ?array $object): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $photo['hash']) || !$object || (int) ($object['bytes'] ?? -1) !== (int) $photo['bytes'] || ($object['hash'] ?? '') !== $photo['hash']) { throw new \RuntimeException('云端对象校验未通过，未公开照片'); }
    }

    public function recover(string $taskId): void
    {
        $task = $this->repo->get('tasks', $taskId); $photo = $this->target($task);
        if ($task['state'] === 'uploaded' || ($task['state'] === 'uploading' && (int) $task['lease'] >= time())) { return; }
        try { $claimed = $this->repo->claim($taskId, (int) $this->config['timeout'], 'verifying'); }
        catch (\RuntimeException $e) {
            $status = $this->repo->uploadStatus($taskId);
            if ($status['state'] === 'uploaded' || $status['state'] === 'uploading') { return; }
            throw $e;
        }
        @set_time_limit((int) $this->config['timeout'] + 30);
        try {
            $object = $this->driver->inspect($photo['object_key']);
            if ($object === null) { $this->repo->finish($taskId, (int) $claimed['attempt'], 'failed', '已确认云端无此对象，请重新选择原照片重试'); return; }
            $this->verify($photo, $object);
            // Metadata can be recovered without the local file after a lost upload response.
            $this->repo->update('photos', $photo['id'], ['mime' => $object['mime'] ?? $photo['mime'], 'preview' => (int) in_array($object['mime'] ?? '', ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp'], true)]);
            $this->repo->finish($taskId, (int) $claimed['attempt'], 'uploaded');
        } catch (\Throwable $e) {
            $message = self::error($e);
            $this->repo->finish($taskId, (int) $claimed['attempt'], 'unknown', $message);
            throw new \RuntimeException($message);
        }
    }

    public static function error(\Throwable $e): string
    {
        // SDK messages can include signed URLs and credentials; expose codes only.
        if ($e instanceof \InvalidArgumentException || ($e instanceof \RuntimeException && get_class($e) === \RuntimeException::class)) { return mb_substr($e->getMessage(), 0, 500); }
        $code = method_exists($e, 'getExceptionCode') ? $e->getExceptionCode() : (method_exists($e, 'getErrorCode') ? $e->getErrorCode() : $e->getCode());
        return '云请求未完成（' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $code) . '）。请检查云权限、公共读设置、网络和服务端日志';
    }
}
