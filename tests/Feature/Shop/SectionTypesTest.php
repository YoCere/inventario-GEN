<?php

namespace Tests\Feature\Shop;

use App\Shop\Landing\SectionTypes;
use Tests\TestCase;

class SectionTypesTest extends TestCase
{
    public function test_lists_the_seven_types(): void
    {
        $this->assertSame(
            ['hero', 'about', 'hours', 'categories', 'gallery', 'contact', 'cta'],
            SectionTypes::keys()
        );
    }

    public function test_exists_validates_type(): void
    {
        $this->assertTrue(SectionTypes::exists('hero'));
        $this->assertFalse(SectionTypes::exists('unknown'));
    }

    public function test_label_and_default_data_available(): void
    {
        $this->assertSame('Héroe', SectionTypes::label('hero'));
        $this->assertArrayHasKey('heading', SectionTypes::defaultData('hero'));
    }
}
