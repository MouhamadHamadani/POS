{{-- The page geometry of every roll-paper print (receipt, debt slip), set once. Needs $width (58 | 80).

     A thermal printer cannot print edge to edge, and Chromium lays the page out in the area the
     driver says is printable, not on the whole sheet. A body as wide as the paper therefore
     overhangs that area and its right-hand end (the amounts) is cut off without wrapping. So the body
     takes whatever width the page really offers and never more than --rw, the printable width of
     this paper (config pos.receipt.printable_mm). Long words break (`overflow-wrap: anywhere`, which
     also lets table cells shrink) instead of widening the page. There is no `@page size`: `80mm auto`
     is not valid CSS, Chromium drops it, and the silent print never reads it; the driver sets the sheet. --}}
:root { --rw: {{ \App\Services\ReceiptPrintService::printableMm((int) $width) }}mm; }
@page { margin: 0; }
html, body { margin: 0; padding: 0; background: white; font-family: 'Cairo', 'Courier New', monospace; }
body { box-sizing: border-box; width: auto; max-width: var(--rw); padding: 4mm 1.5mm; overflow-wrap: anywhere; }
