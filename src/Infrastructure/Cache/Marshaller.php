<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Cache;

use DDD\Infrastructure\Exceptions\Exception;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;

/**
 * Custom Marshaller implementation used for serialization/unserialization of PHP values
 *
 * Contains method for session data unserialization
 */
class Marshaller implements MarshallerInterface
{
    /**
     * @var string[] Namespace prefixes whose classes a cached value may contain. A value naming any other class is a
     * cache miss: whoever can write to the cache server cannot make a worker build objects of arbitrary classes.
     * An application that caches objects of other namespaces extends this list in a subclass.
     */
    public const array ALLOWED_CLASS_NAMESPACE_PREFIXES = [
        'DDD\\',
        'App\\',
        'Symfony\\Component\\HttpFoundation\\',
        'Doctrine\\Common\\Collections\\',
        'Brick\\',
    ];

    /** @var string[] Classes without a namespace a cached value may contain */
    public const array ALLOWED_GLOBAL_CLASS_NAMES = [
        'stdClass', 'ArrayObject', 'ArrayIterator', 'SplObjectStorage', 'DateTime', 'DateTimeImmutable', 'DateTimeZone',
        'DateInterval', 'DatePeriod',
    ];

    /**
     * The classes a serialized value names (`O:` objects, `C:` custom-serialized), when all of them are allowed.
     * A name that only appears inside a string value may be listed too; that never widens anything, as only names
     * passing the check are allowed.
     * @param string $serializedValue
     * @return string[]|null the allowed class names, null when the value names a class outside the allow-list
     */
    public static function findAllowedClassNames(string $serializedValue): ?array
    {
        if (preg_match_all('/(?:^|[;{}])(?:O|C):\d+:"([^"]+)"/', $serializedValue, $classMatches) === false) {
            return null;
        }
        $allowedClassNames = [];
        foreach (array_unique($classMatches[1]) as $className) {
            if (!static::isAllowedClassName($className)) {
                return null;
            }
            $allowedClassNames[] = $className;
        }
        return $allowedClassNames;
    }

    /**
     * @param string $className
     * @return bool
     */
    public static function isAllowedClassName(string $className): bool
    {
        $className = ltrim($className, '\\');
        if (in_array($className, static::ALLOWED_GLOBAL_CLASS_NAMES, true)) {
            return true;
        }
        foreach (static::ALLOWED_CLASS_NAMESPACE_PREFIXES as $namespacePrefix) {
            if (str_starts_with($className, $namespacePrefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array $values
     * @param array|null $failed
     * @return array
     */
    public function marshall(array $values, ?array &$failed): array
    {
        $serialized = $failed = [];

        foreach ($values as $id => $value) {
            try {
                $serialized[$id] = serialize($value);
            } catch (\Exception) {
                $failed[] = $id;
            }
        }

        return $serialized;
    }

    /**
     * Unserialize value
     * @param string $value
     * @return mixed
     * @throws Exception
     */
    public function unmarshall(string $value): mixed
    {
        if ('b:0;' === $value || 'N;' === $value) {
            return null;
        }
        if (':' === ($value[1] ?? ':')) {
            $allowedClassNames = static::findAllowedClassNames($value);
            if ($allowedClassNames === null) {
                // a class outside the allowed namespaces: treated as a miss, the value is computed again
                return null;
            }
            return unserialize($value, ['allowed_classes' => $allowedClassNames]) ?: null;
        }
        if (str_contains($value, '|') && str_contains($value, ':')) {
            return $this->unserializeSessionData($value);
        }
        return null;
    }

    /**
     * Unserialize session data
     * @param string $sessionSerializedData
     * @param array $unserializedData
     * @return array
     * @throws Exception
     */
    protected function unserializeSessionData(string $sessionSerializedData, array $unserializedData = []): array
    {
        if (!str_contains($sessionSerializedData, '|')) {
            throw new Exception('Error while unserializing session data: ' . $sessionSerializedData);
        }
        $separatorPosition = strpos($sessionSerializedData, '|');
        $key = substr($sessionSerializedData, 0, $separatorPosition);
        $value = @unserialize(substr($sessionSerializedData, ++$separatorPosition), ['allowed_classes' => false]);
        $unserializedData[$key] = $value;
        $sessionSerializedData = substr($sessionSerializedData, strlen(serialize($value)) + $separatorPosition);

        if ($sessionSerializedData !== '') {
            return $this->unserializeSessionData($sessionSerializedData, $unserializedData);
        }
        return $unserializedData;
    }

}
