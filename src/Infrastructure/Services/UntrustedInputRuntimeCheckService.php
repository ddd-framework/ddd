<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Services;

use DDD\Infrastructure\Untrusted\RasterImageDecoder;
use DDD\Infrastructure\Untrusted\UntrustedInputRuntimeCheck;
use DDD\Infrastructure\Untrusted\UntrustedInputRuntimeChecks;
use Imagick;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * Checks the runtime of this process against the untrusted-input rule — the second fence behind the fixed-format
 * decoder: ImageMagick must refuse PostScript, EPS, PDF, SVG, MVG, MSL and TEXT even when a caller forgets to pin
 * the coder (an allow-list `policy.xml`), Ghostscript must not be on the PATH, and the raster coders the application
 * needs must work. Used by `app:untrusted-input:check-runtime` (image build, deploy, CI) and by a host's health check.
 *
 * The probe bodies are harmless (they draw nothing and read no file); on an unsafe runtime they are interpreted once.
 */
class UntrustedInputRuntimeCheckService extends Service
{
    /** @var string[] Coder => a harmless body of that format */
    public const array INTERPRETER_PROBE_BODIES = [
        'PS' => "%!PS-Adobe-3.0\n%%BoundingBox: 0 0 1 1\nshowpage\n",
        'EPS' => "%!PS-Adobe-3.0 EPSF-3.0\n%%BoundingBox: 0 0 1 1\nshowpage\n",
        'PDF' => "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 1 1]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
        'SVG' => '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><rect width="1" height="1"/></svg>',
        'MVG' => "push graphic-context\nviewbox 0 0 1 1\npop graphic-context\n",
        'MSL' => '<?xml version="1.0" encoding="UTF-8"?><image></image>',
        'TEXT' => 'probe',
    ];

    /** @var string Messages of a coder that was refused, not run: a policy denial or a coder that is not installed */
    public const string REFUSAL_MESSAGE_PATTERN = '/not authorized|not allowed by the security policy|security policy|no decode delegate|NoDecodeDelegate|unable to load module|UnableToOpenModule/i';

    /** @var string A 3×2 PNG (base64): the raster check needs neither GD nor an encoder to make its input */
    public const string RASTER_PROBE_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAMAAAACCAIAAAASFvFNAAAAC0lEQVR4nGNgwAQAABQAAX3+Hu4AAAAASUVORK5CYII=';

    /** @var string[] Executables that must not be on the PATH of a process that handles foreign bytes */
    public const array FORBIDDEN_EXECUTABLES = ['gs', 'gsc', 'gsx'];

    /**
     * @return UntrustedInputRuntimeChecks
     */
    public function checkRuntime(): UntrustedInputRuntimeChecks
    {
        $untrustedInputRuntimeChecks = new UntrustedInputRuntimeChecks();
        if (!extension_loaded('imagick')) {
            $imagickExtensionCheck = $this->createCheck('imagick-extension', UntrustedInputRuntimeCheck::STATUS_BROKEN, 'The imagick extension is not loaded.');
            $untrustedInputRuntimeChecks->add($imagickExtensionCheck);
        } else {
            foreach (static::INTERPRETER_PROBE_BODIES as $coder => $probeBody) {
                $coderCheck = $this->checkCoderIsRefused($coder, $probeBody);
                $untrustedInputRuntimeChecks->add($coderCheck);
            }
            $rasterCoderCheck = $this->checkRasterCoderWorks();
            $untrustedInputRuntimeChecks->add($rasterCoderCheck);
        }
        foreach (static::FORBIDDEN_EXECUTABLES as $forbiddenExecutable) {
            $executablePath = (new ExecutableFinder())->find($forbiddenExecutable);
            $executableCheck = $this->createCheck(
                $forbiddenExecutable . '-on-path',
                $executablePath === null ? UntrustedInputRuntimeCheck::STATUS_SAFE : UntrustedInputRuntimeCheck::STATUS_UNSAFE,
                $executablePath === null ? 'not found' : "found at {$executablePath}: remove it from the image (PostScript interpreter)"
            );
            $untrustedInputRuntimeChecks->add($executableCheck);
        }
        return $untrustedInputRuntimeChecks;
    }

    /**
     * Reads the probe body with the coder named explicitly (what an unpinned read of hostile bytes would reach).
     * @param string $coder
     * @param string $probeBody
     * @return UntrustedInputRuntimeCheck
     */
    protected function checkCoderIsRefused(string $coder, string $probeBody): UntrustedInputRuntimeCheck
    {
        $checkName = 'imagemagick-reads-' . strtolower($coder);
        RasterImageDecoder::applyResourceLimits();
        $imagick = new Imagick();
        try {
            $imagick->setFormat($coder);
            $imagick->readImageBlob($probeBody);
            return $this->createCheck($checkName, UntrustedInputRuntimeCheck::STATUS_UNSAFE, "ImageMagick decoded {$coder}: install an allow-list policy.xml");
        } catch (Throwable $throwable) {
            $message = substr($throwable->getMessage(), 0, 200);
            if (preg_match(static::REFUSAL_MESSAGE_PATTERN, $throwable->getMessage()) === 1) {
                return $this->createCheck($checkName, UntrustedInputRuntimeCheck::STATUS_SAFE, 'refused: ' . $message);
            }
            // the coder ran (and failed on the probe): it would run on hostile bytes too
            return $this->createCheck($checkName, UntrustedInputRuntimeCheck::STATUS_UNSAFE, "ImageMagick ran the {$coder} coder ({$message}): install an allow-list policy.xml");
        } finally {
            $imagick->clear();
        }
    }

    /**
     * @return UntrustedInputRuntimeCheck
     */
    protected function checkRasterCoderWorks(): UntrustedInputRuntimeCheck
    {
        try {
            $pngBytes = (string)base64_decode(static::RASTER_PROBE_PNG_BASE64, true);
            $imagick = RasterImageDecoder::readBlob($pngBytes);
            RasterImageDecoder::setOutputFormat($imagick, RasterImageDecoder::FORMAT_JPEG);
            $jpegBytes = $imagick->getImageBlob();
            $imagick->clear();
            return $this->createCheck(
                'raster-decode-and-encode',
                RasterImageDecoder::detectFormat($jpegBytes) === RasterImageDecoder::FORMAT_JPEG ? UntrustedInputRuntimeCheck::STATUS_SAFE : UntrustedInputRuntimeCheck::STATUS_BROKEN,
                'PNG decoded, JPEG written'
            );
        } catch (Throwable $throwable) {
            return $this->createCheck('raster-decode-and-encode', UntrustedInputRuntimeCheck::STATUS_BROKEN, 'PNG/JPEG do not work: ' . $throwable->getMessage());
        }
    }

    /**
     * @param string $name
     * @param string $status
     * @param string $detail
     * @return UntrustedInputRuntimeCheck
     */
    protected function createCheck(string $name, string $status, string $detail): UntrustedInputRuntimeCheck
    {
        $untrustedInputRuntimeCheck = new UntrustedInputRuntimeCheck();
        $untrustedInputRuntimeCheck->name = $name;
        $untrustedInputRuntimeCheck->status = $status;
        $untrustedInputRuntimeCheck->detail = $detail;
        return $untrustedInputRuntimeCheck;
    }
}
