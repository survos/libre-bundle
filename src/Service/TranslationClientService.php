<?php // interacts with lingua, the translation server (NOT babel/LibreTranslate)

namespace Survos\LibreTranslateBundle\Service;

use Psr\Log\LoggerInterface;
use Survos\LibreTranslateBundle\Dto\TranslationPayload;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Talks to lingua (POST /batch-translate), not to LibreTranslate -- despite living in
 * libre-bundle. The old default host, translation-server.survos.com, is lingua's former
 * name and 404s today; the server moved to lingua.survos.com.
 *
 * This duplicates Survos\LinguaBundle\Service\LinguaClient, down to the route and a private
 * copy of the hash function, and no application currently installs this bundle. It is slated
 * to be deleted in favour of LinguaClient; the default was corrected so that, until then, it
 * points somewhere real and can be aimed at lingua.wip via LINGUA_BASE_URI.
 *
 * It also declared `implements Survos\BabelBundle\Contract\TranslatorInterface`, an interface
 * that does not exist in babel-bundle -- fetching this service fatals on the missing
 * interface. Removed rather than recreated, since nothing consumed it as that type.
 */
class TranslationClientService
{
    public const ROUTE='/batch-translate';
    public const DEFAULT_SERVER='https://lingua.survos.com';

    public function __construct(
        private LoggerInterface $logger,
        private HttpClientInterface $httpClient,
        private string $translationServer = self::DEFAULT_SERVER,
        #[Autowire('%env(default::PROXY)%')] private ?string $proxy=null,
    )
    {
        // '%env(default::LINGUA_BASE_URI)%' resolves to '' when the var is unset, which would
        // otherwise silently POST to a relative path.
        $this->translationServer = rtrim($translationServer ?: self::DEFAULT_SERVER, '/');

        // Same rule as LinguaClient: a .wip host is only reachable through the symfony proxy.
        $this->proxy = $this->proxy
            ?: (str_contains($this->translationServer, '.wip') ? 'http://127.0.0.1:7080' : null);
    }

    public function translate(string $text, string $from, string $to): string
    {
        assert(false, __CLASS__);
    }

    public static function calcHash(string $string, string $locale): string
    {
        assert(strlen($locale)===2, "Invalid Locale: $locale");
        $str = substr_replace(hash('xxh3', $string), strtoupper($locale), 3, 0); // insert locale into 3rd position
//        dd(hexdec($str), $str, strlen($str)); // 255^8 = a 19-digit number
        return $str;
    }

    public function getTranslationServer(): string
    {
        return $this->translationServer;
    }


    public static function textToCodes(array $text, string $locale): array
    {
        return array_map(fn($string) => self::calcHash($string, $locale), $text);
    }

    public function getSource($hash): array
    {
        return $this->httpClient->request('GET', $this->translationServer . '/source' . '/' . $hash . '.json', [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'proxy' => $this->proxy,

        ])->toArray();

    }
    public function requestTranslations(string $from, string|array $to, array $text, $fetchOnly=false,
                                        bool $forceDispatch=false,
                                        ?string $transport=null
    ): array
    {
        $url = $this->translationServer . self::ROUTE;
        $payload = new TranslationPayload(
            from: $from,
            engine: 'libre',
            insertNewStrings: !$fetchOnly,
            forceDispatch: $forceDispatch,
            to: $to,
            text: $text,
            transport: $transport,

        );

        $response = $this->httpClient->request('POST', $url, [
            'json' => $payload,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'proxy' => $this->proxy,

        ]);
        // @todo: check that server is running.
        if ($response->getStatusCode() !== 200) {
            $this->logger->error(json_encode($payload, JSON_PRETTY_PRINT));
            $results = [
                'status' => $response->getStatusCode(),
                'msg' => $response->getContent(false),
            ];
        } else {
            $results = $response->toArray();
        }
        return $results;
    }

}
