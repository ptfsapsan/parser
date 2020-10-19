<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\DOMNodeRecursiveIterator;
use app\components\helper\nai4rus\NewsPostItemDTO;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DOMElement;
use DOMNode;
use linslin\yii2\curl\Curl;
use RuntimeException;
use SplObjectStorage;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class GroznyParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://grozny.tv';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $maxNewsCount =20;
        $previewList = [];

        $pageNumber = 1;

        while (count($previewList) < $maxNewsCount) {
            $uriPreviewPage = UriResolver::resolve("/news?page={$pageNumber}", $this->getSiteUrl());
            $pageNumber++;
            try {
                $previewNewsContent = $this->getPageContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler($previewNewsContent);
            } catch (Throwable $exception) {
                break;
            }

            $previewNewsCrawler = $previewNewsCrawler->filterXPath('//article');
            if(!$this->crawlerHasNodes($previewNewsCrawler)){
                break;
            }

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                $uriCrawler = $newsPreview->filterXPath('//a');
                $uri = UriResolver::resolve($uriCrawler->attr('href'), "{$this->getSiteUrl()}/news");
                $title = $newsPreview->filterXPath('//h3')->text();

                $timezone = new DateTimeZone('Europe/Moscow');
                $publishedAtString = $newsPreview->filterXPath('//li[contains(@class,"meta-news__date")]')->text();
                $publishedAtString = $this->translateDateToEng($publishedAtString);

                $publishedAt = DateTimeImmutable::createFromFormat('d F Y', $publishedAtString, $timezone);
                $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));
                $preview = null;

                $previewList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $title, $preview);
            });
        }

        if (count($previewList) < $minNewsCount) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null);
        }

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $uri = $previewNewsDTO->getUri();

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"page-news")]');

        $image = null;
        $mainImageCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"bl-news-limg")]/img')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
        }
        if ($image !== null) {
            $image = UriResolver::resolve($image, $uri);
            $previewNewsDTO->setImage($this->encodeUri($image));
        }

        $contentCrawler = $newsPostCrawler->filterXPath('//div[contains(@class,"page-news__content")]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }

    private function translateDateToEng(string $date)
    {
        $date = mb_strtolower($date);
        $monthRegex = [
            '/янв[\S.]*/iu' => 'January',
            '/фев[\S.]*/iu' => 'February',
            '/мар[\S.]*/iu' => 'March',
            '/апр[\S.]*/iu' => 'April',
            '/май[\S.]*/iu' => 'May',
            '/июн[\S.]*/iu' => 'June',
            '/июл[\S.]*/iu' => 'July',
            '/авг[\S.]*/iu' => 'August',
            '/сен[\S.]*/iu' => 'September',
            '/окт[\S.]*/iu' => 'October',
            '/ноя[\S.]*/iu' => 'November',
            '/дек[\S.]*/iu' => 'December'
        ];
        foreach ($monthRegex as $regex => $enMonth) {
            $date = preg_replace($regex, $enMonth, $date);
        }
        return $date;
    }
}