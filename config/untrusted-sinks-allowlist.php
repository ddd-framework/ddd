<?php

declare(strict_types=1);

/**
 * Raw sink calls of this package that are fine, each with its reason (skill ddd-untrusted-input-specialist).
 * Checked by tests/UntrustedSinkScanTest.php: a new raw call fails until it goes through a helper of
 * DDD\Infrastructure\Untrusted or is listed here; an entry without a matching call fails too.
 */
return [
    // the helpers themselves
    ['file' => 'src/Infrastructure/Untrusted/RasterImageDecoder.php', 'sink' => 'imagick-decode', 'reason' => 'the fixed-format decoder: magic-byte allow-list, coder set before the read, resource limits'],
    ['file' => 'src/Infrastructure/Untrusted/SafeXml.php', 'sink' => 'xml-parse', 'reason' => 'the safe loader: no network, no DTD, DOCTYPE refused, size cap'],
    ['file' => 'src/Infrastructure/Untrusted/IsolatedProcess.php', 'sink' => 'process', 'reason' => 'the isolated process: argument array, stripped environment, timeout'],
    ['file' => 'src/Infrastructure/Services/GuardedHttpFetchService.php', 'sink' => 'http-client', 'reason' => 'the guarded fetcher: public address guard per hop, pinned connect, manual redirects, caps'],
    ['file' => 'src/Infrastructure/Services/UntrustedInputRuntimeCheckService.php', 'sink' => 'imagick-decode', 'reason' => 'the runtime check: reads its own harmless probe bodies to prove the policy refuses them'],
    ['file' => 'src/Domain/Common/Entities/MediaItems/ExcelDocument.php', 'sink' => 'spreadsheet-cell', 'reason' => 'writeCellValue(): non-numeric strings typed as text; the other call writes a date number'],

    // our own processes and fixed-host clients
    ['file' => 'src/Domain/Common/Entities/Crons/Cron.php', 'sink' => 'process', 'reason' => 'our own console command as an argument array; admin endpoints accept only schedulable commands'],
    ['file' => 'src/Domain/Common/Entities/MediaItems/PDFDocument.php', 'sink' => 'dompdf', 'reason' => 'Dompdf with PHP, JavaScript and remote resources off, chroot to its cache directory'],
    ['file' => 'src/Domain/Common/Entities/MediaItems/PDFDocument.php', 'sink' => 'xml-parse', 'reason' => 'Dompdf::loadHtml() of the hardened instance above, not libxml'],
    ['file' => 'src/Domain/Common/Services/HttpClientService.php', 'sink' => 'http-client', 'reason' => 'client for our own configured APIs; a URL from outside goes to GuardedHttpFetchService'],
    ['file' => 'src/Domain/Common/Services/OauthHttpClientService.php', 'sink' => 'http-client', 'reason' => 'OAuth client for configured token endpoints'],
    ['file' => 'src/Domain/Common/Services/WorkerDefinitionsService.php', 'sink' => 'yaml-parse', 'reason' => 'the application\'s own workers.yaml, no object flags'],
    ['file' => 'src/Infrastructure/Logging/IssuesAlertWebhookSender.php', 'sink' => 'http-client', 'reason' => 'webhook URL from configuration (environment), not from a request'],
    ['file' => 'src/Infrastructure/Logging/Query/Elasticsearch/ElasticsearchIssuesQueryBackend.php', 'sink' => 'http-client', 'reason' => 'configured log backend host'],
    ['file' => 'src/Infrastructure/Logging/Query/LokiIssuesQueryBackend.php', 'sink' => 'http-client', 'reason' => 'configured log backend host'],
    ['file' => 'src/Infrastructure/Logging/Query/Sentry/SentryIssuesQueryBackend.php', 'sink' => 'http-client', 'reason' => 'configured Sentry host'],
    ['file' => 'src/Infrastructure/Logging/Sentry/SentryEnvelopeSender.php', 'sink' => 'http-client', 'reason' => 'configured Sentry DSN'],
    ['file' => 'src/Infrastructure/ObjectStorage/S3CompatibleObjectStorage.php', 'sink' => 'http-client', 'reason' => 'configured object storage endpoint'],
];
