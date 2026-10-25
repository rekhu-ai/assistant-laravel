<?php

namespace Rekhu\Assistant\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use ReflectionMethod;
use ReflectionNamedType;
use Rekhu\Assistant\Catalogue\Actions;
use Rekhu\Assistant\Catalogue\Pages;
use Rekhu\Assistant\Catalogue\Routes;
use Rekhu\Assistant\RekhuAssistant;
use Rekhu\Assistant\Runtime\Help;
use Rekhu\Assistant\Runtime\Turn;
use Rekhu\Assistant\Support\Rehearsal;
use Throwable;

/**
 * php artisan rekhu:assistant:check --user=12 --locale=ar: what makes the assistant answer badly, found before users
 * do. Every action's description and inputs, records looked up by another key than their id, and with --user each
 * reading action run as that user: refused, too long to read whole, or a list without paging.
 */
class CheckCommand extends Command
{
    protected $signature = 'rekhu:assistant:check
        {--user= : the id of a user to check and run the actions as}
        {--locale= : the language to run them in, such as ar}
        {--no-run : only read the actions, do not run any}';

    protected $description = 'Find what makes the assistant answer badly: unclear actions, results it cannot read whole, lists without paging';

    private int $problems = 0;

    private int $hints = 0;

    public function handle(): int
    {
        $user = Rehearsal::user($this->option('user'));
        if ($this->option('user') && ! $user) {
            $this->error('No user '.$this->option('user').'.');

            return self::FAILURE;
        }
        $run = $user && ! $this->option('no-run');
        $actions = Actions::for($user, all: ! $user);
        $this->line('<options=bold>Checking '.count($actions).' actions</>'.($user ? ' as user '.$user->getAuthIdentifier() : '').($this->option('locale') ? ', in '.$this->option('locale') : ''));

        foreach ($actions as $name => $action) {
            $found = [...$this->setup($action), ...($run ? $this->result($action, $user) : [])];
            if (! $found) {
                $this->line("  <fg=green>✓</> $name");

                continue;
            }
            $this->line("  <fg=yellow>!</> <options=bold>$name</>");
            foreach ($found as [$level, $text]) {
                $level === 'problem' ? $this->problems++ : $this->hints++;
                $this->line('      '.($level === 'problem' ? '<fg=red>problem</>' : '<fg=yellow>hint</>   ').' '.$text);
            }
        }

        $this->newLine();
        if (! Pages::for($user)) {
            $this->line('  <fg=yellow>hint</>    No pages to open: list your front end\'s pages (pages.frontend.list or RekhuAssistant::pagesUsing()).');
            $this->hints++;
        }
        if (! Help::topics()) {
            $this->line('  <fg=yellow>hint</>    No help topics in '.str_replace(base_path().'/', '', RekhuAssistant::helpPath()).': how-to questions are answered best from them.');
            $this->hints++;
        }
        if (! $user) {
            $this->line('  Give --user=ID to also run the reading actions as that user and check what comes back.');
        }
        $this->line('  Run it in every language your users chat in (--locale=…): records can be looked up differently per language.');
        $this->newLine();
        $this->line($this->problems ? "<fg=red>{$this->problems} problems</>, {$this->hints} hints." : "<fg=green>No problems</>, {$this->hints} hints.");
        $this->line('Run one action and see what the assistant reads: php artisan rekhu:assistant:try <name> --user=ID');

        return $this->problems ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<array{string, string}> what is unclear in how the action is described */
    private function setup(array $action): array
    {
        $found = [];
        $description = trim((string) $action['description']);
        if (mb_strlen($description) < 25) {
            $found[] = ['problem', 'The description is too short to tell the assistant what the action does and returns.'];
        } elseif (mb_strlen($description) > 700) {
            $found[] = ['hint', 'The description is '.mb_strlen($description).' characters: move what each input means to "inputs" and keep it to what the action does.'];
        }
        $route = $action['route'] ?? null;
        $parameters = $route instanceof Route ? $route->parameterNames() : [];
        $unexplained = [];
        foreach ((array) ($action['schema']['properties'] ?? []) as $field => $schema) {
            $meaning = trim((string) (((array) $schema)['description'] ?? ''));
            if (! in_array($field, $parameters, true) && ($meaning === '' || str_starts_with($meaning, 'Rules:')) && empty(((array) $schema)['enum'])) {
                $unexplained[] = $field;
            }
        }
        if ($unexplained) {
            $found[] = ['hint', 'Inputs without a meaning: '.implode(', ', $unexplained).'. Say in a few words what each one is, with inputs: [...].'];
        }
        if ($route instanceof Route) {
            foreach ($this->lookups($route) as $parameter => $how) {
                $found[] = ['hint', "{{$parameter}} is looked up $how. Make sure the action that lists them gives the assistant that value, and calls it the id."];
            }
        }

        return $found;
    }

    /** @return array<string, string> route parameters bound to a model by something else than its primary key */
    private function lookups(Route $route): array
    {
        $out = [];
        foreach (Routes::method($route)?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if (! $type instanceof ReflectionNamedType || ! is_subclass_of($type->getName(), Model::class) || ! in_array($parameter->getName(), $route->parameterNames(), true)) {
                continue;
            }
            $class = $type->getName();
            $model = new $class;
            $field = $route->bindingFieldFor($parameter->getName()) ?? $model->getRouteKeyName();
            $custom = (new ReflectionMethod($class, 'resolveRouteBinding'))->getDeclaringClass()->getName() !== Model::class;
            if ($field !== $model->getKeyName()) {
                $out[$parameter->getName()] = 'by its '.$field.', not by '.$model->getKeyName().($custom ? ', and '.class_basename($class).'::resolveRouteBinding() may add conditions of its own (a language, a status)' : '');
            } elseif ($custom) {
                $out[$parameter->getName()] = 'by '.class_basename($class).'::resolveRouteBinding(), which may add conditions of its own (a language, a status)';
            }
        }

        return $out;
    }

    /** @return list<array{string, string}> what goes wrong when the action runs as the user */
    private function result(array $action, $user): array
    {
        $required = (array) ($action['schema']['required'] ?? []);
        if ($action['verb'] !== 'GET' || $action['confirm'] || $required) {
            return [];
        }
        try {
            $run = Rehearsal::run($action, [], $user, $this->option('locale') ?: null);
        } catch (Throwable $e) {
            return [['problem', 'It failed when run: '.$e->getMessage()]];
        }
        $outcome = $run['outcome'];
        if (! $outcome['ok']) {
            return [['problem', 'Refused when run without input: '.$outcome['error']]];
        }
        $found = [];
        if ($run['size'] > Turn::RESULT_LIMIT) {
            $found[] = ['problem', 'It returns '.number_format($run['size']).' characters; the assistant reads '.number_format(strlen($run['read'])).'. Keep only what it needs with fields: [...], or return fewer per page.'];
        }
        $result = $outcome['result'];
        $rows = is_array($result) && array_is_list($result) ? $result : (is_array($result['data'] ?? null) && array_is_list($result['data']) ? $result['data'] : null);
        $paged = is_array($result) && (isset($result['meta']['total']) || isset($result['total']) || isset($result['last_page']) || isset($result['meta']['last_page']) || array_key_exists('next_page_url', $result) || isset($result['links']['next']));
        if ($rows !== null && count($rows) >= 15 && ! $paged) {
            $found[] = ['hint', 'It returns '.count($rows).' rows without paging: the assistant cannot tell whether there are more. Paginate it, or return the total.'];
        }
        if ($run['ms'] > 5000) {
            $found[] = ['hint', 'It took '.number_format($run['ms'] / 1000, 1).' s; the user waits that long for every call.'];
        }

        return $found;
    }
}
