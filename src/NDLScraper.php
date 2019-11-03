<?php

/**
 * bookbok/ndl-book-info-scraper
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright (c) BookBok
 * @license MIT
 * @since 1.0.0
 */

namespace BookBok\BookInfoScraper\NDL;

use BookBok\BookInfoScraper\AbstractIsbnScraper;
use BookBok\BookInfoScraper\Exception\DataProviderException;
use BookBok\BookInfoScraper\Information\BookInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 *
 */
class NDLScraper extends AbstractIsbnScraper
{
    private const API_URI = "https://iss.ndl.go.jp/api/opensearch";

    private const COVER_URI = "https://iss.ndl.go.jp/thumbnail";

    /**
     * @var ClientInterface
     */
    private $httpClient;

    /**
     * @var RequestFactoryInterface
     */
    private $httpRequestFactory;

    /**
     * Constructor.
     *
     * @param ClientInterface         $httpClient The http request client
     * @param RequestFactoryInterface $httpRequestFactory The request factory
     */
    public function __construct(
        ClientInterface $httpClient,
        RequestFactoryInterface $httpRequestFactory
    ) {
        $this->httpClient = $httpClient;
        $this->httpRequestFactory = $httpRequestFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function scrape(string $id): ?BookInterface
    {
        try {
            $response = $this->httpClient->sendRequest(
                $this->httpRequestFactory->createRequest("GET", $this->getApiUri($id))
            );

            $coverResponse = $this->httpClient->sendRequest(
                $this->httpRequestFactory->createRequest("GET", $this->getCoverUri($id))
            );
        } catch (ClientExceptionInterface $e) {
            throw new DataProviderException($e->getMessage(), $e->getCode(), $e);
        }

        if (200 !== $response->getStatusCode()) {
            return null;
        }

        $xml = simplexml_load_string($response);
        $nameSpaces = $xml->getNamespaces(true);
        $channelBookInfoXML = $xml->channel->children($nameSpaces['openSearch']);

        if (0 == $channelBookInfoXML->totalResults[0]) {
            return null;
        }

        // item配下にある名前空間'dc'の要素を取得する
        $dcBookInfoXML = $xml->channel->item->children($nameSpaces['dc']);

        // JSONオブジェクトに変換
        $jsonText = json_encode($dcBookInfoXML);

        if (false === $jsonText) {
            return null;
        }

        $dcBookInfoJSON = json_decode($jsonText);

        if (JSON_ERROR_NONE !== json_last_error()) {
            return null;
        }

        // ScrapeManagerにreturnするBookインスタンスの生成と情報の格納
        $book = new NDLBook($id, $dcBookInfoJSON->title);

        if (property_exists($dcBookInfoJSON, 'description')) {
            $book->setDescription(
                implode("/", (array)$dcBookInfoJSON->description)
            );
        }

        if (200 === $coverResponse->getStatusCode()) {
            $book->setCoverUri($this->getCoverUri($id));
        }

        return $book;
    }

    /**
     * NDLのエンドポイントURLを返す。
     *
     * @param string $isbn リクエストするISBN文字列
     *
     * @return string
     */
    protected function getApiUri(string $isbn): string
    {
        return self::API_URI . "?isbn={$isbn}";
    }

    /**
     * NDLの書影URLを返す。
     *
     * @param string $isbn リクエストするISBN文字列
     *
     * @return string
     */
    protected function getCoverUri(string $isbn): string
    {
        return self::COVER_URI . "/{$isbn}";
    }
}
