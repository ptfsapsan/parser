<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use linslin\yii2\curl\Curl;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class Yalta24Parser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function factoryCurl(): Curl
    {
        $curl = Helper::getCurl();
        $curl->setOption(CURLOPT_ENCODING, "gzip");
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Brave Chrome/83.0.4103.106 Safari/537.36';
        $curl->setOption(CURLOPT_USERAGENT, $userAgent);

        return $curl;
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];
        $pageNumber = 10;

        while (count($previewNewsDTOList) < $maxNewsCount) {
            $urn = "/vsya-yalta?start={$pageNumber}";
            $uriPreviewPage = UriResolver::resolve($urn, $this->getSiteUrl());
            $pageNumber += 10;

            try {
                $previewNewsContent = $this->getPageContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler($previewNewsContent);
            } catch (Throwable $exception) {
                if (count($previewNewsDTOList) < $minNewsCount) {
                    throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
                }
                break;
            }

            $previewNewsXPath = '//div[contains(@class,"item")]';
            $previewNewsCrawler = $previewNewsCrawler->filterXPath($previewNewsXPath);

            $previewNewsCrawler->each(
                function (Crawler $newsPreview) use (&$previewNewsDTOList) {
                    $titleCrawler = $newsPreview->filterXPath('//h2');
                    if ($this->crawlerHasNodes($titleCrawler)) {
                        $title = $titleCrawler->text();
                        $uri = UriResolver::resolve($titleCrawler->filterXPath('//a')->attr('href'), $this->getSiteUrl());
                    }
                    if (empty($uri)) {
                        return false;
                    }
                    if($this->crawlerHasNodes($newsPreview->filterXPath('//time'))) {
                        $publishedAtString = $newsPreview->filterXPath('//time')->text();
                        $publishedAtString = explode(' ', $publishedAtString);
                        unset($publishedAtString[0]);
                        $publishedAtString = $publishedAtString[1].' '.$publishedAtString[2];
                        $timezone = new DateTimeZone('Europe/Moscow');
                        $publishedAt = DateTimeImmutable::createFromFormat('d.m.Y H:i', $publishedAtString, $timezone);
                        $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));
                    }

                    $description = null;
                    $previewNewsDTOList[] = new PreviewNewsDTO(
                        $this->encodeUri($uri),
                        $publishedAtUTC,
                        $title,
                        $description
                    );
                }
            );
        }

        $previewNewsDTOList = array_slice($previewNewsDTOList, 0, $maxNewsCount);
        return $previewNewsDTOList;
    }

    protected function getSiteUrl(): string
    {
        return 'http://www.yalta-24.ru/';
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $uri = $previewNewsDTO->getUri();
        $image = null;
        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[@itemprop="articleBody"]');

        $mainImageCrawler = $newsPageCrawler->filterXPath('//div[@class="item-image"]//img')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
        }
        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $uri);
            $previewNewsDTO->setImage($this->encodeUri($image));
        }

        $description = null;
        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $contentCrawler = $newsPostCrawler;
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"mobile-slider")]');
        $this->removeDomNodes($contentCrawler, '//div[@class="item-image"]//img');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }
}
