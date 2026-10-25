<?php

namespace Rekhu\Assistant\Console;

use Illuminate\Console\GeneratorCommand;

/** php artisan make:assistant-tool ListBranches: a Tool class in app/Assistant/Tools, offered by itself. */
class MakeToolCommand extends GeneratorCommand
{
    protected $name = 'make:assistant-tool';

    protected $description = 'Create an action for Rekhu Assistant, without a route';

    protected $type = 'Assistant tool';

    protected function getStub(): string
    {
        return __DIR__.'/../../resources/stubs/assistant-tool.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return trim((string) (config('rekhu-assistant.tools.namespace') ?: $rootNamespace.'\Assistant\Tools'), '\\');
    }
}
