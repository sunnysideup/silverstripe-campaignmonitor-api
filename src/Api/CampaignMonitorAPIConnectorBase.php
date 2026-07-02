<?php

namespace Sunnysideup\CampaignMonitorApi\Api;

use Psr\SimpleCache\CacheInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use CS_REST_General;

class CampaignMonitorAPIConnectorBase
{
    use Configurable;
    use Extensible;
    use Injectable;

    /**
     * @var bool
     */
    protected $debug = false;

    /**
     * @var bool
     */
    protected $allowCaching = false;

    /**
     * @var int
     */
    protected $httpStatusCode = 0;

    /**
     * REQUIRED!
     * this is the CM url for logging in.
     * which can be used by the client.
     *
     * @var string
     */
    private static $campaign_monitor_url = '';

    /**
     * REQUIRED!
     *
     * @var string
     */
    private static $client_id = '';

    /**
     * OPTION 1: API KEY!
     *
     * @var string
     */
    private static $api_key = '';

    /**
     * OPTION 2: OAUTH OPTION.
     *
     * @var string
     */
    private static $client_secret = '';

    /**
     * OPTION 2: OAUTH OPTION.
     *
     * @var string
     */
    private static $redirect_uri = '';

    /**
     * OPTION 2: OAUTH OPTION.
     *
     * @var string
     */
    private static $code = '';

    private static $error_code = '';

    private static $error_description = '';

    public static function get_last_error_code(): string
    {
        return self::$error_code;
    }

    public static function get_last_error_description(): string
    {
        return self::$error_description;
    }

    public static function inst(): static
    {
        return Injector::inst()->get(static::class);
    }

    /**
     * must be called to use this API.
     * Check if the API is ready to do stuff...
     */
    public function isAvailable(): bool
    {
        $class = Injector::inst()->get(static::class);
        $auth = $class->getAuth();

        return false === empty($auth);
    }

    /**
     * must be called to use this API.
     */
    public function init()
    {
        require_once BASE_PATH . '/vendor/campaignmonitor/createsend-php/csrest_lists.php';
    }

    /**
     * turn debug on or off.
     *
     * @param bool $b
     */
    public function setDebug(?bool $b = true)
    {
        $this->debug = $b;
    }

    public function setAllowCaching(bool $b)
    {
        $this->allowCaching = $b;
    }

    /**
     * @return bool
     */
    public function getAllowCaching()
    {
        return $this->allowCaching;
    }

    /**
     * returns the HTTP code for the response.
     * This can be handy for debuging purposes.
     *
     * @return int
     */
    public function getHttpStatusCode()
    {
        return $this->httpStatusCode;
    }

    /**
     * provides the Authorisation Array.
     *
     * @return array|mixed
     */
    protected function getAuth()
    {
        $auth = $this->getFromCache('getAuth');
        if (!empty($auth)) {
            return $auth;
        }

        $auth = [];
        $apiKey = $this->getApiKey();
        if ($apiKey !== '' && $apiKey !== '0') {
            $auth = ['api_key' => $apiKey];
        } else {
            $clientId = $this->getClientId();
            $clientSecret = $clientId !== '' && $clientId !== '0' ? $this->getClientSecret() : '';
            $code = $clientSecret !== '' && $clientSecret !== '0' ? $this->getCode() : '';
            $redirectUri = $clientSecret !== '' && $clientSecret !== '0' ? $this->getRedirectUri() : '';
            if ($clientId && $clientSecret && $redirectUri && $code) {
                $result = CS_REST_General::exchange_token($clientId, $clientSecret, $redirectUri, $code);

                if ($result->was_successful()) {
                    $auth = [
                        'access_token' => $result->response->access_token,
                        'refresh_token' => $result->response->refresh_token,
                    ];
                    //TODO: do we need to check expiry date?
                    //$expires_in = $result->response->expires_in;
                    // Save $access_token, $expires_in, and $refresh_token.
                    if ($this->debug) {
                        echo 'access token: ' . $result->response->access_token . "\n";
                        echo 'expires in (seconds): ' . $result->response->expires_in . "\n";
                        echo 'refresh token: ' . $result->response->refresh_token . "\n";
                    }
                } elseif ($result->response && 121 === $result->response->Code) {
                    // If you receive '121: Expired OAuth Token', refresh the access token
                    $url = CS_REST_General::authorize_url($clientId, $clientSecret, $redirectUri, $code);
                    return Controller::curr()->redirect($url);
                    // $wrap =
                    // list($new_access_token, , $new_refresh_token) = $wrap->refresh_token();
                    //
                    // $auth = [
                    //     'access_token' => $new_access_token,
                    //     'refresh_token' => $new_refresh_token,
                    // ];
                }
            }

            if ($auth !== []) {
                $this->saveToCache($auth, 'getAuth');
            }
        }

        if ($auth === []) {
            $auth = [];
        }

        return $auth;
    }

