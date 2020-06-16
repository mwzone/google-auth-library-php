<?php
/*
 * Copyright 2015 Google Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Google\Auth\Credentials;

use Google\Auth\GetQuotaProjectInterface;
use Google\Auth\Http\ClientFactory;
use Google\Auth\HttpHandler\HttpClientCache;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use Google\Auth\ProjectIdProviderInterface;
use Google\Auth\BlobSigner\BlobSignerInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;

/**
 * GCECredentials supports authorization on Google Compute Engine.
 *
 * It can be used to authorize requests using the AuthTokenMiddleware, but will
 * only succeed if being run on GCE:
 *
 *   use Google\Auth\Credentials\GCECredentials;
 *   use Google\Auth\Middleware\AuthTokenMiddleware;
 *   use GuzzleHttp\Client;
 *   use GuzzleHttp\HandlerStack;
 *
 *   $gce = new GCECredentials();
 *   $middleware = new AuthTokenMiddleware($gce);
 *   $stack = HandlerStack::create();
 *   $stack->push($middleware);
 *
 *   $client = new Client([
 *      'handler' => $stack,
 *      'base_uri' => 'https://www.googleapis.com/taskqueue/v1beta2/projects/',
 *      'auth' => 'google_auth'
 *   ]);
 *
 *   $res = $client->get('myproject/taskqueues/myqueue');
 */
