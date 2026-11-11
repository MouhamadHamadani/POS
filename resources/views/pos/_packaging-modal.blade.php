{{--
    "Pack or Package?" for a packaged product (client/tobacco, docs/packaging.md). Opens on a scan of the pack's barcode and
    on a tap of the product tile. Keys: 1 Pack, 2 Package, 3 Carton (only when cartons are sold at the till), Enter Pack, Esc
    cancels; logic in pos/_packaging-js.blade.php. Renders nothing unless POS_PACKAGING is on.
--}}
@if (\App\Models\Product::packagingEnabled())
    <div x-show="pkg.open" style="display: none" @keydown.window="pkgKey($event)"
         class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4"
         role="dialog" aria-modal="true" :aria-label="pkgT('choose_title')">
        <div x-ref="pkgBox" tabindex="-1" class="bg-white rounded-lg shadow-xl max-w-lg w-full p-5 outline-none">
            <div class="flex justify-between items-center mb-1">
                <h3 class="text-lg font-bold" x-text="pkgT('choose_title')"></h3>
                <button type="button" @click="pkgClose()" class="text-gray-400 hover:text-gray-700 text-xl" aria-label="{{ __('common.cancel') }}">&times;</button>
            </div>
            <p class="text-sm text-gray-600 mb-4" x-text="pkg.product ? pname(pkg.product) : ''"></p>

            <div class="grid gap-3" :class="pkg.units.length > 2 ? 'grid-cols-3' : 'grid-cols-2'">
                <template x-for="(u, i) in pkg.units" :key="u">
                    <button type="button" @click="pkgChoose(u)"
                            :class="u === 'pack' ? 'border-brand-600 bg-brand-50 ring-2 ring-brand-600' : 'border-gray-300 hover:bg-gray-50'"
                            class="rounded-lg border p-4 text-center">
                        <div class="text-xs text-gray-500" dir="ltr" x-text="i + 1"></div>
                        <div class="font-semibold" x-text="pkg.product ? pkgLabel(pkg.product, u) : ''"></div>
                        <div class="mt-1 text-2xl font-bold text-brand-700"><x-ltr>$<span x-text="pkg.product ? pkgPrice(pkg.product, u).toFixed(2) : ''"></span></x-ltr></div>
                    </button>
                </template>
            </div>

            <p class="mt-4 text-xs text-gray-400" x-text="pkgT('choose_hint')"></p>
        </div>
    </div>
@endif
