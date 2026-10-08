@extends('layouts.app')

@section('title', 'تعديل المنتج')

@section('content')
    @livewire('products.edit', ['product' => $product], 'product-edit-'.$product->id)
@endsection
