<style>
    @page { size: {{ $thermal['paper_width_mm'] }}mm auto; margin: 0; }

    * { box-sizing: border-box; }

    :root {
        --thermal-paper: {{ $thermal['paper_width_mm'] }}mm;
        --thermal-safe: {{ $thermal['safe_margin_mm'] }}mm;
        --thermal-content: {{ $thermal['content_width_mm'] }}mm;
        --thermal-font: {!! $thermal['font_family'] !!};
        --thermal-base: {{ $thermal['font_size_px'] }}px;
        --thermal-ink: #050505;
        --thermal-muted: #4a4a4a;
        --thermal-line: #8a8a8a;
        --thermal-soft: #f1f1f1;
    }

    html, body { margin: 0; min-height: 100%; }
    html { background: #e9eef0; }
    body {
        color: var(--thermal-ink);
        background: transparent;
        font-family: var(--thermal-font);
        font-size: var(--thermal-base);
        line-height: 1.35;
        direction: rtl;
    }

    .thermal-preview-actions {
        display: flex;
        justify-content: center;
        gap: 8px;
        margin: 18px auto 12px;
    }
    .thermal-preview-actions button {
        min-height: 38px;
        padding: 7px 16px;
        border: 1px solid #aab4b8;
        border-radius: 8px;
        background: #fff;
        color: #172126;
        font: 700 13px/1 var(--thermal-font);
        cursor: pointer;
        box-shadow: 0 4px 12px #16252b18;
    }
    .thermal-preview-actions button:first-child {
        border-color: #0f6874;
        background: #0f6874;
        color: #fff;
    }

    .thermal-paper {
        width: var(--thermal-paper);
        max-width: var(--thermal-paper);
        min-height: 40mm;
        margin: 0 auto 24px;
        padding: 2mm var(--thermal-safe) 4mm;
        overflow: hidden;
        background: #fff;
        box-shadow: 0 10px 30px #1a2b321f;
    }

    .thermal-brand { text-align: center; }
    .thermal-brand__logo {
        display: block;
        width: auto;
        height: auto;
        max-width: min(18mm, 28%);
        max-height: 15mm;
        margin: 0 auto 1mm;
        object-fit: contain;
    }
    .thermal-brand__name {
        margin: 0;
        font-size: calc(var(--thermal-base) + 4px);
        line-height: 1.25;
        font-weight: 900;
        overflow-wrap: anywhere;
    }
    .thermal-brand__phones {
        margin-top: .6mm;
        direction: ltr;
        font-size: calc(var(--thermal-base) - 2px);
        line-height: 1.3;
    }
    .thermal-brand__phones span { display: block; }
    .thermal-document-title {
        margin: 1.6mm 0 0;
        padding: 1.2mm 0 1mm;
        border-bottom: .45mm solid var(--thermal-ink);
        font-size: calc(var(--thermal-base) + 2px);
        line-height: 1.2;
        font-weight: 900;
        text-align: center;
    }

    .thermal-meta {
        margin: 1mm 0 1.4mm;
        padding: .6mm 0;
        border-bottom: .18mm dashed var(--thermal-line);
    }
    .thermal-meta__row {
        display: grid;
        grid-template-columns: 18mm minmax(0, 1fr);
        gap: 1.2mm;
        align-items: start;
        padding: .4mm .3mm;
    }
    .thermal-meta__label {
        color: var(--thermal-muted);
        font-size: calc(var(--thermal-base) - 2px);
        font-weight: 700;
    }
    .thermal-meta__value {
        min-width: 0;
        font-size: calc(var(--thermal-base) - 1px);
        font-weight: 800;
        overflow-wrap: anywhere;
        word-break: break-word;
    }
    .thermal-ltr { direction: ltr; unicode-bidi: isolate; }

    .thermal-section-title {
        margin: 1.5mm 0 .6mm;
        font-size: calc(var(--thermal-base) - 1px);
        font-weight: 900;
    }

    .thermal-items {
        width: 100%;
        max-width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }
    .thermal-items th {
        padding: .9mm .3mm;
        border-top: .25mm solid var(--thermal-ink);
        border-bottom: .35mm solid var(--thermal-ink);
        background: var(--thermal-soft);
        font-size: calc(var(--thermal-base) - 2px);
        font-weight: 900;
        white-space: nowrap;
    }
    .thermal-items td {
        padding: 1mm .3mm;
        border-bottom: .15mm solid #b3b3b3;
        font-size: calc(var(--thermal-base) - 2px);
        line-height: 1.38;
        vertical-align: top;
    }
    .thermal-items .thermal-col-seq { width: 7%; text-align: center; }
    .thermal-items .thermal-col-name { width: 39%; text-align: right; overflow-wrap: anywhere; word-break: break-word; }
    .thermal-items .thermal-col-qty { width: 13%; text-align: center; }
    .thermal-items .thermal-col-price { width: 17%; text-align: center; }
    .thermal-items .thermal-col-total { width: 24%; text-align: center; }
    .thermal-items td:not(.thermal-col-name) {
        direction: ltr;
        font-variant-numeric: tabular-nums;
        overflow-wrap: anywhere;
    }

    .thermal-totals {
        width: 76%;
        margin: 1.6mm 0 0 auto;
        border-top: .28mm solid var(--thermal-ink);
    }
    .thermal-total-row {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        gap: 2mm;
        padding: .65mm .35mm;
        border-bottom: .15mm dotted var(--thermal-line);
        font-size: calc(var(--thermal-base) - 1px);
    }
    .thermal-total-row strong {
        direction: ltr;
        text-align: left;
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
    }
    .thermal-total-row--grand {
        margin-top: .7mm;
        padding: 1.1mm .5mm;
        border-top: .45mm double var(--thermal-ink);
        border-bottom: .55mm double var(--thermal-ink);
        background: var(--thermal-soft);
        font-size: calc(var(--thermal-base) + 1px);
        font-weight: 900;
    }

    .thermal-notes {
        margin-top: 1.8mm;
        padding-top: 1mm;
        border-top: .18mm dashed var(--thermal-line);
        font-size: calc(var(--thermal-base) - 2px);
        line-height: 1.5;
        white-space: pre-wrap;
        overflow-wrap: anywhere;
    }
    .thermal-notes strong { display: block; margin-bottom: .4mm; }
    .thermal-footer {
        margin-top: 1.8mm;
        padding-top: 1mm;
        border-top: .25mm solid var(--thermal-ink);
        color: var(--thermal-muted);
        font-size: max(8px, calc(var(--thermal-base) - 3px));
        line-height: 1.45;
        text-align: center;
    }
    .thermal-footer span { display: block; overflow-wrap: anywhere; }
    .thermal-end-feed { height: calc({{ $thermal['feed_lines'] }} * 1.2mm); }

    @media (max-width: 340px) {
        .thermal-preview-actions { margin-inline: 8px; }
    }

    @media print {
        html, body {
            width: var(--thermal-paper) !important;
            max-width: var(--thermal-paper) !important;
            margin: 0 !important;
            padding: 0 !important;
            overflow: hidden !important;
            background: #fff !important;
        }
        .thermal-preview-actions { display: none !important; }
        .thermal-paper {
            width: var(--thermal-paper) !important;
            max-width: var(--thermal-paper) !important;
            margin: 0 !important;
            padding: 2mm var(--thermal-safe) 0 !important;
            box-shadow: none !important;
        }
        .thermal-items thead { display: table-row-group; }
        .thermal-items tr,
        .thermal-total-row,
        .thermal-notes,
        .thermal-footer { break-inside: avoid; page-break-inside: avoid; }
    }
</style>
