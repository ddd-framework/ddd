<?php

declare(strict_types=1);

namespace DDD\Infrastructure\ObjectStorage;

/**
 * Chunk helpers of the streaming object stores ({@see StreamingObjectStorage}).
 */
class ObjectStorageChunks
{
    /**
     * Regroups chunks of any size into chunks of `$chunkBytes` (the last may be shorter); holds at most one chunk.
     *
     * @param iterable<string> $chunks
     * @param int $chunkBytes
     * @return iterable<string>
     */
    public static function regroupChunks(iterable $chunks, int $chunkBytes): iterable
    {
        $chunkBytes = max(1, $chunkBytes);
        $pendingBytes = '';
        foreach ($chunks as $chunk) {
            $pendingBytes .= $chunk;
            while (strlen($pendingBytes) >= $chunkBytes) {
                yield substr($pendingBytes, 0, $chunkBytes);
                $pendingBytes = substr($pendingBytes, $chunkBytes);
            }
        }
        if ($pendingBytes !== '') {
            yield $pendingBytes;
        }
    }

    /**
     * Reads a file in chunks of `$chunkBytes`, from `$firstByte` for `$length` bytes (null = to the end).
     *
     * @param string $filePath
     * @param int $firstByte
     * @param int|null $length
     * @param int $chunkBytes
     * @return iterable<string>
     */
    public static function readFileChunks(string $filePath, int $firstByte, ?int $length, int $chunkBytes): iterable
    {
        $fileHandle = @fopen($filePath, 'rb');
        if ($fileHandle === false) {
            return;
        }
        try {
            if ($firstByte > 0 && fseek($fileHandle, $firstByte) !== 0) {
                return;
            }
            $remainingBytes = $length;
            while (!feof($fileHandle) && ($remainingBytes === null || $remainingBytes > 0)) {
                $readBytes = $remainingBytes === null ? max(1, $chunkBytes) : min(max(1, $chunkBytes), $remainingBytes);
                $chunk = fread($fileHandle, $readBytes);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                if ($remainingBytes !== null) {
                    $remainingBytes -= strlen($chunk);
                }
                yield $chunk;
            }
        } finally {
            fclose($fileHandle);
        }
    }
}
