<?php

namespace Rekhu\Assistant\Catalogue;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\In;
use ReflectionNamedType;
use Throwable;

/**
 * The input of an action as JSON schema, read from the validation rules the app already has: the form request in the
 * controller method's signature, or the rules given on the attribute (an array, or a class whose rules() several
 * actions share). The route's own parameters ({order}) come first. "inputs" says what a field means, in a few words.
 */
class Schema
{
    /** @param  array<string, mixed>|class-string  $rules  @param  array<string, string>  $inputs */
    public static function of(?Route $route, array|string $rules, ?Authenticatable $user, array $inputs = []): array
    {
        $rules = is_string($rules) ? self::classRules($rules, $user) : $rules;
        $rules = $rules ?: ($route ? self::formRequestRules($route, $user) : []);
        $properties = [];
        $required = [];
        foreach ($route?->parameterNames() ?? [] as $name) {
            $properties[$name] = ['type' => 'string', 'description' => self::parameterHelp($route, $name)];
            if (! str_contains($route->uri(), '{'.$name.'?}')) {
                $required[] = $name;
            }
        }
        // nested rules ("lines.*.quantity") are folded into their parent
        ksort($rules);
        foreach ($rules as $field => $rule) {
            $parts = explode('.', (string) $field);
            $list = self::rules($rule);
            $schema = self::field($list, $inputs[$field] ?? null);
            if (count($parts) === 1) {
                $properties[$field] = array_merge($properties[$field] ?? [], $schema);
                if (in_array('required', $list, true)) {
                    $required[] = $field;
                }

                continue;
            }
            $root = $parts[0];
            $properties[$root] ??= ['type' => 'array'];
            if ($parts[1] === '*' && count($parts) === 2) {
                $properties[$root]['type'] = 'array';
                $properties[$root]['items'] = $schema;
            } elseif ($parts[1] === '*' && count($parts) === 3) {
                $properties[$root]['type'] = 'array';
                $properties[$root]['items']['type'] = 'object';
                $properties[$root]['items']['properties'][$parts[2]] = $schema;
                if (in_array('required', $list, true)) {
                    $properties[$root]['items']['required'][] = $parts[2];
                }
            } elseif (count($parts) === 2) {
                $properties[$root]['type'] = 'object';
                $properties[$root]['properties'][$parts[1]] = $schema;
            }
        }

        // an input described but not in the rules (a filter the controller reads itself) is still offered
        foreach ($inputs as $field => $text) {
            if (! str_contains((string) $field, '.') && ! isset($properties[$field])) {
                $properties[$field] = ['type' => 'string', 'description' => (string) $text];
            } elseif (isset($properties[$field]) && ! isset($rules[$field])) {
                $properties[$field]['description'] = trim($text.' '.($properties[$field]['description'] ?? ''));
            }
        }

        return array_filter([
            'type' => 'object',
            'properties' => (object) $properties,
            'required' => array_values(array_unique($required)) ?: null,
        ], fn ($v) => $v !== null);
    }

    /** What goes in {name}: for a bound model its route key ("the order's id"), so the model does not send a display number. */
    private static function parameterHelp(Route $route, string $name): string
    {
        $field = $route->bindingFieldFor($name);
        foreach (Routes::method($route)?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if ($parameter->getName() === $name && $type instanceof ReflectionNamedType && is_subclass_of($type->getName(), Model::class)) {
                $field ??= (new ($type->getName()))->getRouteKeyName();
                $model = Str::snake(class_basename($type->getName()), ' ');

                return "The {$model}'s {$field} (the \"{$field}\" field of the {$model}, not a number shown to people)";
            }
        }

        return $field ? "The {$name}'s {$field}" : "The {$name} in the address";
    }

    /** The rules of the form request the controller method asks for, as the user would get them; none when unknown. */
    public static function formRequestRules(Route $route, ?Authenticatable $user): array
    {
        $method = Routes::method($route);
        foreach ($method?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && is_subclass_of($type->getName(), FormRequest::class)) {
                return self::classRules($type->getName(), $user);
            }
        }

