<?php
declare(strict_types=1);

namespace Survos\LibreTranslateBundle\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function Symfony\Component\String\u;

class LibreTranslateService
{

    /** @var string The API base URL */
    private $apiBase = 'http://127.0.0.1';

    /** @var int The port at apiBase to use   */
    private $apiPort = null;

    /** @var string Local path to ltmanage, if available */
    private $LTManage = null;

    /** @var boolean If the `ltmanage` binary is available on the server or not */
    public $canManage = false;

    private string $sourceLanguage = 'en';

    private $targetLanguage = 'es';

    private string $lastError = '';

    private array $settings = [];
    private array $languages = [];



    public function __construct(
        private ?string $host = null,
        /** @var string The API Key to be used for requests */
        private ?string $apiKey = null,
        private  $port = 5000,
        private ?string $source = null,
        private ?string $target = null,
        private ?LoggerInterface $logger = null,
        private ?HttpClientInterface $httpClient = null,
    )
    {
        // set API base, remove trailing slash
        $this->host ??= rtrim($host, '/\\');

        // set API port
        if (!is_null($port)) {
            $this->apiPort = (int)$port;
        }

        if (!$this->httpClient) {
            $this->httpClient = HttpClient::create();
        }

        // validation is expensive, we don't really need this until the first time its used.
//        if (!is_null($source)) {
//            $this->setSource($source);
//        }
//        if (!is_null($target)) {
//            $this->setTarget($target);
//        }



    }

    public function getHttpClient(): ?HttpClientInterface
    {
        return $this->httpClient;
    }

    public function setHttpClient(?HttpClientInterface $httpClient): void
    {
        $this->httpClient = $httpClient;
    }

    public function ping()
    {
        // Test connection
        try {
            $this->_doRequest('/');
        } catch (\Exception $e) {
            return $e;
        }


        // Go ahead and set info for server settings and available languages
        $this->getSettings();
        $this->getLanguages();

        // If hosting LibreTranslate locally, check if ltmanage is available for API Key management
    }

    public function canManage():bool
    {
        if (function_exists('exec')){
            exec('which ltmanage 2> /dev/null', $binPath, $resCode);
            if (!empty($binPath) && is_array($binPath)) {
                $this->LTManage = $binPath[0];
                $this->canManage = true;
            }
        }
        return $this->canManage;
    }

    /* set Api Key */
    public function setApiKey($apiKey) {
        $this->apiKey = $apiKey;
    }

    /* set Source language */
    public function setSource($lang) {
        // kinda expensive!
        if (empty($this->languages)) {
            $this->languages = $this->getLanguages();
        }
        if (!in_array($lang, array_keys($this->languages))){
            throw new \Exception($lang . " is not an available language.\n" . join('\n', array_keys($this->languages)));
        }
        $this->sourceLanguage = $lang;
    }
    /* set Source language */
    public function setTarget($lang) {
        if (!in_array($lang, array_keys($this->getLanguages()))){
            throw new \Exception($lang . " is not an available language.");
        }
        $this->targetLanguage = $lang;
    }

    /* set both... */
    public function setLanguages($source, $target) {
        $this->setSource($source);
        $this->setTarget($target);
    }

    /*
        Get server's current settings
        Returns: array
    */
    public function getSettings() {
        $settings = $this->_doRequest('/frontend/settings', [], 'GET');
        $this->settings = (array)$settings;
        return (array)$this->settings;
    }

    /*
        Get server's available languages
        Returns: stdClass array
    */
    public function getLanguages(): array {
        static $languages = [];
        if (empty($languages)) {
            $this->languages = [];
            $languages = $this->_doRequest('/languages', [], 'GET');
            foreach ($languages as $language) {
                if (isset($language->code)) {
                    $this->languages[$language->code] = $language->name;
                }
            }
        }
        return $this->languages;
    }

    //=========== Detect Language ======
    public function detect(string $text) {
        $data['q'] = $text;
        if (!is_null($this->apiKey)) {
            $data['api_key'] = $this->apiKey;
        }
        $endpoint = '/detect' . '?' . http_build_query($data);
        $response = $this->_doRequest($endpoint);
        // sort by confidence? return all, or most likely...Or (for now, just return first result)
        if (is_array($response)) {
            return $response[0]->language;
        } else {
            // return false, or throw error....
            return false;
        }
    }

    /*========== Translate ========
    @string,@array $text Single string of text to translate, or array of multiple texts to translate
    @string $source Source language (optional, will use default/current source language)
    @string $target Target language (optional, will use default/current target language)
    Returns: string or array
    */
    #[Cache("1 day")]
    public function translate(array|string $text, ?string $source = null, ?string $target = null): null|string|array {
        // TODO: if source or target passed, validate against known available languages
        $isMulti = false; // check if text passed is single or array
        $translatedText = null;
//        https://github.com/LibreTranslate/LibreTranslate?tab=readme-ov-file#can-i-do-batch-translations
        if (is_array($text)) {
            $isMulti = true;
            $text = json_encode($text);
//            $text = urlencode(json_encode($text));
        }

        $data = [
            'q' => $text,
            'format' => 'html',
            'source' => $source??$this->sourceLanguage,
            'target' => $target??$this->targetLanguage
        ];
        if (!is_null($this->apiKey)) {
            $data['api_key'] = $this->apiKey;
        }

        $response = $this->_doRequest('/translate', $data);
//        dd($response, $data);

        if (is_object($response) && ($translatedText = $response->translatedText??null) ) {

            // return array of translations if input was array
            if ($isMulti) {
                $decoded = urldecode($translatedText);
//                if (!json_validate($translatedText)) {
//                    dd(invalidJson: $translatedText);
//                }
                if (!json_validate($decoded)) {
                    $this->logger?->error("Invalid json: " . $decoded);
//                    dd(decoded: $decoded, tt: $translatedText, orig: $text);
                }
//                dd($response, $data, $translatedText, json_decode($translatedText, true));
                return (json_decode($decoded,true));
            }
            // else return single translation
            $translatedText =  $response->translatedText;
        } else {
            if (isset($response->error)) {
                throw new \Exception($response->error);
            }
        }

        if (!is_null($translatedText)) {
            $pattern = '/(\.|\?\!)$/';
            // apply hacks...
            if (preg_match($pattern, $translatedText, $mm)) {
                $last = $mm[1];
                if (!u($text)->endsWith($last)) {
                    $translatedText = trim($translatedText, $last);
                }
            }
        }
        return $translatedText;
    }


