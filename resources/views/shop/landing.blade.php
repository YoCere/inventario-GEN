@extends('shop.layouts.app')
@section('title', 'Inicio')
@section('content')
    @foreach($sections as $section)
        @if(\App\Shop\Landing\SectionTypes::exists($section->type))
            @include(\App\Shop\Landing\SectionTypes::partial($section->type), ['data' => $section->data ?? []])
        @endif
    @endforeach
@endsection
