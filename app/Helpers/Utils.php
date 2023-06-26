<?php

namespace App\Helpers;

use DateTime;

class Utils
{
    public static function getAge($date)
    {
        $birthDate = new DateTime($date);
        $today = new DateTime('today');
        $age = $birthDate->diff($today)->y;
        return $age;
    }

    public static function isValidCPF(string &$cpf): bool
    {
        $cpf = preg_replace('/[^0-9]/is', '', $cpf);
        if (strlen($cpf) < 11) {
            $cpf = str_pad($cpf, 11, '0', STR_PAD_LEFT);
        }

        if (strlen($cpf) > 11) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            for ($d = 0, $c = 0; $c < $t; $c++) {
                $d += $cpf[$c] * (($t + 1) - $c);
            }
            $d = ((10 * $d) % 11) % 10;
            if ($cpf[$c] != $d) {
                return false;
            }
        }

        return true;
    }
}
