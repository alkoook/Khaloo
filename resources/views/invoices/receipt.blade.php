@php
    $width = in_array((string) $settings->get('receipt_width', '80'), ['72', '80'])
        ? $settings->get('receipt_width', '80')
        : '80';

    $currency = (string) $settings->get('currency_symbol', 'ل.س');

    $companyName = (string) $settings->get(
        'company_name',
        $settings->get('site_name', 'Khaloo')
    );

    $companyPhone = $settings->get('company_phone');

    $companyAddress = (string) $settings->get(
        'company_address',
        $settings->get('address', '')
    );

    $invoiceLogo = $settings->get('invoice_logo', $settings->get('logo'));

    $typeLabel = match (true) {
        $invoice->isReturn() => 'فاتورة مرتجع مبيعات',
        $invoice->isSale() => 'فاتورة مبيعات',
        default => 'فاتورة مشتريات',
    };

    $partyLabel = $invoice->isPurchase() ? 'المورد' : 'العميل';

    $invoiceNumber = '#'.$invoice->getKey();

    $itemsSubtotal = (float) (
        $invoice->subtotal
        ?: $invoice->lines->sum('line_total')
    );

    $discount = (float) (
        $invoice->discount_amount
        ?: max(
            0,
            round(
                $itemsSubtotal - (float) $invoice->total,
                2
            )
        )
    );

    $printFonts = [
        'cairo' => 'Cairo, Tahoma, Arial, sans-serif',
        'changa' => 'Changa, Tahoma, Arial, sans-serif',
        'reem-kufi' => '"Reem Kufi", Tahoma, Arial, sans-serif',
        'baloo' => '"Baloo Bhaijaan 2", Tahoma, Arial, sans-serif',
    ];

    $printFont = $printFonts[
        $settings->get('font_family', 'cairo')
    ] ?? $printFonts['cairo'];
@endphp

<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        {{ $typeLabel }} {{ $invoiceNumber }}
    </title>

    @vite([
        'resources/css/receipt.css',
        'resources/js/receipt.js'
    ])

    <style>
        :root {
            --receipt-width: {{ $width }}mm;
            --receipt-font: {!! $printFont !!};
        }
    </style>
    <style id="receipt-page-size"></style>
</head>

<body class="receipt-page" data-document-kind="{{ $invoice->isReturn() ? 'return' : 'invoice' }}">

