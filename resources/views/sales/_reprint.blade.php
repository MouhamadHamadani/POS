{{-- Reprint helper for the Sales History pages: salesReprint(desktop) for an x-data wrapper.
     Same rule as the sell screen. In the desktop app a reprint goes silently to the printer chosen in
     Settings (the Windows default when none is) and never opens a popup or the print dialog; if it fails
     the page shows an inline error with Retry (_reprint_error). In a browser tab the receipt opens in
     a real popup (window.opener set => the receipt page's auto-close and Close button are safe), with the
     hidden-iframe fallback if the popup is blocked. Every call sends copy=1 so the page is stamped COPY and
     the reprint is audited server-side (ReceiptController). --}}
@once
@push('scripts')
<script>
    function salesReprint(desktop) {
        const printUrl = @js(route('receipts.print', ['sale' => '__ID__'], false));
        const a4Url = @js(route('receipts.print-a4', ['sale' => '__ID__'], false));
        const thermalUrl = @js(route('receipts.print-thermal', ['sale' => '__ID__'], false));
        const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

        return {
            failed: null,   // { id, format, src } of the last desktop print that did not go out

            async print(id, format, src) {
                this.failed = null;
                const tail = 'copy=1&src=' + src;
                if (desktop) {
                    try {
                        const res = await fetch((format === 'a4' ? a4Url : thermalUrl).replace('__ID__', id) + '?' + tail, {
                            method: 'POST',
                            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
                        });
                        const data = await res.json().catch(() => ({}));
                        if (data.printed) return;
                        if (data.desktop !== false) { this.failed = { id, format, src }; return; }
                    } catch (e) { this.failed = { id, format, src }; return; }
                }
                this.open(id, format, tail);
            },

            retry() { if (this.failed) this.print(this.failed.id, this.failed.format, this.failed.src); },

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
