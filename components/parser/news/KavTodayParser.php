<?php

namespace app\components\parser\news;

use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\NewsPostDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class KavTodayParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://kavtoday.ru';
    }

    protected function getNewsPostDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $newsPostDTOList = [];
        $pageNumber = 1;
        $urn = "/site/articles?catids[0]=1&catids[1]=2&catids[2]=3&catids[3]=4&title=В22&page={$pageNumber}&showImages=0";

        $uriPreviewPage = UriResolver::resolve($urn, $this->getSiteUrl());

        while (count($newsPostDTOList) < $maxNewsCount) {
            try {
                $previewNewsContent = $this->getPageContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler($previewNewsContent);
            } catch (Throwable $exception) {
                if (count($newsPostDTOList) < $minNewsCount) {
                    throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
                }
                break;
            }

            $previewNewsXPath = '//div[contains(@class,"item-wrap")]/div[contains(@class,"row-wrap")]';
            $previewNewsCrawler = $previewNewsCrawler->filterXPath($previewNewsXPath);

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$newsPostDTOList) {
                $titleCrawler = $newsPreview->filterXPath('//a[contains(@class,"title-wrap")]');
                $title = $titleCrawler->text();
                $uri = UriResolver::resolve($titleCrawler->attr('href'), $this->getSiteUrl());

                $dateString = $newsPreview->filterXPath('//div[contains(@class,"date-wrap")]')->text();
                $yearString = $newsPreview->filterXPath('//div[contains(@class,"year-wrap")]')->text();
                $publishedAtString = $dateString . ' ' . $yearString;

                $timezone = new DateTimeZone('Europe/Moscow');
                $publishedAt = DateTimeImmutable::createFromFormat('d.m Y', $publishedAtString, $timezone);
                $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

                $description = null;
                $newsPostDTOList[] = new NewsPostDTO($this->encodeUri($uri), $publishedAtUTC, $title, $description);
            });
        }

        $newsPostDTOList = array_slice($newsPostDTOList, 0, $maxNewsCount);
        return $newsPostDTOList;
    }

    protected function parseNewsPage(NewsPostDTO $newsPostDTO): NewsPost
    {
        $uri = $newsPostDTO->getUri();
        $image = null;

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"article-wrap")]');

        $mainImageCrawler = $newsPageCrawler->filterXPath('//meta[@property="og:image"]')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('content');
        }
        if ($image !== null) {
            $image = UriResolver::resolve($image, $uri);
            $newsPostDTO->setImage($this->encodeUri($image));
        }

        $description = $newsPostCrawler->filterXPath('//h2[contains(@class,"subtitle-wrap")]')->text();
        if($description && $description !== ''){
            $newsPostDTO->setDescription($description);
        }

        $contentCrawler = $newsPostCrawler->filterXPath('//div[contains(@class,"inner-wrap")]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $newsPostDTO);

        return $this->factoryNewsPost($newsPostDTO, $newsPostItemDTOList);
    }
}
