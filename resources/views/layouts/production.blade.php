@extends('layouts.app')

@section('head')
    @include('partials.head-app')
@endsection

@section('body-attributes')data-page="app" data-active-view="{{ $activeView }}"@endsection

@section('content')
    @include('partials.app-content')
@endsection

@push('scripts')
    <script src="{{ asset('js/startup.js') }}?v={{ filemtime(public_path('js/startup.js')) }}"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
@endpush
