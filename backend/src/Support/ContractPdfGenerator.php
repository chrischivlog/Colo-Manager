<?php

declare(strict_types=1);

namespace ColoManager\Support;

/**
 * Erzeugt eine unterschriftsfähige Standardbeauftragung aus dem unveränderlichen
 * Vertrags-Snapshot. Das Dokument verwendet ausschließlich PDF-Kernschriftarten
 * und bleibt dadurch auch im schlanken API-Container reproduzierbar.
 */
final class ContractPdfGenerator
{
    private const PAGE_WIDTH = 595.28;
    private const PAGE_HEIGHT = 841.89;

    public function __construct(private readonly DocumentBranding $branding = new DocumentBranding())
    {
    }

    /** @param array<string, mixed> $contract */
    public function generate(array $contract): string
    {
        $pages = [[]];
        $page = 0;
        $y = $this->pageHeader($pages[$page], $contract, 'VERTRAG');
        $counterparty = $contract['counterparty'] ?? [];
        $billingAddress = is_array($counterparty['billingAddress'] ?? null) ? $counterparty['billingAddress'] : [];

        $isAddendum = ($contract['agreementType'] ?? null) === 'addendum';
        $this->text($pages[$page], 48, $y, 'F2', 15, $isAddendum ? 'Vertragsnachtrag Colocation' : 'Standardbeauftragung Colocation', $this->branding->darkHex());
        $y -= 28;
        $this->text($pages[$page], 48, $y, 'F2', 8, 'VERTRAGSPARTEIEN', $this->branding->primaryHex());
        $y -= 18;
        $this->rect($pages[$page], 48, $y - 112, 499, 124, 'F8FAFC');
        $this->text($pages[$page], 62, $y - 10, 'F2', 9, 'Anbieter', $this->branding->darkHex());
        $this->text($pages[$page], 62, $y - 27, 'F1', 8.5, $this->branding->companyName, '334155');
        $this->text($pages[$page], 62, $y - 42, 'F1', 8, 'Anschrift und Vertretungsberechtigte gemäß Anbieter-Stammdaten', '64748B');
        $this->text($pages[$page], 315, $y - 10, 'F2', 9, 'Kunde', $this->branding->darkHex());
        $this->text($pages[$page], 315, $y - 27, 'F1', 8.5, (string) ($counterparty['company'] ?? 'Noch nicht zugeordnet'), '334155');
        $this->text($pages[$page], 315, $y - 42, 'F1', 8, (string) ($counterparty['contactName'] ?? ''), '64748B');
        $this->text($pages[$page], 315, $y - 57, 'F1', 8, (string) ($billingAddress['street'] ?? ''), '64748B');
        $this->text($pages[$page], 315, $y - 72, 'F1', 8, trim((string) ($billingAddress['postalCode'] ?? '') . ' ' . (string) ($billingAddress['city'] ?? '')), '64748B');
        $this->text($pages[$page], 315, $y - 87, 'F1', 8, $this->countryLabel((string) ($billingAddress['country'] ?? '')), '64748B');
        $this->text($pages[$page], 315, $y - 102, 'F1', 8, (string) ($counterparty['email'] ?? ''), '64748B');
        $y -= 142;

        $details = [
            ['Vertragsnummer', (string) ($contract['number'] ?? '')],
            ['Geplanter Beginn', $this->dateValue($contract['plannedStartDate'] ?? null)],
            ['Mindestlaufzeit', (string) ($contract['contractMonths'] ?? 12) . ' Monate'],
            ['Kündigungsfrist', (string) ($contract['noticeMonths'] ?? 3) . ' Monate'],
            ['Verlängerung', (string) ($contract['renewalMonths'] ?? 12) . ' Monate'],
            ['Abrechnung', $this->billingLabel((string) ($contract['billingInterval'] ?? 'monthly'))],
        ];
        if ($isAddendum) {
            $details[] = ['Nachtrag zu', (string) ($contract['parentContractNumber'] ?? '')];
            $details[] = ['Vertragsart', 'Ergaenzende Leistung'];
        }
        $this->text($pages[$page], 48, $y, 'F2', 8, 'VERTRAGSDATEN', $this->branding->primaryHex());
        $y -= 18;
        foreach (array_chunk($details, 2) as $row) {
            foreach ($row as $index => [$label, $value]) {
                $x = $index === 0 ? 48 : 315;
                $this->text($pages[$page], $x, $y, 'F1', 7.5, $label, '64748B');
                $this->text($pages[$page], $x + 88, $y, 'F2', 8.2, $value, $this->branding->darkHex());
            }
            $y -= 18;
        }
        $y -= 12;
        $this->text($pages[$page], 48, $y, 'F2', 12, 'Leistungsverzeichnis', $this->branding->darkHex());
        $y -= 21;
        $y = $this->tableHeader($pages[$page], $y);

        foreach ($contract['lineItems'] ?? [] as $index => $item) {
            $description = $this->wrap((string) ($item['description'] ?? ''), 50);
            $rowHeight = max(38, 29 + max(0, count($description) - 1) * 10);
            if ($y - $rowHeight < 125) {
                $pages[] = [];
                $page++;
                $y = $this->pageHeader($pages[$page], $contract, 'LEISTUNGEN');
                $y = $this->tableHeader($pages[$page], $y);
            }
            if ($index % 2 === 0) {
                $this->rect($pages[$page], 48, $y - $rowHeight + 4, 499, $rowHeight, 'F8FAFC');
            }
            $this->text($pages[$page], 58, $y - 12, 'F2', 8.8, (string) ($item['name'] ?? 'Leistung'), $this->branding->darkHex());
            $lineY = $y - 24;
            foreach (array_slice($description, 0, 3) as $line) {
                $this->text($pages[$page], 58, $lineY, 'F1', 7.2, $line, '64748B');
                $lineY -= 9;
            }
            $quantity = $this->number((float) ($item['quantity'] ?? 1)) . ' ' . (string) ($item['unit'] ?? 'Stk.');
            $this->text($pages[$page], 330, $y - 14, 'F1', 8, $quantity, '334155');
            $this->textRight($pages[$page], 455, $y - 14, 'F1', 8, $this->currency((float) ($item['oneTimeTotal'] ?? 0)), '334155');
            $this->textRight($pages[$page], 537, $y - 14, 'F2', 8, $this->currency((float) ($item['monthlyTotal'] ?? 0)), $this->branding->darkHex());
            $y -= $rowHeight;
        }

        if ($y < 205) {
            $pages[] = [];
            $page++;
            $y = $this->pageHeader($pages[$page], $contract, 'KOSTEN');
        }
        $totals = $contract['totals'] ?? [];
        $y -= 12;
        $this->rect($pages[$page], 315, $y - 82, 232, 94, $this->branding->lightHex());
        $this->text($pages[$page], 330, $y - 14, 'F1', 8.5, 'Einmalige Kosten netto', '475569');
        $this->textRight($pages[$page], 532, $y - 14, 'F2', 9, $this->currency((float) ($totals['oneTime'] ?? 0)), $this->branding->darkHex());
        $this->text($pages[$page], 330, $y - 39, 'F2', 8.5, 'Monatlicher Gesamtpreis netto', $this->branding->darkHex());
        $this->textRight($pages[$page], 532, $y - 58, 'F2', 12, $this->currency((float) ($totals['monthly'] ?? 0)), $this->branding->primaryHex());
        $this->text($pages[$page], 330, $y - 75, 'F1', 7, 'Alle Preise zuzüglich gesetzlicher Umsatzsteuer.', '64748B');

        $pages[] = [];
        $page++;
        $y = $this->pageHeader($pages[$page], $contract, 'VERTRAGSBEDINGUNGEN');
        $this->text($pages[$page], 48, $y, 'F2', 15, 'Vertragsbedingungen', $this->branding->darkHex());
        $y -= 28;
        $paragraphs = preg_split('/\R+/u', trim((string) ($contract['legalTerms'] ?? ''))) ?: [];
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            $isHeading = preg_match('/^\d+\.\s/u', $paragraph) === 1;
            $lines = $this->wrap($paragraph, $isHeading ? 88 : 104);
            $needed = count($lines) * ($isHeading ? 12 : 10) + ($isHeading ? 8 : 5);
            // Unterhalb dieses Sicherheitsabstands würden lange Klauseln mit
            // dem Seitenfuß konkurrieren. Die Signatur kann auf der Folgeseite
            // direkt unter den letzten Bedingungen anschließen.
            if ($y - $needed < 175) {
                $pages[] = [];
                $page++;
                $y = $this->pageHeader($pages[$page], $contract, 'VERTRAGSBEDINGUNGEN');
            }
            foreach ($lines as $line) {
                $this->text($pages[$page], 48, $y, $isHeading ? 'F2' : 'F1', $isHeading ? 9 : 7.8, $line, $isHeading ? $this->branding->darkHex() : '334155');
                $y -= $isHeading ? 12 : 10;
            }
            $y -= $isHeading ? 8 : 5;
        }

