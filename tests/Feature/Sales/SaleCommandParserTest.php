<?php

namespace Tests\Feature\Sales;

use App\Enums\PaymentMethod;
use App\Services\Sales\SaleCommandParser;
use PHPUnit\Framework\TestCase;

class SaleCommandParserTest extends TestCase
{
    private SaleCommandParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new SaleCommandParser();
    }

    public function test_name_with_qty_and_unit_price(): void
    {
        $c = $this->parser->parse('vende 3 figuras de mario a 10');
        $this->assertNotNull($c);
        $this->assertSame(3, $c->quantity);
        $this->assertSame(1000, $c->unitPriceCents);
        $this->assertNull($c->totalPriceCents);
        $this->assertSame('figuras de mario', $c->productQuery);
        $this->assertNull($c->position);
        $this->assertSame(PaymentMethod::CASH, $c->method);
    }

    public function test_name_without_price(): void
    {
        $c = $this->parser->parse('véndeme dos fundas');
        $this->assertNotNull($c);
        $this->assertSame(2, $c->quantity);
        $this->assertNull($c->unitPriceCents);
        $this->assertSame('fundas', $c->productQuery);
    }

    public function test_name_with_total_price(): void
    {
        $c = $this->parser->parse('vende 5 cables en total 40');
        $this->assertSame(5, $c->quantity);
        $this->assertSame(4000, $c->totalPriceCents);
        $this->assertNull($c->unitPriceCents);
        $this->assertSame('cables', $c->productQuery);
    }

    public function test_word_numbers(): void
    {
        $c = $this->parser->parse('vende tres labubus a diez');
        $this->assertSame(3, $c->quantity);
        $this->assertSame(1000, $c->unitPriceCents);
        $this->assertSame('labubus', $c->productQuery);
    }

    public function test_transfer_method(): void
    {
        $c = $this->parser->parse('vende 2 fundas a 20 por transferencia');
        $this->assertSame(PaymentMethod::TRANSFER, $c->method);
        $this->assertSame(2, $c->quantity);
        $this->assertSame(2000, $c->unitPriceCents);
        $this->assertSame('fundas', $c->productQuery);
    }

    public function test_positional_with_qty_and_price(): void
    {
        $c = $this->parser->parse('vende 3 del segundo a 30');
        $this->assertSame(3, $c->quantity);
        $this->assertSame(2, $c->position);
        $this->assertSame(3000, $c->unitPriceCents);
        $this->assertNull($c->productQuery);
    }

    public function test_positional_el_primero(): void
    {
        $c = $this->parser->parse('vende el primero');
        $this->assertSame(1, $c->quantity);
        $this->assertSame(1, $c->position);
        $this->assertNull($c->productQuery);
    }

    public function test_positional_numero(): void
    {
        $c = $this->parser->parse('vende 2 del número 3 a 15');
        $this->assertSame(2, $c->quantity);
        $this->assertSame(3, $c->position);
        $this->assertSame(1500, $c->unitPriceCents);
    }

    public function test_past_tense_is_not_a_command(): void
    {
        $this->assertNull($this->parser->parse('vendí 3 hoy'));
        $this->assertNull($this->parser->parse('cuánto vendí ayer'));
    }

    public function test_non_command(): void
    {
        $this->assertNull($this->parser->parse('hola'));
        $this->assertNull($this->parser->parse('¿tienes fundas?'));
    }

    public function test_embedded_number_in_name_not_taken_as_quantity(): void
    {
        $c = $this->parser->parse('vende iphone 12 a 100');
        $this->assertSame(1, $c->quantity);
        $this->assertSame('iphone 12', $c->productQuery);
        $this->assertSame(10000, $c->unitPriceCents);
        $this->assertNull($c->position);
    }

    public function test_leading_qty_with_embedded_number_name(): void
    {
        $c = $this->parser->parse('vende 3 cargador 20w a 10');
        $this->assertSame(3, $c->quantity);
        $this->assertSame('cargador 20w', $c->productQuery);
        $this->assertSame(1000, $c->unitPriceCents);
    }

    public function test_de_inside_name_is_not_positional(): void
    {
        $c = $this->parser->parse('vende cable de 3 metros a 10');
        $this->assertNull($c->position);
        $this->assertSame(1, $c->quantity);
        $this->assertSame('cable de 3 metros', $c->productQuery);
        $this->assertSame(1000, $c->unitPriceCents);
    }

    public function test_del_is_still_positional(): void
    {
        $c = $this->parser->parse('vende 2 del tercero a 5');
        $this->assertSame(3, $c->position);
        $this->assertSame(2, $c->quantity);
        $this->assertNull($c->productQuery);
    }
}
