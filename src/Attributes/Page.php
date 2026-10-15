<?php

namespace Rekhu\Assistant\Attributes;

use Attribute;

/**
 * A label and a description for a page the assistant may open. Every named GET route is a page already; this only
 * tells the assistant what is on it, or hides it ("hidden: true").
 *
 *     #[Page('Invoices', help: 'All invoices of the account, with download and payment status')]
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Page
{
    public function __construct(
        public string $label,
        public string $help = '',
        public bool $hidden = false,
    ) {
    }
}
