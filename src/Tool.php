<?php

namespace Rekhu\Assistant;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

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
