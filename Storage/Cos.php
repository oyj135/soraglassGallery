<?php
namespace TypechoPlugin\SoraglassGallery\Storage;

final class Cos implements Driver
{
    private \Qcloud\Cos\Client $client;
    public function __construct(private array $config)
    {
        Registry::sdk();
        $options = ['region' => $config['region'], 'scheme' => 'https', 'credentials' => ['secretId' => $config['keyId'], 'secretKey' => $config['keySecret']], 'timeout' => (int) $config['timeout'], 'connect_timeout' => 15, 'retry' => 1];
        if ($config['endpoint'] !== '') { $options['domain'] = parse_url($config['endpoint'], PHP_URL_HOST); }
        $this->client = new \Qcloud\Cos\Client($options);
    }

    public function upload(string $file, string $key, string $mime, string $hash): void
    {
        $stream = fopen($file, 'rb');
        if (!$stream) { throw new \RuntimeException('无法读取上传临时文件'); }
        // The SDK writes multipart failure details to stdout. Keep signed request data out of JSON.
        ob_start();
        try {
            $this->client->upload($this->config['bucket'], $key, $stream, ['ACL' => 'public-read', 'ContentType' => $mime, 'ContentDisposition' => in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp'], true) ? 'inline' : 'attachment', 'Metadata' => ['sg-sha256' => $hash], 'Concurrency' => 1]);
        } finally {
            ob_end_clean();
            // The SDK's PSR stream owns the resource and can close it on return.
            if (is_resource($stream)) { fclose($stream); }
        }
    }

    public function inspect(string $key): ?array
    {
        try {
            $r = $this->client->headObject(['Bucket' => $this->config['bucket'], 'Key' => $key]);
            return ['bytes' => (int) $r['ContentLength'], 'hash' => $r['Metadata']['sg-sha256'] ?? '', 'mime' => $r['ContentType'] ?? ''];
        } catch (\Qcloud\Cos\Exception\ServiceResponseException $e) {
            // HeadObject uses an empty 404 response for a missing key, but not for auth failures.
            if ((string) $e->getExceptionCode() === 'NoSuchKey' || ((string) $e->getExceptionCode() === '404' && $e->getResponse()->getStatusCode() === 404)) { return null; }
            throw $e;
        }
    }
    public function delete(string $key): void { $this->client->deleteObject(['Bucket' => $this->config['bucket'], 'Key' => $key]); }
    public function url(string $key): string { return Registry::publicUrl($this->config, $key); }
    public function thumbnail(string $key): string { return $this->url($key); }
}