<div class="receipt-preview">

    <main
        class="receipt receipt--{{ $width }}"
        aria-label="{{ $typeLabel }} {{ $invoiceNumber }}"
    >

        {{-- =========================
             CANCELLED
        ========================== --}}

        @if($invoice->isCancelled())
            <div
                style="
                    padding:6px;
                    margin-bottom:6px;
                    border:2px solid #000;
                    text-align:center;
                    font-weight:900;
                "
            >
                فاتورة ملغاة — ليست مستندًا ماليًا نشطًا
            </div>
        @endif


        {{-- =========================
             COMPANY NAME
        ========================== --}}

        <header class="receipt-header">

            @if($settings->get('receipt_show_company', true))
                <div class="receipt-brand">
                    @if($settings->get('receipt_show_logo', true) && filled($invoiceLogo))
                        <img class="receipt-brand__logo" src="{{ Storage::url($invoiceLogo) }}" alt="شعار {{ $companyName }}">
                    @endif
                    <div class="receipt-brand__copy">
                        <h1>
                            {{ $companyName }}
                        </h1>
                    </div>
                </div>
            @endif

        </header>


        {{-- =========================
             INVOICE INFORMATION
        ========================== --}}

        <div class="receipt-facts">

            {{-- رقم الفاتورة --}}
            @if($settings->get('receipt_show_number', true))
                <div class="receipt-fact-box">
                    <div
                        class="receipt-fact-value"
                        dir="ltr"
                    >
                        {{ $invoiceNumber }}
                    </div>
                </div>
            @endif


            {{-- التاريخ --}}
            <div class="receipt-fact-box">
                <div
                    class="receipt-fact-value"
                    dir="ltr"
                >
                    {{ $invoice->created_at->format('Y-m-d H:i') }}
                </div>
            </div>

            @if($invoice->isReturn() && $invoice->original_invoice_id)
                <div class="receipt-fact-box">
                    <div class="receipt-fact-value">
                        أصل الفاتورة #{{ $invoice->original_invoice_id }}
                    </div>
                </div>
            @endif


            {{-- رقم الهاتف --}}
            @if(
                $settings->get('receipt_show_phone', true)
                && filled($companyPhone)
            )
                <div class="receipt-fact-box">
                    <div
                        class="receipt-fact-value"
                        dir="ltr"
                    >
                        {{ $companyPhone }}
                    </div>
                </div>
            @endif


            {{-- العنوان --}}
            @if(filled($companyAddress))
                <div class="receipt-fact-box receipt-fact-box--address">
                    <div class="receipt-fact-value">
                        {{ $companyAddress }}
                    </div>
                </div>
            @endif

        </div>


        {{-- =========================
             CUSTOMER / SUPPLIER
        ========================== --}}

        @if(
            $settings->get('receipt_show_partner', true)
            && $invoice->partner
        )
            <div class="receipt-customer">

                <span>
                    {{ $partyLabel }}:
                </span>

                <strong>
                    {{ $invoice->partner->name }}
                </strong>

            </div>
        @endif


        {{-- =========================
             INVOICE ITEMS
        ========================== --}}

        <section
            class="receipt-items"
            aria-label="بنود الفاتورة"
        >

            <div class="receipt-section-label">
                بنود الفاتورة
            </div>

            <table>

                <thead>
                <tr>

                    <th>
                        الصنف
                    </th>

                    <th class="receipt-number">
                        الكمية
                    </th>

                    <th class="receipt-number">
                        السعر
                    </th>

                    <th class="receipt-number">
                        الإجمالي
                    </th>

                </tr>
                </thead>

                <tbody>

                @foreach($invoice->lines as $line)

                    <tr>

                        <td>
                            {{ $line->product_name }}
                        </td>

                        <td class="receipt-number">
                            {{ number_format((float) $line->quantity) }}
                        </td>

                        <td class="receipt-number">
                            {{ number_format(
                                (float) $line->unit_price,
                                2
                            ) }}
                        </td>

                        <td class="receipt-number">
                            {{ number_format(
                                (float) (
                                    $line->net_line_total
                                    ?? $line->line_total
                                ),
                                2
                            ) }}
                        </td>

                    </tr>

                @endforeach

                </tbody>

            </table>

        </section>


        {{-- =========================
             TOTALS
        ========================== --}}

        <section
            class="receipt-totals"
            aria-label="ملخص المبالغ"
        >

            {{-- المجموع --}}
            <div>
                <span>
                    المجموع
                </span>

                <b>
                    {{ number_format($itemsSubtotal, 2) }}
                    {{ $currency }}
                </b>
            </div>


            {{-- الحسم --}}
            @if($discount > 0)

                <div>

                    <span>
                        الحسم

                        @if($invoice->discount_type === 'percentage')
                            ({{ number_format(
                                (float) $invoice->discount_value,
                                2
                            ) }}٪)
                        @endif
                    </span>

                    <b>
                        {{ number_format($discount, 2) }}
                        {{ $currency }}
                    </b>

                </div>

            @endif


            {{-- الإجمالي --}}
            <div class="receipt-totals__grand">

                <span>
                    الإجمالي
                </span>

                <strong>
                    {{ number_format(
                        (float) $invoice->total,
                        2
                    ) }}
                    {{ $currency }}
                </strong>

            </div>


            {{-- المدفوع / الاسترداد النقدي --}}
            <div>

                <span>
                    {{ $invoice->isReturn() ? 'المسترد نقدًا' : 'المدفوع' }}
                </span>

                <b>
                    {{ number_format(
                        (float) $invoice->amount_paid,
                        2
                    ) }}
                    {{ $currency }}
                </b>

            </div>


            {{-- المتبقي / أثر المرتجع على الذمة --}}
            <div class="receipt-totals__remaining">

                <span>
                    {{ $invoice->isReturn() ? 'المخصوم من الذمة' : 'المتبقي' }}
                </span>

                <b>
                    {{ number_format(
                        $invoice->isReturn()
                            ? max(0, (float) $invoice->total - (float) $invoice->amount_paid)
                            : (float) $invoice->remaining,
                        2
                    ) }}
                    {{ $currency }}
                </b>

            </div>

        </section>


        {{-- =========================
             FOOTER
        ========================== --}}
