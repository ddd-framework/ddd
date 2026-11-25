<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Entities\Crons;

use DDD\Infrastructure\Validation\ValidationErrors;
use PHPUnit\Framework\TestCase;

/**
 * The command of a Cron must be registered in this console — except when an existing Cron is switched off and keeps
 * its stored command: a release that removed the command must not keep the row from being switched off.
 */
class CronCommandRegistrationValidationTest extends TestCase
{
    /** @return string[] The messages of the violations on `command` */
    protected function findCommandViolationMessages(CommandRegistrationProbeCron $cron): array
    {
        $validationErrors = null;
        $validation = $cron->validate($validationErrors);
        if ($validation === true) {
            return [];
        }
        $this->assertInstanceOf(ValidationErrors::class, $validation);
        $messages = [];
        foreach ($validation->getElements() as $validationError) {
            foreach ($validationError->getElements() as $validationResult) {
                if ($validationResult->propertyName === 'command') {
                    $messages[] = (string)$validationResult->errorMessage;
                }
            }
        }
        return $messages;
    }

    public function testSwitchingOffACronWhoseCommandWasRemovedIsValid(): void
    {
        $cron = CommandRegistrationProbeCron::createStored('app:removed:command --batch=10', true, false);
        $cron->deactivate();

        $this->assertFalse($cron->isCommandRegistrationCheckRequired());
        $this->assertSame([], $this->findCommandViolationMessages($cron));
    }

    public function testAnInactiveCronWithARemovedCommandStaysSavable(): void
    {
        $cron = CommandRegistrationProbeCron::createStored('app:removed:command', false, false);
        $cron->description = 'A new description for the paused cron';

        $this->assertSame([], $this->findCommandViolationMessages($cron));
    }

    public function testSwitchingOnACronWhoseCommandWasRemovedIsRefused(): void
    {
        $cron = CommandRegistrationProbeCron::createStored('app:removed:command', false, false);
        $cron->activate();

        $messages = $this->findCommandViolationMessages($cron);
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('"app:removed:command" is not registered in this console', $messages[0]);
    }

    public function testChangingTheCommandOfAnInactiveCronToAnUnknownOneIsRefused(): void
    {
        $cron = CommandRegistrationProbeCron::createStored('app:removed:command', false, false);
        $cron->command = 'app:another:unknown';

        $this->assertTrue($cron->isCommandRegistrationCheckRequired());
        $this->assertCount(1, $this->findCommandViolationMessages($cron));
    }

    public function testANewInactiveCronWithAnUnknownCommandIsRefused(): void
    {
        $cron = CommandRegistrationProbeCron::createStored('app:removed:command', false, false);
        $cron->id = null;
        $cron->storedCommandInDatabase = null;

        $this->assertCount(1, $this->findCommandViolationMessages($cron));
    }

    public function testAnInactiveCronWhoseRowCannotBeReadIsChecked(): void
    {
        $cron = CommandRegistrationProbeCron::createStored('app:removed:command', false, false);
        $cron->storedCommandInDatabase = null;

        $this->assertCount(1, $this->findCommandViolationMessages($cron));
    }

    public function testARegisteredCommandIsValidInEveryState(): void
    {
        $activeCron = CommandRegistrationProbeCron::createStored('app:crons:list', true, true);
        $this->assertSame([], $this->findCommandViolationMessages($activeCron));

        $newCron = CommandRegistrationProbeCron::createStored('app:crons:list', true, true);
        $newCron->id = null;
        $this->assertSame([], $this->findCommandViolationMessages($newCron));
    }
}
