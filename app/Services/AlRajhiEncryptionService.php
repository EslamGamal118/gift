<?php

namespace App\Services;

class AlRajhiEncryptionService
{
    private $key;
    private $iv;

    public function __construct()
    {
        $this->key = env("ALRAJHI_ENCRYPTION_KEY");
        $rawIv = env("ALRAJHI_IV");
        $this->iv = str_pad($rawIv, 16, "\0");
    }

    /**
     * Encrypt data using AES-256-CBC
     */
    public function encrypt($data)
    {
        $encrypted = openssl_encrypt(
            $data,
            'aes-256-cbc',
            $this->key,
            OPENSSL_RAW_DATA,
            $this->iv
        );

        return bin2hex($encrypted);
    }

    /**
     * Decrypt data using AES-256-CBC
     */
    public function decrypt($data)
    {
        $binaryData = hex2bin($data);
        return openssl_decrypt(
            $binaryData,
            'aes-256-cbc',
            $this->key,
            OPENSSL_RAW_DATA,
            $this->iv
        );
    }
}