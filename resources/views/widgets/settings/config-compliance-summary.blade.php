{{-- Instellingen voor de compliance-widget. Breidt core's standaard
     widget-instellingenformulier uit (dat levert de opslaan-knop). We bieden
     alleen een eigen titel; de inhoud komt volledig uit de laatste scan. --}}
@extends('widgets.settings.base')

@section('form')
    <div class="form-group">
        <label for="title-{{ $id }}" class="control-label">{{ __('Widget title') }}</label>
        <input type="text" class="form-control" name="title" id="title-{{ $id }}"
               placeholder="Config Compliance" value="{{ $title }}">
        <span class="help-block">{{ __('Sets the bar along the top of the widget.') }}</span>
    </div>
@endsection
