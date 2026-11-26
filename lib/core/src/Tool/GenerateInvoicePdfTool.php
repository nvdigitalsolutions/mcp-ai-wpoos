<?php

/**
 * Render one invoice as a PDF.
 *
 * Port of `WP_MCP_AI_Tool_Generate_Invoice_PDF` under Proposal 164 §164.41.
 *
 * ## It is a renderer, not an order reader
 *
 * The WordPress tool takes every figure as an argument — number, dates, items,
 * tax rate, currency — and queries nothing. It is portable exactly as it is,
 * which is what §164.40 wrongly doubted: this needs no WooCommerce and no
 * product decision.
 *
 * The WordPress tool declares `requires-model` and `consumes-tokens`. Neither is
 * true: there is no model call anywhere in that file, only HTML assembly and a
 * PDF engine. The flags here say what actually happens.
 *
 * ## The arithmetic is shared, and it is audited
 *
 * Totals come from {@see InvoiceDocument}, the same class the batch tool uses,
 * and `subtotal`/`total` are accepted only as a cross-check. See that class for
 * why a supplied total is refused rather than silently corrected.
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

class GenerateInvoicePdfTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
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
        return 'generate_invoice_pdf';
    }

    public function getName(): string
    {
        return 'Generate Invoice PDF';
    }

    public function getDescription(): string
    {
        return 'Render an itemised invoice as a PDF from the figures supplied: number, dates, recipient, line items, tax rate and currency. Totals are computed from the items in minor units; a supplied subtotal or total is checked against them and refused when it disagrees.';
    }

    public function getParametersSchema(): array
    {
        $sizes = $this->composer->supportedPageSizes();

        return array(
            'type' => 'object',
            'properties' => array(
                'invoice_number' => array(
                    'type' => 'string',
                    'description' => 'Invoice number or id, printed on the document.',
                ),
                'date' => array(
                    'type' => 'string',
                    'description' => 'Invoice date, YYYY-MM-DD. Defaults to today.',
                ),
                'due_date' => array(
                    'type' => 'string',
                    'description' => 'Payment due date, YYYY-MM-DD. Defaults to 30 days from today.',
                ),
                'bill_to' => array(
                    'type' => 'object',
                    'description' => 'Who is invoiced. A string, or an object with name/company/address/city/postcode/country/email/phone — every value given is printed.',
                ),
                'items' => array(
                    'type' => 'array',
                    'description' => 'Line items, at most ' . InvoiceDocument::MAX_ITEMS . '. A line amount is "amount" when given, otherwise quantity × rate.',
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
                    'description' => 'Tax as a percentage of the subtotal, e.g. 10 for 10%. Default 0.',
                    'minimum' => 0,
                    'maximum' => 100,
                ),
                'currency' => array(
                    'type' => 'string',
                    'description' => 'Three-letter ISO-4217 code, e.g. USD or KYD. Default USD.',
                    'pattern' => '^[A-Za-z]{3}$',
                ),
                'subtotal' => array(
                    'type' => 'number',
                    'description' => 'Optional cross-check. Refused when it disagrees with the items; never overrides them.',
                ),
                'total' => array(
                    'type' => 'number',
                    'description' => 'Optional cross-check across subtotal + tax. Refused when it disagrees.',
                ),
                'title' => array(
                    'type' => 'string',
                    'description' => 'Document title and filename stem. Default "Invoice {number}".',
                ),
                'page_size' => array(
                    'type' => 'string',
                    'description' => 'Page size. Case-insensitive.',
                    'enum' => array() === $sizes ? array('A4') : $sizes,
                    'default' => array() === $sizes ? 'A4' : $sizes[0],
                ),
                'orientation' => array(
                    'type' => 'string',
                    'description' => 'Page orientation. Default portrait.',
                    'enum' => array('portrait', 'landscape'),
                    'default' => 'portrait',
                ),
                'save_to_media' => array(
                    'type' => 'boolean',
                    'description' => 'Write the PDF to the file registry. Default true.',
                    'default' => true,
                ),
                'visibility' => array(
                    'type' => 'string',
                    'description' => 'Registry visibility for the stored file. Default public.',
                    'enum' => array(
                        FileVisibilityStoreInterface::VISIBILITY_PUBLIC,
                        FileVisibilityStoreInterface::VISIBILITY_PRIVATE,
                    ),
                    'default' => FileVisibilityStoreInterface::VISIBILITY_PUBLIC,
                ),
            ),
            'required' => array('invoice_number', 'items'),
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
        try {
            $invoice = $this->invoices->build($arguments);
        } catch (\InvalidArgumentException $e) {
            return $this->errors->validationFailed($e->getMessage());
        }

        if (!$this->composer->isAvailable()) {
            return $this->errors->create(
                'no_pdf_engine',
                'No PDF renderer is bound on this host. Configure the media worker (MEDIA_WORKER_URL) or install a PHP renderer.',
            );
        }

        $number = (string) $invoice['totals']['number'];
        $title = $this->stringParam($arguments, 'title');
        $document = array(
            'title' => '' === $title ? 'Invoice ' . $number : $title,
            'page_size' => $this->stringParam($arguments, 'page_size'),
            'orientation' => $this->stringParam($arguments, 'orientation', 'portrait'),
        );

        try {
            $payload = $this->composer->documentFromHtml($invoice['body'], $document, $arguments, $context);
        } catch (\InvalidArgumentException $e) {
            return $this->errors->validationFailed($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errors->create('pdf_failed', $e->getMessage());
        }

        return $this->success(
            sprintf('Created invoice %s as a PDF.', $number),
            array('invoice' => $invoice['totals']) + $payload,
        );
    }
}
