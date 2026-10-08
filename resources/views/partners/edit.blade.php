@extends('layouts.app')

@section('title', 'تعديل زبون/مورد')

@section('content')
    @livewire('partners.edit', ['partner' => $partner])
@endsection
