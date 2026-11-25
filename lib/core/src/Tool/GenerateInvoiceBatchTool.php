<?php

/**
 * Render several invoices as PDFs in one call.
 *
 * Port of `WP_MCP_AI_Tool_Generate_Invoice_Batch` under Proposal 164 §164.41,
 * re-scoped — see below.
 *
 * ## Why this is not "invoice my orders"
 *
 * The WordPress tool takes `order_ids` and reads each order from WooCommerce,
 * plus a `template_id` (a document-template CPT) and `send_email`. None of those
 * three exist on this runtime: there is no order store, no template CPT and no
 * mailer on this path. Shipping the schema anyway would produce a tool whose
 * only possible answer is "no such order".
 *
 * So the premise changes to what can actually be rendered: **give the invoices,
 * get the PDFs.** One call, up to 25 documents, each built from its own figures
 * by the same {@see InvoiceDocument} the single-invoice tool uses. The tool's
 * name stops promising an order lookup it cannot do.
 *
 * ## A failing invoice does not lose the batch
 *
 * `continue_on_error` defaults to **true**, as in `pro_batch_ocr`: a run that
 * aborts on invoice three has rendered two documents and returned nothing,
 * which leaves the caller unsure what exists. Each result carries its own
 * `ok`/`error`, and the summary counts what happened.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Application\Document\InvoiceDocument;
use Nvoos\Core\Application\Document\PdfDocumentComposer;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\HtmlToPdfInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Service\Document\HtmlFormatter;

class GenerateInvoiceBatchTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * Invoices per call. Each one is a render, so the cap is a timeout guard.
     */
    public const MAX_INVOICES = 25;

    private readonly InvoiceDocument $invoices;

    private readonly PdfDocumentComposer $composer;

    public function __construct(
        ErrorFactoryInterface $e,
        HtmlToPdfInterface $pdf,
        ?FileVisibilityStoreInterface $store = null,
    ) {
        parent::__construct($e);

        $formatter = new HtmlFormatter();

        $this->invoices = new InvoiceDocument($formatter);
        $this->composer = new PdfDocumentComposer($formatter, $pdf, $store);
    }

    public function getSlug(): string
    {
        return 'generate_invoice_batch';
    }

    public function getName(): string
    {
        return 'Generate Invoice Batch';
    }

    public function getDescription(): string
    {
        return 'Render up to ' . self::MAX_INVOICES . ' itemised invoices as PDFs in one call from the figures supplied, returning a per-invoice result. This host has no order store: the invoices are given, not looked up. A failing invoice does not abort the batch unless asked to.';
    }

    public function getParametersSchema(): array
    {
        $sizes = $this->composer->supportedPageSizes();

        return array(
            'type' => 'object',
            'properties' => array(
                'invoices' => array(
                    'type' => 'array',
                    'description' => 'The invoices to render, at most ' . self::MAX_INVOICES . '. Each takes the same fields as generate_invoice_pdf.',
                    'minItems' => 1,
                    'maxItems' => self::MAX_INVOICES,
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'invoice_number' => array(
                                'type' => 'string',
                                'description' => 'Invoice number or id.',
                            ),
                            'date' => array(
                                'type' => 'string',
                                'description' => 'Invoice date, YYYY-MM-DD. Defaults to today.',
                            ),
                            'due_date' => array(
                                'type' => 'string',
                                'description' => 'Due date, YYYY-MM-DD. Defaults to 30 days from today.',
                            ),
                            'bill_to' => array(
                                'type' => 'object',
                                'description' => 'Who is invoiced: a string, or a map of name/company/address/city/postcode/country/email/phone.',
                            ),
                            'items' => array(
                                'type' => 'array',
                                'description' => 'Line items, at most ' . InvoiceDocument::MAX_ITEMS . '.',
                                'minItems' => 1,
                                'maxItems' => InvoiceDocument::MAX_ITEMS,
                                'items' => array(
                                    'type' => 'object',
                                    'properties' => array(
                                        'description' => array(
                                            'type' => 'string',
                                            'description' => 'What is being billed.',
                                        ),
                                        'quantity' => array(
                                            'type' => 'number',
                                            'description' => 'Units. Default 1.',
                                            'minimum' => 0,
                                        ),
                                        'rate' => array(
                                            'type' => 'number',
                                            'description' => 'Price per unit. Default 0.',
                                            'minimum' => 0,
                                        ),
                                        'amount' => array(
                                            'type' => 'number',
                                            'description' => 'Line total. Omit to compute quantity × rate.',
                                            'minimum' => 0,
                                        ),
                                    ),
                                    'required' => array('description'),
                                    'additionalProperties' => false,
                                ),
                            ),
                            'tax_rate' => array(
                                'type' => 'number',
                                'description' => 'Tax percentage of the subtotal. Default 0.',
                                'minimum' => 0,
                                'maximum' => 100,
                            ),
                            'currency' => array(
                                'type' => 'string',
                                'description' => 'Three-letter ISO-4217 code. Default USD.',
                                'pattern' => '^[A-Za-z]{3}$',
                            ),
                            'subtotal' => array(
                                'type' => 'number',
                                'description' => 'Optional cross-check, refused when it disagrees.',
                            ),
                            'total' => array(
                                'type' => 'number',
                                'description' => 'Optional cross-check, refused when it disagrees.',
                            ),
                            'title' => array(
                                'type' => 'string',
                                'description' => 'Document title and filename stem. Default "Invoice {number}".',
                            ),
                        ),
                        'required' => array('invoice_number', 'items'),
                        'additionalProperties' => false,
                    ),
                ),
                'continue_on_error' => array(
                    'type' => 'boolean',
                    'description' => 'When true (the default) a failing invoice is reported and the batch continues.',
                    'default' => true,
                ),
                'page_size' => array(
                    'type' => 'string',
                    'description' => 'Page size for every invoice. Case-insensitive.',
                    'enum' => array() === $sizes ? array('A4') : $sizes,
                    'default' => array() === $sizes ? 'A4' : $sizes[0],
                ),
                'orientation' => array(
                    'type' => 'string',
                    'description' => 'Page orientation for every invoice. Default portrait.',
                    'enum' => array('portrait', 'landscape'),
                    'default' => 'portrait',
                ),
                'save_to_media' => array(
                    'type' => 'boolean',
                    'description' => 'Write each PDF to the file registry. Default true.',
                    'default' => true,
                ),
                'visibility' => array(
                    'type' => 'string',
                    'description' => 'Registry visibility for every stored file. Default public.',
                    'enum' => array(
                        FileVisibilityStoreInterface::VISIBILITY_PUBLIC,
                        FileVisibilityStoreInterface::VISIBILITY_PRIVATE,
                    ),
                    'default' => FileVisibilityStoreInterface::VISIBILITY_PUBLIC,
                ),
            ),
            'required' => array('invoices'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'upload_files';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('requires-capability', 'state-changing', 'write');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $invoices = $this->arrayParam($arguments, 'invoices');

        if (array() === $invoices) {
            return $this->errors->validationFailed(
                'At least one invoice is required.',
                array('invoices' => array('Give one or more invoice objects.')),
            );
        }

        if (count($invoices) > self::MAX_INVOICES) {
            return $this->errors->validationFailed(
                sprintf('At most %d invoices can be rendered in one call.', self::MAX_INVOICES),
                array('invoices' => array('Split the batch.')),
            );
        }

        if (!$this->composer->isAvailable()) {
            return $this->errors->create(
                'no_pdf_engine',
                'No PDF renderer is bound on this host. Configure the media worker (MEDIA_WORKER_URL) or install a PHP renderer.',
            );
        }

        $continue = $this->boolParam($arguments, 'continue_on_error', true);
        $save = $this->boolParam($arguments, 'save_to_media', true);
        $visibility = $this->stringParam(
            $arguments,
            'visibility',
            FileVisibilityStoreInterface::VISIBILITY_PUBLIC,
        );
        $pageSize = $this->stringParam($arguments, 'page_size');
        $orientation = $this->stringParam($arguments, 'orientation', 'portrait');

        $results = array();
        $failed = 0;

        foreach (array_values($invoices) as $index => $invoice) {
            if (!is_array($invoice)) {
                $failed++;
                $results[] = array(
                    'index' => $index,
                    'ok' => false,
                    'error' => 'Invoice ' . ($index + 1) . ' is not an object.',
                );

                if (!$continue) {
                    break;
                }

                continue;
            }

            $number = is_scalar($invoice['invoice_number'] ?? null)
                ? trim((string) $invoice['invoice_number'])
                : '';
            $title = is_scalar($invoice['title'] ?? null) ? trim((string) $invoice['title']) : '';

            try {
                $built = $this->invoices->build($invoice);
            } catch (\InvalidArgumentException $e) {
                $failed++;
                $results[] = array(
                    'index' => $index,
                    'invoice_number' => $number,
                    'ok' => false,
                    'error' => $e->getMessage(),
                );

                if (!$continue) {
                    break;
                }

                continue;
            }

            $document = array(
                'title' => '' === $title ? 'Invoice ' . (string) $built['totals']['number'] : $title,
                'page_size' => $pageSize,
                'orientation' => $orientation,
            );

            // Only the rendering choices travel with each invoice; the invoice's
            // own fields were already consumed by `build()`.
            $renderArguments = array(
                'save_to_media' => $save,
                'visibility' => $visibility,
                'title' => $document['title'],
            );

            try {
                $payload = $this->composer->documentFromHtml($built['body'], $document, $renderArguments, $context);
            } catch (\Throwable $e) {
                $failed++;
                $results[] = array(
                    'index' => $index,
                    'invoice_number' => (string) $built['totals']['number'],
                    'ok' => false,
                    'error' => $e->getMessage(),
                );

                if (!$continue) {
                    break;
                }

                continue;
            }

            $results[] = array(
                'index' => $index,
                'ok' => true,
                'invoice' => $built['totals'],
            ) + $payload;
        }

        $processed = count($results) - $failed;

        return $this->success(
            sprintf(
                'Rendered %d of %d invoice(s)%s.',
                $processed,
                count($invoices),
                $failed > 0 ? sprintf('; %d failed', $failed) : '',
            ),
            array(
                'results' => $results,
                'processed' => $processed,
                'failed' => $failed,
                'requested' => count($invoices),
                'stopped_early' => !$continue && $failed > 0,
            ),
        );
    }
}
