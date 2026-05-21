<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>{{ $businessName }} — Próximamente</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet">
    <style>
        :root {
            --color-primary: {{ $primaryColor }};
            --color-text-on-primary: {{ $textOnPrimary }};
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: #f8fafc;
            color: #0f172a;
            line-height: 1.6;
        }
        header {
            background: var(--color-primary);
            color: var(--color-text-on-primary);
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        header img { height: 36px; width: 36px; object-fit: contain; border-radius: 6px; background: rgba(255,255,255,0.15); padding: 2px; }
        header h1 { font-size: 1.125rem; font-weight: 600; }
        main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1.5rem;
        }
        .card {
            background: white;
            border-radius: 16px;
            padding: 3rem 2rem;
            max-width: 480px;
            width: 100%;
            text-align: center;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.06), 0 4px 6px -2px rgba(0,0,0,0.03);
        }
        .icon { font-size: 3rem; margin-bottom: 1rem; }
        h2 { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem; color: var(--color-primary); }
        p { color: #475569; margin-bottom: 1.5rem; }
        .welcome { background: #f1f5f9; border-left: 3px solid var(--color-primary); padding: 0.75rem 1rem; border-radius: 4px; margin-top: 1.5rem; text-align: left; color: #1e293b; font-size: 0.9rem; }
        footer { padding: 1rem; text-align: center; font-size: 0.8rem; color: #94a3b8; }
    </style>
</head>
<body>
    <header>
        @if($logoUrl)
            <img src="{{ $logoUrl }}" alt="{{ $businessName }}">
        @endif
        <h1>{{ $businessName }}</h1>
    </header>

    <main>
        <div class="card">
            <div class="icon">🛍️</div>
            <h2>Tienda en construcción</h2>
            <p>Estamos preparando nuestro catálogo en línea. Vuelve pronto para ver nuestros productos.</p>

            @if(!empty($welcomeMessage))
                <div class="welcome">{{ $welcomeMessage }}</div>
            @endif
        </div>
    </main>

    <footer>
        © {{ date('Y') }} {{ $businessName }}
    </footer>
</body>
</html>
