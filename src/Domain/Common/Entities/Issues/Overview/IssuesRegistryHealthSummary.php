<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Overview;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\Issues\IssuesRegistryHealth;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Presentation\Base\OpenApi\Attributes\Required;

/**
 * Whether error collection is alive for the selected environment, so an empty list is never mistaken for health.
 */
class IssuesRegistryHealthSummary extends ValueObject
{
    /** @var DateTime|null Last successful run of the registry job */
    #[Required]
    public ?DateTime $lastSuccessfulRunAt = null;

    /** @var DateTime|null Newest event the job has seen */
    #[Required]
    public ?DateTime $newestEventAt = null;

    /** @var bool The registry job has not run successfully for too long */
    #[Required]
    public bool $jobStalled = false;

    /** @var bool No event at all has arrived for too long (the collector may be down) */
    #[Required]
    public bool $collectorSilent = false;

    /** @var string|null The last error of the registry job */
    #[Required]
    public ?string $lastError = null;

    /**
     * The health of one environment, or the overall health when the environment has none of its own ("all").
     */
    public static function fromIssuesRegistryHealth(IssuesRegistryHealth $issuesRegistryHealth, ?string $environment): static
    {
        $selectedHealth = $issuesRegistryHealth;
        foreach ($issuesRegistryHealth->environments?->getElements() ?? [] as $environmentHealth) {
            if ($environmentHealth->environment === $environment) {
                $selectedHealth = $environmentHealth;
                break;
            }
        }
        $issuesRegistryHealthSummary = new static();
        $issuesRegistryHealthSummary->lastSuccessfulRunAt = $selectedHealth->lastSuccessfulRunAt;
        $issuesRegistryHealthSummary->newestEventAt = $selectedHealth->newestEventSeenAt;
        $issuesRegistryHealthSummary->jobStalled = $selectedHealth->jobStalled;
        $issuesRegistryHealthSummary->collectorSilent = $selectedHealth->collectorSilent;
        $issuesRegistryHealthSummary->lastError = $selectedHealth->lastError;
        return $issuesRegistryHealthSummary;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
