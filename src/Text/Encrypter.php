<?php

namespace App\Text;

class Encrypter
{
    public function encrypt(string $value, string $password): string
    {
        $salt = openssl_random_pseudo_bytes(8);

        $salted = '';
        $dx = '';
        while (\strlen($salted) < 48) {
            $dx = md5($dx.$password.$salt, true);
            $salted .= $dx;
        }
        $key = substr($salted, 0, 32);
        $iv = substr($salted, 32, 16);

        $encrypted_data = openssl_encrypt($value, 'aes-256-cbc', $key, true, $iv);

        $data = sprintf('%s%s%s',
            base64_encode($salt),
            base64_encode($iv),
            base64_encode($encrypted_data),
        );

        return $data;
    }
}
