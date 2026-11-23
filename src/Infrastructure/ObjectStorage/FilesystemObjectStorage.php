<?php

declare(strict_types=1);

namespace DDD\Infrastructure\ObjectStorage;

use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * An object store in a local directory, for tests and development: a key is a file below the root (and the prefix).
 * Presigned requests are `file://` URLs a client of the same machine reads or writes directly; create-only conditions
 * are checked by the driver's own methods only (a client writing to a `file://` URL is not stopped).
 */
class FilesystemObjectStorage implements ObjectStorage
{
    /** Directory below the store's root (and prefix) that holds the parts of open multipart uploads */
    public const string MULTIPART_DIRECTORY = '.multipart';

    public function __construct(
        protected string $rootDirectory,
        protected string $keyPrefix = '',
    ) {
        $this->rootDirectory = rtrim($rootDirectory, '/');
    }

    public static function fromSettings(ObjectStorageSettings $objectStorageSettings): static
    {
        if ($objectStorageSettings->path === null) {
            throw new InternalErrorException(sprintf(
                'Object storage "%s": the filesystem driver needs %s',
                $objectStorageSettings->name,
                ObjectStorageSettings::getEnvName($objectStorageSettings->name, ObjectStorageSettings::SETTING_PATH)
            ));
        }
        return new static($objectStorageSettings->path, $objectStorageSettings->prefix);
    }

