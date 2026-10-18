<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Tests\Feature;

use ReflectionClass;
use Rekhu\Assistant\Attributes\Page;
use Rekhu\Assistant\Attributes\Tool;
use Rekhu\Assistant\Tests\TestCase;

/** The examples in examples/ stay true to the package: every attribute on them builds, and the config is valid PHP. */
final class ExamplesTest extends TestCase
{
    public function test_every_attribute_in_the_example_controller_builds(): void
    {
        // the example app's own classes, as stand-ins
        if (! class_exists('App\Http\Controllers\Controller')) {
            eval('namespace App\Http\Controllers; abstract class Controller { public function authorize(...$a) {} }');
            eval('namespace App\Models; class Order {}');
        }
        require_once __DIR__.'/../../examples/app/Http/Requests/ChangeAddressRequest.php';
        require_once __DIR__.'/../../examples/app/Http/Controllers/OrderController.php';

        $tools = [];
        foreach ((new ReflectionClass('App\Http\Controllers\OrderController'))->getMethods() as $method) {
            foreach ($method->getAttributes(Tool::class) as $attribute) {
                $tool = $attribute->newInstance();
                $tools[$tool->name] = $tool;
                $this->assertNotEmpty($tool->label, "{$tool->name} has a label for the user");
            }
            foreach ($method->getAttributes(Page::class) as $attribute) {
                $this->assertNotEmpty($attribute->newInstance()->label);
            }
        }
        $this->assertSame(['list_orders', 'show_order', 'change_address', 'cancel_order', 'track_parcel'], array_keys($tools));
        $this->assertTrue($tools['track_parcel']->guests);
        $this->assertTrue($tools['change_address']->confirm);
    }

    public function test_the_example_config_has_only_known_options(): void
    {
        $example = require __DIR__.'/../../examples/config/rekhu-assistant.php';
        $package = require __DIR__.'/../../config/rekhu-assistant.php';

        $this->assertSame([], array_diff(array_keys($example), array_keys($package)));
    }
}
