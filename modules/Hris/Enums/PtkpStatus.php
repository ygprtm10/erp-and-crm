<?php

declare(strict_types=1);

namespace Modules\Hris\Enums;

/**
 * Official Indonesian PPh 21 PTKP statuses and PP 58/2023 TER category mappings.
 */
enum PtkpStatus: string
{
    case Tk0 = 'TK/0';
    case Tk1 = 'TK/1';
    case Tk2 = 'TK/2';
    case Tk3 = 'TK/3';
    case K0 = 'K/0';
    case K1 = 'K/1';
    case K2 = 'K/2';
    case K3 = 'K/3';

    /**
     * Return the PP 58/2023 TER category (A, B, or C) corresponding to this PTKP status.
     */
    public function terCategory(): string
    {
        return match ($this) {
            self::Tk0, self::Tk1, self::K0 => 'A',
            self::Tk2, self::Tk3, self::K1, self::K2 => 'B',
            self::K3 => 'C',
        };
    }
}
