<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Files\Sources;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The facts of the files a caller asked for and may read; a file the caller may not read is left out.
 *
 * @property FileFacts[] $elements
 * @method FileFacts|null first()
 * @method FileFacts[] getElements()
 */
class FileFactsSet extends ObjectSet
{
    public function findByFileReference(FileReference $fileReference): ?FileFacts
    {
        foreach ($this->getElements() as $fileFacts) {
            if ($fileFacts->fileReference->isSameAs($fileReference)) {
                return $fileFacts;
            }
        }
        return null;
    }
}
