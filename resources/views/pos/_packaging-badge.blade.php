{{-- Unit badge on a cart line (client/tobacco, docs/packaging.md). Tapping it on a packaged line switches Pack <-> Package. --}}
@if (\App\Models\Product::packagingEnabled())
    <button type="button" x-show="line.sale_unit !== 'pack' || line.can_toggle" style="display: none"
            @click="pkgToggle(idx)" :disabled="!line.can_toggle" :title="pkgT('switch_unit')"
            :class="line.sale_unit === 'pack' ? 'bg-gray-100 text-gray-700' : 'bg-amber-100 text-amber-800'"
            class="ms-1 px-1.5 py-0.5 rounded text-[0.625rem] font-bold leading-none align-middle disabled:cursor-default"
            x-text="pkgBadge(line)"></button>
@endif
