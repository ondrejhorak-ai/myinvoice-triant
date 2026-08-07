<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Service;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

final class QuoteImageService
{
    public const MAX_INPUT_BYTES = 10 * 1024 * 1024;
    public const MAX_PIXELS = 25_000_000;
    public const MAX_DIMENSION = 640;
    public const MAX_OUTPUT_BYTES = 300 * 1024;

    public function __construct(private readonly Connection $db) {}

    /** @return array{id:int,url:string,width_px:int,height_px:int,size_bytes:int} */
    public function process(string $sourcePath, int $supplierId): array
    {
        $jpeg = $this->normaliseUploadedFile($sourcePath);
        $hash = hash('sha256', $jpeg['bytes']);
        $storedName = $hash . '.jpg';
        $dir = self::supplierDir($supplierId);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new QuoteImageException('storage_failed', 'Nepodařilo se připravit úložiště obrázků.', 500);
        }
        $path = $dir . '/' . $storedName;
        if (!is_file($path)) {
            $tmp = $dir . '/.upload-' . bin2hex(random_bytes(8));
            if (@file_put_contents($tmp, $jpeg['bytes'], LOCK_EX) === false) {
                @unlink($tmp);
                throw new QuoteImageException('storage_failed', 'Obrázek se nepodařilo uložit.', 500);
            }
            if (!@rename($tmp, $path)) {
                // Na Windows rename nepřepíše soubor, který mohl souběžně
                // vytvořit druhý totožný upload. V takovém případě je hotovo.
                if (!is_file($path)) {
                    @unlink($tmp);
                    throw new QuoteImageException('storage_failed', 'Obrázek se nepodařilo uložit.', 500);
                }
                @unlink($tmp);
            }
        }

