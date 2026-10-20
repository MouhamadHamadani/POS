{{-- Reprint helper for the Sales History pages: salesReprint(a4Silent) for an x-data wrapper.
     Same approach as the sell screen's _printReceipt/_printA4, so the receipt always opens in a
     real popup (window.opener set => the receipt page's auto-close and Close button are safe),
     with the hidden-iframe fallback if the popup is blocked. Every call sends copy=1 so the
     page is stamped COPY and the reprint is audited server-side (ReceiptController). --}}
@once
@push('scripts')
<script>
    function salesReprint(a4Silent) {
        const printUrl = @js(route('receipts.print', ['sale' => '__ID__'], false));
        const a4Url = @js(route('receipts.print-a4', ['sale' => '__ID__'], false));
        const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

        return {
            // A4 goes straight to the printer chosen in Settings when the desktop app can do that;
            // otherwise (or if the printer refuses) the A4 page opens with the system print dialog.
            async print(id, format, src) {
                const tail = 'copy=1&src=' + src;
                if (format === 'a4' && a4Silent) {
                    try {
                        const res = await fetch(a4Url.replace('__ID__', id) + '?' + tail, {
                            method: 'POST',
                            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
                        });
                        if (res.ok && (await res.json()).printed) return;
                    } catch (e) { /* fall through to the dialog */ }
                }
                this.open(id, format, tail);
            },

            open(id, format, tail) {
                const url = printUrl.replace('__ID__', id) + '?format=' + format + '&auto=1&' + tail;
                const w = window.open(url, 'receipt-' + id + '-' + format,
                    format === 'a4' ? 'width=900,height=1000' : 'width=420,height=640');
                if (w) return;
                // Popup blocked: print from a hidden iframe instead (no window to close, so no auto-close either).
                let iframe = document.getElementById('__receipt_iframe');
                if (!iframe) {
                    iframe = document.createElement('iframe');
                    iframe.id = '__receipt_iframe';
                    iframe.style.cssText = 'position:fixed;left:-9999px;width:0;height:0;border:0';
                    document.body.appendChild(iframe);
                }
                iframe.src = url;
            },
        };
    }
</script>
@endpush
@endonce
