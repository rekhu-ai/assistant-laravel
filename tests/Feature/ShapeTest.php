<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Tests\Feature;

use Rekhu\Assistant\Runtime\Shape;
use Rekhu\Assistant\Tests\TestCase;

final class ShapeTest extends TestCase
{
    public function test_fields_keep_only_what_the_action_names_for_each_record(): void
    {
        $result = ['data' => [
            ['id' => 1, 'name' => 'Olaya', 'reviews' => [['star' => 5, 'by' => 'x']], 'address' => [['city' => 'Riyadh', 'street' => 'King Fahd']]],
            ['id' => 2, 'name' => 'Nada', 'reviews' => [], 'address' => [['city' => 'Jeddah', 'street' => 'Tahlia']]],
        ], 'meta' => ['total' => 2]];

        $this->assertSame([
            'data' => [
                ['id' => 1, 'name' => 'Olaya', 'address' => [['city' => 'Riyadh']]],
                ['id' => 2, 'name' => 'Nada', 'address' => [['city' => 'Jeddah']]],
            ],
            'meta' => ['total' => 2],
        ], Shape::pick($result, ['id', 'name', 'address.*.city']));

        // a plain list and a single record work the same
        $this->assertSame([['id' => 1]], Shape::pick([['id' => 1, 'x' => 2]], ['id']));
        $this->assertSame(['id' => 1], Shape::pick(['id' => 1, 'x' => 2], ['id']));
    }

    public function test_a_long_list_loses_its_last_rows_and_keeps_its_paging(): void
    {
        $rows = array_map(fn ($i) => ['id' => $i, 'name' => str_repeat('n', 80)], range(1, 500));
        $result = ['data' => $rows, 'links' => ['next' => 'https://x/?page=2'], 'meta' => ['total' => 900, 'last_page' => 2, 'links' => [['url' => null]]]];

        $fit = Shape::fit($result, 5000);
        $json = Shape::json($fit);

        $this->assertLessThanOrEqual(5000, strlen($json));
        $this->assertNotNull(json_decode($json, true));
        $this->assertArrayNotHasKey('links', $fit);
        $this->assertSame(['total' => 900, 'last_page' => 2], $fit['meta']);
        $kept = count($fit['data']);
        $this->assertGreaterThan(10, $kept);
        $this->assertSame(1, $fit['data'][0]['id']);
        $this->assertStringContainsString("Only the first {$kept} of the 500 rows here fit", $fit['note']);
    }

    public function test_a_short_result_stays_as_it_is_without_its_page_links(): void
    {
        $this->assertSame(['data' => [['id' => 1]], 'meta' => ['total' => 1]],
            Shape::fit(['data' => [['id' => 1]], 'links' => ['first' => 'x'], 'meta' => ['total' => 1, 'links' => []]], 1000));
    }
}
