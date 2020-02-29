<?php
declare(strict_types=1);
namespace ParagonIE\Chronicle\Process;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use ParagonIE\Chronicle\Chronicle;
use ParagonIE\Chronicle\Error\ConfigurationError;
use ParagonIE\Chronicle\Exception\{FilesystemException, InvalidInstanceException, TargetNotFound};
use ParagonIE\ConstantTime\Base64UrlSafe;
use ParagonIE\EasyDB\EasyDB;
use ParagonIE\Sapient\Adapter\Guzzle;
use ParagonIE\Sapient\CryptographyKeys\SigningPublicKey;
use ParagonIE\Sapient\Exception\InvalidMessageException;
use ParagonIE\Sapient\Sapient;
use Psr\Http\Message\ResponseInterface;

/**
 * Class CrossSign
 *
 * Publish the latest hash onto another remote Chronicle instance.
 *
 * @package ParagonIE\Chronicle\Process
 */
class CrossSign
{
    /** @var string */
    protected $clientId;

    /** @var Client */
    protected $guzzle;

    /** @var int */
    protected $id;

    /** @var array<string, string> */
    protected $lastRun;

    /** @var string */
    protected $name;

    /** @var \DateTime */
    protected $now;

    /** @var array */
    protected $policy;

    /** @var SigningPublicKey */
    protected $publicKey;

    /** @var Sapient */
    protected $sapient;

    /** @var string */
    protected $url;

    /** @var resource */
    protected $lockHandle

    /** @var string */
    protected $lockPath

    /**
     * CrossSign constructor.
     *
     * @param int $id
     * @param string $name
     * @param string $url
     * @param string $clientId
     * @param SigningPublicKey $publicKey
     * @param array $policy
     * @param array<string, string> $lastRun
     * @throws \Exception
     */
    public function __construct(
        int $id,
        string $name,
        string $url,
        string $clientId,
        SigningPublicKey $publicKey,
        array $policy,
        array $lastRun = []
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->url = $url;
        $this->clientId = $clientId;
        $this->publicKey = $publicKey;
        $this->policy = $policy;
        $this->lastRun = $lastRun;
        $this->now = new \DateTime();
        $this->guzzle = new Client();
        $this->sapient = new Sapient(new Guzzle($this->guzzle));
    }

    /**
     * Get a CrossSign instance, given its database ID
     *
     * @param int $id
     * @return self
     *
     * @throws InvalidInstanceException
     * @throws TargetNotFound
     */
    public static function byId(int $id): self
    {
        $db = Chronicle::getDatabase();
        /** @var array<string, string> $data */
        $data = $db->row('SELECT * FROM ' . Chronicle::getTableName('xsign_targets') . ' WHERE id = ?', $id);
        if (empty($data)) {
            throw new TargetNotFound('Cross-sign target not found');
        }
        /** @var array $policy */
        $policy = \json_decode($data['policy'] ?? '[]', true);
        /** @var array<string, string> $lastRun */
        $lastRun = \json_decode($data['lastrun'] ?? '[]', true);

        return new static(
            $id,
            $data['name'],
            $data['url'],
            $data['clientid'],
            new SigningPublicKey(Base64UrlSafe::decode($data['publickey'])),
            \is_array($policy) ? $policy : [],
            \is_array($lastRun) ? $lastRun : []
        );
    }

    /**
     * Are we supposed to cross-sign our latest hash to this target?
     *
     * @return bool
     *
     * @throws ConfigurationError
     * @throws InvalidInstanceException
     */
    public function needsToCrossSign(): bool
    {
        if (empty($this->lastRun)) {
            return true;
        }
        if (!isset($this->lastRun['time'], $this->lastRun['id'])) {
            return true;
        }
        $db = Chronicle::getDatabase();

        if (isset($this->policy['push-after'])) {
            /** @var int $head */
            $head = $db->cell('SELECT MAX(id) FROM ' . Chronicle::getTableName('chain'));
            // Only run if we've had more than N entries
            if (($head - (int) ($this->lastRun['id'])) >= $this->policy['push-after']) {
                return true;
            }
            // Otherwise, fall back to the daily scheduler:
        }

        if (isset($this->policy['push-days'])) {
            $days = (string) \intval($this->policy['push-days']);
            if ($days < 10) {
                $days = '0' . $days;
            }
            try {
                $lastRun = (new \DateTime($this->lastRun['time']))
                    ->add(new \DateInterval('P' . $days . 'D'));
            } catch (\Exception $ex) {
                throw new ConfigurationError('Invalid push-days policy: ' . $days, 0, $ex);
            }

            // Return true only if we're more than N days since the last run:
            return $this->now > $lastRun;
        }

        throw new ConfigurationError('No valid policy configured');
    }

