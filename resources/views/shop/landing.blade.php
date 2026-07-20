@extends('shop.layouts.app')
@section('title', 'Inicio')
@section('content')
    @foreach($sections as $section)
        @php($partial = \App\Shop\Landing\SectionTypes::partial($section->type))
        @if($partial && \Illuminate\Support\Facades\View::exists($partial))
            @include($partial, [
                'data' => $section->data ?? [],
                'shopCategories' => $shopCategories ?? collect(),
            ])
        @endif
    @endforeach
@endsection