@if($settings->get('receipt_show_footer', true))
    <footer class="receipt-footer">
        <div class="receipt-footer__line">
            <span class="receipt-footer__message">
                {{ $settings->get('receipt_footer', 'شكرًا لتعاملكم معنا') }}
            </span>

            <span class="receipt-footer__separator">—</span>

            <span class="receipt-footer__date" dir="rtl">
                طُبع في {{ now()->format('Y-m-d H:i') }}
            </span>
        </div>
    </footer>
@endif
        <div class="receipt-end-feed" aria-hidden="true"></div>
    </main>


    {{-- =========================
         SCREEN ACTIONS
    ========================== --}}

    <div
        class="receipt-screen-actions"
        aria-label="إجراءات الإيصال"
    >

        <button
            type="button"
            class="receipt-screen-actions__print"
            onclick="printReceipt()"
        >

            <svg
                viewBox="0 0 24 24"
                aria-hidden="true"
            >
                <path
                    d="
                        M7 8V3h10v5
                        M7 17h10v4H7z
                        M5 17H3v-7h18v7h-2
                        M17 12h1
                    "
                />
            </svg>

            <span>
                {{ $invoice->isReturn() ? 'طباعة المرتجع' : 'طباعة الإيصال' }}
            </span>

        </button>

        <button
            type="button"
            class="receipt-screen-actions__screenshot"
            data-filename="{{ $invoice->isReturn() ? 'return' : 'invoice' }}-{{ $invoice->getKey() }}.png"
            onclick="captureReceipt(this)"
        >
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M4 7h3l1.5-2h7L17 7h3v12H4zM12 10a3 3 0 1 0 0 6 3 3 0 0 0 0-6z" />
            </svg>
            <span data-screenshot-label>لقطة شاشة</span>
        </button>


        <button
            type="button"
            onclick="window.close()"
        >
            إغلاق
        </button>

    </div>

</div>


<script>
    (() => {
        const paperWidthMm = {{ (int) $width }};
        const THERMAL_END_FEED_MM = 12;
        const PRINT_PAGE_ROUNDING_MM = 2.5;
        const pageStyle = document.getElementById('receipt-page-size');
        let printInProgress = false;

        const nextPaint = () => new Promise((resolve) => {
            requestAnimationFrame(() => requestAnimationFrame(resolve));
        });

        const pixelsPerMillimeter = () => {
            const probe = document.createElement('div');
            probe.setAttribute('aria-hidden', 'true');
            probe.style.cssText = 'position:fixed;visibility:hidden;pointer-events:none;inset:auto auto -10000px -10000px;width:100mm;height:1mm;padding:0;border:0';
            document.body.appendChild(probe);

            const measured = probe.getBoundingClientRect().width / 100;
            probe.remove();

            return measured > 0 ? measured : (96 / 25.4);
        };

        const applyReceiptPageSize = () => {
            const receipt = document.querySelector('.receipt');

            if (!receipt || !pageStyle) {
                return;
            }

            const measuredHeightPixels = Math.max(
                receipt.scrollHeight,
                Math.ceil(receipt.getBoundingClientRect().height)
            );
            const endFeed = receipt.querySelector('.receipt-end-feed');
            const renderedEndFeedPixels = endFeed?.getBoundingClientRect().height || 0;
            const contentHeightPixels = Math.max(0, measuredHeightPixels - renderedEndFeedPixels);
            const heightMm = Math.ceil(
                (
                    (contentHeightPixels / pixelsPerMillimeter())
                    + THERMAL_END_FEED_MM
                    + PRINT_PAGE_ROUNDING_MM
                ) * 10
            ) / 10;

            pageStyle.textContent = `@page { size: ${paperWidthMm}mm ${heightMm}mm; margin: 0; }`;
        };

        const prepareReceiptForPrint = async () => {
            if (document.fonts?.ready) {
                try {
                    await document.fonts.ready;
                } catch (_) {
                    // A system fallback font remains usable when a local font fails to load.
                }
            }

            await nextPaint();
            applyReceiptPageSize();
        };

        window.printReceipt = async () => {
            if (printInProgress) {
                return;
            }

            printInProgress = true;

            try {
                await prepareReceiptForPrint();
                window.print();
            } catch (_) {
                printInProgress = false;
            }
        };

        addEventListener('beforeprint', applyReceiptPageSize);
        addEventListener('afterprint', () => {
            printInProgress = false;
        });

        addEventListener('load', async () => {
            await prepareReceiptForPrint();

            @if($settings->get('receipt_auto_print', true))
                setTimeout(() => window.printReceipt(), 100);
            @endif
        });
    })();
</script>

</body>
</html>
