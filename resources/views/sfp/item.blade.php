@php($snapshot = $item->catalog_snapshot ?? [])
<small class="characteristics">Количество: {{ $snapshot['copies'] ?? $item->quantity }}@if(!empty($snapshot['area_pricing'])) экз. · {{ $snapshot['width'] ?? '—' }} × {{ $snapshot['height'] ?? '—' }} м @elseif(!empty($snapshot['unit'])) {{ $snapshot['unit'] }} @endif
@foreach(($snapshot['characteristics'] ?? []) as $key => $value) @if(is_scalar($value)) · {{ is_string($snapshot['fields'][$key] ?? null) ? $snapshot['fields'][$key] : $key }}: {{ $value }} @endif @endforeach</small>
