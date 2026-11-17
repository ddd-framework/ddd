<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Issues\Overview;

use DDD\Domain\Common\Entities\Issues\Overview\IssueEventDetail;
use PHPUnit\Framework\TestCase;

class IssueEventDetailTest extends TestCase
{
    public function testABackendEventMapsFramesCausesRouteParametersAndTheWorker(): void
    {
        $issueEventDetail = IssueEventDetail::fromEventPayload([
            '@timestamp' => '2026-10-28T10:00:00.123Z',
            'event' => ['id' => 'event-1'],
            'log.level' => 'critical',
            'message' => 'PDOException: gone away',
            'error.type' => 'PDOException',
            'route_name' => 'orders_create',
            'http.response.status_code' => '500',
            'route_parameters' => ['orderId' => 5, 'draft' => true, 'nested' => ['x']],
            'error.stack_trace' => str_repeat('x', 50),
            'error.frames' => [
                ['file' => 'src/Orders.php', 'line' => 12, 'function' => 'create', 'in_app' => true, 'code_context' => ['start_line' => 10, 'lines' => ['a', 'b']]],
            ],
            'error.causes' => [['type' => 'RuntimeException', 'message' => 'inner', 'file' => 'src/Db.php:3', 'frames' => [['file' => 'src/Db.php', 'line' => 3]]]],
            'messenger' => ['message_class' => 'App\SendEmailMessage', 'retry_count' => 2, 'origin' => ['command' => 'app:orders:import']],
            'will_retry' => false,
            'transports' => 'send_email',
        ], 20);

        $this->assertSame('event-1', $issueEventDetail->eventId);
        $this->assertSame('critical', $issueEventDetail->level);
        $this->assertSame(500, $issueEventDetail->httpStatus);
        $this->assertSame(20, strlen((string)$issueEventDetail->stackTrace));
        $this->assertSame(['orderId', 'draft'], array_map(fn($keyValue) => $keyValue->key, array_values($issueEventDetail->routeParameters->getElements())));
        $this->assertSame('true', $issueEventDetail->routeParameters->getElements()[array_key_last($issueEventDetail->routeParameters->getElements())]->value);
        $this->assertSame(['a', 'b'], $issueEventDetail->backendFrames->first()->codeContextLines);
        $this->assertSame('src/Db.php', $issueEventDetail->causes->first()->frames->first()->file);
        $this->assertSame(3, $issueEventDetail->worker->attempt);
        $this->assertTrue($issueEventDetail->worker->final);
        $this->assertSame(['send_email'], $issueEventDetail->worker->transports);
        $this->assertSame('app:orders:import', $issueEventDetail->worker->origin?->command);
        $this->assertNull($issueEventDetail->client);
        $this->assertSame(0, $issueEventDetail->frames->count());
    }

    public function testAClientEventMapsItsContextBreadcrumbsAndResolvedFrames(): void
    {
        $issueEventDetail = IssueEventDetail::fromEventPayload([
            'section' => 'WEB',
            'client' => [
                'runtime' => 'browser',
                'viewport' => '1280x720',
                'tags' => ['commit' => 'abc123'],
                'breadcrumbs' => [['category' => 'navigation', 'from' => '/', 'to' => '/orders']],
                'frames' => [['file' => 'https://x.test/chunk.js', 'line' => 1, 'column' => 99]],
            ],
            'resolvedFrames' => [['file' => 'https://x.test/chunk.js', 'line' => 1, 'column' => 99, 'resolved' => true, 'originalFile' => 'src/page.tsx', 'originalLine' => 7]],
            'resolvedStackTrace' => 'at page (src/page.tsx:7)',
        ], 8192);

        $this->assertSame('browser', $issueEventDetail->client?->runtime);
        $this->assertSame(1280, $issueEventDetail->client?->viewportWidth);
        $this->assertSame('abc123', $issueEventDetail->commit);
        $this->assertSame('/orders', $issueEventDetail->breadcrumbs->first()->to);
        $this->assertSame('src/page.tsx', $issueEventDetail->frames->first()->resolvedFile);
        $this->assertSame('at page (src/page.tsx:7)', $issueEventDetail->resolvedStackTrace);
        $this->assertNull($issueEventDetail->worker);
    }
}
