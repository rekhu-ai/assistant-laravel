<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Runtime;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Rekhu\Assistant\RekhuAssistant;
use Rekhu\Core\Exceptions\RekhuException;
use Rekhu\Core\Rekhu;

/**
 * Rekhu's knowers as tools of the assistant, when the app has rekhu/core with knowers installed (php artisan rekhu:core
 * --install=seshat): Seshat checks and fixes the print files the user attaches, Rekhu Machines knows printing machines.
 * They run for the user's tenant, are kept in rekhu/core's usage ledger, and show their own cards: the check of a file
 * with a button to fix it, the fixed file as a download.
 */
class Knowers
{
    private const ORDER = [
        'type' => 'object',
        'description' => 'What was ordered: ask the user when it is not known',
        'properties' => [
            'width_mm' => ['type' => 'number', 'description' => 'Finished width in mm'],
            'height_mm' => ['type' => 'number', 'description' => 'Finished height in mm'],
            'bleed_mm' => ['type' => 'number', 'description' => 'Bleed in mm, 3 when not said'],
            'pages' => ['type' => 'integer'],
            'colour' => ['type' => 'string', 'enum' => ['cmyk', 'any']],
        ],
    ];

    /** The knowers this app installed that the assistant can use; none without rekhu/core or when turned off. */
    public static function installed(bool $visitor): array
    {
        if (! class_exists(Rekhu::class) || ! config('rekhu-assistant.knowers.enabled', true) || ($visitor && ! config('rekhu-assistant.guests.knowers', false))) {
            return [];
        }

        return array_values(array_filter(['seshat', 'machines'], fn ($k) => Rekhu::installed($k)));
    }

