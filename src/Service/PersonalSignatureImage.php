<?php
namespace App\Service;
final class PersonalSignatureImage
{
    public function normalize(string $value): string
    {
        if (strlen($value) > 700000 || !preg_match('#^data:image/(png|jpeg);base64,(.+)$#s', $value, $m)) {
            throw new \InvalidArgumentException('Importez une image PNG ou JPEG de moins de 500 Ko.');
        }
        $bytes = base64_decode($m[2], true);
        $info = $bytes === false ? false : @getimagesizefromstring($bytes);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true) || $info[0] > 2400 || $info[1] > 1200 || strlen($bytes) > 512000) {
            throw new \InvalidArgumentException('Image invalide : maximum 2 400 × 1 200 pixels et 500 Ko.');
        }
        $image = @imagecreatefromstring($bytes);
        if (!$image) throw new \InvalidArgumentException('Cette image ne peut pas être lue.');
        imagesavealpha($image, true);
        ob_start(); imagepng($image); $clean = ob_get_clean(); imagedestroy($image);
        return 'data:image/png;base64,'.base64_encode($clean);
    }
}
