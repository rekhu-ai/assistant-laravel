<?php

namespace Rekhu\Assistant\Attributes;

use Attribute;

/**
 * Marks a controller action the assistant may perform for the signed-in user. The route runs exactly as when the user
 * submits the form: its middleware, the form request's authorize() and rules, policies and events all apply.
 *
 *     #[Tool('Reorder a past order: the same products and quantities, delivered to the same address')]
 *     public function reorder(ReorderRequest $request, Order $order) { … }
 *
 * The input is read from the form request's rules; give "rules" when the action validates inline. "confirm" makes the
 * user click to confirm first (always so for DELETE routes). "guests" offers it in the public chat too, to visitors who
 * are not signed in; its route must then work without login. "label" is what the user reads while it runs and on its
 * cards, short and in their words, a text or one per language (['en' => 'Looking up your orders', 'nl' => …]); the
 * description is for the assistant and is never shown.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Tool
{
    public function __construct(
        public string $description,
        public ?string $name = null,
        public ?bool $confirm = null,
        public array $rules = [],
        public bool $guests = false,
        public string|array|null $label = null,
    ) {}
}
