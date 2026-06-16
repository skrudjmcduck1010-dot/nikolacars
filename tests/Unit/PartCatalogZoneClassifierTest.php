<?php

namespace Tests\Unit;

use App\Models\PartCatalogItem;
use App\Services\PartCatalogZoneClassifier;
use Tests\TestCase;

class PartCatalogZoneClassifierTest extends TestCase
{
    public function test_front_bumper_absorber_is_classified_as_front(): void
    {
        $item = new PartCatalogItem([
            'name' => 'Абсорбер (пінопласт) підсилювача переднього бампера Tesla Model Y',
            'main_category_name' => '10 - КУЗОВ',
            'subcategory_name' => '1001 -    ',
            'node_name' => 'ϲ  ',
        ]);

        $zones = collect((new PartCatalogZoneClassifier)->classify($item))->pluck('zone')->all();

        $this->assertContains('front', $zones);
    }

    public function test_rear_door_lock_is_not_classified_as_rear_impact(): void
    {
        $item = new PartCatalogItem([
            'name' => 'Замок двери задней левой',
            'subcategory_name' => 'Closure Assist Mechanisms and Hinges',
        ]);

        $zones = collect((new PartCatalogZoneClassifier)->classify($item))->pluck('zone')->all();

        $this->assertNotContains('rear', $zones);
    }

    public function test_rear_view_mirror_is_not_classified_as_rear_impact(): void
    {
        $item = new PartCatalogItem([
            'name' => 'Зеркало заднего вида с креплением и камерой в сборе',
        ]);

        $zones = collect((new PartCatalogZoneClassifier)->classify($item))->pluck('zone')->all();

        $this->assertNotContains('rear', $zones);
    }

    public function test_generic_tesla_trim_part_type_does_not_make_second_row_headrest_front(): void
    {
        $item = new PartCatalogItem([
            'name' => 'SECOND ROW CENTER HEADREST ASSEMBLY - BLACK',
            'main_category_name' => 'SEATS',
            'subcategory_name' => '2nd Row Seat Assemblies and Hardware',
            'node_name' => '2nd Row Seat Assemblies',
            'raw_attributes' => [
                'part_type' => 'Trim (Interior/Exterior/Frunk/Liftgate/Seat)',
            ],
        ]);

        $zones = collect((new PartCatalogZoneClassifier)->classify($item))->pluck('zone')->all();

        $this->assertNotContains('front', $zones);
        $this->assertContains('interior', $zones);
    }
}
