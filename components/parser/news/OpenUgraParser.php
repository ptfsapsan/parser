<?php

namespace app\components\parser\news;

use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class OpenUgraParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];
        $pageNumber = 1;

        while (count($previewNewsDTOList) < $maxNewsCount) {
            $urn = "/news/?PAGEN_1={$pageNumber}";
            $uriPreviewPage = UriResolver::resolve($urn, $this->getSiteUrl());
            $pageNumber++;

            try {
                $previewNewsContent = $this->getPageContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler(mb_convert_encoding($previewNewsContent, "utf-8", "windows-1251"));
            } catch (Throwable $exception) {
                if (count($previewNewsDTOList) < $minNewsCount) {
                    throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
                }
                break;
            }

            $previewNewsXPath = '//section[contains(@class,"news-list")]//div[contains(@class,"list-news-block")]';
            $previewNewsCrawler = $previewNewsCrawler->filterXPath($previewNewsXPath);

            $previewNewsCrawler->each(
                function (Crawler $newsPreview) use (&$previewNewsDTOList, $pageNumber) {
                    $titleCrawler = $newsPreview->filterXPath('//a[@class="h5"][1]');
                    $title = $titleCrawler->text();
                    $uri = UriResolver::resolve($titleCrawler->attr('href'), $this->getSiteUrl());
                    $previewNewsDTOList[] = new PreviewNewsDTO($this->encodeUri($uri), null, $title, null);
                }
            );
        }
        $previewNewsDTOList = array_slice($previewNewsDTOList, 0, $maxNewsCount);
        return $previewNewsDTOList;
    }

    protected function getSiteUrl(): string
    {
        return 'https://myopenugra.ru/';
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $uri = $previewNewsDTO->getUri();
        $image = null;

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler(mb_convert_encoding($newsPage, "utf-8", "windows-1251"));
        $newsPostCrawler = $newsPageCrawler->filterXPath(
            '//section[contains(@class,"detail-news sec-border")]//div[contains(@class,"detail-text")]'
        );

        $mainImageCrawler = $newsPageCrawler->filterXPath(
            '//section[contains(@class,"detail-news-prev no-bt-pd aos-init aos-animate")]//img[1]'
        )->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
        }
        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $uri);
            $previewNewsDTO->setImage($this->encodeUri($image));
        }

        $publishedAtString = $newsPageCrawler->filterXPath('//div[@class="detail-news-info-cart"]//p[1]')->text();
        $publishedAtString = explode(' ', $publishedAtString);
        $this->convertStringMonthToNumber($publishedAtString[1]);
        $publishedAtString[1] = $this->convertStringMonthToNumber($publishedAtString[1]);
        //[1] - day // [2] - month // [3] - year
        $publishedAtString = $publishedAtString[0].' '.$publishedAtString[1].' '.$publishedAtString[2];
        $timezone = new DateTimeZone('Europe/Moscow');
        $publishedAt = DateTimeImmutable::createFromFormat('d m Y', $publishedAtString, $timezone);
        $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));
        $previewNewsDTO->setPublishedAt($publishedAtUTC);

        $contentCrawler = $newsPostCrawler;
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"col-12 detail-text")]//p[1]');

        $this->purifyNewsPostContent($contentCrawler);
        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }

    public function convertStringMonthToNumber($stringMonth): int
    {
        $monthsList = [
            "Января" => 1,
            "Февраля" => 2,
            "Марта" => 3,
            "Апреля" => 4,
            "Мая" => 5,
            "Июня" => 6,
            "Июля" => 7,
            "Августа" => 8,
            "Сентября" => 9,
            "Октября" => 10,
            "Ноября" => 11,
            "Декабря" => 12,
        ];
        return $monthsList[$stringMonth];
    }
}
