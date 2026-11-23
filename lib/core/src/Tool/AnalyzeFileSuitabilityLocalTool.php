<?php

/**
 * Tool: analyze_file_suitability — will this file work for that purpose?
 *
 * `AnalyzeFileSuitabilityLocalTool` because the work is local: size, extension and a
 * short read of the bytes against a published table of limits. No model, no network,
 * no credentials — unlike most of the `replaced_by` promises still open.
 *
 * ## What the answer means
 *
 * The whole tool is one invariant, and it is the thing the WordPress original gets
 * wrong:
 *
 *     suitable === (warnings === [])
 *
 * WordPress collects a warning when an image is 80 pixels wide and still answers
 * `suitable: true`, then formats the message as "File has %d warnings preventing use"
 * from the count it just said was not disqualifying. A caller cannot tell a blocking
 * problem from advice. Here `warnings` blocks and `recommendations` advises, and the
 * two never overlap.
 *
 * What blocks, and why:
 *
 * - **Size over the purpose's limit** — the API will refuse it.
 * - **A format the purpose does not accept** — same.
 * - **A spreadsheet or presentation for a vector store** — OpenAI's parsers handle
 *   these badly, which is the honest reason to send the caller to a PDF.
 * - **An empty text file** — there is nothing to ingest.
 * - **A JSONL file whose first lines are not JSON** — it cannot be a fine-tune or
 *   batch input.
 *
 * Everything else is advice: an image smaller than 100px or wider than 4096px, text
 * that is not UTF-8, one malformed line in a JSONL file, a file at 90% of the limit.
 *
 * ## Privacy
 *
 * When the caller passes a user id, `FileStoreInterface::userCanAccess()` is checked
 * before the metadata is read, and a file the caller may not see answers exactly as a
 * file that does not exist. Distinguishing them would confirm existence to someone who
 * is not allowed to know it.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Entity\StoredFile;

final class AnalyzeFileSuitabilityLocalTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * Per-purpose ceilings, in bytes. These are the provider's numbers, not ours, so
     * they are kept verbatim.
     */
    private const MAX_FILE_SIZES = array(
        'assistants' => 536870912,
        'fine-tune'  => 1073741824,
        'batch'      => 104857600,
        'vision'     => 20971520,
        'whisper'    => 26214400,
    );

    private const ALLOWED_FILE_TYPES = array(
        'assistants' => array( 'pdf', 'txt', 'md', 'json', 'docx', 'html' ),
        'fine-tune'  => array( 'jsonl' ),
        'batch'      => array( 'jsonl' ),
        'vision'     => array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ),
        'whisper'    => array( 'mp3', 'mp4', 'mpeg', 'mpga', 'm4a', 'wav', 'webm', 'flac' ),
    );

    /**
     * Formats that are accepted nowhere in this table but fail specifically for vector
     * stores, so the recommendation can name the fix instead of the rule.
     */
    private const UNRELIABLE_FILE_TYPES = array(
        'assistants' => array( 'csv', 'xlsx', 'pptx', 'xls', 'ppt' ),
    );

    private const IMAGE_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );

    private const TEXT_EXTENSIONS = array( 'txt', 'md', 'json', 'jsonl', 'html' );

    /**
     * A file within ten percent of the ceiling is worth mentioning before the upload
     * fails, not after.
     */
    private const NEAR_LIMIT_RATIO = 0.9;

    private const ENCODING_SAMPLE_BYTES = 8192;

    private const JSONL_LINES_CHECKED = 10;

    private const TINY_AUDIO_BYTES = 10240;

    private const EMPTY_TEXT_BYTES = 10;

    private const LARGE_IMAGE_EDGE = 4096;

    private const SMALL_IMAGE_EDGE = 100;

    public function __construct(
        ErrorFactoryInterface $errors,
        private readonly ?FileStoreInterface $files = null,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string
    {
        return 'analyze_file_suitability';
    }

    public function getName(): string
    {
        return 'Analyze File Suitability';
    }

    public function getDescription(): string
    {
        return 'Checks whether a stored file will work for a given purpose (assistants, fine-tune, batch, vision or whisper): size against the purpose limit, format against the accepted list, and a short read of the bytes for images, audio and documents. Returns warnings that block use and recommendations that do not.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'file_id'       => array(
                    'type'        => 'integer',
                    'description' => 'Stored file to analyse.',
                    'minimum'     => 1,
                ),
                'purpose'       => array(
                    'type'        => 'string',
                    'description' => 'What the file is for. Each purpose has its own size ceiling and accepted formats.',
                    'enum'        => array( 'assistants', 'fine-tune', 'batch', 'vision', 'whisper' ),
                    'default'     => 'assistants',
                ),
                'check_content' => array(
                    'type'        => 'boolean',
                    'description' => 'Read the first bytes as well as the metadata. Set false for a metadata-only answer that touches no file.',
                    'default'     => true,
                ),
            ),
            'required'             => array( 'file_id' ),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'upload_files';
    }

    public function getCapabilityFlags(): array
    {
        return array(
            'idempotent',
            'local-only',
            'read-only',
            'requires-capability',
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $fileId = $this->intParam($arguments, 'file_id');

        if ($fileId < 1) {
            return $this->errors->validationFailed(
                'A file_id is required.',
                array( 'file_id' => 'Required.' )
            );
        }

        if (null === $this->files) {
            return $this->errors->create(
                'file_store_unavailable',
                'This host has no file store, so a stored file cannot be analysed.'
            );
        }

        $purpose = $this->stringParam($arguments, 'purpose', 'assistants');

        if (! isset(self::MAX_FILE_SIZES[ $purpose ])) {
            return $this->errors->validationFailed(
                \sprintf('Unknown purpose "%s".', $purpose),
                array( 'purpose' => 'Use one of: ' . \implode(', ', \array_keys(self::MAX_FILE_SIZES)) )
            );
        }

        $userId = (int) ( $context['user_id'] ?? $context['owner_id'] ?? 0 );

        if ($userId > 0 && ! $this->files->userCanAccess($fileId, $userId)) {
            // Indistinguishable from absent on purpose: confirming that a file exists
            // is itself information this caller may not have.
            return $this->notFound($fileId);
        }

        $file = $this->files->getMetadata($fileId);

        if (null === $file) {
            return $this->notFound($fileId);
        }

        $extension = $this->extension($file);
        $size      = $this->size($file);

        $warnings        = array();
        $recommendations = array();

        $this->checkSize($size, $purpose, $warnings, $recommendations);
        $this->checkType($extension, $purpose, $warnings, $recommendations);

        $contentChecked = false;

        if ($this->boolParam($arguments, 'check_content', true) && array() === $warnings) {
            $contentChecked = $this->checkContent($file, $extension, $purpose, $warnings, $recommendations);
        }

        if (array() === $warnings) {
            $recommendations[] = \sprintf('The file is suitable for the %s purpose.', $purpose);
        }

        return $this->success(
            array() === $warnings
                ? \sprintf('File (%s, %s) is suitable for %s.', $extension, $this->human($size), $purpose)
                : \sprintf(
                    'File is not suitable for %s: %s',
                    $purpose,
                    \implode(' ', $warnings)
                ),
            array(
                'suitable'        => array() === $warnings,
                'file_id'         => $file->id,
                'filename'        => $file->filename,
                'file_type'       => $extension,
                'mime_type'       => $file->mimeType,
                'file_size'       => $size,
                'file_size_human' => $this->human($size),
                'purpose'         => $purpose,
                'max_size'        => self::MAX_FILE_SIZES[ $purpose ],
                'max_size_human'  => $this->human(self::MAX_FILE_SIZES[ $purpose ]),
                'allowed_types'   => self::ALLOWED_FILE_TYPES[ $purpose ],
                'content_checked' => $contentChecked,
                'warnings'        => $warnings,
                'recommendations' => $recommendations,
            )
        );
    }

    private function notFound(int $fileId): mixed
    {
        return $this->errors->create(
            'file_not_found',
            \sprintf('No file with id %d is readable by this caller.', $fileId)
        );
    }

    /**
     * @param list<string> $warnings
     * @param list<string> $recommendations
     */
    private function checkSize(int $size, string $purpose, array &$warnings, array &$recommendations): void
    {
        $max = self::MAX_FILE_SIZES[ $purpose ];

        if ($size > $max) {
            $warnings[] = \sprintf(
                'It is %s, over the %s ceiling for %s.',
                $this->human($size),
                $this->human($max),
                $purpose
            );

            return;
        }

        if ($size > ( $max * self::NEAR_LIMIT_RATIO )) {
            $recommendations[] = \sprintf(
                'The file is %s of a %s ceiling. It fits, but compressing it leaves room for the next one.',
                $this->human($size),
                $this->human($max)
            );
        }
    }

    /**
     * @param list<string> $warnings
     * @param list<string> $recommendations
     */
    private function checkType(string $extension, string $purpose, array &$warnings, array &$recommendations): void
    {
        $allowed = self::ALLOWED_FILE_TYPES[ $purpose ];

        if (\in_array($extension, $allowed, true)) {
            return;
        }

        $unreliable = self::UNRELIABLE_FILE_TYPES[ $purpose ] ?? array();

        if (\in_array($extension, $unreliable, true)) {
            $warnings[] = \sprintf(
                '"%s" does not parse reliably for a vector store.',
                $extension
            );

            $recommendations[] = \sprintf(
                'Convert the %s to PDF or plain text first. For a spreadsheet, export it as text that keeps the logical structure.',
                \strtoupper($extension)
            );

            return;
        }

        $warnings[] = \sprintf(
            '"%s" is not accepted for %s. Accepted: %s.',
            $extension,
            $purpose,
            \implode(', ', $allowed)
        );
    }

    /**
     * Read a bounded sample of the file. Returns whether a read actually happened, so
     * the payload never implies content was inspected when it was not.
     *
     * @param list<string> $warnings
     * @param list<string> $recommendations
     */
    private function checkContent(
        StoredFile $file,
        string $extension,
        string $purpose,
        array &$warnings,
        array &$recommendations,
    ): bool {
        $path = $file->localPath;
        $size = $this->size($file);

        if ('' === $path || ! \is_file($path) || ! \is_readable($path)) {
            $recommendations[] = 'The stored file could not be opened, so only its metadata was checked.';

            return false;
        }

        if (\in_array($extension, self::IMAGE_EXTENSIONS, true) && 'vision' === $purpose) {
            $this->checkImage($path, $recommendations);

            return true;
        }

        if ('whisper' === $purpose) {
            $this->checkAudio($size, $recommendations);

            return true;
        }

        if (\in_array($extension, self::TEXT_EXTENSIONS, true)) {
            if ($size < self::EMPTY_TEXT_BYTES) {
                // Nothing to ingest is a blocking problem, not advice.
                $warnings[] = 'The file is empty, so there is nothing to ingest.';

                return true;
            }

            $this->checkEncoding($path, $recommendations);

            if ('jsonl' === $extension) {
                $this->checkJsonl($path, $warnings, $recommendations);
            }

            $recommendations[] = 'Well-structured text with clear sections retrieves better than a wall of prose.';
            $recommendations[] = 'Remove navigation, headers and footers before upload.';

            return true;
        }

        if ('pdf' === $extension) {
            $recommendations[] = 'Make sure the PDF contains real text, not just scanned pages. OCR it first if it does not.';
            $recommendations[] = 'Remove embedded images that carry no meaning for search.';

            return true;
        }

        if ('docx' === $extension) {
            $recommendations[] = 'Accept tracked changes and delete comments before upload; both can surface as content.';
            $recommendations[] = 'Use real headings so the extractor can see the document structure.';

            return true;
        }

        return false;
    }

    /**
     * @param list<string> $recommendations
     */
    private function checkImage(string $path, array &$recommendations): void
    {
        $info = @\getimagesize($path);

        if (false === $info) {
            $recommendations[] = 'The image headers could not be read, so its dimensions are unknown to this check.';

            return;
        }

        $width  = (int) $info[0];
        $height = (int) $info[1];

        if ($width > self::LARGE_IMAGE_EDGE || $height > self::LARGE_IMAGE_EDGE) {
            $recommendations[] = \sprintf(
                'The image is %d×%d. Downscaling it will be processed faster and cost fewer tokens.',
                $width,
                $height
            );
        }

        if ($width < self::SMALL_IMAGE_EDGE || $height < self::SMALL_IMAGE_EDGE) {
            $recommendations[] = \sprintf(
                'The image is only %d×%d. Vision results on something this small are unreliable.',
                $width,
                $height
            );
        }
    }

    /**
     * @param list<string> $recommendations
     */
    private function checkAudio(int $size, array &$recommendations): void
    {
        $recommendations[] = 'Transcription is best on clear audio with little background noise.';

        if ($size < self::TINY_AUDIO_BYTES) {
            $recommendations[] = 'The file is very small, so it may hold only a moment of speech.';
        }
    }

    /**
     * @param list<string> $recommendations
     */
    private function checkEncoding(string $path, array &$recommendations): void
    {
        $sample = $this->sample($path);

        if (null === $sample) {
            $recommendations[] = 'The encoding could not be sampled.';

            return;
        }

        if (! \mb_check_encoding($sample, 'UTF-8')) {
            $recommendations[] = 'The text is not valid UTF-8, which can break extraction. Convert it to UTF-8 first.';

            return;
        }

        $recommendations[] = 'The text is valid UTF-8, which is what ingestion wants.';
    }

    /**
     * @param list<string> $warnings
     * @param list<string> $recommendations
     */
    private function checkJsonl(string $path, array &$warnings, array &$recommendations): void
    {
        $handle = @\fopen($path, 'rb');

        if (false === $handle) {
            $recommendations[] = 'The JSONL lines could not be read.';

            return;
        }

        $lines  = 0;
        $valid  = 0;
        $read   = 0;

        while (! \feof($handle) && $read < self::JSONL_LINES_CHECKED) {
            $line = \fgets($handle);

            if (false === $line) {
                break;
            }

            ++$read;

            if ('' === \trim($line)) {
                continue;
            }

            ++$lines;

            if (null !== \json_decode($line, true)) {
                ++$valid;
            }
        }

        \fclose($handle);

        if ($lines > 0 && 0 === $valid) {
            // A fine-tune or batch input with no parseable line is unusable.
            $warnings[] = 'None of the first lines of the JSONL file parse as JSON.';

            return;
        }

        if ($valid < $lines) {
            $recommendations[] = \sprintf(
                '%d of the first %d JSONL lines did not parse. Check the file before submitting it.',
                $lines - $valid,
                $lines
            );
        }
    }

    private function sample(string $path): ?string
    {
        $handle = @\fopen($path, 'rb');

        if (false === $handle) {
            return null;
        }

        $sample = \fread($handle, self::ENCODING_SAMPLE_BYTES);

        \fclose($handle);

        return false === $sample ? null : $sample;
    }

    /**
     * The registry's size, corrected by the file on disk when it can be measured.
     *
     * A record and a file can disagree after a partial copy, and the question is whether
     * the file will upload, so the file wins.
     */
    private function size(StoredFile $file): int
    {
        if ('' !== $file->localPath && \is_file($file->localPath) && \is_readable($file->localPath)) {
            $actual = @\filesize($file->localPath);

            if (\is_int($actual) && $actual >= 0) {
                return $actual;
            }
        }

        return $file->sizeBytes;
    }

    /**
     * The extension from the stored filename, falling back to the entity's derivation.
     */
    private function extension(StoredFile $file): string
    {
        $fromName = \strtolower(\pathinfo($file->filename, PATHINFO_EXTENSION));

        if ('' !== $fromName) {
            return $fromName;
        }

        return \strtolower($file->getExtension());
    }

    private function human(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = array( 'KB', 'MB', 'GB', 'TB' );
        $value = $bytes / 1024;

        foreach ($units as $unit) {
            if ($value < 1024 || 'TB' === $unit) {
                return \round($value, 1) . ' ' . $unit;
            }

            $value /= 1024;
        }

        return $bytes . ' B';
    }
}
