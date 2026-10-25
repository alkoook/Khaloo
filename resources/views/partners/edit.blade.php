@extends('layouts.app')

@section('title', 'تعديل السيد/المورد')

@section('content')
    @livewire('partners.edit', ['partner' => $partner])
@endsection
