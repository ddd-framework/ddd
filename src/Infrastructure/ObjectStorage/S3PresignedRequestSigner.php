<?php

declare(strict_types=1);

namespace DDD\Infrastructure\ObjectStorage;

use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * Presigns S3 requests with signature V4 (query form) and signs the headers the client must send with it, e.g.
 * `If-None-Match: *`, `Content-Type` or `x-amz-checksum-sha256`. async-aws's presign drops exactly those headers
 * (conditional and content headers are never signed there), so a presigned PUT could replace an object; a URL from
 * this signer cannot: the store rejects the request when a signed header is missing or differs.
 */
class S3PresignedRequestSigner
{
    public const string ALGORITHM = 'AWS4-HMAC-SHA256';
    public const string SERVICE = 's3';
    public const string UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';
    /** S3 accepts presigned URLs for at most 7 days */
    public const int MAX_EXPIRES_IN_SECONDS = 604800;

    public function __construct(protected ObjectStorageSettings $objectStorageSettings)
    {
    }

    /**
     * A presigned request for a key inside the bucket (prefix already applied).
     *
     * @param string[] $queryParameters Further query parameters, signed (e.g. `partNumber`, `uploadId`, `response-content-type`)
     * @param string[] $headers Headers the client must send, name => value; signed
     * @throws InternalErrorException
     */
    public function createPresignedRequest(
        string $method,
        string $storageKey,
        int $expiresInSeconds,
        array $queryParameters = [],
        array $headers = [],
        ?int $nowTimestamp = null
    ): ObjectStoragePresignedRequest {
        if ($this->objectStorageSettings->accessKey === null || $this->objectStorageSettings->secretKey === null) {
            throw new InternalErrorException(sprintf('Object storage "%s": presigning needs an access key and a secret key', $this->objectStorageSettings->name));
        }
        $expiresInSeconds = min(max(1, $expiresInSeconds), self::MAX_EXPIRES_IN_SECONDS);
        $nowTimestamp ??= time();
        $amzDate = gmdate('Ymd\THis\Z', $nowTimestamp);
        $shortDate = gmdate('Ymd', $nowTimestamp);
        $region = $this->objectStorageSettings->region;
        $credentialScope = $shortDate . '/' . $region . '/' . self::SERVICE . '/aws4_request';

        [$scheme, $host, $canonicalUri] = $this->getSchemeHostAndPath($storageKey);

        $canonicalHeaders = ['host' => $host];
        foreach ($headers as $headerName => $headerValue) {
            $canonicalHeaders[strtolower((string)$headerName)] = trim(preg_replace('/\s+/', ' ', (string)$headerValue));
        }
        ksort($canonicalHeaders, SORT_STRING);
        $signedHeaders = implode(';', array_keys($canonicalHeaders));

        $query = $queryParameters;
        $query['X-Amz-Algorithm'] = self::ALGORITHM;
        $query['X-Amz-Credential'] = $this->objectStorageSettings->accessKey . '/' . $credentialScope;
        $query['X-Amz-Date'] = $amzDate;
        $query['X-Amz-Expires'] = (string)$expiresInSeconds;
        $query['X-Amz-SignedHeaders'] = $signedHeaders;
        $canonicalQuery = $this->buildCanonicalQuery($query);

        $canonicalHeaderLines = '';
        foreach ($canonicalHeaders as $headerName => $headerValue) {
            $canonicalHeaderLines .= $headerName . ':' . $headerValue . "\n";
        }
        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $canonicalUri,
            $canonicalQuery,
            $canonicalHeaderLines,
            $signedHeaders,
            self::UNSIGNED_PAYLOAD,
        ]);
        $stringToSign = implode("\n", [self::ALGORITHM, $amzDate, $credentialScope, hash('sha256', $canonicalRequest)]);
        $signingKey = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', self::SERVICE, hash_hmac('sha256', $region, hash_hmac('sha256', $shortDate, 'AWS4' . $this->objectStorageSettings->secretKey, true), true), true), true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $presignedRequest = new ObjectStoragePresignedRequest();
        $presignedRequest->method = strtoupper($method);
        $presignedRequest->url = $scheme . '://' . $host . $canonicalUri . '?' . $canonicalQuery . '&X-Amz-Signature=' . $signature;
        $presignedRequest->headers = $headers;
        $presignedRequest->expiresAt = DateTime::fromTimestamp($nowTimestamp + $expiresInSeconds);
        return $presignedRequest;
    }

    /**
     * Scheme, host (with a non-default port) and the URI-encoded path of the key, path style or virtual-hosted style.
     *
     * @return string[]
     */
    protected function getSchemeHostAndPath(string $storageKey): array
    {
        $bucket = (string)$this->objectStorageSettings->bucket;
        $encodedKey = implode('/', array_map('rawurlencode', explode('/', $storageKey)));
        $endpoint = $this->objectStorageSettings->endpoint ?? ('https://s3.' . $this->objectStorageSettings->region . '.amazonaws.com');
        $endpointParts = parse_url($endpoint);
        $scheme = $endpointParts['scheme'] ?? 'https';
        $host = $endpointParts['host'] ?? '';
        if (isset($endpointParts['port']) && !(($scheme === 'https' && $endpointParts['port'] === 443) || ($scheme === 'http' && $endpointParts['port'] === 80))) {
            $host .= ':' . $endpointParts['port'];
        }
        $basePath = rtrim($endpointParts['path'] ?? '', '/');
        if ($this->objectStorageSettings->pathStyle) {
            return [$scheme, $host, $basePath . '/' . rawurlencode($bucket) . '/' . $encodedKey];
        }
        return [$scheme, $bucket . '.' . $host, $basePath . '/' . $encodedKey];
    }

    /** @param string[] $query */
    protected function buildCanonicalQuery(array $query): string
    {
        $encodedPairs = [];
        foreach ($query as $name => $value) {
            $encodedPairs[rawurlencode((string)$name)] = rawurlencode((string)$value);
        }
        ksort($encodedPairs, SORT_STRING);
        $queryParts = [];
        foreach ($encodedPairs as $encodedName => $encodedValue) {
            $queryParts[] = $encodedName . '=' . $encodedValue;
        }
        return implode('&', $queryParts);
    }
}
