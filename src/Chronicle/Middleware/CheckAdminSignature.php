<?php
declare(strict_types=1);
namespace ParagonIE\Chronicle\Middleware;

use ParagonIE\Chronicle\Chronicle;
use ParagonIE\Chronicle\Exception\ClientNotFound;
use ParagonIE\Sapient\CryptographyKeys\SigningPublicKey;

/**
 * Class CheckAdminSignature
 * @package ParagonIE\Chronicle\Middleware
 */
class CheckAdminSignature extends CheckClientSignature
{
    const PROPERTIES_TO_SET = ['authenticated', 'administrator'];

    /**
     * Only selects a valid result if the client has isAdmin set to TRUE.
     *
     * @param string $clientId
     * @return SigningPublicKey
     *
     * @throws ClientNotFound
     */
    public function getPublicKey(string $clientId): SigningPublicKey
    {
        return Chronicle::getClientsPublicKey($clientId, true);
    }
}
