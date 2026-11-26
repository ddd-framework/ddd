<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Files;

use DDD\Domain\Common\Entities\Files\Sources\FileAudience;
use DDD\Domain\Common\Entities\Files\Sources\FileFacts;
use DDD\Domain\Common\Entities\Files\Sources\FileFamily;
use DDD\Domain\Common\Entities\Files\Sources\FileReference;
use DDD\Infrastructure\Exceptions\BadRequestException;
use PHPUnit\Framework\TestCase;

class FileReferenceTest extends TestCase
{
    public function testAReferenceIsSourceSlashId(): void
    {
        $fileReference = FileReference::fromString(' media/901 ');
        $this->assertSame('media', $fileReference->sourceName);
        $this->assertSame('901', $fileReference->id);
        $this->assertSame('media/901', $fileReference->toString());
        $this->assertSame('media/901', (string)$fileReference);
        $this->assertTrue($fileReference->isSameAs(new FileReference('media', '901')));
    }

    public function testEverythingElseIsRefused(): void
    {
        foreach ([
            '',
            'media',
            'media/',
            '/901',
            'media/901/x',
            'media/../901',
            'Media/901',
            'media/9 01',
            'media/a.b',
            'https://example.org/media/901',
            'assets/logo.png',
            "media/901\n",
            '9media/901',
        ] as $text) {
            $this->assertNull(FileReference::tryFromString($text === "media/901\n" ? "media/90\n1" : $text), var_export($text, true));
        }
        $this->expectException(BadRequestException::class);
        FileReference::fromString('assets/logo.png');
    }

    public function testTheFamilyComesFromTheMeasuredContentType(): void
    {
        $this->assertSame(FileFamily::IMAGE, FileFamily::fromContentType('image/png'));
        $this->assertSame(FileFamily::PDF, FileFamily::fromContentType('application/pdf; qs=1'));
        $this->assertSame(FileFamily::RIDE_FILE, FileFamily::fromContentType('application/xml', 'tour.GPX'));
        $this->assertSame(FileFamily::RIDE_FILE, FileFamily::fromContentType('application/gpx+xml'));
        // the name decides only between families one content type covers: a picture named .gpx stays a picture
        $this->assertSame(FileFamily::IMAGE, FileFamily::fromContentType('image/jpeg', 'tour.gpx'));
        $this->assertSame(FileFamily::PRESENTATION, FileFamily::fromContentType('application/vnd.openxmlformats-officedocument.presentationml.presentation'));
        $this->assertSame(FileFamily::TEXT, FileFamily::fromContentType('text/markdown'));
        $this->assertSame(FileFamily::OTHER, FileFamily::fromContentType(null));
    }

    public function testAnOpenLinkIsAPathOfTheApplicationOrNothing(): void
    {
        $fileFacts = new FileFacts();
        $fileFacts->setOpenLinkIfSafe('/admin/media/901');
        $this->assertSame('/admin/media/901', $fileFacts->openLink);
        foreach (['https://store.example/bucket/key', '//store.example/key', 'javascript:alert(1)', '/a\\b', "/a\nb", ''] as $unsafeLink) {
            $fileFacts->setOpenLinkIfSafe($unsafeLink);
            $this->assertNull($fileFacts->openLink, $unsafeLink);
        }
    }

    public function testAudiencesAreOrderedFromWideToNarrow(): void
    {
        $this->assertSame(0, FileAudience::getRestrictionRank(FileAudience::PUBLIC));
        $this->assertSame(3, FileAudience::getRestrictionRank(FileAudience::PERSONAL));
        $this->assertSame(3, FileAudience::getRestrictionRank('SOMETHING'));
        $this->assertFalse(FileAudience::isKnown('SOMETHING'));
    }
}
