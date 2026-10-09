<?php

/**
 * Mensajes de validación en español.
 *
 * Sin este archivo Laravel muestra la clave cruda ("validation.required") en
 * pantalla, porque `locale` y `fallback_locale` son `es` y no hay traducción
 * de dónde sacar el texto.
 *
 * Los textos están escritos como le hablaríamos a la dueña del negocio: nada de
 * "el campo category_id", sino "la categoría". Para eso sirve el bloque
 * `attributes` del final: traduce el nombre interno al nombre de negocio.
 */

return [

    'accepted' => 'Tenés que aceptar :attribute.',
    'accepted_if' => 'Tenés que aceptar :attribute cuando :other sea :value.',
    'active_url' => ':attribute no es una dirección web válida.',
    'after' => ':attribute tiene que ser una fecha posterior a :date.',
    'after_or_equal' => ':attribute tiene que ser :date o una fecha posterior.',
    'alpha' => ':attribute solo puede tener letras.',
    'alpha_dash' => ':attribute solo puede tener letras, números, guiones y guiones bajos.',
    'alpha_num' => ':attribute solo puede tener letras y números.',
    'any_of' => ':attribute no es válido.',
    'array' => ':attribute tiene que ser una lista.',
    'ascii' => ':attribute solo puede tener caracteres y símbolos simples.',
    'before' => ':attribute tiene que ser una fecha anterior a :date.',
    'before_or_equal' => ':attribute tiene que ser :date o una fecha anterior.',
    'between' => [
        'array' => ':attribute tiene que tener entre :min y :max elementos.',
        'file' => ':attribute tiene que pesar entre :min y :max kilobytes.',
        'numeric' => ':attribute tiene que estar entre :min y :max.',
        'string' => ':attribute tiene que tener entre :min y :max caracteres.',
    ],
    'boolean' => ':attribute tiene que ser sí o no.',
    'can' => ':attribute tiene un valor que no está permitido.',
    'confirmed' => 'La confirmación de :attribute no coincide.',
    'contains' => 'A :attribute le falta un valor obligatorio.',
    'current_password' => 'La contraseña es incorrecta.',
    'date' => ':attribute no es una fecha válida.',
    'date_equals' => ':attribute tiene que ser la fecha :date.',
    'date_format' => ':attribute no tiene el formato :format.',
    'decimal' => ':attribute tiene que tener :decimal decimales.',
    'declined' => 'Tenés que rechazar :attribute.',
    'declined_if' => 'Tenés que rechazar :attribute cuando :other sea :value.',
    'different' => ':attribute y :other tienen que ser distintos.',
    'digits' => ':attribute tiene que tener :digits dígitos.',
    'digits_between' => ':attribute tiene que tener entre :min y :max dígitos.',
    'dimensions' => ':attribute tiene un tamaño de imagen no válido.',
    'distinct' => ':attribute está repetido.',
    'doesnt_end_with' => ':attribute no puede terminar con: :values.',
    'doesnt_start_with' => ':attribute no puede empezar con: :values.',
    'email' => ':attribute tiene que ser un correo válido.',
    'ends_with' => ':attribute tiene que terminar con: :values.',
    'enum' => 'La opción elegida en :attribute no es válida.',
    'exists' => 'La opción elegida en :attribute no existe.',
    'extensions' => ':attribute tiene que ser un archivo de tipo: :values.',
    'file' => ':attribute tiene que ser un archivo.',
    'filled' => ':attribute no puede quedar vacío.',
    'gt' => [
        'array' => ':attribute tiene que tener más de :value elementos.',
        'file' => ':attribute tiene que pesar más de :value kilobytes.',
        'numeric' => ':attribute tiene que ser mayor que :value.',
        'string' => ':attribute tiene que tener más de :value caracteres.',
    ],
    'gte' => [
        'array' => ':attribute tiene que tener :value elementos o más.',
        'file' => ':attribute tiene que pesar :value kilobytes o más.',
        'numeric' => ':attribute tiene que ser :value o más.',
        'string' => ':attribute tiene que tener :value caracteres o más.',
    ],
    'hex_color' => ':attribute tiene que ser un color en formato hexadecimal.',
    'image' => ':attribute tiene que ser una imagen.',
    'in' => 'La opción elegida en :attribute no es válida.',
    'in_array' => ':attribute no está entre los valores de :other.',
    'in_array_keys' => ':attribute tiene que incluir al menos una de estas claves: :values.',
    'integer' => ':attribute tiene que ser un número entero.',
    'ip' => ':attribute tiene que ser una dirección IP válida.',
    'ipv4' => ':attribute tiene que ser una dirección IPv4 válida.',
    'ipv6' => ':attribute tiene que ser una dirección IPv6 válida.',
    'json' => ':attribute tiene que ser un texto JSON válido.',
    'list' => ':attribute tiene que ser una lista.',
    'lowercase' => ':attribute tiene que ir en minúsculas.',
    'lt' => [
        'array' => ':attribute tiene que tener menos de :value elementos.',
        'file' => ':attribute tiene que pesar menos de :value kilobytes.',
        'numeric' => ':attribute tiene que ser menor que :value.',
        'string' => ':attribute tiene que tener menos de :value caracteres.',
    ],
    'lte' => [
        'array' => ':attribute no puede tener más de :value elementos.',
        'file' => ':attribute no puede pesar más de :value kilobytes.',
        'numeric' => ':attribute no puede ser mayor que :value.',
        'string' => ':attribute no puede tener más de :value caracteres.',
    ],
    'mac_address' => ':attribute tiene que ser una dirección MAC válida.',
    'max' => [
        'array' => ':attribute no puede tener más de :max elementos.',
        'file' => ':attribute no puede pesar más de :max kilobytes.',
        'numeric' => ':attribute no puede ser mayor que :max.',
        'string' => ':attribute no puede tener más de :max caracteres.',
    ],
    'max_digits' => ':attribute no puede tener más de :max dígitos.',
    'mimes' => ':attribute tiene que ser un archivo de tipo: :values.',
    'mimetypes' => ':attribute tiene que ser un archivo de tipo: :values.',
    'min' => [
        'array' => ':attribute tiene que tener al menos :min elementos.',
        'file' => ':attribute tiene que pesar al menos :min kilobytes.',
        'numeric' => ':attribute tiene que ser al menos :min.',
        'string' => ':attribute tiene que tener al menos :min caracteres.',
    ],
    'min_digits' => ':attribute tiene que tener al menos :min dígitos.',
    'missing' => ':attribute no puede venir en el formulario.',
    'missing_if' => ':attribute no puede venir cuando :other sea :value.',
    'missing_unless' => ':attribute no puede venir salvo que :other sea :value.',
    'missing_with' => ':attribute no puede venir junto con :values.',
    'missing_with_all' => ':attribute no puede venir junto con :values.',
    'multiple_of' => ':attribute tiene que ser múltiplo de :value.',
    'not_in' => 'La opción elegida en :attribute no es válida.',
    'not_regex' => ':attribute tiene un formato no válido.',
    'numeric' => ':attribute tiene que ser un número.',
    'password' => [
        'letters' => 'La contraseña tiene que tener al menos una letra.',
        'mixed' => 'La contraseña tiene que tener al menos una mayúscula y una minúscula.',
        'numbers' => 'La contraseña tiene que tener al menos un número.',
        'symbols' => 'La contraseña tiene que tener al menos un símbolo.',
        'uncompromised' => 'Esa contraseña apareció en filtraciones conocidas. Elegí otra.',
    ],
    'present' => ':attribute tiene que venir en el formulario.',
    'present_if' => ':attribute tiene que venir cuando :other sea :value.',
    'present_unless' => ':attribute tiene que venir salvo que :other sea :value.',
    'present_with' => ':attribute tiene que venir junto con :values.',
    'present_with_all' => ':attribute tiene que venir junto con :values.',
    'prohibited' => ':attribute no está permitido.',
    'prohibited_if' => ':attribute no está permitido cuando :other sea :value.',
    'prohibited_if_accepted' => ':attribute no está permitido cuando se acepta :other.',
    'prohibited_if_declined' => ':attribute no está permitido cuando se rechaza :other.',
    'prohibited_unless' => ':attribute no está permitido salvo que :other sea :values.',
    'prohibits' => ':attribute no deja usar :other.',
    'regex' => ':attribute tiene un formato no válido.',
    'required' => 'Falta completar :attribute.',
    'required_array_keys' => 'A :attribute le faltan datos: :values.',
    'required_if' => 'Falta completar :attribute cuando :other sea :value.',
    'required_if_accepted' => 'Falta completar :attribute cuando se acepta :other.',
    'required_if_declined' => 'Falta completar :attribute cuando se rechaza :other.',
    'required_unless' => 'Falta completar :attribute salvo que :other sea :values.',
    'required_with' => 'Falta completar :attribute cuando se carga :values.',
    'required_with_all' => 'Falta completar :attribute cuando se cargan :values.',
    'required_without' => 'Falta completar :attribute cuando no se carga :values.',
    'required_without_all' => 'Falta completar :attribute cuando no se carga ninguno de :values.',
    'same' => ':attribute y :other tienen que coincidir.',
    'size' => [
        'array' => ':attribute tiene que tener :size elementos.',
        'file' => ':attribute tiene que pesar :size kilobytes.',
        'numeric' => ':attribute tiene que ser :size.',
        'string' => ':attribute tiene que tener :size caracteres.',
    ],
    'starts_with' => ':attribute tiene que empezar con: :values.',
    'string' => ':attribute tiene que ser texto.',
    'timezone' => ':attribute tiene que ser una zona horaria válida.',
    'ulid' => ':attribute tiene que ser un ULID válido.',
    'unique' => ':attribute ya está en uso.',
    'uploaded' => 'No se pudo subir :attribute. Puede ser demasiado pesado.',
    'uppercase' => ':attribute tiene que ir en mayúsculas.',
    'url' => ':attribute tiene que ser una dirección web válida.',
    'uuid' => ':attribute tiene que ser un UUID válido.',

    /*
    |--------------------------------------------------------------------------
    | Mensajes propios
    |--------------------------------------------------------------------------
    |
    | Para un campo puntual que merece un texto más claro que el genérico.
    | Formato: 'campo.regla' => 'mensaje'.
    |
    */

    'custom' => [
        'gallery' => [
            'max' => 'No se pueden subir más de :max fotos por producto.',
        ],
        'gallery.*' => [
            'max' => 'Cada foto puede pesar hasta 8 MB. Esa es más pesada: sacala con menos resolución.',
            'mimes' => 'Ese archivo no es una foto válida. Usá JPG, PNG o WebP.',
        ],
        'newUpload.*' => [
            'max' => 'Cada foto puede pesar hasta 8 MB. Esa es más pesada: sacala con menos resolución.',
            'mimes' => 'Ese archivo no es una foto válida. Usá JPG, PNG o WebP.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Nombres de los campos
    |--------------------------------------------------------------------------
    |
    | Lo que ve la persona en el mensaje. Sin esto diría "el campo category_id".
    |
    */

    'attributes' => [
        'address' => 'la dirección',
        'amount' => 'el monto',
        'category_id' => 'la categoría',
        'city' => 'la ciudad',
        'code' => 'el código',
        'cost' => 'el costo',
        'current_password' => 'la contraseña actual',
        'customer_id' => 'el cliente',
        'date' => 'la fecha',
        'description' => 'la descripción',
        'discount' => 'el descuento',
        'email' => 'el correo',
        'gallery' => 'las fotos',
        'image' => 'la imagen',
        'location_id' => 'la ubicación',
        'min_stock' => 'la alerta de stock mínimo',
        'name' => 'el nombre',
        'nit' => 'el NIT',
        'notes' => 'las notas',
        'password' => 'la contraseña',
        'password_confirmation' => 'la confirmación de la contraseña',
        'phone' => 'el teléfono',
        'photo' => 'la foto',
        'price' => 'el precio',
        'purchase_price' => 'el precio de compra',
        'quantity' => 'la cantidad',
        'reason' => 'el motivo',
        'selling_price' => 'el precio de venta',
        'sin_code' => 'el código SIN',
        'sku' => 'el código del producto',
        'supplier_id' => 'el proveedor',
        'title' => 'el título',
        'unit_id' => 'la unidad',
        'username' => 'el nombre de usuario',
        'warehouse_id' => 'el almacén',
    ],

];
