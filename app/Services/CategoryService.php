<?php

namespace App\Services;

use Exception;
use App\Models\Category;
use App\DTOs\CategoryData;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Exceptions\CategoryException;
use Illuminate\Support\Facades\Cache;

class CategoryService
{
    /**
     * Busca una categoría por nombre y, si no existe, la crea.
     *
     * Pensado para los atajos donde el usuario escribe el nombre suelto (el
     * selector del formulario de producto, el bot de Telegram): no queremos
     * terminar con "Cintos", "cintos" y "Cíntos" como tres categorías distintas,
     * así que la búsqueda ignora mayúsculas y tildes y el slug se desambigua
     * solo si hace falta.
     */
    public function findOrCreateByName(string $name): Category
    {
        $name = self::cleanName($name);

        if ($name === '') {
            throw CategoryException::creationFailed('El nombre no puede estar vacío.');
        }

        return $this->findByName($name) ?? $this->createCategory(
            CategoryData::fromArray(['name' => $name, 'slug' => $this->uniqueSlug($name)])
        );
    }

    /**
     * Categoría cuyo nombre coincide ignorando mayúsculas, tildes y espacios
     * repetidos. Coincidencia exacta: "Cintos" no debe encontrar
     * "Cintos de cuero".
     */
    public function findByName(string $name): ?Category
    {
        $name = self::cleanName($name);

        if ($name === '') {
            return null;
        }

        // Camino rápido: la mayoría de los nombres son ASCII y el motor
        // resuelve el case-insensitive sin traernos la tabla.
        $direct = Category::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        if ($direct) {
            return $direct;
        }

        // Camino con tildes: la tabla de categorías es chica (decenas de filas),
        // comparar en PHP es más barato que depender del collation del motor.
        $needle = self::normalizeName($name);

        return Category::query()
            ->get()
            ->first(fn (Category $category) => self::normalizeName($category->name) === $needle);
    }

    /**
     * Slug libre para ese nombre. Sufija -1, -2, … hasta encontrar uno sin uso
     * (la columna es unique y dos nombres distintos pueden dar el mismo slug:
     * "Cintos" y "Cíntos").
     */
    public function uniqueSlug(string $name): string
    {
        $base = Str::slug(str_replace('&', '', $name));

        if ($base === '') {
            $base = 'categoria';
        }

        $slug = $base;
        $suffix = 1;

        while (Category::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $suffix++;
        }

        return $slug;
    }

    /** Recorta y colapsa espacios; respeta mayúsculas y tildes que escribió el usuario. */
    public static function cleanName(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    }

    /** Minúsculas y sin tildes, solo para comparar. */
    private static function normalizeName(string $name): string
    {
        return mb_strtolower(Str::ascii(self::cleanName($name)));
    }

    /**
     * Create a new category.
     */
    public function createCategory(CategoryData $data): Category
    {
        return DB::transaction(function () use ($data) {
            try {
                $category = Category::create([
                    'name' => $data->name,
                    'slug' => $data->slug,
                    'description' => $data->description,
                ]);

                Cache::forget('categories_list_all');

                return $category;

            } catch (Exception $e) {
                throw CategoryException::creationFailed($e->getMessage(), [
                    'data' => (array) $data,
                    'trace' => $e->getTraceAsString()
                ]);
            }
        });
    }

    /**
     * Update an existing category.
     */
    public function updateCategory(Category $category, CategoryData $data): Category
    {
        return DB::transaction(function () use ($category, $data) {
            try {
                $category->update([
                    'name' => $data->name,
                    'slug' => $data->slug,
                    'description' => $data->description,
                ]);

                Cache::forget('categories_list_all');

                return $category->refresh();

            } catch (Exception $e) {
                throw CategoryException::updateFailed($e->getMessage(), [
                    'id'   => $category->id,
                    'data' => (array) $data
                ]);
            }
        });
    }

    /**
     * Delete a category.
     */
    public function deleteCategory(Category $category): void
    {
        DB::transaction(function () use ($category) {
            try {
                if ($category->products()->exists()) {
                    throw new Exception("No se puede eliminar categoría porque está asociada a productos.");
                }

                $category->delete();

                Cache::forget('categories_list_all');

            } catch (Exception $e) {
                throw CategoryException::deletionFailed($e->getMessage(), ['id' => $category->id]);
            }
        });
    }
}
