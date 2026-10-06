{{-- $cols: [['label' => string, 'num' => bool], ...]; $data: list of cell lists; $foot: optional cell list; $empty: text --}}
@php
    $rtl = app()->getLocale() === 'ar';
    $order = array_keys($cols);
    if ($rtl) { $order = array_reverse($order); }
@endphp
<table>
    <thead>
        <tr>@foreach ($order as $i)<th class="{{ $cols[$i]['num'] ? 'num' : '' }}">@pdf($cols[$i]['label'])</th>@endforeach</tr>
    </thead>
    <tbody>
    @forelse ($data as $row)
        <tr>@foreach ($order as $i)<td class="{{ $cols[$i]['num'] ? 'num' : '' }}">@pdf($row[$i])</td>@endforeach</tr>
    @empty
        <tr><td colspan="{{ count($cols) }}" class="center">@pdf($empty)</td></tr>
    @endforelse
    </tbody>
    @if (isset($foot))
    <tfoot>
        <tr>@foreach ($order as $i)<td class="{{ $cols[$i]['num'] ? 'num' : '' }}">@pdf($foot[$i] ?? '')</td>@endforeach</tr>
    </tfoot>
    @endif
</table>
