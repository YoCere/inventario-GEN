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

    public function test_every_type_declares_form_partial_and_rules(): void
    {
        foreach (SectionTypes::keys() as $type) {
            $this->assertNotEmpty(SectionTypes::form($type), "Tipo {$type} sin partial de formulario");
            $this->assertNotEmpty(SectionTypes::rules($type), "Tipo {$type} sin reglas de validación");
        }
    }

    public function test_form_partial_path_follows_convention(): void
    {
        $this->assertSame('settings.landing.forms.hero', SectionTypes::form('hero'));
    }

    public function test_rules_are_keyed_by_field(): void
    {
        $this->assertArrayHasKey('heading', SectionTypes::rules('hero'));
    }
}
