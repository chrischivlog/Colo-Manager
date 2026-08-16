<?php

declare(strict_types=1);

namespace ColoManager\Support;

/**
 * Kleine, abhängigkeitenfreie PDF-Erzeugung für strukturierte Angebote.
 * Kernschriftarten und ein bewusst ruhiges Layout halten das Dokument auch in
 * minimalen Docker-Images reproduzierbar und langfristig lesbar.
 */
final class OfferPdfGenerator
{
    private const PAGE_WIDTH = 595.28;
    private const PAGE_HEIGHT = 841.89;

    public function __construct(private readonly DocumentBranding $branding = new DocumentBranding())
    {
    }

    /** @param array<string, mixed> $ticket @param array<string, mixed> $draft */
    public function generate(array $ticket, array $draft, int $round): string
    {
        $pages = [[]];
        $page = 0;
        $y = $this->pageHeader($pages[$page], $ticket, $round);
        $recipient = $ticket['requester'] ?? [];
        $billingAddress = is_array($recipient['billingAddress'] ?? null) ? $recipient['billingAddress'] : [];
        $this->text($pages[$page], 48, $y, 'F2', 9, 'ANGEBOT FÜR', $this->branding->primaryHex());
        $y -= 18;
        $this->text($pages[$page], 48, $y, 'F2', 13, (string) ($recipient['company'] ?? $recipient['name'] ?? 'Interessent'), $this->branding->darkHex());
        $recipientLines = array_values(array_filter([
            (string) ($recipient['name'] ?? ''),
            (string) ($billingAddress['street'] ?? ''),
            trim((string) ($billingAddress['postalCode'] ?? '') . ' ' . (string) ($billingAddress['city'] ?? '')),
            $this->countryLabel((string) ($billingAddress['country'] ?? '')),
            (string) ($recipient['email'] ?? ''),
        ], static fn (string $line): bool => trim($line) !== ''));
        foreach ($recipientLines as $line) {
            $y -= 14;
            $this->text($pages[$page], 48, $y, 'F1', 9, $line, '334155');
        }

        $detailX = 360;
        $detailY = 672;
        $details = [
            ['Angebotsrunde', (string) $round],
            ['Gültig bis', $this->date((string) ($draft['validUntil'] ?? ''))],
            ['Laufzeit', (string) ($draft['contractMonths'] ?? 12) . ' Monate'],
            ['Geplanter Start', $this->date((string) ($draft['plannedStartDate'] ?? '')) ?: 'Nach Vereinbarung'],
        ];
        foreach ($details as [$label, $value]) {
            $this->text($pages[$page], $detailX, $detailY, 'F1', 8, $label, '64748B');
            $this->text($pages[$page], $detailX + 82, $detailY, 'F2', 8.5, $value, $this->branding->darkHex());
            $detailY -= 17;
        }

        $y -= 28;
        $this->line($pages[$page], 48, $y, 547, $y, 'DCE4F0');
        $y -= 28;
        $this->text($pages[$page], 48, $y, 'F2', 15, 'Leistungsübersicht', $this->branding->darkHex());
        $y -= 25;
        $y = $this->tableHeader($pages[$page], $y);

        foreach ($draft['lineItems'] ?? [] as $index => $item) {
            $descriptionLines = $this->wrap((string) ($item['description'] ?? ''), 52);
            $rowHeight = max(44, 32 + max(0, count($descriptionLines) - 1) * 11);
            if ($y - $rowHeight < 120) {
                $pages[] = [];
                $page++;
                $y = $this->continuationHeader($pages[$page], $ticket, $round);
                $y = $this->tableHeader($pages[$page], $y);
            }
            if ($index % 2 === 0) {
                $this->rect($pages[$page], 48, $y - $rowHeight + 5, 499, $rowHeight, 'F8FAFC');
            }
            $this->text($pages[$page], 58, $y - 13, 'F2', 9.5, (string) ($item['name'] ?? 'Leistung'), $this->branding->darkHex());
            $lineY = $y - 27;
            foreach (array_slice($descriptionLines, 0, 3) as $line) {
                $this->text($pages[$page], 58, $lineY, 'F1', 7.8, $line, '64748B');
                $lineY -= 10;
            }
            $quantity = $this->number((float) ($item['quantity'] ?? 1)) . ' ' . (string) ($item['unit'] ?? 'Stk.');
            $this->text($pages[$page], 330, $y - 16, 'F1', 8.5, $quantity, '334155');
            $this->textRight($pages[$page], 455, $y - 16, 'F1', 8.5, $this->currency((float) ($item['oneTimeTotal'] ?? 0)), '334155');
            $this->textRight($pages[$page], 537, $y - 16, 'F2', 8.5, $this->currency((float) ($item['monthlyTotal'] ?? 0)), $this->branding->darkHex());
            $y -= $rowHeight;
        }

        if ($y < 215) {
            $pages[] = [];
            $page++;
            $y = $this->continuationHeader($pages[$page], $ticket, $round);
        }
        $totals = $draft['totals'] ?? [];
        $y -= 16;
        $this->rect($pages[$page], 322, $y - 94, 225, 104, $this->branding->lightHex());
        $this->text($pages[$page], 338, $y - 15, 'F1', 9, 'Einmalige Kosten netto', '475569');
        $this->textRight($pages[$page], 530, $y - 15, 'F2', 10, $this->currency((float) ($totals['oneTime'] ?? 0)), $this->branding->darkHex());
        $this->text($pages[$page], 338, $y - 42, 'F2', 9, 'Monatlicher Gesamtpreis netto', $this->branding->darkHex());
        $this->textRight($pages[$page], 530, $y - 62, 'F2', 14, $this->currency((float) ($totals['monthly'] ?? 0)), $this->branding->primaryHex());
        $this->text($pages[$page], 338, $y - 82, 'F1', 7.5, 'Alle Preise netto zuzüglich gesetzlicher Umsatzsteuer.', '64748B');

        $notes = trim((string) ($draft['notes'] ?? ''));
        if ($notes !== '') {
            $noteY = $y - 124;
            $this->text($pages[$page], 48, $noteY, 'F2', 9, 'Hinweise', $this->branding->darkHex());
            foreach (array_slice($this->wrap($notes, 105), 0, 5) as $line) {
                $noteY -= 12;
                $this->text($pages[$page], 48, $noteY, 'F1', 8.2, $line, '475569');
            }
        }
        foreach ($pages as $index => &$operations) {
            $this->pageFooter($operations, $index + 1);
        }
        unset($operations);
        return $this->buildPdf($pages);
    }

