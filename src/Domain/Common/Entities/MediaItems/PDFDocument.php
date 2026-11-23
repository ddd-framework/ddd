<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\MediaItems;

use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Untrusted\IsolatedProcess;
use DDD\Infrastructure\Untrusted\RasterImageDecoder;
use DDD\Infrastructure\Untrusted\UntrustedContentHeaders;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Dompdf\Dompdf;
use Dompdf\Options;
use ImagickException;
use Throwable;

/**
 * A PDF file. A PDF is treated as foreign: it is never handed to Imagick (whose PDF coder is Ghostscript, a PostScript
 * interpreter) and never put on a shell line. Pages are rendered and text is extracted by poppler (`pdftoppm`,
 * `pdftotext`) and OCR'd by `tesseract`, each as an {@see IsolatedProcess} (argument array, stripped environment,
 * timeout) on a file in a private working directory. HTML is rendered by Dompdf without PHP, JavaScript and remote
 * resources (inline `data:` images work).
 */
class PDFDocument extends Document
{
    /** @var int Pages the OCR fallback of getTextContent() renders at most */
    public const int MAX_OCR_PAGES = 20;

    /** @var int Seconds one poppler or tesseract run may take */
    public const int PROCESS_TIMEOUT_SECONDS = 60;

    /** @var string|null The type of the mediaitem */
    #[Choice(choices: [self::TYPE_PHOTO, self::TYPE_VIDEO, self::TYPE_DOCUMENT])]
    public ?string $type = self::TYPE_DOCUMENT;

    /** @var string The text content of the PDF */
    public string $textContent;

    /** @var string The title of the PDF */
    public string $title;

    /** @var GenericMediaItemContent PDF File Content */
    public GenericMediaItemContent $mediaItemContent;

    public function __construct(?string $fileName = null, ?string $title = null, ?string $pdfBody = null)
    {
        if ($pdfBody) {
            $this->mediaItemContent = new GenericMediaItemContent();
            $this->addChildren($this->mediaItemContent);
            if ($fileName) {
                $this->fileName = $fileName;
            }
            if ($title) {
                $this->title = $title;
            }
            $this->mediaItemContent->body = $pdfBody;
        }
    }


    /**
     * Renders the first page as PNG (poppler `pdftoppm`), trimmed, with a white 10 px border, scaled to the width.
     * @param int $width
     * @return PDFDocumentAsImage|null null when the body is no PDF or the page cannot be rendered
     * @throws ImagickException
     */
    public function getDocumentAsImage(int $width = 1536): ?PDFDocumentAsImage
    {
        $pdfBody = $this->mediaItemContent->getBody();
        if (!is_string($pdfBody) || !str_starts_with(ltrim(substr($pdfBody, 0, 1024)), '%PDF-')) {
            return null;
        }
        $workingDirectory = IsolatedProcess::createWorkingDirectory('pdf-image');
        try {
            file_put_contents($workingDirectory . '/input.pdf', $pdfBody);
            $process = IsolatedProcess::create(
                'pdftoppm',
                ['-png', '-singlefile', '-f', '1', '-l', '1', '-r', '300', $workingDirectory . '/input.pdf', $workingDirectory . '/page'],
                $workingDirectory,
                static::PROCESS_TIMEOUT_SECONDS
            );
            $process->run();
            $pngBytes = is_file($workingDirectory . '/page.png') ? (string)file_get_contents($workingDirectory . '/page.png') : '';
            if (!$process->isSuccessful() || $pngBytes === '') {
                error_log('Error converting PDF to image: ' . trim($process->getErrorOutput()));
                return null;
            }
            $imagick = RasterImageDecoder::readBlob($pngBytes);
            RasterImageDecoder::setOutputFormat($imagick, RasterImageDecoder::FORMAT_PNG);
            $imagick->trimImage(0);
            $imagick->borderImage('white', 10, 10);
            $imagick->scaleImage($width, 0);

            $pdfDocumentAsImage = new PDFDocumentAsImage();
            $pdfDocumentAsImage->mediaItemContent = new GenericMediaItemContent();
            $pdfDocumentAsImage->mediaItemContent->populateMediaItemContentInfoFromImagick($imagick);
            $pdfDocumentAsImage->addChildren($pdfDocumentAsImage->mediaItemContent);
            $imagick->clear();
            return $pdfDocumentAsImage;
        } catch (InternalErrorException $internalErrorException) {
            error_log('Error converting PDF to image: ' . $internalErrorException->getMessage());
            return null;
        } finally {
            IsolatedProcess::removeWorkingDirectory($workingDirectory);
        }
    }

