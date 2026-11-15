<?php
namespace TypechoPlugin\SoraglassGallery\Storage;

/** A provider owns authentication, public-read uploads, verification and URL generation. */
interface Driver
{
    public function upload(string $file, string $key, string $mime, string $hash): void;
    /** null means a confirmed missing object; authorization/network errors must throw. */
    public function inspect(string $key): ?array;
    public function delete(string $key): void;
    public function url(string $key): string;
    public function thumbnail(string $key): string;
}
