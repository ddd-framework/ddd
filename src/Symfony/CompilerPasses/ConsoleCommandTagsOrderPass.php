<?php

declare(strict_types=1);

namespace DDD\Symfony\CompilerPasses;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Puts the named `console.command` tag of a command first, so a command with aliases is listed once.
 *
 * The console's command pass reads the name and aliases from the FIRST tag and registers the `command` attribute of
 * every later tag as one more alias, verbatim. A module command gets the plain tag of its `Command` parent before the
 * autoconfiguration adds the tag of its #[AsCommand] attribute (`command: 'name|alias'`); in that order the joined
 * string `name|alias` became one more command name and the command showed up twice in `list`. An application command
 * gets the attribute's tag first, so nothing changes for it.
 *
 * Per command service: identical tags are kept once, a later tag naming the same command as an earlier one is dropped,
 * and the first tag with a `command` attribute moves to the front. Runs after the autoconfiguration (priority 100 of
 * this phase) and before the console's command loader is built (BEFORE_REMOVING).
 */
class ConsoleCommandTagsOrderPass implements CompilerPassInterface
{
    public const string CONSOLE_COMMAND_TAG = 'console.command';

    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds(self::CONSOLE_COMMAND_TAG) as $serviceId => $tags) {
            $orderedTags = $this->orderCommandTags($tags);
            if ($orderedTags === array_values($tags)) {
                continue;
            }
            $definition = $container->getDefinition($serviceId);
            $definition->clearTag(self::CONSOLE_COMMAND_TAG);
            foreach ($orderedTags as $tagAttributes) {
                $definition->addTag(self::CONSOLE_COMMAND_TAG, $tagAttributes);
            }
        }
    }

    /**
     * The tags of one command service in the order the console's command pass needs: the first named tag first,
     * without exact duplicates and without later tags repeating a command name already registered.
     *
     * @param array<int, array<string, mixed>> $tags
     * @return array<int, array<string, mixed>>
     */
    public function orderCommandTags(array $tags): array
    {
        $uniqueTags = [];
        $registeredCommandNames = [];
        foreach ($tags as $tagAttributes) {
            if (in_array($tagAttributes, $uniqueTags, true)) {
                continue;
            }
            $commandName = $tagAttributes['command'] ?? null;
            if (is_string($commandName)) {
                if (isset($registeredCommandNames[$commandName])) {
                    continue;
                }
                $registeredCommandNames[$commandName] = true;
            }
            $uniqueTags[] = $tagAttributes;
        }
        foreach ($uniqueTags as $tagIndex => $tagAttributes) {
            if (!isset($tagAttributes['command'])) {
                continue;
            }
            if ($tagIndex > 0) {
                unset($uniqueTags[$tagIndex]);
                array_unshift($uniqueTags, $tagAttributes);
            }
            break;
        }
        return array_values($uniqueTags);
    }
}
