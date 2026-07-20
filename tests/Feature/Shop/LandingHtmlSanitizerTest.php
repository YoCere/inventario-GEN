<?php

namespace Tests\Feature\Shop;

use App\Shop\Services\LandingHtmlSanitizer;
use Tests\TestCase;

class LandingHtmlSanitizerTest extends TestCase
{
    private LandingHtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new LandingHtmlSanitizer();
    }

    public function test_removes_script_tags(): void
    {
        $out = $this->sanitizer->sanitize('<p>Hola</p><script>alert(1)</script>');
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringContainsString('Hola', $out);
    }

    public function test_keeps_allowed_formatting_tags(): void
    {
        $out = $this->sanitizer->sanitize('<p>Texto <strong>fuerte</strong> y <em>énfasis</em></p><ul><li>uno</li></ul>');
        $this->assertStringContainsString('<strong>', $out);
        $this->assertStringContainsString('<em>', $out);
        $this->assertStringContainsString('<li>', $out);
    }

    public function test_strips_dangerous_attributes(): void
    {
        $out = $this->sanitizer->sanitize('<a href="javascript:alert(1)" onclick="x()">click</a>');
        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringNotContainsString('onclick', $out);
    }

    public function test_null_and_empty_return_empty_string(): void
    {
        $this->assertSame('', $this->sanitizer->sanitize(null));
        $this->assertSame('', $this->sanitizer->sanitize('   '));
    }
}
