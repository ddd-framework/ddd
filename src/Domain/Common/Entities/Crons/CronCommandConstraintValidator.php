<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Cron command validator: the command (its first word) must be registered in this console. A Cron decides whether a
 * write needs the check ({@see Cron::isCommandRegistrationCheckRequired()}): an existing Cron that is switched off and
 * keeps its stored command is never refused for it, so a row whose command a release removed can still be switched
 * off; a new Cron, an active Cron and a changed command are checked.
 */
class CronCommandConstraintValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof CronCommandConstraint) {
            throw new UnexpectedTypeException($constraint, CronCommandConstraint::class);
        }

        if (null === $value || '' === $value) {
            return;
        }
        $validatedObject = $this->context->getObject();
        if ($validatedObject instanceof Cron && $validatedObject->command === $value) {
            if ($validatedObject->hasRegisteredCommand() || !$validatedObject->isCommandRegistrationCheckRequired()) {
                return;
            }
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $value)
                ->addViolation();
            return;
        }
        $parts = explode(' ', trim($value), 2);
        $commandName = $parts[0];

        /** @var Application $application */
        $application = DDDService::instance()->createConsoleApplicationForCurrentKernel();
        try {
            $command = $application->find($commandName);
        } catch (CommandNotFoundException) {
            $command = null;
        }
        if (null === $command) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $value)
                ->addViolation();
        }
    }
}