    /**
     * Extracts the text (poppler `pdftotext`); a PDF without a text layer is OCR'd page by page (`pdftoppm` +
     * `tesseract`, at most MAX_OCR_PAGES pages).
     * @return string Extracted text ('' when nothing could be read)
     */
    public function getTextContent(): string
    {
        if (isset($this->textContent)) {
            return $this->textContent;
        }
        $pdfBody = $this->mediaItemContent->getBody();
        if (!is_string($pdfBody) || $pdfBody === '') {
            return '';
        }
        $workingDirectory = IsolatedProcess::createWorkingDirectory('pdf-text');
        try {
            $pdfPath = $workingDirectory . '/input.pdf';
            file_put_contents($pdfPath, $pdfBody);
            $textProcess = IsolatedProcess::create('pdftotext', [$pdfPath, '-'], $workingDirectory, static::PROCESS_TIMEOUT_SECONDS);
            $textProcess->run();
            $extractedText = $textProcess->isSuccessful() ? $textProcess->getOutput() : '';
            if (strlen(trim($extractedText)) > 50) {
                return (string)preg_replace('/\.{4,}/', ' ', $extractedText);
            }
            return $this->extractTextByOcr($workingDirectory, $pdfPath);
        } catch (Throwable $throwable) {
            error_log('Error extracting text from PDF: ' . $throwable->getMessage());
            return '';
        } finally {
            IsolatedProcess::removeWorkingDirectory($workingDirectory);
        }
    }

    /**
     * @param string $workingDirectory
     * @param string $pdfPath
     * @return string
     * @throws InternalErrorException
     */
    protected function extractTextByOcr(string $workingDirectory, string $pdfPath): string
    {
        $renderProcess = IsolatedProcess::create(
            'pdftoppm',
            ['-png', '-r', '300', '-f', '1', '-l', (string)static::MAX_OCR_PAGES, $pdfPath, $workingDirectory . '/page'],
            $workingDirectory,
            static::PROCESS_TIMEOUT_SECONDS
        );
        $renderProcess->run();
        $pageImagePaths = glob($workingDirectory . '/page-*.png') ?: [];
        natsort($pageImagePaths);
        $combinedText = '';
        foreach ($pageImagePaths as $pageImagePath) {
            $ocrProcess = IsolatedProcess::create('tesseract', [$pageImagePath, 'stdout'], $workingDirectory, static::PROCESS_TIMEOUT_SECONDS);
            $ocrProcess->run();
            if ($ocrProcess->isSuccessful()) {
                $combinedText .= $ocrProcess->getOutput() . "\n";
            }
        }
        return (string)preg_replace('/\.{4,}/', ' ', $combinedText);
    }

    public static function fromHTML(
        string $html,
        ?string $author = null,
        ?string $title = null,
        ?DateTime $createdDateTime = null,
        ?DateTime $modifiedDateTime = null
    ): PDFDocument {
        // Set cache path
        $cachePath = DDDService::instance()->getCacheDir(false) . '/dompdf_cache';

        // Check if the cache directory exists, if not create it
        if (!file_exists($cachePath)) {
            mkdir($cachePath, 0775, true);
        }

        // No PHP, no JavaScript, no remote resources: the HTML may carry text from outside (inline data: images work)
        $dompdf = new Dompdf();
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isFontSubsettingEnabled', true);
        $options->set('defaultMediaType', true);
        $options->set('fontDir', $cachePath);
        $options->set('fontCache', $cachePath);
        $options->set('tempDir', $cachePath);
        $options->set('chroot', $cachePath);

        // Set cache directory in Dompdf options
        $pdfDocument = new static();

        $dompdf->setOptions($options);
        $dompdf->setPaper('A4', 'portrait');

        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        $dompdf->addInfo('Creator', 'Word');
        if ($createdDateTime) {
            $dompdf->addInfo('CreationDate', 'D:' . $createdDateTime->format('YmdHis'));
            $pdfDocument->createdDateTime = $createdDateTime;
        }
        if ($modifiedDateTime) {
            $pdfDocument->modifiedDateTime = $modifiedDateTime;
        }
        if ($author) {
            $dompdf->addInfo('Author', $author);
        }
        if ($title) {
            $dompdf->addInfo('Title', $title);
        }

        $pdfDocument->mediaItemContent = new GenericMediaItemContent();
        $pdfDocument->addChildren($pdfDocument->mediaItemContent);
        if ($title) {
            $pdfDocument->title = $title;
        }
        $pdfDocument->mediaItemContent->body = $dompdf->output();
        return $pdfDocument;
    }

    public function getFileName(): ?string
    {
        $fileName = null;

        if (isset($this->fileName)) {
            $fileName = $this->fileName;
        } elseif (isset($this->title)) {
            $fileName = $this->title;
        }

        // Check if the fileName is set and does not end with '.pdf'
        if ($fileName !== null && substr($fileName, -4) !== '.pdf') {
            $fileName .= '.pdf'; // Add '.pdf' to the end
        }
        if (!$fileName) {
            $fileName = 'pdf.pdf';
        }
        return $fileName;
    }

    public function download(): void
    {
        // Set headers to force download
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . UntrustedContentHeaders::buildContentDisposition(false, $this->getFileName()));
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($this->mediaItemContent->body));

        // Output the PDF content
        echo $this->mediaItemContent->body;
        exit;
    }
}
