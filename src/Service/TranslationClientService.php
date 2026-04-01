<?php // interacts with the translation-server

namespace Survos\LibreTranslateBundle\Service;

use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Contract\TranslatorInterface;
use Survos\LibreTranslateBundle\Dto\TranslationPayload;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TranslationClientService implements TranslatorInterface
{
    public const ROUTE='/batch-translate';
    public function __construct(
        private LoggerInterface $logger,
        private HttpClientInterface $httpClient,
        private string $translationServer = 'https://translation-server.survos.com',
        #[Autowire('%env(PROXY)%')] private ?string $proxy=null,
    )
    {

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
