<?php

namespace Rekhu\Assistant;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Rekhu\Assistant\Runtime\Attachments;
use Rekhu\Assistant\Runtime\Dispatcher;
use Rekhu\Assistant\Runtime\ToolContext;

/**
 * An action written for the assistant only: a short list, a summary, a figure, without a route or a controller. Put it
 * in app/Assistant/Tools (php artisan make:assistant-tool OverdueInvoices) and it is offered by itself.
 *
 *     class OverdueInvoices extends Tool
 *     {
 *         protected string $description = 'Invoices past their due date and not paid: number, customer, amount and days overdue';
 *
 *         protected array $rules = ['days' => 'nullable|integer|min:1'];
 *
 *         public function authorize(?Authenticatable $user): bool
 *         {
 *             return $user?->can('view-invoices') ?? false;
 *         }
 *
 *         public function handle(Request $request): array
 *         {
 *             return Invoice::unpaid()->where('due_at', '<', now()->subDays($request->integer('days')))->get(['number', 'customer_name', 'total'])->all();
 *         }
 *     }
 *
 * handle() gets the input as the request (validated against $rules first; $request->user() is the user) and anything
 * else it asks for from the container. It returns an array, a model or collection, a JSON resource or response, or a
 * download, which the user then gets as a button. A tool that changes something sets $confirm, so the user clicks
 * Confirm first.
 *
 * A tool can do several steps in a row, the way a person in the app would:
 *
 *     $price = $this->route('cart.price', ['product' => $id, 'quantity' => 1000]);     // the app's own route, as the user
 *     $this->step('Comparing 120 offers…');                                            // the user sees how far it is
 *     $this->card('offers', ['rows' => $firstOffers], 'First offers');                  // and the first results early
 *     $this->usage('marketplace_quote', count($offers));                               // billed with the answer
 *     $file = $this->attachment($request->input('artwork'));                           // a file the user attached
 */
abstract class Tool
{
    /** What it does, written for the assistant: what comes back, what the input means, when to use it. Never shown. */
    protected string $description = '';

    /** The tool's name; by default the class name in snake case (OverdueInvoices → overdue_invoices). */
    protected ?string $name = null;

    /** @var array<string, mixed> the input, as validation rules */
    protected array $rules = [];

    /** @var array<string, string> what an input means, in a few words */
    protected array $inputs = [];

    /** @var list<string> keep only these fields of each record it returns */
    protected array $fields = [];

    /** What the user reads while it runs: a text or one per language. */
    protected string|array|null $label = null;

    /** The user clicks Confirm before it runs. */
    protected bool $confirm = false;

    /** Offered to visitors of the public chat too. */
    protected bool $guests = false;

    private ?ToolContext $context = null;

    /** @internal set by the package before handle() */
    public function bind(ToolContext $context): void
    {
        $this->context = $context;
    }

    /**
     * Runs a route of the app by its name, as the chat's user: its middleware, its permissions, its validation, exactly as
     * an action does. ['ok' => true, 'result' => …] or ['ok' => false, 'error' => …, 'errors'?]. A file the user attached
     * goes in by its id (att_…).
     */
    protected function route(string $name, array $input = []): array
    {
        $route = app('router')->getRoutes()->getByName($name);
        if (! $route) {
            return ['ok' => false, 'error' => "the app has no route named {$name}"];
        }
        if (! $this->context) {
            return ['ok' => false, 'error' => 'a route can only be called while the tool runs for a user'];
        }

        return Dispatcher::callRoute($route, $input, $this->context->request, $this->context->conversation);
    }

    /** The conversation the tool runs in: its id, its chat of its own (scope) and the files attached in it. */
    protected function conversation(): array
    {
        $c = $this->context?->conversation;

        return ['id' => $c?->id, 'scope' => $c?->scope, 'attachments' => array_map(fn ($a) => ['id' => $a['id'], 'name' => $a['name'], 'type' => $a['type'], 'size' => $a['size'], 'info' => $a['info'] ?? []], array_values($c?->attachments ?? []))];
    }

    /** A file the user attached in this conversation, by its id, as an uploaded file; null when there is none. */
    protected function attachment(?string $id): ?UploadedFile
    {
        $c = $this->context?->conversation;

        return $c && $id ? Attachments::upload($c, $id) : null;
    }

    /** What the user reads while the tool works ("Comparing 120 offers…"): a text, or one per language. */
    protected function step(string|array $label): void
    {
        $this->context?->emit(['type' => 'step', 'label' => is_array($label) ? ($label[app()->getLocale()] ?? $label['en'] ?? reset($label)) : $label]);
    }

    /** A card shown under the answer right away, while the tool goes on; the assistant only reads the final result. */
    protected function card(string $kind, array $data = [], ?string $title = null): void
    {
        $card = Card::make($kind, $data, $title);
        $this->context?->emit(['type' => 'card', 'kind' => $card['kind'], 'data' => (object) $card['data']] + (isset($card['title']) ? ['title' => $card['title']] : []));
    }

    /** What this run used of its own (a preflight check, an image improved): in AnswerCompleted's actionUsage. */
    protected function usage(string $kind, float|int $units = 1, array $meta = []): void
    {
        if ($this->context) {
            $this->context->usage[] = Usage::make($kind, $units, $meta);
        }
    }

    /** Whether the user (null: a visitor) is offered this tool. Signed-in users by default, visitors when $guests is on. */
    public function authorize(?Authenticatable $user): bool
    {
        return $user !== null || $this->guests;
    }

    public function name(): string
    {
        return $this->name ?: Str::snake(class_basename(static::class));
    }

    public function description(): string
    {
        return $this->description;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->rules;
    }

    /** @return array<string, string> */
    public function inputs(): array
    {
        return $this->inputs;
    }

    /** @return list<string> */
    public function fields(): array
    {
        return $this->fields;
    }

    public function label(): string|array|null
    {
        return $this->label;
    }

    public function confirms(): bool
    {
        return $this->confirm;
    }

    public function forGuests(): bool
    {
        return $this->guests;
    }
}
