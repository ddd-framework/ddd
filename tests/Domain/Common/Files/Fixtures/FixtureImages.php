<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Files\Fixtures;

use Imagick;
use ImagickPixel;

/**
 * Small pictures built in memory for the tests.
 */
class FixtureImages
{
    /** A JPEG of the given size; with $exifOrientation it carries an EXIF profile naming that orientation */
    public static function buildJpeg(int $width, int $height, ?int $exifOrientation = null): string
    {
        $imagick = new Imagick();
        $imagick->newImage($width, $height, new ImagickPixel('#1E88E5'));
        $imagick->setImageFormat('JPEG');
        if ($exifOrientation !== null) {
            $imagick->setImageProfile('exif', static::buildExifProfile($exifOrientation));
            $imagick->setImageOrientation($exifOrientation);
        }
        $bytes = $imagick->getImageBlob();
        $imagick->clear();
        return $bytes;
    }

    /** A PNG with a transparent background */
    public static function buildTransparentPng(int $width, int $height): string
    {
        $imagick = new Imagick();
        $imagick->newImage($width, $height, new ImagickPixel('transparent'));
        $imagick->setImageFormat('PNG');
        $bytes = $imagick->getImageBlob();
        $imagick->clear();
        return $bytes;
    }

    /** A minimal EXIF profile: one IFD with the orientation tag (0x0112) */
    public static function buildExifProfile(int $orientation): string
    {
        return "Exif\0\0" . 'MM' . "\0*" . pack('N', 8) . pack('n', 1)
            . pack('nnNnn', 0x0112, 3, 1, $orientation, 0) . pack('N', 0);
    }

    /** The profile names of a picture, lower case */
    public static function getProfileNames(string $bytes): array
    {
        $imagick = new Imagick();
        $imagick->readImageBlob($bytes);
        $profileNames = array_map('strtolower', $imagick->getImageProfiles('*', false));
        $imagick->clear();
        return $profileNames;
    }
}