        if ($y < 245) {
            $pages[] = [];
            $page++;
            $y = $this->pageHeader($pages[$page], $contract, 'UNTERSCHRIFT');
        }
        $y -= 8;
        $this->line($pages[$page], 48, $y, 547, $y, 'DCE4F0');
        $y -= 26;
        $this->text($pages[$page], 48, $y, 'F2', 13, 'Verbindliche Beauftragung', $this->branding->darkHex());
        $y -= 20;
        foreach ($this->wrap('Mit ihrer Unterschrift bestätigen die Parteien den vorstehenden Leistungsumfang, die Preise und Vertragsbedingungen. Der Vertrag tritt zum ausgewiesenen Startdatum in Kraft.', 108) as $line) {
            $this->text($pages[$page], 48, $y, 'F1', 8, $line, '475569');
            $y -= 11;
        }
        $y -= 56;
        foreach ([[48, 'Für den Anbieter'], [315, 'Für den Kunden']] as [$x, $label]) {
            $this->line($pages[$page], $x, $y, $x + 215, $y, '94A3B8');
            $this->text($pages[$page], $x, $y - 14, 'F1', 7.5, 'Ort, Datum, Unterschrift', '64748B');
            $this->text($pages[$page], $x, $y - 31, 'F2', 8, $label, $this->branding->darkHex());
        }