    public function putObject(string $key, string $content, ?string $contentType = null): void
    {
        $filePath = $this->getFilePath($key);
        $directory = dirname($filePath);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new InternalErrorException('Object storage: cannot create ' . $directory);
        }
        if (@file_put_contents($filePath, $content, LOCK_EX) === false) {
            throw new InternalErrorException('Object storage: cannot write ' . $filePath);
        }
    }

    public function getObjectContent(string $key): ?string
    {
        $filePath = $this->getFilePath($key);
        if (!is_file($filePath)) {
            return null;
        }
        $content = @file_get_contents($filePath);
        if ($content === false) {
            throw new InternalErrorException('Object storage: cannot read ' . $filePath);
        }
        return $content;
    }

    public function deleteObject(string $key): void
    {
        $filePath = $this->getFilePath($key);
        if (is_file($filePath) && !@unlink($filePath)) {
            throw new InternalErrorException('Object storage: cannot delete ' . $filePath);
        }
    }

    public function objectExists(string $key): bool
    {
        return is_file($this->getFilePath($key));
    }

    public function listObjects(string $keyPrefix = '', int $limit = 1000, ?string $startAfterKey = null): ObjectStorageObjects
    {
        $baseDirectory = $this->rootDirectory . ($this->keyPrefix !== '' ? '/' . rtrim($this->keyPrefix, '/') : '');
        $keys = [];
        if (is_dir($baseDirectory)) {
            $fileIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDirectory, FilesystemIterator::SKIP_DOTS));
            foreach ($fileIterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }
                $key = substr($fileInfo->getPathname(), strlen($baseDirectory) + 1);
                if (str_starts_with($key, self::MULTIPART_DIRECTORY . '/')) {
                    continue;
                }
                if ($startAfterKey !== null && $startAfterKey !== '' && strcmp($key, $startAfterKey) <= 0) {
                    continue;
                }
                if (str_starts_with($key, $keyPrefix)) {
                    $keys[] = $key;
                }
            }
        }
        sort($keys, SORT_STRING);
        $objectStorageObjects = new ObjectStorageObjects();
        foreach (array_slice($keys, 0, max(0, $limit)) as $key) {
            $objectStorageObject = new ObjectStorageObject();
            $objectStorageObject->key = $key;
            $filePath = $this->getFilePath($key);
            $objectStorageObject->size = (int)filesize($filePath);
            $objectStorageObject->lastModified = DateTime::fromTimestamp((int)filemtime($filePath));
            $objectStorageObjects->add($objectStorageObject);
        }
        return $objectStorageObjects;
    }

    public function createTemporaryUrl(
        string $key,
        int $expiresInSeconds = 3600,
        ?string $responseContentType = null,
        ?string $responseContentDisposition = null
    ): string {
        return 'file://' . $this->getFilePath($key);
    }

    public function putObjectIfAbsent(string $key, string $content, ?string $contentType = null): bool
    {
        $filePath = $this->getFilePath($key);
        $this->ensureDirectoryOfFile($filePath);
        $fileHandle = @fopen($filePath, 'xb');
        if ($fileHandle === false) {
            if (is_file($filePath)) {
                return false;
            }
            throw new InternalErrorException('Object storage: cannot write ' . $filePath);
        }
        fwrite($fileHandle, $content);
        fclose($fileHandle);
        return true;
    }

    public function putObjectFromFile(string $key, string $filePath, ?string $contentType = null): void
    {
        $targetFilePath = $this->getFilePath($key);
        $this->ensureDirectoryOfFile($targetFilePath);
        if (!@copy($filePath, $targetFilePath)) {
            throw new InternalErrorException('Object storage: cannot write ' . $targetFilePath);
        }
    }

    public function saveObjectToFile(string $key, string $filePath): bool
    {
        $sourceFilePath = $this->getFilePath($key);
        if (!is_file($sourceFilePath)) {
            return false;
        }
        if (!@copy($sourceFilePath, $filePath)) {
            throw new InternalErrorException('Object storage: cannot write ' . $filePath);
        }
        return true;
    }

    public function getObjectContentRange(string $key, int $offset, int $length): ?string
    {
        $filePath = $this->getFilePath($key);
        if (!is_file($filePath)) {
            return null;
        }
        if ($length <= 0) {
            return '';
        }
        $content = @file_get_contents($filePath, false, null, max(0, $offset), $length);
        return $content === false ? '' : $content;
    }

    public function headObject(string $key): ?ObjectStorageObject
    {
        $filePath = $this->getFilePath($key);
        if (!is_file($filePath)) {
            return null;
        }
        $objectStorageObject = new ObjectStorageObject();
        $objectStorageObject->key = ltrim($key, '/');
        $objectStorageObject->size = (int)filesize($filePath);
        $objectStorageObject->lastModified = DateTime::fromTimestamp((int)filemtime($filePath));
        $objectStorageObject->etag = md5_file($filePath) ?: null;
        $objectStorageObject->checksumSha256 = base64_encode((string)hash_file('sha256', $filePath, true));
        return $objectStorageObject;
    }

    public function copyObject(string $sourceKey, string $targetKey, bool $createOnly = false): bool
    {
        $sourceFilePath = $this->getFilePath($sourceKey);
        if (!is_file($sourceFilePath)) {
            throw new InternalErrorException(sprintf('Object storage: copy of "%s" failed: no such object', $sourceKey));
        }
        $targetFilePath = $this->getFilePath($targetKey);
        if ($createOnly && is_file($targetFilePath)) {
            return false;
        }
        $this->ensureDirectoryOfFile($targetFilePath);
        if (!@copy($sourceFilePath, $targetFilePath)) {
            throw new InternalErrorException('Object storage: cannot write ' . $targetFilePath);
        }
        return true;
    }

    public function deleteObjects(array $keys): void
    {
        foreach ($keys as $key) {
            $this->deleteObject((string)$key);
        }
    }

    public function createTemporaryUploadRequest(
        string $key,
        int $expiresInSeconds = 3600,
        ?string $contentType = null,
        bool $createOnly = true,
        ?string $checksumSha256 = null,
        ?string $contentMd5 = null
    ): ObjectStoragePresignedRequest {
        $filePath = $this->getFilePath($key);
        $this->ensureDirectoryOfFile($filePath);
        return $this->createFilePresignedRequest($filePath, $expiresInSeconds, $createOnly ? ['If-None-Match' => '*'] : []);
    }

    public function createMultipartUpload(string $key, ?string $contentType = null): string
    {
        $this->getFilePath($key);
        $uploadId = bin2hex(random_bytes(16));
        $uploadDirectory = $this->getMultipartUploadDirectory($uploadId);
        if (!@mkdir($uploadDirectory, 0775, true) && !is_dir($uploadDirectory)) {
            throw new InternalErrorException('Object storage: cannot create ' . $uploadDirectory);
        }
        return $uploadId;
    }

    public function createTemporaryUploadPartRequest(
        string $key,
        string $uploadId,
        int $partNumber,
        int $expiresInSeconds = 3600,
        ?string $contentMd5 = null
    ): ObjectStoragePresignedRequest {
        return $this->createFilePresignedRequest($this->getMultipartUploadDirectory($uploadId) . '/' . max(1, $partNumber), $expiresInSeconds);
    }

    public function listUploadedParts(string $key, string $uploadId): ObjectStorageUploadedParts
    {
        $uploadedParts = new ObjectStorageUploadedParts();
        $uploadDirectory = $this->getMultipartUploadDirectory($uploadId);
        if (!is_dir($uploadDirectory)) {
            return $uploadedParts;
        }
        $partNumbers = [];
        foreach ((array)scandir($uploadDirectory) as $fileName) {
            if (is_string($fileName) && ctype_digit($fileName)) {
                $partNumbers[] = (int)$fileName;
            }
        }
        sort($partNumbers);
        foreach ($partNumbers as $partNumber) {
            $partFilePath = $uploadDirectory . '/' . $partNumber;
            $uploadedPart = new ObjectStorageUploadedPart();
            $uploadedPart->partNumber = $partNumber;
            $uploadedPart->etag = (string)md5_file($partFilePath);
            $uploadedPart->size = (int)filesize($partFilePath);
            $uploadedParts->add($uploadedPart);
        }
        return $uploadedParts;
    }

    public function completeMultipartUpload(
        string $key,
        string $uploadId,
        ?ObjectStorageUploadedParts $uploadedParts = null,
        bool $createOnly = true
    ): bool {
        $filePath = $this->getFilePath($key);
        if ($createOnly && is_file($filePath)) {
            return false;
        }
        $uploadedParts ??= $this->listUploadedParts($key, $uploadId);
        $uploadDirectory = $this->getMultipartUploadDirectory($uploadId);
        $this->ensureDirectoryOfFile($filePath);
        $temporaryFilePath = $filePath . '.' . $uploadId . '.part';
        $targetHandle = @fopen($temporaryFilePath, 'wb');
        if ($targetHandle === false) {
            throw new InternalErrorException('Object storage: cannot write ' . $temporaryFilePath);
        }
        foreach ($uploadedParts->getElements() as $uploadedPart) {
            $partHandle = @fopen($uploadDirectory . '/' . $uploadedPart->partNumber, 'rb');
            if ($partHandle === false) {
                fclose($targetHandle);
                @unlink($temporaryFilePath);
                throw new InternalErrorException(sprintf('Object storage: part %d of upload %s is missing', $uploadedPart->partNumber, $uploadId));
            }
            stream_copy_to_stream($partHandle, $targetHandle);
            fclose($partHandle);
        }
        fclose($targetHandle);
        if (!@rename($temporaryFilePath, $filePath)) {
            @unlink($temporaryFilePath);
            throw new InternalErrorException('Object storage: cannot write ' . $filePath);
        }
        $this->abortMultipartUpload($key, $uploadId);
        return true;
    }

    public function abortMultipartUpload(string $key, string $uploadId): void
    {
        $uploadDirectory = $this->getMultipartUploadDirectory($uploadId);
        if (!is_dir($uploadDirectory)) {
            return;
        }
        foreach ((array)scandir($uploadDirectory) as $fileName) {
            if (is_string($fileName) && $fileName !== '.' && $fileName !== '..') {
                @unlink($uploadDirectory . '/' . $fileName);
            }
        }
        @rmdir($uploadDirectory);
    }

    public function supportsConditionalWrites(): bool
    {
        return true;
    }

    /** @param string[] $headers */
    protected function createFilePresignedRequest(string $filePath, int $expiresInSeconds, array $headers = []): ObjectStoragePresignedRequest
    {
        $presignedRequest = new ObjectStoragePresignedRequest();
        $presignedRequest->method = ObjectStoragePresignedRequest::METHOD_PUT;
        $presignedRequest->url = 'file://' . $filePath;
        $presignedRequest->headers = $headers;
        $presignedRequest->expiresAt = DateTime::fromTimestamp(time() + max(1, $expiresInSeconds));
        return $presignedRequest;
    }

    protected function getMultipartUploadDirectory(string $uploadId): string
    {
        if (!ctype_xdigit($uploadId)) {
            throw new InternalErrorException(sprintf('Object storage: invalid upload id "%s"', $uploadId));
        }
        return $this->rootDirectory . '/' . $this->keyPrefix . self::MULTIPART_DIRECTORY . '/' . $uploadId;
    }

    protected function ensureDirectoryOfFile(string $filePath): void
    {
        $directory = dirname($filePath);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new InternalErrorException('Object storage: cannot create ' . $directory);
        }
    }

    /**
     * The file of a key; a key leaving the root (`..`, an absolute path) is refused.
     *
     * @throws InternalErrorException
     */
    protected function getFilePath(string $key): string
    {
        $key = ltrim($key, '/');
        if ($key === '' || in_array('..', explode('/', $key), true) || str_contains($key, "\0")) {
            throw new InternalErrorException(sprintf('Object storage: invalid key "%s"', $key));
        }
        return $this->rootDirectory . '/' . $this->keyPrefix . $key;
    }
}
