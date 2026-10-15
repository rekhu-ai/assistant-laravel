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
 * controller method's signature, or the rules given on the attribute. The route's own parameters ({order}) come first.
 */
class Schema
{
    public static function of(Route $route, array $rules, ?Authenticatable $user): array
    {
        $rules = $rules ?: self::formRequestRules($route, $user);
        $properties = [];
        $required = [];
        foreach ($route->parameterNames() as $name) {
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
            $schema = self::field($list);
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
            if (! $type instanceof ReflectionNamedType || $type->isBuiltin() || ! is_subclass_of($type->getName(), FormRequest::class)) {
                continue;
            }
            try {
                $request = $type->getName()::create('/', 'POST');
                $request->setContainer(app())->setUserResolver(fn () => $user);
                $rules = method_exists($request, 'rules') ? app()->call([$request, 'rules']) : [];

                return is_array($rules) ? $rules : [];
            } catch (Throwable) {
                return [];
            }
        }

        return [];
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

    private static function field(array $rules): array
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
            if ($name === 'in' && $argument !== null) {
                $schema['enum'] = array_map(fn ($v) => trim($v, '"'), str_getcsv($argument, ',', '"', '\\'));
            }
            if (in_array($name, ['max', 'min', 'between', 'size', 'exists', 'unique', 'regex', 'after', 'before', 'confirmed', 'required_if', 'required_with'], true)) {
                $notes[] = $rule;
            }
        }
        if ($notes) {
            $schema['description'] = 'Rules: '.implode(', ', $notes);
        }

        return $schema;
    }
}
