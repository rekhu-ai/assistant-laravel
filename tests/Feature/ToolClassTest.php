<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Client\Request as Sent;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Rekhu\Assistant\Attributes\Tool as ToolAttribute;
use Rekhu\Assistant\Catalogue\Actions;
use Rekhu\Assistant\RekhuAssistant;
use Rekhu\Assistant\Runtime\Dispatcher;
use Rekhu\Assistant\Tests\TestCase;
use Rekhu\Assistant\Tool;

final class ToolClassTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RekhuAssistant::tools([UnpaidAttending::class, AdminsOnly::class]);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/api/transactions', [TransactionsController::class, 'index'])->name('api.transactions');
        $router->get('/api/branches', [TransactionsController::class, 'branches'])->name('api.branches');
    }

    public function test_a_tool_class_is_offered_with_its_input_and_as_it_authorizes(): void
    {
        $actions = Actions::for($this->user());

        $this->assertArrayHasKey('unpaid_attending', $actions);
        $this->assertArrayNotHasKey('admins_only', $actions);
        $this->assertSame('class', $actions['unpaid_attending']['source']);
        $this->assertSame('Checking who has not paid', $actions['unpaid_attending']['label']);
        $schema = json_decode(json_encode($actions['unpaid_attending']['schema']), true);
        $this->assertSame('integer', $schema['properties']['branch']['type']);
        $this->assertSame('The branch_id from list_branches', $schema['properties']['branch']['description']);
        $this->assertSame(['branch'], $schema['required']);
    }

    public function test_a_tool_class_validates_its_input_and_answers_with_the_user(): void
    {
        $action = Actions::for($this->user())['unpaid_attending'];
        $outer = Request::create('/rekhu/assistant/chat', 'POST');
        $outer->setUserResolver(fn () => $this->user());

        $this->assertSame(['ok' => false, 'error' => 'the input was refused', 'errors' => ['branch' => ['The branch field is required.']]],
            Dispatcher::run($action, [], $outer));
        $this->assertSame(['ok' => true, 'result' => [['child' => 'Sara', 'branch' => 4, 'asked_by' => 7]]],
            Dispatcher::run($action, ['branch' => 4], $outer));
    }

    public function test_the_attribute_shares_rules_describes_inputs_and_names_enum_values(): void
    {
        $actions = Actions::for($this->user());
        $schema = json_decode(json_encode($actions['list_transactions']['schema']), true);

        // the rules come from a shared form request, the meanings from "inputs" and from the enum itself
        $this->assertSame(['308', '301'], $schema['properties']['status']['enum']);
        $this->assertStringContainsString('values: 308 paid, 301 pending', $schema['properties']['status']['description']);
        $this->assertStringStartsWith('Paid date, YYYY-MM-DD', $schema['properties']['from']['description']);
        $this->assertSame(['id', 'name'], $actions['list_branches']['fields']);
    }

    public function test_fields_keep_the_result_short(): void
    {
        $outer = Request::create('/rekhu/assistant/chat', 'POST');
        $outer->setUserResolver(fn () => $this->user());

        $this->assertSame(['data' => [['id' => 1, 'name' => 'Olaya']], 'meta' => ['total' => 1]],
            Dispatcher::run(Actions::for($this->user())['list_branches'], [], $outer)['result']);
    }

    public function test_the_model_calls_a_tool_class_in_a_turn(): void
    {
        $call = $this->streamed([['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'unpaid_attending', 'input' => ['branch' => 4]]], 'tool_use');
        $done = $this->streamed([['type' => 'text', 'text' => 'Sara has not paid.']], 'end_turn');
        Http::fake(['rekhu.test/*' => Http::sequence()->push($call, 200, ['Content-Type' => 'text/event-stream'])->push($done, 200, ['Content-Type' => 'text/event-stream'])]);

        $this->actingAs($this->user())->withHeaders($this->headers())->post('/rekhu/assistant/chat', ['message' => 'Who has not paid in Olaya?'])->assertOk()->streamedContent();

        Http::assertSent(fn (Sent $r) => str_contains(json_encode($r['messages'] ?? []), '\"child\":\"Sara\"'));
    }

    /** A streamed model answer with these content blocks. */
    private function streamed(array $blocks, string $stop): string
    {
        $events = [['type' => 'message_start', 'message' => ['content' => []]]];
        foreach ($blocks as $i => $block) {
            if ($block['type'] === 'text') {
                $events[] = ['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'text', 'text' => '']];
                $events[] = ['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'text_delta', 'text' => $block['text']]];
            } else {
                $events[] = ['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'tool_use', 'id' => $block['id'], 'name' => $block['name'], 'input' => []]];
                $events[] = ['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'input_json_delta', 'partial_json' => json_encode($block['input'])]];
            }
            $events[] = ['type' => 'content_block_stop', 'index' => $i];
        }
        $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => $stop]];
        $events[] = ['type' => 'message_stop'];

        return implode('', array_map(fn ($e) => 'event: '.$e['type']."\ndata: ".json_encode($e)."\n\n", $events));
    }
}

class UnpaidAttending extends Tool
{
    protected string $description = 'Children who attend a branch this month and have not paid';

    protected array $rules = ['branch' => 'required|integer'];

    protected array $inputs = ['branch' => 'The branch_id from list_branches'];

    protected string|array|null $label = ['en' => 'Checking who has not paid'];

    public function handle(Request $request): array
    {
        return [['child' => 'Sara', 'branch' => $request->integer('branch'), 'asked_by' => $request->user()->getAuthIdentifier()]];
    }
}

class AdminsOnly extends Tool
{
    protected string $description = 'Something only admins may use';

    public function authorize(?Authenticatable $user): bool
    {
        return false;
    }

    public function handle(): array
    {
        return [];
    }
}

enum Status: int
{
    case Paid = 308;
    case Pending = 301;
}

class TransactionFilters extends FormRequest
{
    public function rules(): array
    {
        return ['status' => ['nullable', Rule::enum(Status::class)], 'from' => 'nullable|date'];
    }
}

class TransactionsController extends Controller
{
    #[ToolAttribute('The transactions', name: 'list_transactions', rules: TransactionFilters::class, inputs: ['from' => 'Paid date, YYYY-MM-DD'])]
    public function index()
    {
        return response()->json([]);
    }

    #[ToolAttribute('The branches', name: 'list_branches', fields: ['id', 'name'])]
    public function branches()
    {
        return response()->json(['data' => [['id' => 1, 'name' => 'Olaya', 'reviews' => [['star' => 5]]]], 'meta' => ['total' => 1]]);
    }
}