        return [];
    }

    /** The rules() of a form request, or of any class that has them, as the user would get them; none when unknown. */
    public static function classRules(string $class, ?Authenticatable $user): array
    {
        try {
            if (is_subclass_of($class, FormRequest::class)) {
                $object = $class::create('/', 'POST');
                $object->setContainer(app())->setUserResolver(fn () => $user);
            } else {
                $object = class_exists($class) ? app($class) : null;
            }
            $rules = $object && method_exists($object, 'rules') ? app()->call([$object, 'rules']) : [];

            return is_array($rules) ? $rules : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** One field's rules as a list of strings ("required", "max:20", "in:a,b"); objects become their string form. */
    private static function rules(mixed $rule): array
    {
        $list = is_string($rule) ? explode('|', $rule) : (array) $rule;
        $out = [];
        foreach ($list as $item) {
            if (is_string($item)) {
                $out[] = $item;
            } elseif ($item instanceof In) {
                $out[] = (string) $item;
            } elseif ($item instanceof Enum) {
                $out[] = self::enumRule($item);
                $out[] = self::enumMeaning($item);
            }
        }

        return array_values(array_filter($out));
    }

    private static function enumRule(Enum $rule): ?string
    {
        try {
            $type = (fn () => $this->type)->call($rule);
            if (enum_exists($type) && method_exists($type, 'cases')) {
                return 'in:'.implode(',', array_map(fn ($case) => $case->value ?? $case->name, $type::cases()));
            }
        } catch (Throwable) {
        }

        return null;
    }

    /** "values: 308 paid, 301 pending" for a backed enum, from each case's label() or its name; null when the values are the names. */
    private static function enumMeaning(Enum $rule): ?string
    {
        try {
            $type = (fn () => $this->type)->call($rule);
            if (! enum_exists($type) || ! is_subclass_of($type, \BackedEnum::class)) {
                return null;
            }
            $values = array_map(function ($case) {
                $label = method_exists($case, 'label') ? (string) $case->label() : Str::of($case->name)->snake(' ')->lower();

                return (string) $case->value === $label ? null : $case->value.' '.$label;
            }, $type::cases());

            return array_filter($values) ? 'values:'.implode(', ', array_filter($values)) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function field(array $rules, ?string $meaning = null): array
    {
        $schema = ['type' => 'string'];
        $notes = [];
        foreach ($rules as $rule) {
            [$name, $argument] = array_pad(explode(':', $rule, 2), 2, null);
            match ($name) {
                'integer', 'int' => $schema['type'] = 'integer',
                'numeric', 'decimal' => $schema['type'] = 'number',
                'boolean', 'bool', 'accepted', 'declined' => $schema['type'] = 'boolean',
                'array', 'list' => $schema['type'] = 'array',
                'email' => $schema['format'] = 'email',
                'url', 'active_url' => $schema['format'] = 'uri',
                'date', 'date_format' => $schema['format'] = 'date',
                'uuid' => $schema['format'] = 'uuid',
                'nullable' => $notes[] = 'may be empty',
                default => null,
            };
            if ($name === 'values' && $argument !== null) {
                $notes[] = 'values: '.$argument;
            }
            if ($name === 'in' && $argument !== null) {
                $schema['enum'] = array_map(fn ($v) => trim($v, '"'), str_getcsv($argument, ',', '"', '\\'));
            }
            if (in_array($name, ['max', 'min', 'between', 'size', 'exists', 'unique', 'regex', 'after', 'before', 'confirmed', 'required_if', 'required_with'], true)) {
                $notes[] = $rule;
            }
        }
        $description = trim(($meaning ?? '').($notes ? ($meaning ? ' ' : '').'Rules: '.implode(', ', $notes) : ''));
        if ($description !== '') {
            $schema['description'] = $description;
        }

        return $schema;
    }
}