        foreach ($pages as $index => &$operations) {
            $this->pageFooter($operations, $index + 1, count($pages));
        }
        unset($operations);
        return $this->buildPdf($pages);
    }

    /** @param list<string> $ops @param array<string, mixed> $contract */
    private function pageHeader(array &$ops, array $contract, string $section): float
    {
        $this->rect($ops, 0, 0, self::PAGE_WIDTH, self::PAGE_HEIGHT, 'FFFFFF');
        $this->rect($ops, 0, 754, self::PAGE_WIDTH, 88, $this->branding->darkHex());
        if ($section === 'VERTRAG') {
            $nameX = $this->drawBrandMark($ops, 48, 779, 34, 34);
            $this->text($ops, $nameX, 799, 'F2', $this->brandFontSize(16), $this->brandName(48), 'FFFFFF');
            $this->text($ops, $nameX, 784, 'F1', 7.2, 'DATACENTER SERVICES', $this->branding->mutedHex());
        } else {
            // Folgeseiten erhalten eine bewusst kompakte Dokumentkennung. So
            // bleibt auch bei langen Vertragsbedingungen mehr visuelle Ruhe.
            $nameX = $this->drawBrandMark($ops, 48, 782, 28, 26);
            $this->text($ops, $nameX, 794, 'F2', $this->brandFontSize(10), $this->brandName(38) . ' | ' . (string) ($contract['number'] ?? ''), 'FFFFFF');
        }
        $this->textRight($ops, 547, 799, 'F2', 13, $section, 'FFFFFF');
        if ($section === 'VERTRAG') {
            $this->textRight($ops, 547, 783, 'F1', 8, (string) ($contract['number'] ?? ''), $this->branding->mutedHex());
        }
        return 722;
    }

    /** @param list<string> $ops */
    private function tableHeader(array &$ops, float $y): float
    {
        $this->rect($ops, 48, $y - 21, 499, 26, $this->branding->darkHex());
        $this->text($ops, 58, $y - 12, 'F2', 7.2, 'POSITION / BESCHREIBUNG', 'FFFFFF');
        $this->text($ops, 330, $y - 12, 'F2', 7.2, 'MENGE', 'FFFFFF');
        $this->textRight($ops, 455, $y - 12, 'F2', 7.2, 'EINMALIG NETTO', 'FFFFFF');
        $this->textRight($ops, 532, $y - 12, 'F2', 6.9, 'MONATLICH NETTO', 'FFFFFF');
        return $y - 26;
    }

    /** @param list<string> $ops */
    private function pageFooter(array &$ops, int $page, int $total): void
    {
        $this->line($ops, 48, 52, 547, 52, 'DCE4F0');
        $this->text($ops, 48, 34, 'F1', 7.2, $this->branding->companyName . ' · Standardbeauftragung Colocation', '64748B');
        $this->textRight($ops, 547, 34, 'F1', 7.2, sprintf('Seite %d von %d', $page, $total), '64748B');
    }

    /** @param list<list<string>> $pages */
    private function buildPdf(array $pages): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $logo = $this->branding->jpegLogo();
        $logoResource = '';
        $kids = [];
        $next = 5;
        if ($logo !== null) {
            $objects[5] = sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
                $logo['width'],
                $logo['height'],
                strlen($logo['content']),
                $logo['content'],
            );
            $logoResource = ' /XObject << /Logo 5 0 R >>';
            $next = 6;
        }
        foreach ($pages as $operations) {
            $pageId = $next++;
            $contentId = $next++;
            $kids[] = $pageId . ' 0 R';
            $content = implode("\n", $operations);
            $objects[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>%s >> /Contents %d 0 R >>', self::PAGE_WIDTH, self::PAGE_HEIGHT, $logoResource, $contentId);
            $objects[$contentId] = "<< /Length " . strlen($content) . ">>\nstream\n" . $content . "\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= count($objects); $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    }

    /** @param list<string> $ops */
    private function text(array &$ops, float $x, float $y, string $font, float $size, string $text, string $color): void
    {
        $ops[] = sprintf('BT /%s %.2F Tf %s rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET', $font, $size, $this->rgb($color), $x, $y, $this->escape($text));
    }

    /** @param list<string> $ops */
    private function textRight(array &$ops, float $right, float $y, string $font, float $size, string $text, string $color): void
    {
        $width = strlen($this->encode($text)) * $size * ($font === 'F2' ? 0.54 : 0.5);
        $this->text($ops, max(10, $right - $width), $y, $font, $size, $text, $color);
    }

    /** @param list<string> $ops */
    private function rect(array &$ops, float $x, float $y, float $w, float $h, string $color): void
    {
        $ops[] = sprintf('q %s rg %.2F %.2F %.2F %.2F re f Q', $this->rgb($color), $x, $y, $w, $h);
    }

    /** @param list<string> $ops */
    private function line(array &$ops, float $x1, float $y1, float $x2, float $y2, string $color): void
    {
        $ops[] = sprintf('q %s RG 0.7 w %.2F %.2F m %.2F %.2F l S Q', $this->rgb($color), $x1, $y1, $x2, $y2);
    }

    /** Zeichnet Logo oder Initialen und gibt die X-Position des Namens zurück. */
    private function drawBrandMark(array &$ops, float $x, float $y, float $width, float $height): float
    {
        $logo = $this->branding->jpegLogo();
        if ($logo === null) {
            $this->rect($ops, $x, $y, $width, $height, $this->branding->primaryHex());
            $this->text($ops, $x + 7, $y + ($height * 0.34), 'F2', min(12, $height * 0.34), $this->branding->initials(), 'FFFFFF');
            return $x + $width + 14;
        }

        $this->rect($ops, $x, $y, $width + 18, $height, 'FFFFFF');
        $scale = min(($width + 10) / $logo['width'], ($height - 8) / $logo['height']);
        $drawWidth = $logo['width'] * $scale;
        $drawHeight = $logo['height'] * $scale;
        $drawX = $x + (($width + 18 - $drawWidth) / 2);
        $drawY = $y + (($height - $drawHeight) / 2);
        $ops[] = sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /Logo Do Q', $drawWidth, $drawHeight, $drawX, $drawY);
        return $x + $width + 30;
    }

    private function brandName(int $limit): string
    {
        return mb_strlen($this->branding->companyName) <= $limit
            ? $this->branding->companyName
            : mb_substr($this->branding->companyName, 0, $limit - 1) . '…';
    }

    private function brandFontSize(float $preferred): float
    {
        $length = mb_strlen($this->branding->companyName);
        return $length <= 24 ? $preferred : ($length <= 38 ? $preferred - 2 : $preferred - 4);
    }

    private function rgb(string $hex): string
    {
        return sprintf('%.3F %.3F %.3F', hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255);
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $this->encode($value));
    }

    private function encode(string $value): string
    {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
        return is_string($encoded) ? $encoded : $value;
    }

    /** @return list<string> */
    private function wrap(string $value, int $length): array
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        return $value === '' ? [] : explode("\n", wordwrap($value, $length, "\n", true));
    }

    private function currency(float $value): string
    {
        return number_format($value, 2, ',', '.') . ' EUR';
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }

    private function billingLabel(string $value): string
    {
        return ['monthly' => 'Monatlich', 'quarterly' => 'Quartalsweise', 'yearly' => 'Jährlich'][$value] ?? $value;
    }

    /** Übersetzt die ISO-Ländercodes des Vertragssnapshots für das Dokument. */
    private function countryLabel(string $country): string
    {
        return match (strtoupper($country)) {
            'DE' => 'Deutschland', 'AT' => 'Österreich', 'CH' => 'Schweiz',
            'NL' => 'Niederlande', 'BE' => 'Belgien', 'LU' => 'Luxemburg',
            'FR' => 'Frankreich', 'PL' => 'Polen', 'CZ' => 'Tschechien',
            'DK' => 'Dänemark', default => strtoupper($country),
        };
    }

    private function dateValue(mixed $value): string
    {
        try {
            if ($value instanceof \MongoDB\BSON\UTCDateTime) {
                return $value->toDateTime()->format('d.m.Y');
            }
            return empty($value) ? 'Nach Vereinbarung' : (new \DateTimeImmutable((string) $value))->format('d.m.Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    }
}
