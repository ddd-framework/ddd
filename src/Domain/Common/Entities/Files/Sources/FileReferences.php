<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Files\Sources;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * References of files, in the order they were added; one reference appears once (keyed by `<source>/<id>`).
 *
 * @property FileReference[] $elements
 * @method FileReference|null first()
 * @method FileReference[] getElements()
 */
class FileReferences extends ObjectSet
{
    /**
     * A set of the given references.
     *
     * @param FileReference[] $fileReferences
     */
    public static function fromFileReferences(array $fileReferences): static
    {
        $fileReferencesSet = new static();
        foreach ($fileReferences as $fileReference) {
            $fileReferencesSet->add($fileReference);
        }
        return $fileReferencesSet;
    }
}