class ComputeCredentials implements
    CredentialsInterface,
    SignBlobInterface,
    ProjectIdProviderInterface,
    GetQuotaProjectInterface
{
    use CredentialsTrait, IamServiceSignerTrait {
        IamServiceSignerTrait::signBlob as iamSignBlob
    }

    const CACHE_KEY = 'GOOGLE_AUTH_PHP_GCE';

    /**
     * The metadata IP address on appengine instances.
     *
     * The IP is used instead of the domain 'metadata' to avoid slow responses
     * when not on Compute Engine.
     */
    const METADATA_IP = '169.254.169.254';

    /**
     * The metadata path of the default token.
     */
    const TOKEN_URI_PATH = 'v1/instance/service-accounts/default/token';

    /**
     * The metadata path of the default id token.
     */
    const ID_TOKEN_URI_PATH = 'v1/instance/service-accounts/default/identity';

    /**
     * The metadata path of the client ID.
     */
    const CLIENT_ID_URI_PATH = 'v1/instance/service-accounts/default/email';

    /**
     * The metadata path of the project ID.
     */
    const PROJECT_ID_URI_PATH = 'v1/project/project-id';

    /**
     * The header whose presence indicates GCE presence.
     */
    const FLAVOR_HEADER = 'Metadata-Flavor';

    /**
     * Note: the explicit `timeout` and `tries` below is a workaround. The underlying
     * issue is that resolving an unknown host on some networks will take
     * 20-30 seconds; making this timeout short fixes the issue, but
     * could lead to false negatives in the event that we are on GCE, but
     * the metadata resolution was particularly slow. The latter case is
     * "unlikely" since the expected 4-nines time is about 0.5 seconds.
     * This allows us to limit the total ping maximum timeout to 1.5 seconds
     * for developer desktop scenarios.
     */
    const MAX_COMPUTE_PING_TRIES = 3;
    const COMPUTE_PING_CONNECTION_TIMEOUT_S = 0.5;

    /**
     * Flag used to ensure that the onGCE test is only done once;.
     *
     * @var bool
     */
    private $hasCheckedOnGce = false;

    /**
     * Flag that stores the value of the onGCE check.
     *
     * @var bool
     */
    private $isOnGce = false;

    /**
     * Result of fetchAuthToken.
     */
    protected $lastReceivedToken;

    /**
     * @var string|null
     */
    private $clientName;

    /**
     * @var string|null
     */
    private $projectId;

    /**
     * @var string
     */
    private $tokenUri;

    /**
     * @var string
     */
    private $targetAudience;

    /**
     * @var string|null
     */
    private $quotaProject;

    /**
     * @var ClientInterface
     */
    private $httpClient;

    /**
     * @param array $options {
     *     @type string|array $scope the scope of the access request,
     *         expressed either as an array or as a space-delimited string.
     *     @type string $targetAudience The audience for the ID token.
     *     @type string $quotaProject Specifies a project to bill for access
     *         charges associated with the request.
     * }
     */
    public function __construct(array $options = [])
    {
        $options += [
            'scope' => null,
            'targetAudience' => null,
            'quotaProject' => null,
            'httpClient' => null,
        ];

        if ($options['scope'] && $options['targetAudience']) {
            throw new InvalidArgumentException(
                'Scope and targetAudience cannot both be supplied'
            );
        }

        $tokenUri = self::getTokenUri();
        if ($options['scope']) {
            if (is_string($options['scope'])) {
                $options['scope'] = explode(' ', $options['scope']);
            }

            $options['scope'] = implode(',', $options['scope']);

            $tokenUri = $tokenUri . '?scopes='. $options['scope'];
        } elseif ($options['targetAudience']) {
            $tokenUri = sprintf(
                'http://%s/computeMetadata/%s?audience=%s',
                self::METADATA_IP,
                self::ID_TOKEN_URI_PATH,
                $options['targetAudience']
            );
            $this->targetAudience = $options['targetAudience'];
        }

        $this->tokenUri = $tokenUri;
        $this->quotaProject = $options['quotaProject'];
        $this->httpClient = $options['httpClient'] ?: ClientFactory::build();
    }

    /**
     * The full uri for accessing the default token.
     *
     * @return string
     */
    public static function getTokenUri(): string
    {
        $base = 'http://' . self::METADATA_IP . '/computeMetadata/';

        return $base . self::TOKEN_URI_PATH;
    }

    /**
     * The full uri for accessing the default service account.
     *
     * @return string
     */
    public static function getClientNameUri(): string
    {
        $base = 'http://' . self::METADATA_IP . '/computeMetadata/';

        return $base . self::CLIENT_ID_URI_PATH;
    }

    /**
     * Determines if this an App Engine Flexible instance, by accessing the
     * GAE_INSTANCE environment variable.
     *
     * @return bool
     */
    public static function onAppEngineFlexible(): bool
    {
        return substr(getenv('GAE_INSTANCE'), 0, 4) === 'aef-';
    }

    /**
     * Determines if this a GCE instance, by accessing the expected metadata
     * host.
     *
     * @param ClientInterface $httpClient
     * @return bool
     */
    public static function onGce(ClientInterface $httpClient): bool
    {
        $checkUri = 'http://' . self::METADATA_IP;
        for ($i = 1; $i <= self::MAX_COMPUTE_PING_TRIES; $i++) {
            try {
                // Comment from: oauth2client/client.py
                //
                // Note: the explicit `timeout` below is a workaround. The underlying
                // issue is that resolving an unknown host on some networks will take
                // 20-30 seconds; making this timeout short fixes the issue, but
                // could lead to false negatives in the event that we are on GCE, but
                // the metadata resolution was particularly slow. The latter case is
                // "unlikely".
                $resp = $httpClient->send(
                    new Request(
                        'GET',
                        $checkUri,
                        [self::FLAVOR_HEADER => 'Google']
                    ),
                    ['timeout' => self::COMPUTE_PING_CONNECTION_TIMEOUT_S]
                );

                return $resp->getHeaderLine(self::FLAVOR_HEADER) == 'Google';
            } catch (ClientException $e) {
            } catch (ServerException $e) {
            } catch (RequestException $e) {
            }
        }
        return false;
    }

    /**
     * Implements FetchAuthTokenInterface#fetchAuthToken.
     *
     * Fetches the auth tokens from the GCE metadata host if it is available.
     * If $httpClient is not specified a the default HttpHandler is used.
     *
     * @param ClientInterface $httpClient callback which delivers psr7 request
     *
     * @return array A set of auth related metadata, based on the token type.
     *
     * Access tokens have the following keys:
     *   - access_token (string)
     *   - expires_in (int)
     *   - token_type (string)
     * ID tokens have the following keys:
     *   - id_token (string)
     *
     * @throws \Exception
     */
    public function fetchAuthToken(ClientInterface $httpClient = null): array
    {
        if (!$this->isOnGce($httpClient)) {
            return [];  // return an empty array with no access token
        }

        $response = $this->getFromMetadata(
            $httpClient ?: $this->httpClient,
            $this->tokenUri
        );

        if ($this->targetAudience) {
            return ['id_token' => $response];
        }

        if (null === $json = json_decode($response, true)) {
            throw new \Exception('Invalid JSON response');
        }

        // store this so we can retrieve it later
        $this->lastReceivedToken = $json;
        $this->lastReceivedToken['expires_at'] = time() + $json['expires_in'];

        return $json;
    }

    /**
     * Get the client name from GCE metadata.
     *
     * Subsequent calls will return a cached value.
     *
     * @param ClientInterface $httpClient callback which delivers psr7 request
     * @return string
     */
    public function getClientName(ClientInterface $httpClient = null): string
    {
        if ($this->clientName) {
            return $this->clientName;
        }

        if (!$this->isOnGce($httpClient)) {
            return '';
        }

        return $this->clientName = $this->getFromMetadata(
            $httpClient ?: $this->httpClient,
            self::getClientNameUri()
        );
    }

    /**
     * Sign a string using the default service account private key.
     *
     * This implementation uses IAM's signBlob API.
     *
     * @see https://cloud.google.com/iam/credentials/reference/rest/v1/projects.serviceAccounts/signBlob SignBlob
     *
     * @param string $stringToSign The string to sign.
     * @param bool $forceOpenSsl [optional] Does not apply to this credentials
     *        type.
     * @return string
     */
    public function signBlob($stringToSign, $forceOpenSsl = false)
    {
        $email = $this->getClientName();

        $previousToken = $this->getLastReceivedToken();
        $accessToken = $previousToken
            ? $previousToken['access_token']
            : $this->fetchAuthToken()['access_token'];

        return $this->iamSignBlob(
            $this->httpClient,
            $email,
            $accessToken,
            $stringToSign
        );
    }

    /**
     * Fetch the default Project ID from compute engine.
     *
     * Returns null if called outside GCE.
     *
     * @param ClientInterface $httpClient Callback which delivers psr7 request
     * @return string|null
     */
    public function getProjectId(ClientInterface $httpClient = null): ?string
    {
        if ($this->projectId) {
            return $this->projectId;
        }

        if (!$this->isOnGce($httpClient)) {
            return null;
        }

        return $this->projectId = $this->getFromMetadata(
            $httpClient ?: $this->httpClient,
            self::getProjectIdUri()
        );
    }

    /**
     * Get the quota project used for this API request
     *
     * @return string|null
     */
    public function getQuotaProject(): ?string
    {
        return $this->quotaProject;
    }

    private function isOnGce($httpClient = null): bool
    {
        if (!$this->hasCheckedOnGce) {
            $this->isOnGce = self::onGce($httpClient ?: $this->httpClient);
            $this->hasCheckedOnGce = true;
        }

        return $this->isOnGce;
    }

    /**
     * Fetch the value of a GCE metadata server URI.
     *
     * @param ClientInterface $httpClient An HTTP Handler to deliver PSR7 requests.
     * @param string $uri The metadata URI.
     * @return string
     */
    private function getFromMetadata(ClientInterface $httpClient, $uri)
    {
        $resp = $httpClient(
            new Request(
                'GET',
                $uri,
                [self::FLAVOR_HEADER => 'Google']
            )
        );

        return (string) $resp->getBody();
    }

    /**
     * The full uri for accessing the default project ID.
     *
     * @return string
     */
    private static function getProjectIdUri(): string
    {
        $base = 'http://' . self::METADATA_IP . '/computeMetadata/';

        return $base . self::PROJECT_ID_URI_PATH;
    }
}