    /** @return list<array<string, mixed>> */
    public static function tools(array $installed): array
    {
        $tools = [];
        if (in_array('seshat', $installed, true)) {
            $tools[] = ['name' => 'seshat_check', 'description' => 'Seshat, the print-file expert: checks a file the user attached (its att_ id) against what was ordered: size, bleed, resolution, colours, fonts, ink, pages. Each finding has a decision: fix (Seshat can repair it), ask (the user chooses), refuse, note. The user sees a card with the result.', 'input_schema' => [
                'type' => 'object', 'required' => ['file'],
                'properties' => ['file' => ['type' => 'string', 'description' => 'The id (att_…) of the attached file'], 'order' => self::ORDER],
            ]];
            $tools[] = ['name' => 'seshat_fix', 'description' => 'Seshat repairs a checked file: "auto" makes every fix it decided on, or name the fixes with the user\'s choices (scale_to_size with option scale, fit or fill; add_bleed; set_trim_box; convert_cmyk; embed_fonts; picture_to_pdf with option fill or fit). The user gets the fixed file as a download.', 'input_schema' => [
                'type' => 'object', 'required' => ['file_id'],
                'properties' => [
                    'file_id' => ['type' => 'string', 'description' => 'The file_id seshat_check gave'],
                    'fixes' => ['description' => '"auto", or a list of {fix, option?}', 'anyOf' => [['type' => 'string', 'enum' => ['auto']], ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['fix' => ['type' => 'string'], 'option' => ['type' => 'string']], 'required' => ['fix']]]]],
                    'order' => self::ORDER,
                ],
            ]];
        }
        if (in_array('machines', $installed, true)) {
            $machine = ['type' => 'object', 'description' => 'The machine: type, method (offset, digital_toner, digital_liquid, inkjet_sheet, large_format, guillotine), sheet_max_mm [w, h], gsm [min, max], colour_units, speed, makeready_minutes…'];
            $tools[] = ['name' => 'machines_identify', 'description' => 'Rekhu Machines: which printing or finishing machine a description is ("a four-colour B2 offset press with coater"), with its library id, and what to ask when unsure.', 'input_schema' => ['type' => 'object', 'required' => ['text'], 'properties' => ['text' => ['type' => 'string']]]];
            $tools[] = ['name' => 'machines_profile', 'description' => 'Rekhu Machines: the whole profile of a library model (from machines_identify), every value with where it came from.', 'input_schema' => ['type' => 'object', 'required' => ['model'], 'properties' => ['model' => ['type' => 'string'], 'known' => ['type' => 'object']]]];
            $tools[] = ['name' => 'machines_check', 'description' => 'Rekhu Machines: values of a machine that cannot be right (speed above the press, a sheet it cannot take) and what a price needs.', 'input_schema' => ['type' => 'object', 'required' => ['machine'], 'properties' => ['machine' => $machine, 'model' => ['type' => 'string', 'description' => 'The library id, when known']]]];
            $tools[] = ['name' => 'machines_choose', 'description' => 'Rekhu Machines: the machines for a job, best first, with why (up on the sheet, passes, hours); the others with why not.', 'input_schema' => ['type' => 'object', 'required' => ['job', 'machines'], 'properties' => [
                'job' => ['type' => 'object', 'properties' => ['width_mm' => ['type' => 'number'], 'height_mm' => ['type' => 'number'], 'quantity' => ['type' => 'integer'], 'colours_front' => ['type' => 'integer'], 'colours_back' => ['type' => 'integer'], 'gsm' => ['type' => 'number']], 'required' => ['width_mm', 'height_mm', 'quantity']],
                'machines' => ['type' => 'array', 'items' => $machine],
            ]]];
            $tools[] = ['name' => 'machines_explain', 'description' => 'Rekhu Machines: a question about a printing machine, answered as a print expert.', 'input_schema' => ['type' => 'object', 'required' => ['question'], 'properties' => ['question' => ['type' => 'string'], 'machine' => $machine, 'model' => ['type' => 'string']]]];
        }

        return $tools;
    }

    public static function handles(string $name, array $installed): bool
    {
        [$knower] = explode('_', $name, 2) + [null];

        return in_array($knower, $installed, true) && in_array($name, ['seshat_check', 'seshat_fix', 'machines_identify', 'machines_profile', 'machines_check', 'machines_choose', 'machines_explain'], true);
    }

    /** One knower tool for the user, with its cards; the result is what the model reads. */
    public static function call(string $name, array $input, ?Authenticatable $user, array $tenant, Conversation $conversation, Closure $emit, ?string $locale = null): array
    {
        self::$branded = RekhuAssistant::brand($tenant)['rekhu'];
        // the cards speak the user's language when the package has texts for it, else the app's
        $short = strtolower(substr((string) $locale, 0, 2));
        $previous = app()->getLocale();
        if ($short !== '' && is_dir(__DIR__.'/../../resources/lang/'.$short)) {
            app()->setLocale($short);
        }
        try {
            return match (true) {
                $name === 'seshat_check' => self::check($input, $user, $tenant, $conversation, $emit),
                $name === 'seshat_fix' => self::fix($input, $user, $tenant, $emit),
                default => self::machines(substr($name, 9), $input, $user, $tenant, $emit),
            };
        } catch (RekhuException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            app()->setLocale($previous);
        }
    }

    private static function check(array $input, ?Authenticatable $user, array $tenant, Conversation $conversation, Closure $emit): array
    {
        $file = Attachments::read($conversation, (string) ($input['file'] ?? ''));
        if (! $file) {
            return ['ok' => false, 'error' => 'no attached file with that id in this conversation; ask the user to attach it'];
        }
        $emit(['type' => 'step', 'label' => self::say('seshat_check')]);
        $order = (array) ($input['order'] ?? []);
        $seshat = Rekhu::seshat()->for($user)->forTenant((string) $tenant['id'], (string) $tenant['name']);
        $result = $seshat->check($file['bytes'], $order, name: $file['name']);
        $findings = (array) ($result['findings'] ?? []);
        $fixable = array_values(array_filter($findings, fn ($f) => ($f['decision'] ?? '') === 'fix'));
        $emit(['type' => 'card', 'kind' => 'seshat', 'title' => $file['name'], 'data' => (object) [
            'status' => $result['status'] ?? null,
            'text' => __('rekhu-assistant::messages.seshat_'.($result['status'] ?? 'ok')),
            'image' => self::proof($seshat, $result['file_id'] ?? null),
            'rows' => array_map(fn ($f) => ['label' => self::label((string) $f['code']), 'value' => self::say('seshat_decision_'.($f['decision'] ?? 'note'))], array_slice($findings, 0, 12)),
            'buttons' => $fixable ? [['label' => __('rekhu-assistant::messages.seshat_fix_button'), 'message' => self::say('seshat_fix_message', ['name' => $file['name']])]] : [],
        ]]);

        return ['ok' => true, 'result' => [
            'file_id' => $result['file_id'] ?? null, 'status' => $result['status'] ?? null, 'file' => $result['file'] ?? null, 'findings' => $findings, 'order' => $result['order'] ?? $order,
            'note' => 'The user sees a card with these findings. Explain them briefly in plain words: what Seshat will fix, and ask what is the user\'s to choose (each "ask"). Fix when the user asks for it or agrees: the fixes do not depend on the open questions, which stay open and are asked again afterwards. '
                .'Seshat repairs the file as a whole (bleed, size, trim box, colours, fonts); it never changes the content: moving text or replacing a picture is for the user to do in their own design file, so never offer it.',
        ]];
    }

    private static function fix(array $input, ?Authenticatable $user, array $tenant, Closure $emit): array
    {
        $emit(['type' => 'step', 'label' => self::say('seshat_fix')]);
        $seshat = Rekhu::seshat()->for($user)->forTenant((string) $tenant['id'], (string) $tenant['name']);
        $result = $seshat->fix((string) ($input['file_id'] ?? ''), $input['fixes'] ?? 'auto', (array) ($input['order'] ?? []));
        if (empty($result['applied'])) {
            return ['ok' => true, 'result' => ['note' => 'Nothing needed fixing.']];
        }
        $kept = Files::keep($seshat->download((string) $result['file_id']), (string) ($result['name'] ?? 'print.pdf'), 'application/pdf');
        if (! $kept['ok']) {
            return $kept;
        }
        $emit(['type' => 'card', 'kind' => 'seshat', 'title' => __('rekhu-assistant::messages.seshat_proof'), 'data' => (object) array_filter([
            'status' => $result['check']['status'] ?? null,
            'text' => __('rekhu-assistant::messages.seshat_'.($result['check']['status'] ?? 'ok')),
            'image' => self::proof($seshat, (string) $result['file_id']),
            'rows' => array_map(fn ($a) => ['label' => self::label((string) $a['fix']), 'value' => __('rekhu-assistant::messages.seshat_done')], (array) $result['applied']),
        ])]);
        $emit(['type' => 'card', 'kind' => 'file', 'title' => __('rekhu-assistant::messages.seshat_fixed'), 'name' => $kept['result']['name'], 'url' => $kept['result']['url'], 'size' => $kept['result']['size']]);
        $left = array_values(array_filter((array) ($result['check']['findings'] ?? []), fn ($f) => in_array($f['decision'] ?? '', ['ask', 'refuse'], true)));

        return ['ok' => true, 'result' => [
            'file_id' => $result['file_id'], 'applied' => $result['applied'], 'status_after' => $result['check']['status'] ?? null, 'still_to_decide' => $left,
            'note' => 'The user has a download button for the fixed file. Say what was done in plain words, and what is still theirs to decide or to change in their own design file (Seshat does not move text or replace pictures).',
        ]];
    }

    private static function machines(string $action, array $input, ?Authenticatable $user, array $tenant, Closure $emit): array
    {
        $emit(['type' => 'step', 'label' => __('rekhu-assistant::messages.machines')]);
        $machines = Rekhu::machines()->for($user)->forTenant((string) $tenant['id'], (string) $tenant['name']);
        $result = match ($action) {
            'identify' => $machines->identify((string) ($input['text'] ?? '')),
            'profile' => $machines->profile((string) ($input['model'] ?? ''), (array) ($input['known'] ?? [])),
            'check' => $machines->check((array) ($input['machine'] ?? []), isset($input['model']) ? (string) $input['model'] : null),
            'choose' => $machines->choose((array) ($input['job'] ?? []), (array) ($input['machines'] ?? [])),
            'explain' => $machines->explain((string) ($input['question'] ?? ''), (array) ($input['machine'] ?? []), isset($input['model']) ? (string) $input['model'] : null),
        };
        unset($result['meta']);

        return ['ok' => true, 'result' => $result];
    }

    /** A text of the cards; white-label (brand.rekhu off), the one that names no maker when the package has it. */
    private static function say(string $key, array $replace = []): string
    {
        $plain = 'rekhu-assistant::messages.'.$key.'_plain';
        if (! self::$branded) {
            $text = __($plain, $replace);
            if ($text !== $plain) {
                return (string) $text;
            }
        }

        return (string) __('rekhu-assistant::messages.'.$key, $replace);
    }

    /** Whether the tenant of this call shows Rekhu's names (brand.rekhu); set per call. */
    private static bool $branded = true;

    /** The first page with its trim, bleed and safe zone, as a data: URL for the card; none when it cannot be drawn. */
    private static function proof($seshat, ?string $fileId): ?string
    {
        if (! $fileId || ! config('rekhu-assistant.knowers.proof', true)) {
            return null;
        }
        try {
            return $seshat->preview($fileId, 1, 700)['image'] ?? null;
        } catch (RekhuException) {
            return null;
        }
    }

    /** A finding's code as words, from the package's texts; the code itself when there is no text for it. */
    private static function label(string $code): string
    {
        $key = 'rekhu-assistant::messages.seshat_codes.'.$code;
        $text = __($key);

        return $text === $key ? str_replace('_', ' ', $code) : (string) $text;
    }
}
