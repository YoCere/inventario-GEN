<?php

namespace App\Services;

use Exception;
use App\Models\Product;
use Illuminate\Support\Str;
use App\DTOs\ProductData;
use App\Exceptions\ProductException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductService
{
    public function __construct(
        protected AuditService $auditService
    ) {
    }

    /**
     * Create a new product.
     */
    public function createProduct(ProductData $data): Product
    {
        return DB::transaction(function () use ($data) {
            try {
                $sku = $data->sku ?? $this->generateUniqueSku();

                $product = Product::create([
                    'category_id' => $data->category_id,
                    'unit_id' => $data->unit_id,
                    'sku' => $sku,
                    'name' => $data->name,
                    'purchase_price' => $data->purchase_price,
                    'selling_price' => $data->selling_price,
                    'quantity' => $data->quantity,
                    'min_stock' => $data->min_stock,
                    'is_active' => $data->is_active,
                    'description' => $data->description,
                    'notes' => $data->notes,
                    'image_path' => $data->image_path,
                ]);

                $this->auditService->log(
                    'producto.creado',
                    $product,
                    null,
                    [
                        'nombre' => $product->name,
                        'sku' => $product->sku,
                        'precio_compra' => $product->purchase_price,
                        'precio_venta' => $product->selling_price,
                        'stock_inicial' => $product->quantity,
                    ]
                );

                return $product;

            } catch (Exception $e) {
                throw ProductException::creationFailed($e->getMessage(), [
                    'data' => (array) $data,
                    'trace' => $e->getTraceAsString()
                ]);
            }
        });
    }

    /**
     * Update an existing product.
     */
    public function updateProduct(Product $product, ProductData $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            try {
                // Lock to prevent race condition on quantity edits
                $product = Product::where('id', $product->id)->lockForUpdate()->first();

                $oldValues = [
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'purchase_price' => $product->purchase_price,
                    'selling_price' => $product->selling_price,
                    'quantity' => $product->quantity,
                    'min_stock' => $product->min_stock,
                    'is_active' => $product->is_active,
                ];

                $imagePath = $data->image_path ?? $product->image_path;

                if ($data->image_path && $data->image_path !== $product->image_path) {
                    if ($product->image_path && Storage::disk('public')->exists($product->image_path)) {
                        Storage::disk('public')->delete($product->image_path);
                    }
                    $imagePath = $data->image_path;
                }

                $product->update([
                    'category_id' => $data->category_id,
                    'unit_id' => $data->unit_id,
                    'sku' => $data->sku ?? $product->sku,
                    'name' => $data->name,
                    'purchase_price' => $data->purchase_price,
                    'selling_price' => $data->selling_price,
                    'quantity' => $data->quantity,
                    'min_stock' => $data->min_stock,
                    'is_active' => $data->is_active,
                    'description' => $data->description,
                    'notes' => $data->notes,
                    'image_path' => $imagePath,
                ]);

                $newValues = [
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'purchase_price' => $product->purchase_price,
                    'selling_price' => $product->selling_price,
                    'quantity' => $product->quantity,
                    'min_stock' => $product->min_stock,
                    'is_active' => $product->is_active,
                ];

                // Compute diff (only changed fields)
                $changedOld = [];
                $changedNew = [];
                foreach ($newValues as $key => $value) {
                    if ($oldValues[$key] != $value) {
                        $changedOld[$key] = $oldValues[$key];
                        $changedNew[$key] = $value;
                    }
                }

                if (!empty($changedNew)) {
                    // If quantity changed manually, audit as ajuste manual
                    if (array_key_exists('quantity', $changedNew)) {
                        $this->auditService->log(
                            'stock.ajuste_manual',
                            $product,
                            ['quantity' => $changedOld['quantity']],
                            [
                                'quantity' => $changedNew['quantity'],
                                'diferencia' => $changedNew['quantity'] - $changedOld['quantity'],
                                'origen' => 'edicion_directa_producto',
                            ]
                        );
                    }

                    $this->auditService->log(
                        'producto.actualizado',
                        $product,
                        $changedOld,
                        $changedNew
                    );
                }

                return $product->refresh();

            } catch (Exception $e) {
                throw ProductException::updateFailed($e->getMessage(), [
                    'id'   => $product->id,
                    'data' => (array) $data
                ]);
            }
        });
    }

    /**
     * Delete a product.
     */
    public function deleteProduct(Product $product): void
    {
        DB::transaction(function () use ($product) {
            try {
                if ($product->purchaseItems()->exists() || $product->saleItems()->exists()) {
                    throw new Exception('No se puede eliminar producto porque está asociado a registros de compra o venta.');
                }

                $this->auditService->log(
                    'producto.eliminado',
                    $product,
                    [
                        'nombre' => $product->name,
                        'sku' => $product->sku,
                        'precio_compra' => $product->purchase_price,
                        'precio_venta' => $product->selling_price,
                        'stock_final' => $product->quantity,
                    ],
                    null
                );

                if ($product->image_path && Storage::disk('public')->exists($product->image_path)) {
                    Storage::disk('public')->delete($product->image_path);
                }

                $product->delete();

            } catch (Exception $e) {
                throw ProductException::deletionFailed($e->getMessage(), ['id' => $product->id]);
            }
        });
    }

    /**
     * Generate a unique SKU in format P.YYMMDD.XXXX.
     */
    private function generateUniqueSku(): string
    {
        $prefix = 'P.' . date('ymd') . '.';

        do {
            $sku = $prefix . strtoupper(Str::random(4));
        } while (Product::where('sku', $sku)->exists());

        return $sku;
    }
}
