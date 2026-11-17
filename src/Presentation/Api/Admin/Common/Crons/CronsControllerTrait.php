<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Crons;

use DDD\Domain\Common\Services\CronsManagementService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Presentation\Api\Admin\Common\Crons\Dtos\CronCreateRequestDto;
use DDD\Presentation\Api\Admin\Common\Crons\Dtos\CronDeleteResponseDto;
use DDD\Presentation\Api\Admin\Common\Crons\Dtos\CronDetailGetResponseDto;
use DDD\Presentation\Api\Admin\Common\Crons\Dtos\CronExecutionDetailGetResponseDto;
use DDD\Presentation\Api\Admin\Common\Crons\Dtos\CronExecutionGetRequestDto;
use DDD\Presentation\Api\Admin\Common\Crons\Dtos\CronExecutionsListGetRequestDto;
use DDD\Presentation\Api\Admin\Common\Crons\Dtos\CronExecutionsListGetResponseDto;
use DDD\Presentation\Api\Admin\Common\Crons\Dtos\CronGetRequestDto;
use DDD\Presentation\Api\Admin\Common\Crons\Dtos\CronGetResponseDto;
use DDD\Presentation\Api\Admin\Common\Crons\Dtos\CronSchedulableCommandsGetResponseDto;
use DDD\Presentation\Api\Admin\Common\Crons\Dtos\CronSchedulePreviewGetRequestDto;
use DDD\Presentation\Api\Admin\Common\Crons\Dtos\CronSchedulePreviewGetResponseDto;
use DDD\Presentation\Api\Admin\Common\Crons\Dtos\CronsListGetResponseDto;
use DDD\Presentation\Api\Admin\Common\Crons\Dtos\CronsTimelineGetRequestDto;
use DDD\Presentation\Api\Admin\Common\Crons\Dtos\CronsTimelineGetResponseDto;
use DDD\Presentation\Api\Admin\Common\Crons\Dtos\CronUpdateRequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\Router\Routes\Delete;
use DDD\Presentation\Base\Router\Routes\Get;
use DDD\Presentation\Base\Router\Routes\Patch;
use DDD\Presentation\Base\Router\Routes\Post;

/**
 * The Crons endpoints of an admin API: the Crons with their health, the run history and timeline, the schedulable
 * commands, a schedule preview, and the writes (create, update, delete, switch on or off, run now). An application
 * mounts them with one controller of its own, which sets the path prefix, the tag and the access:
 *
 *     #[Route('/common/crons')]
 *     #[Tag(group: 'Common', name: 'Crons', description: 'Scheduled console commands, their health and run history')]
 *     class AdminCronsController extends AdminController
 *     {
 *         use CronsControllerTrait;
 *     }
 *
 * A Cron runs its console command with the full rights of the console: restrict the path to the accounts that may
 * operate the system. Only commands with `#[CronSchedulable]` (or the framework's list) can be scheduled here; the
 * routes with a literal segment come before the `{cronId}` ones, and both ids accept digits only. The data comes from
 * {@see CronsManagementService}.
 */
trait CronsControllerTrait
{
    /**
     * Every Cron with its health verdict, its newest 30 runs and 24-hour stats, plus the runner's summary.
     */
    #[Get('/list')]
    #[Summary('Crons List')]
    public function listCrons(CronsManagementService $cronsManagementService): CronsListGetResponseDto
    {
        $cronsOverview = $cronsManagementService->getCronsOverview();
        $responseDto = new CronsListGetResponseDto();
        $responseDto->crons = $cronsOverview->crons;
        $responseDto->runner = $cronsOverview->runner;
        return $responseDto;
    }

    /**
     * One page of runs across all Crons or of one Cron, newest first.
     */
    #[Get('/executions/list')]
    #[Summary('Cron Executions List')]
    public function listCronExecutions(
        CronExecutionsListGetRequestDto $requestDto,
        CronsManagementService $cronsManagementService
    ): CronExecutionsListGetResponseDto {
        $cronExecutionsPage = $cronsManagementService->getCronExecutionsPage(
            $requestDto->cronId,
            $requestDto->result,
            $requestDto->limit,
            $requestDto->offset
        );
        $responseDto = new CronExecutionsListGetResponseDto();
        $responseDto->executions = $cronExecutionsPage->executions;
        $responseDto->totalCount = $cronExecutionsPage->totalCount;
        return $responseDto;
    }

