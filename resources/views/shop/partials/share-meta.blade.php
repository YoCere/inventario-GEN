{{-- Etiquetas para vistas previas al compartir (WhatsApp, Facebook, X) y buscadores.
     Recibe $meta (App\Shop\Seo\ShareMeta) y $siteName. Único emisor de OG en la tienda:
     no agregar etiquetas sueltas en las vistas, se duplican. --}}
@php($meta = $meta ?? app(\App\Shop\Seo\ShareMetaBuilder::class)->forLanding())

<meta name="description" content="{{ $meta->description }}">
<link rel="canonical" href="{{ $meta->url }}">
@if($meta->noindex)
    <meta name="robots" content="noindex">
@endif

<meta property="og:type" content="{{ $meta->type }}">
<meta property="og:site_name" content="{{ $siteName }}">
<meta property="og:title" content="{{ $meta->title }}">
<meta property="og:description" content="{{ $meta->description }}">
<meta property="og:url" content="{{ $meta->url }}">
@if($meta->imageUrl)
    <meta property="og:image" content="{{ $meta->imageUrl }}">
@endif

<meta name="twitter:card" content="{{ $meta->imageUrl ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $meta->title }}">
<meta name="twitter:description" content="{{ $meta->description }}">
@if($meta->imageUrl)
    <meta name="twitter:image" content="{{ $meta->imageUrl }}">
@endif
