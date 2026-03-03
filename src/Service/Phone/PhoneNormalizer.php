<?php

// src/Service/Phone/PhoneNormalizer.php
namespace App\Service\Phone;

use libphonenumber\PhoneNumberUtil;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;

class PhoneNormalizer
{
    public function __construct(private string $defaultRegion = 'FR') {}

    /**
     * @return string|null  Numéro au format E.164 (+33612345678) ou null si vide/invalid
     */
    public function normalize(?string $raw, ?string $region = null): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') return null;

        // Tolérances courantes
        $raw = str_replace(['.', ' ', '-', '(', ')'], '', $raw);

        $region = $region ?: $this->defaultRegion;
        $util = PhoneNumberUtil::getInstance();

        try {
            $num = $util->parse($raw, $region);
            if (!$util->isValidNumber($num)) {
                return null;
            }
            return $util->format($num, PhoneNumberFormat::E164); // +33612345678
        } catch (NumberParseException) {
            return null;
        }
    }
}