    /**
     * returns the result or NULL in case of an error
     * NULL RESULT IS ERROR!
     *
     * @param \CS_REST_Wrapper_Result $result
     * @param mixed                   $apiCall
     * @param mixed                   $description
     *
     * @return mixed
     */
    protected function returnResult($result, $apiCall, $description)
    {
        if ($this->debug) {
            if (is_string($result)) {
                echo sprintf('<h1>%s ( %s ) ...</h1>', $description, $apiCall);
                echo sprintf("<p style='color: red'>%s</p>", $result);
            } else {
                echo sprintf('<h1>%s ( %s ) ...</h1>', $description, $apiCall);
                if ($result->was_successful()) {
                    echo '<h2>SUCCESS</h2>';
                } else {
                    echo '<h2>FAILURE: ' . $result->http_status_code . '</h2>';
                }

                echo '<pre>';
                print_r($result);
                echo '</pre>';
                echo '<hr /><hr /><hr />';
                ob_flush();
                flush();
            }
        }

        if (is_string($result)) {
            $this->httpStatusCode = 500;
            self::$error_description = $result;
            self::$error_code = 500;

            return null;
        }

        if ($result->was_successful()) {
            if (!empty($result->response)) {
                return $result->response;
            }

            return true;
        }

        $this->httpStatusCode = $result->http_status_code;
        self::$error_description = serialize($result) . ' --- --- ' . serialize($apiCall) . ' --- --- ' . serialize($description);
        self::$error_code = $result->http_status_code;

        return null;
    }

    // caching

    /**
     * @return mixed
     */
    protected function getFromCache(string $name)
    {
        if ($this->getAllowCaching()) {
            $cache = $this->getCache();
            $value = $cache->has($name) ? $cache->get($name) : null;
            if ($value) {
                return unserialize((string) $value);
            }
        }

        return null;
    }

    /**
     * @param mixed $unserializedValue
     */
    protected function saveToCache($unserializedValue, string $name): bool
    {
        if ($this->getAllowCaching()) {
            $serializedValue = serialize($unserializedValue);
            $cache = $this->getCache();
            $cache->set($name, $serializedValue);

            return true;
        }

        return false;
    }

    protected function getCache()
    {
        return Injector::inst()->get(CacheInterface::class . '.CampaignMonitor');
    }

    public function getApiKey(): string
    {
        return $this->getEnvOrConfigVar('SS_CAMPAIGNMONITOR_API_KEY', 'api_key', true);
    }

    public function getClientId(): string
    {
        return $this->getEnvOrConfigVar('SS_CAMPAIGNMONITOR_CLIENT_ID', 'client_id', true);
    }

    public function getClientSecret(): string
    {
        return $this->getEnvOrConfigVar('SS_CAMPAIGNMONITOR_CLIENT_SECRET', 'client_secret', true);
    }

    public function getCode(): string
    {
        return $this->getEnvOrConfigVar('SS_CAMPAIGNMONITOR_CODE', 'code', true);
    }

    public function getRedirectUri(): string
    {
        return $this->getEnvOrConfigVar('SS_CAMPAIGNMONITOR_REDIRECT_URI', 'campaign_monitor_url', true);
    }

    protected function getEnvOrConfigVar(string $envVar, string $configVar, ?bool $allowToBeEmpty = false)
    {
        $var = Environment::getEnv($envVar);
        if (!$var) {
            $var = $this->Config()->get($configVar);
        }

        $var = trim((string) $var);
        if (!$var && false === $allowToBeEmpty) {
            user_error('Please set .env var ' . $envVar . ' (recommended) or config var ' . $configVar, E_USER_NOTICE);
        }

        return $var;
    }

    /**
     * @param mixed $customFields (should be an array)
     */
    protected function cleanCustomFields($customFields): array
    {
        if (!is_array($customFields)) {
            $customFields = [];
        }

        $customFieldsBetter = [];
        foreach ($customFields as $key => $value) {
            if (isset($customFields[$key]['Key'], $customFields[$key]['Value'])) {
                $customFieldsBetter[] = $customFields[$key];
            } elseif (is_array($value)) {
                foreach ($value as $innerValue) {
                    $customFieldsBetter[] = [
                        'Key' => $key,
                        'Value' => $innerValue,
                        // 'Clear' => empty($value) ? true : false,
                    ];
                }
            } else {
                $customFieldsBetter[] = [
                    'Key' => $key,
                    'Value' => $value,
                    // 'Clear' => empty($value) ? true : false,
                ];
            }
        }

        return $customFieldsBetter;
    }
}
