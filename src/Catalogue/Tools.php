<?php

namespace Rekhu\Assistant\Catalogue;

use Illuminate\Support\Str;
use Rekhu\Assistant\RekhuAssistant;
use Rekhu\Assistant\Tool;
use Symfony\Component\Finder\Finder;

/** The Tool classes of the app: those in tools.path (app/Assistant/Tools), and those named with RekhuAssistant::tools(). */
class Tools
{
    /** @return list<class-string<Tool>> */
    public static function classes(): array
    {
        $classes = RekhuAssistant::registeredTools();
        $path = (string) (config('rekhu-assistant.tools.path') ?: app_path('Assistant/Tools'));
        $namespace = trim((string) (config('rekhu-assistant.tools.namespace') ?: app()->getNamespace().'Assistant\\Tools'), '\\');
        if (is_dir($path)) {
            foreach ((new Finder)->files()->in($path)->name('*.php')->sortByName() as $file) {
                $relative = Str::of($file->getRelativePathname())->beforeLast('.php')->replace('/', '\\');
                $classes[] = $namespace.'\\'.$relative;
            }
        }

        return array_values(array_unique(array_filter($classes, fn ($class) => is_string($class) && class_exists($class)
            && is_subclass_of($class, Tool::class) && ! (new \ReflectionClass($class))->isAbstract() && method_exists($class, 'handle'))));
    }
}
