<?php
namespace TypechoPlugin\SoraglassGallery\Storage;

use AlibabaCloud\Oss\V2 as SDK;

final class Oss implements Driver
{
    private SDK\Client $client;
    public function __construct(private array $config)
    {
        Registry::sdk();
        $cfg = SDK\Config::loadDefault();
        $cfg->setRegion($config['region']);
        $cfg->setCredentialsProvider(new SDK\Credentials\StaticCredentialsProvider($config['keyId'], $config['keySecret']));
        $cfg->setConnectTimeout(15); $cfg->setReadwriteTimeout((float) $config['timeout']);
        if ($config['endpoint'] !== '') { $cfg->setEndpoint($config['endpoint']); }
        $this->client = new SDK\Client($cfg);
    }
    public function upload(string $file, string $key, string $mime, string $hash): void
    {
        $r = new SDK\Models\PutObjectRequest(bucket: $this->config['bucket'], key: $key, acl: 'public-read', metadata: ['sg-sha256' => $hash], contentType: $mime, contentDisposition: in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp'], true) ? 'inline' : 'attachment');
        $this->client->newUploader()->uploadFile($r, $file, ['parallel_num' => 1]);
    }
    public function inspect(string $key): ?array
    {
        try {
            $r = $this->client->headObject(new SDK\Models\HeadObjectRequest(bucket: $this->config['bucket'], key: $key));
            return ['bytes' => $r->contentLength, 'hash' => $r->metadata['sg-sha256'] ?? '', 'mime' => $r->contentType ?? ''];
        } catch (\Throwable $e) {
            for ($cause = $e; $cause; $cause = $cause->getPrevious()) {
                if ($cause instanceof SDK\Exception\ServiceException && ($cause->getErrorCode() === 'NoSuchKey' || ($cause->getStatusCode() === 404 && !$cause->getErrorCode()))) { return null; }
            }
            throw $e;
        }
    }
    public function delete(string $key): void { $this->client->deleteObject(new SDK\Models\DeleteObjectRequest(bucket: $this->config['bucket'], key: $key)); }
    public function url(string $key): string { return Registry::publicUrl($this->config, $key); }
    public function thumbnail(string $key): string { return $this->url($key); }
}
