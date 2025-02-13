<?php

namespace MSDev\DoctrineFMDataAPIDriver;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\ServerInfoAwareConnection;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use MSDev\DoctrineFMDataAPIDriver\Exception\FMException;
use MSDev\DoctrineFMDataAPIDriver\Exception\AuthenticationException;
use MSDev\DoctrineFMDataAPIDriver\Exception\NotImplementedException;

class FMConnection implements ServerInfoAwareConnection
{
    private const SERVER_VERSION_CLOUD = 'FMCloud';

    /** @var */
    private $connection = null;

    /** @var FMStatement */
    private $statement = null;

    /** @var bool */
    private $transactionOpen = false;

    /** @var array */
    protected $params;

    /** @var string */
    protected $baseURI;

    /** @var string */
    protected $token;

    /** @var bool */
    protected $retried = false;

    /** @var array  */
    public $queryStack = [];

    /** @var array|null */
    public $metadata;

    /**
     * FMConnection constructor.
     *
     * @param array $params
     * @param FMDriver $driver
     *
     * @throws AuthenticationException
     * @throws DBALException
     */
    public function __construct(array $params, FMDriver $driver)
    {
        $this->params = $params;
        $this->setBaseURL($params['host'], $params['dbname']);
        $this->fetchToken($params);
    }

    public function rollBack()
    {
        // this method must exist, but rollback isn't possible so nothing is implemented
    }

    public function prepare(string $sql): Statement
    {
        $this->statement = new FMStatement($sql, $this);
        $this->statement->setFetchMode($this->defaultFetchMode);

        return $this->statement;
    }

    public function beginTransaction()
    {
        $this->transactionOpen = true;
        return true;
    }

    /**
     * {@inheritdoc}
     * @throws NotImplementedException|AuthenticationException|FMException
     */
    public function query(string $sql): Result
    {
        $args = func_get_args();
        $sql = $args[0];

        $stmt = $this->prepare($sql);
        $stmt->execute();

        return $stmt;
    }

    /**
     * @return bool
     *
     * @throws AuthenticationException|FMException
     */
    public function commit()
    {
        /** @var FMStatement $stmt */
        foreach($this->queryStack as $stmt) {
            $stmt->performCommand();
        }

        $this->queryStack = [];
        $this->transactionOpen = false;

        return true;
    }


    public function isTransactionOpen()
    {
        return $this->transactionOpen;
    }

    /**
     * @param null $name
     *
     * @return string|int
     *
     * @throws FMException|AuthenticationException
     */
    public function lastInsertId($name = null)
    {
        $this->statement->performCommand();
        unset($this->queryStack[$this->statement->id]);

        return $this->statement->extractID();
    }

    /**
     * At present it's not possible to get metadata from the Data API
     * so for now this is hard coded.
     */
    public function getServerVersion(): string
    {
        return 'FMS Data API v1';
    }

    public function getConnection()
    {
        return $this->connection;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    /**
     * @return array|null
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function quote($value, $type = ParameterType::STRING)
    {
        throw new NotImplementedException('Quote method is not implemented in this connection');
    }

    public function exec(string $sql): int
    {
        throw new NotImplementedException('Exec method is not implemented in this connection');
    }

    /**
     * @throws AuthenticationException
     * @throws FMException
     */
    public function performFMRequest(string $method, string $uri, array $options, bool $returnScriptResult = false): array
    {
        $client = new Client();
        $headers = [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => sprintf('Bearer %s', $this->token),
                'Accept-Encoding' => 'gzip, deflate, br',
            ]
        ];

        try {
            $response = $client->request($method, $this->baseURI.$uri, array_merge($headers, $options));
            $content = json_decode($response->getBody()->getContents(), true);
            $this->metadata = $content['response']['dataInfo'] ?? null;

            if($returnScriptResult) {
                return [
                    'error' => $content['response']['scriptError'],
                    'result' => $content['response']['scriptResult'] ?? '',
                ];
            }

            return $content['response']['data'] ?? $content['response'];
        } catch (ConnectException $e) {
            throw new FMException($e->getMessage(), $e->getCode(), $e);
        } catch (Exception $e) {
            /** @var ClientException $e */
            if(null === $e->getResponse()) {
                throw new FMException($e->getMessage(), $e->getCode(), $e);
            }

            // With FMCloud if the token has expired then we get a status code of 401 (not to be confused with
            // FileMaker's 401, no records) rather than a 200 status code and error 952 as we get on-prem.
            if(401 === $e->getResponse()->getStatusCode()) {
                if($this->retried) {
                    throw new FMException($e->getMessage(), $e->getCode(), $e);
                }

                $this->retried = true;
                $this->forceTokenRefresh();
                return $this->performFMRequest($method, $uri, $options);
            }

            // We should be able to decode data from the response body
            $content = json_decode($e->getResponse()->getBody()->getContents(), false);
            // But not always
            if(null === $content) {
                throw new FMException($e->getResponse()->getReasonPhrase(), $e->getResponse()->getStatusCode());
            }

            if(401 === (int)$content->messages[0]->code) {
                // no records found
                return [];
            }

            // if the token has expired or is invalid then in theory 952 will come back,
            // but sometimes you get 105 missing layout (go figure), so try a token refresh
            if(!$this->retried && in_array((int)$content->messages[0]->code, [105, 952], true)) {
                $this->retried = true;
                $this->forceTokenRefresh();
                return $this->performFMRequest($method, $uri, $options);
            }
            throw new FMException($content->messages[0]->message, $content->messages[0]->code);
        } catch(GuzzleException $e) {
            throw new AuthenticationException('Unknown error', -1);
        }
    }