    /**
     * One run with its full output.
     *
     * @throws NotFoundException
     */
    #[Get('/executions/{executionId}', requirements: ['executionId' => '\d+'])]
    #[Summary('Cron Execution Details')]
    public function getCronExecution(
        CronExecutionGetRequestDto $requestDto,
        CronsManagementService $cronsManagementService
    ): CronExecutionDetailGetResponseDto {
        $cronExecutionDetail = $cronsManagementService->getCronExecutionDetail($requestDto->executionId);
        $responseDto = new CronExecutionDetailGetResponseDto();
        $responseDto->execution = $cronExecutionDetail->execution;
        $responseDto->output = $cronExecutionDetail->output;
        return $responseDto;
    }

    /**
     * Runs per Cron per time bucket over the last 1 to 168 hours: the heatmap of the runs view.
     */
    #[Get('/timeline')]
    #[Summary('Crons Timeline')]
    public function getCronsTimeline(
        CronsTimelineGetRequestDto $requestDto,
        CronsManagementService $cronsManagementService
    ): CronsTimelineGetResponseDto {
        $cronsTimeline = $cronsManagementService->getCronsTimeline($requestDto->hours);
        $responseDto = new CronsTimelineGetResponseDto();
        $responseDto->from = $cronsTimeline->from;
        $responseDto->to = $cronsTimeline->to;
        $responseDto->bucketMinutes = $cronsTimeline->bucketMinutes;
        $responseDto->lanes = $cronsTimeline->lanes;
        return $responseDto;
    }

    /**
     * The console commands that may be scheduled from the admin, with the Crons that already run them.
     */
    #[Get('/commands')]
    #[Summary('Cron Commands List')]
    public function listCronSchedulableCommands(CronsManagementService $cronsManagementService): CronSchedulableCommandsGetResponseDto
    {
        $responseDto = new CronSchedulableCommandsGetResponseDto();
        $responseDto->commands = $cronsManagementService->getSchedulableCommands();
        return $responseDto;
    }

    /**
     * Whether a cron expression is valid and when it would run next. An invalid expression answers 200 with `isValid`
     * false and a sentence in `error`.
     */
    #[Get('/schedule-preview')]
    #[Summary('Cron Schedule Preview')]
    public function previewCronSchedule(
        CronSchedulePreviewGetRequestDto $requestDto,
        CronsManagementService $cronsManagementService
    ): CronSchedulePreviewGetResponseDto {
        $cronSchedulePreview = $cronsManagementService->getSchedulePreview($requestDto->schedule);
        $responseDto = new CronSchedulePreviewGetResponseDto();
        $responseDto->isValid = $cronSchedulePreview->isValid;
        $responseDto->error = $cronSchedulePreview->error;
        $responseDto->nextRuns = $cronSchedulePreview->nextRuns;
        return $responseDto;
    }

    /**
     * One Cron with its next 5 slots, 14-day stats and the duration history of its last 100 ended runs.
     *
     * @throws NotFoundException
     */
    #[Get('/{cronId}', requirements: ['cronId' => '\d+'])]
    #[Summary('Cron Details')]
    public function getCron(
        CronGetRequestDto $requestDto,
        CronsManagementService $cronsManagementService
    ): CronDetailGetResponseDto {
        $cronDetail = $cronsManagementService->getCronDetail($requestDto->cronId);
        $responseDto = new CronDetailGetResponseDto();
        $responseDto->cron = $cronDetail->cron;
        $responseDto->nextRuns = $cronDetail->nextRuns;
        $responseDto->stats14d = $cronDetail->stats14d;
        $responseDto->durationHistory = $cronDetail->durationHistory;
        $responseDto->serverNow = $cronDetail->serverNow;
        return $responseDto;
    }

    /**
     * Creates a Cron from a schedulable command; it is due at the next slot of its expression. A rule of the write
     * that fails is a 400 naming the field.
     *
     * @throws BadRequestException
     * @throws NotFoundException
     */
    #[Post('')]
    #[Summary('Cron Create')]
    public function createCron(
        CronCreateRequestDto $requestDto,
        CronsManagementService $cronsManagementService
    ): CronGetResponseDto {
        $cron = $cronsManagementService->createCron(
            $requestDto->name,
            $requestDto->description,
            $requestDto->schedule,
            $requestDto->commandName,
            $requestDto->arguments,
            $requestDto->isActive
        );
        return $this->buildCronGetResponseDto((int)$cron->id, $cronsManagementService);
    }

