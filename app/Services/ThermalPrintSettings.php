<?php

namespace App\Services;

final class ThermalPrintSettings
{
    /** @var array<string, string> */
    private const FONT_FAMILIES = [
        'cairo' => 'Cairo, Tahoma, Arial, sans-serif',
        'changa' => 'Changa, Tahoma, Arial, sans-serif',
        'reem-kufi' => '"Reem Kufi", Tahoma, Arial, sans-serif',
        'baloo' => '"Baloo Bhaijaan 2", Tahoma, Arial, sans-serif',
    ];

    public function __construct(private readonly SettingsService $settings) {}

    /** @return array<string, mixed> */
    public function resolve(): array
    {
        $width = (int) $this->settings->get('receipt_width', 80);
        $width = in_array($width, [72, 80], true) ? $width : 80;
        $fontKey = (string) $this->settings->get('font_family', 'cairo');

        return [
            'paper_width_mm' => $width,
            'safe_margin_mm' => 2,
            'content_width_mm' => $width - 4,
            'is_narrow' => $width <= 72,
            'font_key' => array_key_exists($fontKey, self::FONT_FAMILIES) ? $fontKey : 'cairo',
            'font_family' => self::FONT_FAMILIES[$fontKey] ?? self::FONT_FAMILIES['cairo'],
            'font_size_px' => max(10, min(16, (int) $this->settings->get('receipt_font_size', 12))),
            'company_name' => (string) $this->settings->get('company_name', $this->settings->get('site_name', 'Khaloo')),
            'company_phones' => $this->settings->invoicePhones(),
            'company_address' => trim((string) $this->settings->get('company_address', $this->settings->get('address', ''))),
            'logo' => $this->settings->get('invoice_logo', $this->settings->get('logo')),
            'footer' => trim((string) $this->settings->get('receipt_footer', '')),
            'currency' => trim((string) $this->settings->get('currency_symbol', '')),
            'show_logo' => (bool) $this->settings->get('receipt_show_logo', true),
            'show_company' => (bool) $this->settings->get('receipt_show_company', true),
            'show_phone' => (bool) $this->settings->get('receipt_show_phone', true),
            'show_address' => (bool) $this->settings->get('receipt_show_address', true),
            'show_partner' => (bool) $this->settings->get('receipt_show_partner', true),
            'show_number' => (bool) $this->settings->get('receipt_show_number', true),
            'show_footer' => (bool) $this->settings->get('receipt_show_footer', true),
            'feed_lines' => max(1, min(10, (int) $this->settings->get('receipt_cut_feed_lines', 3))),
        ];
    }
}
