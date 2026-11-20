<?php
namespace TypechoPlugin\SoraglassGallery;

use TypechoPlugin\SoraglassGallery\Storage\Registry;

/**
 * Delete a photo: always the record, optionally the cloud object.
 * The object target comes from the frozen snapshot, the credentials from the live
 * config — a photo uploaded to another Bucket can never be touched with today's keys.
 */
final class Removal
{
    /** @param callable(array):Driver|null $factory Test seam; production builds the adapter from the snapshot. */
    public function __construct(private Repository $repo, private array $settings, private $factory = null) {}

    public function delete(string $id, bool $purge): array
    {
        $photo = $this->repo->get('photos', $id);
        if ($photo['state'] === 'removed') { return ['id' => $id, 'state' => 'removed', 'remote' => false, 'warning' => '照片已不在相册中']; }
        $snapshot = is_array($photo['storage']) ? $photo['storage'] : (json_decode((string) $photo['storage'], true) ?: []);
        $key = (string) ($photo['object_key'] ?? '');
        $remote = false;
        $warning = '';
        if (!$purge) {
            $warning = '已从相册移除，云端文件按你的选择保留。';
        } elseif ($key === '') {
            $warning = '这条记录没有云端文件，已直接移除。';
        } elseif (($snapshot['profile'] ?? Settings::profile($snapshot)) !== Settings::profile($this->settings)) {
            // Nothing else is safe here: the old Bucket may need different keys.
            $warning = '这张照片属于以前的云配置，当前凭据无法删除；已移除相册记录，云端原图请在原 Bucket 手动清理。';
        } else {
            $target = ['provider' => $snapshot['provider'] ?? '', 'bucket' => $snapshot['bucket'] ?? '', 'region' => $snapshot['region'] ?? '', 'endpoint' => $snapshot['endpoint'] ?? '', 'domain' => $snapshot['domain'] ?? '', 'prefix' => $snapshot['prefix'] ?? ''];
            if ($this->factory) { $driver = ($this->factory)($target); }
            else {
                $config = Settings::cloud();
                $driver = Registry::create($target + ['keyId' => $config['keyId'], 'keySecret' => $config['keySecret'], 'timeout' => $config['timeout']]);
            }
            // Deletion failure aborts before the record changes, so a retry stays possible.
            $driver->delete($key);
            $remote = true;
        }
        $this->repo->removePhoto($id);
        return ['id' => $id, 'state' => 'removed', 'remote' => $remote, 'warning' => $warning];
    }
}
