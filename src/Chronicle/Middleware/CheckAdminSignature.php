<?php
declare(strict_types=1);
namespace ParagonIE\Chronicle\Middleware;

use ParagonIE\Chronicle\Chronicle;
use ParagonIE\Chronicle\Exception\ClientNotFound;
use ParagonIE\ConstantTime\Base64UrlSafe;
use ParagonIE\Sapient\CryptographyKeys\SigningPublicKey;

/**
 * Class CheckAdminSignature
 * @package ParagonIE\Chronicle\Middleware
 */
class CheckAdminSignature extends CheckClientSignature
{
    /**
     * @param string $clientId
     * @return SigningPublicKey
     * @throws ClientNotFound
     */
    public function getPublicKey(string $clientId): SigningPublicKey
    {
        $sqlResult = Chronicle::getDatabase()->row(
            "SELECT * FROM chronicle_clients WHERE publicid = ? AND isAdmin",
            $clientId
        );
        if (empty($sqlResult)) {
            throw new ClientNotFound('Client not found');
        }
        return new SigningPublicKey(
            Base64UrlSafe::decode($sqlResult['publickey'])
        );
    }
}