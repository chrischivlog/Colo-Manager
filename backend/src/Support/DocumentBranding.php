<?php

declare(strict_types=1);

namespace ColoManager\Support;

/**
 * Unveränderlicher Branding-Snapshot für Dokumente und E-Mails.
 *
 * Der Snapshot entkoppelt die Ausgabe von MongoDB und stellt neben den
 * konfigurierten Werten auch gut lesbare Farbvarianten sowie ein für rohe
 * PDF-XObjects geeignetes JPEG des Logos bereit.
 */
final class DocumentBranding
{
    private bool $logoResolved = false;

    /** @var array{content: string, width: int, height: int}|null */
    private ?array $jpegLogo = null;

    public readonly string $companyName;
    public readonly string $primaryColor;

    public function __construct(
        string $companyName = 'COLO MANAGER',
        string $primaryColor = '#0667F9',
        public readonly ?string $logoContent = null,
        public readonly ?string $logoMimeType = null,
        public readonly ?string $logoUrl = null,
        private readonly ?\Closure $logoLoader = null,
    ) {
        $companyName = trim($companyName);
        $this->companyName = $companyName !== '' ? $companyName : 'COLO MANAGER';
        $primaryColor = strtoupper(trim($primaryColor));
        $this->primaryColor = preg_match('/^#[0-9A-F]{6}$/', $primaryColor) === 1
            ? $primaryColor
            : '#0667F9';
    }

    /** Primärfarbe ohne führendes Rautezeichen für PDF-Zeichenoperationen. */
    public function primaryHex(): string
    {
        return substr($this->primaryColor, 1);
    }

    /** Dunkle, kontrastreiche Variante für Kopfzeilen und Überschriften. */
    public function darkHex(): string
    {
        return $this->mix($this->primaryHex(), '00133F', 0.36);
    }

    /** Sehr helle Variante für Summenboxen und dezente Flächen. */
    public function lightHex(): string
    {
        return $this->mix($this->primaryHex(), 'FFFFFF', 0.09);
    }

    /** Helle Variante für Text auf dunklen, gebrandeten Flächen. */
    public function mutedHex(): string
    {
        return $this->mix($this->primaryHex(), 'FFFFFF', 0.32);
    }

    /** Zwei Initialen als belastbarer Fallback, wenn kein Logo hinterlegt ist. */
    public function initials(): string
    {
        $words = preg_split('/\s+/u', trim($this->companyName)) ?: [];
        $initials = '';
        foreach (array_slice($words, 0, 2) as $word) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        }
        return $initials !== '' ? $initials : 'DC';
    }

    /**
     * Normalisiert PNG, JPEG oder WebP einmalig zu einem DeviceRGB-JPEG.
     * Transparente Bereiche werden auf Weiß gesetzt, damit sie in PDF-Readern
     * unabhängig von Alpha-Masken sauber dargestellt werden.
     *
     * @return array{content: string, width: int, height: int}|null
     */
    public function jpegLogo(): ?array
    {
        if ($this->logoResolved) {
            return $this->jpegLogo;
        }
        $this->logoResolved = true;
        $logoContent = $this->logoContent;
        if (($logoContent === null || $logoContent === '') && $this->logoLoader !== null) {
            try {
                $loaded = ($this->logoLoader)();
                $logoContent = is_array($loaded) ? (string) ($loaded['content'] ?? '') : null;
            } catch (\Throwable) {
                $logoContent = null;
            }
        }
        if ($logoContent === null || $logoContent === '' || !function_exists('imagecreatefromstring')) {
            return null;
        }

        $source = @imagecreatefromstring($logoContent);
        if (!$source instanceof \GdImage) {
            return null;
        }
        $width = imagesx($source);
        $height = imagesy($source);
        if ($width < 1 || $height < 1) {
            imagedestroy($source);
            return null;
        }

        $canvas = imagecreatetruecolor($width, $height);
        if (!$canvas instanceof \GdImage) {
            imagedestroy($source);
            return null;
        }
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagealphablending($canvas, true);
        imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);

        ob_start();
        imagejpeg($canvas, null, 92);
        $jpeg = ob_get_clean();
        imagedestroy($canvas);
        imagedestroy($source);
        if (!is_string($jpeg) || $jpeg === '') {
            return null;
        }

        return $this->jpegLogo = ['content' => $jpeg, 'width' => $width, 'height' => $height];
    }

    private function mix(string $foreground, string $background, float $foregroundShare): string
    {
        $components = [];
        for ($offset = 0; $offset < 6; $offset += 2) {
            $front = hexdec(substr($foreground, $offset, 2));
            $back = hexdec(substr($background, $offset, 2));
            $components[] = (int) round(($front * $foregroundShare) + ($back * (1 - $foregroundShare)));
        }
        return sprintf('%02X%02X%02X', ...$components);
    }
}