    /**
     * Changes the sent fields of a Cron; a field that is not sent stays. A new schedule re-anchors the next slot; a
     * schedule change while the Cron runs (or is about to) answers 409.
     *
     * @throws BadRequestException
     * @throws ConflictException
     * @throws NotFoundException
     */
    #[Patch('/{cronId}', requirements: ['cronId' => '\d+'])]
    #[Summary('Cron Update')]
    public function updateCron(
        CronUpdateRequestDto $requestDto,
        CronsManagementService $cronsManagementService
    ): CronGetResponseDto {
        $cron = $cronsManagementService->findCronOrThrowNotFound($requestDto->cronId);
        $cronsManagementService->updateCron(
            $cron,
            $requestDto->name,
            $requestDto->description,
            $requestDto->schedule,
            $requestDto->commandName,
            $requestDto->arguments,
            $requestDto->isActive
        );
        return $this->buildCronGetResponseDto($requestDto->cronId, $cronsManagementService);
    }

    /**
     * Deletes a Cron and its run history. A Cron that is running answers 409.
     *
     * @throws ConflictException
     * @throws NotFoundException
     */
    #[Delete('/{cronId}', requirements: ['cronId' => '\d+'])]
    #[Summary('Cron Delete')]
    public function deleteCron(
        CronGetRequestDto $requestDto,
        CronsManagementService $cronsManagementService
    ): CronDeleteResponseDto {
        $cron = $cronsManagementService->findCronOrThrowNotFound($requestDto->cronId);
        $cronsManagementService->deleteCron($cron);
        $responseDto = new CronDeleteResponseDto();
        $responseDto->deletedCronId = $requestDto->cronId;
        return $responseDto;
    }

    /**
     * Switches a Cron on and re-anchors its next slot (a Cron paused across its slot does not fire at once). Idempotent.
     *
     * @throws NotFoundException
     */
    #[Post('/{cronId}/activate', requirements: ['cronId' => '\d+'])]
    #[Summary('Cron Activate')]
    public function activateCron(
        CronGetRequestDto $requestDto,
        CronsManagementService $cronsManagementService
    ): CronGetResponseDto {
        $cron = $cronsManagementService->findCronOrThrowNotFound($requestDto->cronId);
        $cronsManagementService->activateCron($cron);
        return $this->buildCronGetResponseDto($requestDto->cronId, $cronsManagementService);
    }

    /**
     * Switches a Cron off. Idempotent.
     *
     * @throws NotFoundException
     */
    #[Post('/{cronId}/deactivate', requirements: ['cronId' => '\d+'])]
    #[Summary('Cron Deactivate')]
    public function deactivateCron(
        CronGetRequestDto $requestDto,
        CronsManagementService $cronsManagementService
    ): CronGetResponseDto {
        $cron = $cronsManagementService->findCronOrThrowNotFound($requestDto->cronId);
        $cronsManagementService->deactivateCron($cron);
        return $this->buildCronGetResponseDto($requestDto->cronId, $cronsManagementService);
    }

    /**
     * Queues a run: the Cron becomes due now, and the runner's next tick (within a minute) runs it like any due Cron —
     * execution row, result and issues log included. 409 while the Cron is switched off or running, 400 when its
     * command is not in this build.
     *
     * @throws BadRequestException
     * @throws ConflictException
     * @throws NotFoundException
     */
    #[Post('/{cronId}/run-now', requirements: ['cronId' => '\d+'])]
    #[Summary('Cron Run Now')]
    public function runCronNow(
        CronGetRequestDto $requestDto,
        CronsManagementService $cronsManagementService
    ): CronGetResponseDto {
        $cron = $cronsManagementService->findCronOrThrowNotFound($requestDto->cronId);
        $cronsManagementService->scheduleCronToRunNow($cron);
        return $this->buildCronGetResponseDto($requestDto->cronId, $cronsManagementService);
    }

    /**
     * @throws NotFoundException
     */
    protected function buildCronGetResponseDto(int $cronId, CronsManagementService $cronsManagementService): CronGetResponseDto
    {
        $responseDto = new CronGetResponseDto();
        $responseDto->cron = $cronsManagementService->getCronOverview($cronId);
        return $responseDto;
    }
}