    //=========== Translate File ======
    /*
    @string $file Full path to file to be translated
    Returns: string
    */
    public function translateFiles($file, $source = null, $target = null){
        if (!is_file($file)) {
            throw new \Exception("File $file not found.");
        } else {
            $data = [
                'file' => new \CURLFile($file),
                'format' => 'html',
                'source' => !is_null($source) ? $source : $this->sourceLanguage,
                'target' => !is_null($target) ? $target : $this->targetLanguage
            ];
            if (!is_null($this->apiKey)) {
                $data['api_key'] = $this->apiKey;
            }
            $response = $this->_doRequest('/translate_file', $data);
            if (is_object($response) && isset($response->translatedFileUrl)) {
                // fetch file from returned URL location
                $fh = curl_init($response->translatedFileUrl);
                curl_setopt($fh, CURLOPT_RETURNTRANSFER, true);
                $translatedFile = curl_exec($fh);
                if (curl_errno($fh) == 0) {
                    return $translatedFile;
                } else {
                    throw new \Exception(curl_error($fh), curl_errno($fh));
                }
            } else {
                if (isset($response->error)) {
                    throw new \Exception($response->error);
                }
            }
        }
    }


    //============= Suggest ========
    /*
    @string $original Source text
    @string $translation Suggested translation
    @string $source Source language (optional, will use default/current source language)
    @string $target Target language (optional, will use default/current target language)
    */
    function Suggest($original, $suggestion, $source = null, $target = null) {
        $data = [
            'q' => $original,
            's' => $suggestion,
            'source' => !is_null($source) ? $source : $this->sourceLanguage,
            'target' => !is_null($target) ? $target : $this->targetLanguage
        ];
        if (!is_null($this->apiKey)) {
            $data['api_key'] = $this->apiKey;
        }
        $data = http_build_query($data);
        $response = $this->_doRequest('/suggest', $data);
        if (is_object($response) && isset($response->success)) {
            return $response->success;
        } else {
            if (isset($response->error)) {
                throw new \Exception($response->error);
            }
        }
    }



    //====== LTManage - list keys ===========
    function listKeys() {
        if ($this->canManage) {
            $keyList = [];
            exec($this->LTManage . " keys", $keys, $resultCode);
            foreach ($keys as $list) {
                list($key, $req_limit) = explode(":", $list);
                $keyList[] = [
                    'key' => $key,
                    'req_limit' => $req_limit
                ];
            }
            return $keyList;
        } else {
            throw new \Exception("ltmanage command not found");
        }
    }

    //====== LTManage - add key ===========
    // $req_limit: optional, override default server setting
    function addKey($req_limit = null) {
        if ($this->canManage) {
            exec($this->LTManage . " keys add " . $req_limit, $newKey, $resultCode);
            if (is_array($newKey) && !empty($newKey)) {
                return $newKey[0];
            }

        } else {
            throw new \Exception("ltmanage command not found");
        }
    }

    //====== LTManage - remove key ===========
    function removeKey($api_key) {
        if ($this->canManage) {
            $keys = $this->listKeys();
            $keyList = [];
            foreach ($keys as $key) {
                $keyList[] = $key['key'];
            }
            if (!in_array($api_key, $keyList)) {
                throw new \Exception("API Key to delete does not exist.");
            }
            exec($this->LTManage . " keys remove " . $api_key, $result, $resultCode);
            return true;
        } else {
            throw new \Exception("ltmanage command not found");
        }
    }



    public function getError() {
        return (!empty($this->lastError) ? $this->lastError : null);
    }

    //====== send request to libretranslate server
    // TODO: connection issue or libretranslate error message....
    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function _doRequest($endpoint, $data = [], $type = 'POST'): mixed
    {
        $finalEndpoint = $this->host . ( !is_null($this->apiPort) ? ':' . $this->apiPort : '' ) . $endpoint;
        $this->lastError = '';
        $this->logger?->info("Request to $finalEndpoint: " . json_encode($data, JSON_PRETTY_PRINT));
        $ch = \curl_init($finalEndpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_CUSTOMREQUEST => $type,
            CURLOPT_POSTFIELDS => $data,
        ]);
        $response = curl_exec($ch);
        $responseInfo = curl_getinfo($ch);
        if (curl_errno($ch) != 0) {
            throw new \Exception(curl_error($ch), curl_errno($ch));
        }
        if ($response) {
            $this->logger->info($response);
        }

        return json_decode($response);
    }
}
