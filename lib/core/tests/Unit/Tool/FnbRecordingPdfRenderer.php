<?php

/**
 * A PDF renderer for the F&B report tests.
 *
 * Records the HTML it was given and writes a marker file the file store can
 * read. The HTML is the assertion target: it is the exporter's own work.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\HtmlToPdfInterface;

final class FnbRecordingPdfRenderer implements HtmlToPdfInterface
{
    public string $html = '';

    public bool $called = false;

    public function render(string $html, array $options = array()): array
    {
        $this->called = true;
        $this->html   = $html;

        $path = (string) tempnam(sys_get_temp_dir(), 'nvoos-fnb-pdf-');
        file_put_contents($path, '%PDF-1.4 fake');

        return array(
            'path'      => $path,
            'mime_type' => 'application/pdf',
            'bytes'     => 13,
            'format'    => 'A4',
        );
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getSupportedPageFormats(): array
    {
        return array('A4');
    }
}