        $pdo = $this->db->pdo();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO tri_quote_images
                    (supplier_id, stored_name, sha256, width_px, height_px, size_bytes)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $supplierId,
                $storedName,
                $hash,
                $jpeg['width'],
                $jpeg['height'],
                strlen($jpeg['bytes']),
            ]);
            $id = (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            // Obnovit ochrannou 24h lhůtu i u deduplikovaného, právě znovu
            // nahraného assetu, který zatím nemusí být připojen k řádku.
            $pdo->prepare(
                'UPDATE tri_quote_images SET last_uploaded_at = CURRENT_TIMESTAMP WHERE supplier_id = ? AND sha256 = ?'
            )->execute([$supplierId, $hash]);
            $stmt = $pdo->prepare('SELECT id FROM tri_quote_images WHERE supplier_id = ? AND sha256 = ?');
            $stmt->execute([$supplierId, $hash]);
            $id = (int) $stmt->fetchColumn();
            if ($id <= 0) {
                throw $e;
            }
        }

        return [
            'id'         => $id,
            'url'        => self::url($id, $hash),
            'width_px'   => $jpeg['width'],
            'height_px'  => $jpeg['height'],
            'size_bytes' => strlen($jpeg['bytes']),
        ];
    }

    /** @return array{bytes:string,width:int,height:int} */
    public function normaliseUploadedFile(string $sourcePath): array
    {
        $size = is_file($sourcePath) ? (int) filesize($sourcePath) : 0;
        if ($size <= 0) {
            throw new QuoteImageException('empty_file', 'Soubor je prázdný.');
        }
        if ($size > self::MAX_INPUT_BYTES) {
            throw new QuoteImageException('file_too_large', 'Obrázek je příliš velký (max 10 MiB).', 413);
        }

        $info = @getimagesize($sourcePath);
        if ($info === false || !isset($info[0], $info[1], $info[2])) {
            throw new QuoteImageException('invalid_image', 'Soubor není platný obrázek.');
        }
        $width = (int) $info[0];
        $height = (int) $info[1];
        if ($width < 1 || $height < 1 || $width > 20_000 || $height > 20_000
            || $width * $height > self::MAX_PIXELS) {
            throw new QuoteImageException('image_too_large', 'Obrázek má příliš velké rozměry.', 413);
        }
        if (!in_array((int) $info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            throw new QuoteImageException('unsupported_image', 'Podporované formáty jsou JPG, PNG, WebP a GIF.', 415);
        }
        if (!function_exists('imagecreatefromstring')) {
            throw new QuoteImageException('image_processing_unavailable', 'Server nemá dostupné zpracování obrázků.', 500);
        }

        $bytes = (string) file_get_contents($sourcePath);
        $source = @imagecreatefromstring($bytes);
        if (!$source instanceof \GdImage) {
            throw new QuoteImageException('invalid_image', 'Obrázek se nepodařilo dekódovat.');
        }

        try {
            if ((int) $info[2] === IMAGETYPE_JPEG) {
                $source = $this->orientJpeg($source, $sourcePath);
            }
            return $this->normalise($source);
        } finally {
            unset($source);
        }
    }

    /** @return array{id:int,stored_name:string,sha256:string,width_px:int,height_px:int,size_bytes:int}|null */
    public function find(int $imageId, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, stored_name, sha256, width_px, height_px, size_bytes
               FROM tri_quote_images WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$imageId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'id'         => (int) $row['id'],
            'stored_name'=> (string) $row['stored_name'],
            'sha256'     => (string) $row['sha256'],
            'width_px'   => (int) $row['width_px'],
            'height_px'  => (int) $row['height_px'],
            'size_bytes' => (int) $row['size_bytes'],
        ];
    }

    public function resolvePath(array $image, int $supplierId): ?string
    {
        $storedName = (string) ($image['stored_name'] ?? '');
        if (!preg_match('/\A[a-f0-9]{64}\.jpg\z/', $storedName)) {
            return null;
        }
        $base = realpath(self::supplierDir($supplierId));
        $path = realpath(self::supplierDir($supplierId) . '/' . $storedName);
        if ($base === false || $path === false || !is_file($path)) {
            return null;
        }
        $prefix = strtolower($base) . DIRECTORY_SEPARATOR;

        return str_starts_with(strtolower($path), $prefix) ? $path : null;
    }

    public static function supplierDir(int $supplierId): string
    {
        return RuntimePaths::storage('tri-quote-images') . '/sup-' . $supplierId;
    }

    public static function url(int $id, string $hash): string
    {
        return '/api/tri/quote-images/' . $id . '?v=' . substr($hash, 0, 12);
    }

    /** @return array{bytes:string,width:int,height:int} */
    private function normalise(\GdImage $source): array
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $limit = self::MAX_DIMENSION;

        while ($limit >= 320) {
            $scale = min($limit / $sourceWidth, $limit / $sourceHeight, 1.0);
            $width = max(1, (int) round($sourceWidth * $scale));
            $height = max(1, (int) round($sourceHeight * $scale));
            $target = imagecreatetruecolor($width, $height);
            imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
            imagealphablending($target, true);
            imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

            try {
                for ($quality = 84; $quality >= 60; $quality -= 6) {
                    ob_start();
                    imagejpeg($target, null, $quality);
                    $bytes = (string) ob_get_clean();
                    if ($bytes !== '' && strlen($bytes) <= self::MAX_OUTPUT_BYTES) {
                        return ['bytes' => $bytes, 'width' => $width, 'height' => $height];
                    }
                }
            } finally {
                unset($target);
            }
            $limit = (int) floor($limit * 0.8);
        }

        throw new QuoteImageException('image_processing_failed', 'Obrázek se nepodařilo dostatečně zmenšit.');
    }

    private function orientJpeg(\GdImage $image, string $path): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        if ($orientation === 2) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
            return $image;
        }
        if ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
            return $image;
        }
        $angle = match ($orientation) {
            3 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, imagecolorallocate($image, 255, 255, 255));
        if (!$rotated instanceof \GdImage) {
            return $image;
        }
        unset($image);
        if (in_array($orientation, [5, 7], true)) {
            imageflip($rotated, IMG_FLIP_HORIZONTAL);
        }

        return $rotated;
    }
}