    /**
     * @param string $host
     * @param string $database
     */
    private function setBaseURL(string $host, string $database): void
    {
        $this->baseURI =
            ('http' === substr($host, 4) ? $host : 'https://' . $host) .
            ('/' === substr($host, -1) ? '' : '/') .
            'fmi/data/v1/databases/' .
            $database . '/';
    }

    /**
     * @throws AuthenticationException
     */
    private function fetchToken(array $params): void
    {
        if($token = $this->readTokenFromDisk()) {
            $this->token = $token;
            return;
        }

        if(isset($this->params['serverVersion']) && $this->params['serverVersion'] === self::SERVER_VERSION_CLOUD) {
            $this->fetchCloudToken();
            return;
        }

        $client = new Client();
        try {
            $response = $client->request('POST', $this->baseURI . 'sessions', [
                'headers' => [
                    'Content-Type' => 'application/json'
                ],
                'auth' => [
                    $params['user'], $params['password']
                ]
            ]);

            $content = json_decode($response->getBody()->getContents(), false);
            $this->token = $content->response->token;
            $this->writeTokenToDisk();
        } catch (ConnectException $e) {
            throw new AuthenticationException($e->getMessage(), $e->getCode(), $e);
        } catch (Exception $e) {
            /** @var ClientException $e */
            if(null === $e->getResponse()) {
                throw new AuthenticationException($e->getMessage(), $e->getCode(), $e);
            }

            if(404 === $e->getResponse()->getStatusCode()) {
                throw new AuthenticationException($e->getResponse()->getReasonPhrase(), $e->getResponse()->getStatusCode());
            }

            $content = json_decode($e->getResponse()->getBody()->getContents(), false);
            throw new AuthenticationException($content->messages[0]->message, $content->messages[0]->code);
        } catch(GuzzleException $e) {
            throw new AuthenticationException('Unknown error', -1);
        }
    }

    /**
     * @throws AuthenticationException
     * @noinspection PhpUndefinedNamespaceInspection
     * @noinspection PhpFullyQualifiedNameUsageInspection
     */
    private function fetchCloudToken(): void
    {
        if(!class_exists('\MSDev\FMCloudAuthenticator\Authenticate')) {
            throw new AuthenticationException('You must include matatirosoln/fm-cloud-authentication when using FileMaker Cloud.', -1);
        }

        /** @noinspection PhpUndefinedClassInspection */
        $credentials = new \MSDev\FMCloudAuthenticator\Credentials(
            $this->params['host'],
            $this->params['user'],
            $this->params['password'],
            \MSDev\FMCloudAuthenticator\Credentials::DAPI,
            $this->params['dbname']
        );

        $authenticator = new \MSDev\FMCloudAuthenticator\Authenticate();
        $this->token = $authenticator->fetchToken($credentials);
        $this->writeTokenToDisk();
    }


    /**
     * @throws AuthenticationException
     */
    private function forceTokenRefresh(): void
    {
        $file = $this->getTokenDiskLocation();
        file_put_contents($file, '');

        $this->fetchToken($this->params);
    }

    /**
     * @return boolean|string
     */
    private function readTokenFromDisk()
    {
        $file = $this->getTokenDiskLocation();
        if(!file_exists($file)) {
            return false;
        }

        $content = file_get_contents($file);
        if(empty($content)) {
            return false;
        }

        return $content;
    }

    /**
     * Write the Data API token to disk for later access
     */
    private function writeTokenToDisk(): void
    {
        $file = $this->getTokenDiskLocation();
        file_put_contents($file, $this->token);
    }

    /**
     * Determine where to save the Data API token
     */
    private function getTokenDiskLocation(): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'fmp-token.txt';
    }

}