    /** @param list<string> $ops @param array<string, mixed> $ticket */
    private function pageHeader(array &$ops, array $ticket, int $round): float
    {
        $this->rect($ops, 0, 0, self::PAGE_WIDTH, self::PAGE_HEIGHT, 'FFFFFF');
        $this->rect($ops, 0, 722, self::PAGE_WIDTH, 120, $this->branding->darkHex());
        $nameX = $this->drawBrandMark($ops, 48, 768, 38, 38);
        $this->text($ops, $nameX, 790, 'F2', $this->brandFontSize(17), $this->brandName(48), 'FFFFFF');
        $this->text($ops, $nameX, 775, 'F1', 7.5, 'DATACENTER SERVICES', $this->branding->mutedHex());
        $this->textRight($ops, 547, 788, 'F2', 22, 'ANGEBOT', 'FFFFFF');
        $this->textRight($ops, 547, 770, 'F1', 8.5, (string) ($ticket['number'] ?? '') . ' / Runde ' . $round, $this->branding->mutedHex());
        return 690;
    }

    /** @param list<string> $ops @param array<string, mixed> $ticket */
    private function continuationHeader(array &$ops, array $ticket, int $round): float
    {
        $this->rect($ops, 0, 0, self::PAGE_WIDTH, self::PAGE_HEIGHT, 'FFFFFF');
        $this->rect($ops, 0, 772, self::PAGE_WIDTH, 70, $this->branding->darkHex());
        $nameX = $this->drawBrandMark($ops, 48, 783, 30, 28);
        $this->text($ops, $nameX, 802, 'F2', $this->brandFontSize(14), $this->brandName(48), 'FFFFFF');
        $this->textRight($ops, 547, 802, 'F2', 12, 'ANGEBOT - FORTSETZUNG', 'FFFFFF');
        $this->textRight($ops, 547, 786, 'F1', 8, (string) ($ticket['number'] ?? '') . ' / Runde ' . $round, $this->branding->mutedHex());
        return 740;
    }

    /** @param list<string> $ops */
    private function tableHeader(array &$ops, float $y): float
    {
        $this->rect($ops, 48, $y - 23, 499, 28, $this->branding->darkHex());
        $this->text($ops, 58, $y - 13, 'F2', 7.5, 'POSITION / BESCHREIBUNG', 'FFFFFF');
        $this->text($ops, 330, $y - 13, 'F2', 7.5, 'MENGE', 'FFFFFF');
        $this->textRight($ops, 455, $y - 13, 'F2', 7.5, 'EINMALIG NETTO', 'FFFFFF');
        $this->textRight($ops, 532, $y - 13, 'F2', 7.2, 'MONATLICH NETTO', 'FFFFFF');
        return $y - 28;
    }

    /** @param list<string> $ops */
    private function pageFooter(array &$ops, int $page): void
    {
        $this->line($ops, 48, 52, 547, 52, 'DCE4F0');
        $this->text($ops, 48, 34, 'F1', 7.5, $this->branding->companyName . ' · Individuelles Colocation-Angebot', '64748B');
        $this->textRight($ops, 547, 34, 'F1', 7.5, 'Seite ' . $page, '64748B');
    }

    /** @param list<list<string>> $pages */
    private function buildPdf(array $pages): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $logo = $this->branding->jpegLogo();
        $logoResource = '';
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
        $kids = [];
        foreach ($pages as $operations) {
            $pageId = $next++;
            $contentId = $next++;
            $kids[] = $pageId . ' 0 R';
            $content = implode("\n", $operations);
            $objects[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>%s >> /Contents %d 0 R >>', self::PAGE_WIDTH, self::PAGE_HEIGHT, $logoResource, $contentId);
            $objects[$contentId] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
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
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        return $pdf;
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
            $this->text($ops, $x + 8, $y + ($height * 0.34), 'F2', min(13, $height * 0.34), $this->branding->initials(), 'FFFFFF');
            return $x + $width + 15;
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
        return $length <= 24 ? $preferred : ($length <= 38 ? $preferred - 3 : $preferred - 5);
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

    private function date(string $value): string
    {
        try {
            return $value === '' ? '' : (new \DateTimeImmutable($value))->format('d.m.Y');
        } catch (\Throwable) {
            return $value;
        }
    }

    /** Übersetzt die ISO-Ländercodes der Lead-Anschrift für das Angebot. */
    private function countryLabel(string $country): string
    {
        return match (strtoupper($country)) {
            'DE' => 'Deutschland', 'AT' => 'Österreich', 'CH' => 'Schweiz',
            'NL' => 'Niederlande', 'BE' => 'Belgien', 'LU' => 'Luxemburg',
            'FR' => 'Frankreich', 'PL' => 'Polen', 'CZ' => 'Tschechien',
            'DK' => 'Dänemark', default => strtoupper($country),
        };
    }
}
