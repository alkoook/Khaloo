@extends('layouts.app')

@section('title', 'تعديل التصنيف')

@section('content')
    @livewire('categories.edit', ['category' => $category])
@endsection