    /**
     * Perform the actual cross-signing.
     *
     * First, sign and send a JSON request to the server.
     * Then, verify and decode the JSON response.
     * Finally, update the local metadata table.
     *
     * @return bool
     *
     * @throws InvalidMessageException
     * @throws GuzzleException
     * @throws FilesystemException
     * @throws InvalidInstanceException
     */
    public function performCrossSign(): bool
    {
        $db = Chronicle::getDatabase();
        $message = $this->getEndOfChain($db);
        if (!isset($message['currhash'], $message['summaryhash'])) {
            return false;
        }

        if (!$this->acquireLock()) {
            return false;
        }

        $response = $this->sapient->decodeSignedJsonResponse(
            $this->sendToPeer($message),
            $this->publicKey
        );

        $this->releaseLock();

        return $this->updateLastRun($db, $response, $message);
    }

    /**
     * Send a signed request to our peer, return their response.
     *
     * @param array $message
     * @return ResponseInterface
     *
     * @throws GuzzleException
     * @throws FilesystemException
     */
    protected function sendToPeer(array $message): ResponseInterface
    {
        $signingKey = Chronicle::getSigningKey();
        return $this->guzzle->send(
            $this->sapient->createSignedJsonRequest(
                'POST',
                $this->url . '/publish',
                [
                    'target' => $this->publicKey->getString(),
                    'cross-sign-at' => $this->now->format(\DateTime::ATOM),
                    'currhash' => $message['currhash'],
                    'summaryhash' => $message['summaryhash']
                ],
                $signingKey,
                [
                    Chronicle::CLIENT_IDENTIFIER_HEADER => $this->clientId
                ]
            )
        );
    }

    /**
     * Get the last row in this Chronicle's chain.
     *
     * @param EasyDB $db
     * @return array<string, string>
     * @throws InvalidInstanceException
     */
    protected function getEndOfChain(EasyDB $db): array
    {
        /** @var array<string, string> $last */
        $last = $db->row('SELECT * FROM ' . Chronicle::getTableName('chain') . ' ORDER BY id DESC LIMIT 1');
        if (empty($last)) {
            return [];
        }
        return $last;
    }

    /**
     * Update the lastrun element of the cross-signing table, which helps
     * enforce our local cross-signing policies:
     *
     * @param EasyDB $db
     * @param array $response
     * @param array $message
     * @return bool
     * @throws InvalidInstanceException
     */
    protected function updateLastRun(EasyDB $db, array $response, array $message): bool
    {
        $db->beginTransaction();
        $db->update(
            Chronicle::getTableNameUnquoted('xsign_targets'),
            [
                'lastrun' => \json_encode([
                    'id' => $message['id'],
                    'time' => $this->now->format(\DateTime::ATOM),
                    'response' => $response
                ])
            ], [
                'id' => $this->id
            ]
        );
        return $db->commit();
    }
    
    /**
     * Acquires a lock to prevent 2 cross-signing operation from happening
     * concurrently.
     *
     * @return bool
     */
    protected function acquireLock(): bool
    {
        $settings = Chronicle::getSettings();
        $this->lockPath = $settings['crossSignLockDir'] . '/lock-' . $this->id . '.lock';
        $this->lockHandle = fopen($path, 'w+');
        if (!flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($this->lockHandle);
            return false;
        }

        return true;
    }

    
    /**
     * Releases a cross-signing lock.
     */
    protected function releaseLock(): void
    {
        fclose($this->lockHandle);
        unlink($this->lockPath);
        unset($this->lockHandle)
        unset($this->lockPath)
    }
}
