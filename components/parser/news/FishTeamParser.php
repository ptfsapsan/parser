<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\helper\metallizzer\Text;
use app\components\helper\nai4rus\DOMNodeRecursiveIterator;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTimeImmutable;
use DateTimeZone;
use DOMElement;
use DOMNode;
use linslin\yii2\curl\Curl;
use RuntimeException;
use SplObjectStorage;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class FishTeamParser implements ParserInterface
{
    /*run*/
    public const USER_ID = 2;
    public const FEED_ID = 2;
    private const SITE_URL = 'https://fish.team';

    private int $microsecondsDelay;
    private int $pageCountBetweenDelay;
    private SplObjectStorage $nodeStorage;
    private Curl $curl;

    public function __construct(int $microsecondsDelay = 1000000, int $pageCountBetweenDelay = 3)
    {
        $this->microsecondsDelay = $microsecondsDelay;
        $this->pageCountBetweenDelay = $pageCountBetweenDelay;
        $this->nodeStorage = new SplObjectStorage();

        $this->curl = Helper::getCurl();
    }

    public static function run(): array
    {
        $parser = new self(200000, 10);

        return $parser->parse(10, 100);
    }

    public function parse(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = $this->getPreviewList($minNewsCount, $maxNewsCount);

        $newsList = [];

        /** @var PreviewNewsDTO $previewNewsItem */
        foreach ($previewList as $key => $previewNewsItem) {
            $newsList[] = $this->parseNewsPage($previewNewsItem);
            $this->nodeStorage = new SplObjectStorage();

            if ($key % $this->pageCountBetweenDelay === 0) {
                usleep($this->microsecondsDelay);
            }
        }

        $this->curl->reset();
        return $newsList;
    }

    private function getPreviewList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $pageNumber = 1;

        while (count($previewList) < $maxNewsCount) {
            $uriPreviewPage = UriResolver::resolve("/news?page={$pageNumber}", $this->getSiteUri());
            $pageNumber++;

            try {
                $previewNewsContent = $this->getPageContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler($previewNewsContent);
            } catch (Throwable $exception) {
                if (count($previewList) < $minNewsCount) {
                    throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
                }
                break;
            }

            $previewNewsCrawler = $previewNewsCrawler->filter('.jrResults');
            $this->removeDomNodes($previewNewsCrawler, '//div[contains(@class,"jrDataListHeader")]');
            $previewNewsCrawler = $previewNewsCrawler->filter('.jrRow');

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                $title = $newsPreview->filter('.jrListingTitle > a')->text();
                $uri = UriResolver::resolve($newsPreview->filter('.jrListingTitle > a')->attr('href'), $this->getSiteUri());

                $publishedAt = $this->getPublishedAt($newsPreview);

                $previewList[] = new PreviewNewsDTO($uri, $publishedAt, $title, null);
            });
        }

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    private function parseNewsPage(PreviewNewsDTO $previewNewsItem): NewsPost
    {
        $uri = $previewNewsItem->getUri();
        $publishedAt = $previewNewsItem->getDateTime();
        $title = $previewNewsItem->getTitle();
        $image = null;

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);

        $contentCrawler = $newsPageCrawler->filter('.jrListingFulltext');

        $description = $this->getDescriptionFromText($contentCrawler);

        $mainImageCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"jrDetailHeader")]//figure//img[1]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('data-jr-src') ?? $mainImageCrawler->attr('src');
        }

        if ($image !== null) {
            $image = UriResolver::resolve($image, $uri);
            $image = $this->encodeUri($image);
        }

        $this->removeDomNodes($contentCrawler, '//script | //video | //style | //form | //table');

        $newsPost = new NewsPost(self::class, $title, $description, $publishedAt->format('Y-m-d H:i:s'), $uri, $image);

        foreach ($contentCrawler as $item) {
            $nodeIterator = new DOMNodeRecursiveIterator($item->childNodes);

            foreach ($nodeIterator->getRecursiveIterator() as $k => $node) {
                $newsPostItem = $this->parseDOMNode($node, $previewNewsItem);
                if (!$newsPostItem) {
                    continue;
                }

                if ($newsPostItem->type === NewsPostItem::TYPE_IMAGE && $newsPost->image === null) {
                    $newsPost->image = $newsPostItem->image;
                }

                $newsPost->addItem($newsPostItem);
            }
        }

        return $newsPost;
    }

    private function parseDOMNode(DOMNode $node, PreviewNewsDTO $previewNewsItem): ?NewsPostItem
    {
        try {
            $newsPostItem = $this->searchQuoteNewsItem($node);
            if ($newsPostItem) {
                return $newsPostItem;
            }

            $newsPostItem = $this->searchHeadingNewsItem($node);
            if ($newsPostItem) {
                return $newsPostItem;
            }

            $newsPostItem = $this->searchLinkNewsItem($node, $previewNewsItem);
            if ($newsPostItem) {
                return $newsPostItem;
            }

            $newsPostItem = $this->searchYoutubeVideoNewsItem($node);
            if ($newsPostItem) {
                return $newsPostItem;
            }

            $newsPostItem = $this->searchImageNewsItem($node, $previewNewsItem);
            if ($newsPostItem) {
                return $newsPostItem;
            }

            $newsPostItem = $this->searchTextNewsItem($node);
            if ($newsPostItem) {
                return $newsPostItem;
            }

            if ($node->nodeName === 'br') {
                $this->removeParentsFromStorage($node->parentNode);
                return null;
            }
        } catch (RuntimeException $exception) {
            return null;
        }
        return null;
    }

    private function searchQuoteNewsItem(DOMNode $node): ?NewsPostItem
    {
        if ($node->nodeName === '#text' || !$this->isQuoteType($node)) {
            $parentNode = $this->getRecursivelyParentNode($node, function (DOMNode $parentNode) {
                return $this->isQuoteType($parentNode);
            });
            $node = $parentNode ?: $node;
        }

        if (!$this->isQuoteType($node) || !$this->hasText($node)) {
            return null;
        }

        if ($this->nodeStorage->contains($node)) {
            throw new RuntimeException('Тег уже сохранен');
        }

        $newsPostItem = new NewsPostItem(NewsPostItem::TYPE_QUOTE, $this->normalizeSpaces($node->textContent));

        $this->nodeStorage->attach($node, $newsPostItem);
        $this->removeParentsFromStorage($node->parentNode);

        return $newsPostItem;
    }

    private function searchHeadingNewsItem(DOMNode $node): ?NewsPostItem
    {
        if ($node->nodeName === '#text' || $this->getHeadingLevel($node) === null) {
            $parentNode = $this->getRecursivelyParentNode($node, function (DOMNode $parentNode) {
                return $this->getHeadingLevel($parentNode);
            });
            $node = $parentNode ?: $node;
        }

        $headingLevel = $this->getHeadingLevel($node);

        if (!$headingLevel || !$this->hasText($node)) {
            return null;
        }

        if ($this->nodeStorage->contains($node)) {
            throw new RuntimeException('Тег уже сохранен');
        }

        $newsPostItem = new NewsPostItem(
            NewsPostItem::TYPE_HEADER,
            $this->normalizeSpaces($node->textContent),
            null,
            null,
            $headingLevel
        );

        $this->nodeStorage->attach($node, $newsPostItem);
        $this->removeParentsFromStorage($node->parentNode);

        return $newsPostItem;
    }

    private function searchLinkNewsItem(DOMNode $node, PreviewNewsDTO $previewNewsItem): ?NewsPostItem
    {
        if ($this->isImageType($node)) {
            return null;
        }

        if ($node->nodeName === '#text' || !$this->isLink($node)) {
            $parentNode = $this->getRecursivelyParentNode($node, function (DOMNode $parentNode) {
                return $this->isLink($parentNode);
            });
            $node = $parentNode ?: $node;
        }


        if (!$node instanceof DOMElement || !$this->isLink($node)) {
            return null;
        }

        $link = UriResolver::resolve($node->getAttribute('href'), $previewNewsItem->getUri());
        $link = $this->encodeUri($link);
        if ($link === null) {
            return null;
        }

        if ($this->nodeStorage->contains($node)) {
            throw new RuntimeException('Тег уже сохранен');
        }

        $linkText = $this->hasText($node) ? $node->textContent : null;
        $newsPostItem = new NewsPostItem(NewsPostItem::TYPE_LINK, $linkText, null, $link);

        $this->nodeStorage->attach($node, $newsPostItem);
        $this->removeParentsFromStorage($node->parentNode);

        return $newsPostItem;
    }

    private function searchYoutubeVideoNewsItem(DOMNode $node): ?NewsPostItem
    {
        if ($node->nodeName === '#text' || $node->nodeName !== 'iframe') {
            $parentNode = $this->getRecursivelyParentNode($node, function (DOMNode $parentNode) {
                return $parentNode->nodeName === 'iframe';
            }, 3);
            $node = $parentNode ?: $node;
        }

        if (!$node instanceof DOMElement || $node->nodeName !== 'iframe') {
            return null;
        }

        if ($this->nodeStorage->contains($node)) {
            throw new RuntimeException('Тег уже сохранен');
        }

        $youtubeVideoId = $this->getYoutubeVideoId($node->getAttribute('src'));
        if (!$youtubeVideoId) {
            return null;
        }
        $newsPostItem = new NewsPostItem(NewsPostItem::TYPE_VIDEO, null, null, null, null, $youtubeVideoId);
        $this->nodeStorage->attach($node, $newsPostItem);

        return $newsPostItem;
    }

    private function searchImageNewsItem(DOMNode $node, PreviewNewsDTO $previewNewsItem): ?NewsPostItem
    {
        $isPicture = $this->isPictureType($node);

        if (!$node instanceof DOMElement || (!$this->isImageType($node) && !$isPicture)) {
            return null;
        }

        $imageLink = $node->getAttribute('src');

        if ($isPicture) {
            if ($this->nodeStorage->contains($node->parentNode)) {
                throw new RuntimeException('Тег уже сохранен');
            }

            $pictureCrawler = new Crawler($node->parentNode);
            $imgCrawler = $pictureCrawler->filterXPath('//img');

            if ($imgCrawler->count()) {
                $imageLink = $imgCrawler->first()->attr('src');
            }
        }

        if ($imageLink === '' || mb_stripos($imageLink, 'data:') === 0) {
            return null;
        }

        $imageLink = UriResolver::resolve($imageLink, $previewNewsItem->getUri());
        $imageLink = $this->encodeUri($imageLink);
        if ($imageLink === null) {
            return null;
        }

        $alt = $node->getAttribute('alt');
        $alt = $alt !== '' ? $alt : null;

        $newsPostItem = new NewsPostItem(NewsPostItem::TYPE_IMAGE, $alt, $imageLink);

        if ($isPicture) {
            $this->nodeStorage->attach($node->parentNode, $newsPostItem);
        }

        return $newsPostItem;
    }

    private function searchTextNewsItem(DOMNode $node): ?NewsPostItem
    {
        if ($node->nodeName === '#comment' || !$this->hasText($node)) {
            return null;
        }

        $attachNode = $node;
        if ($node->nodeName === '#text') {
            $parentNode = $this->getRecursivelyParentNode($node, function (DOMNode $parentNode) {
                return $this->isFormattingTag($parentNode) && !$this->isFormattingTag($parentNode->parentNode);
            }, 6);

            $attachNode = $parentNode ?: $node->parentNode;
        }

        if ($this->isFormattingTag($attachNode)) {
            $attachNode = $attachNode->parentNode;
        }

        if ($this->nodeStorage->contains($attachNode)) {
            /** @var NewsPostItem $parentNewsPostItem */
            $parentNewsPostItem = $this->nodeStorage->offsetGet($attachNode);
            $parentNewsPostItem->text .= $this->normalizeSpaces($node->textContent);

            throw new RuntimeException('Контент добавлен к существующему объекту NewsPostItem');
        }

        $newsPostItem = new NewsPostItem(NewsPostItem::TYPE_TEXT, $this->normalizeSpaces($node->textContent));

        $this->nodeStorage->attach($attachNode, $newsPostItem);

        return $newsPostItem;
    }

    private function removeParentsFromStorage(
        DOMNode $node,
        int $maxLevel = 5,
        array $exceptNewsPostItemTypes = null
    ): void
    {
        if ($maxLevel <= 0 || !$node->parentNode) {
            return;
        }

        if ($exceptNewsPostItemTypes === null) {
            $exceptNewsPostItemTypes = [NewsPostItem::TYPE_HEADER, NewsPostItem::TYPE_QUOTE, NewsPostItem::TYPE_LINK];
        }

        if ($this->nodeStorage->contains($node)) {
            /** @var NewsPostItem $newsPostItem */
            $newsPostItem = $this->nodeStorage->offsetGet($node);

            if (in_array($newsPostItem->type, $exceptNewsPostItemTypes, true)) {
                return;
            }

            $this->nodeStorage->detach($node);
            return;
        }

        $maxLevel--;

        $this->removeParentsFromStorage($node->parentNode, $maxLevel);
    }

    private function getRecursivelyParentNode(DOMNode $node, callable $callback, int $maxLevel = 5): ?DOMNode
    {
        if ($callback($node)) {
            return $node;
        }

        if ($maxLevel <= 0 || !$node->parentNode) {
            return null;
        }

        $maxLevel--;

        return $this->getRecursivelyParentNode($node->parentNode, $callback, $maxLevel);
    }

    private function parseHumanDateTime(string $dateTime, DateTimeZone $timeZone): DateTimeInterface
    {
        $formattedDateTime = mb_strtolower(trim($dateTime));
        $now = new DateTimeImmutable('now', $timeZone);

        if ($formattedDateTime === 'только что') {
            return $now;
        }

        if (str_contains($formattedDateTime, 'час') && str_contains($formattedDateTime, 'назад')) {
            $numericTime = preg_replace('/\bчас\b/u', '1', $formattedDateTime);
            $hours = preg_replace('/[^0-9]/u', '', $numericTime);
            return $now->sub(new DateInterval("PT{$hours}H"));
        }

        if (str_contains($formattedDateTime, 'вчера')) {
            $time = preg_replace('/[^0-9:]/u', '', $formattedDateTime);
            return DateTimeImmutable::createFromFormat('H:i', $time, $timeZone)->sub(new DateInterval("P1D"));
        }

        if (str_contains($formattedDateTime, 'сегодня')) {
            $time = preg_replace('/[^0-9:]/u', '', $formattedDateTime);
            return DateTimeImmutable::createFromFormat('H:i', $time, $timeZone);
        }

        throw new RuntimeException("Не удалось распознать дату: {$dateTime}");
    }

    private function getJsonContent(string $uri): array
    {
        $encodedUri = Helper::encodeUrl($uri);
        $result = $this->curl->get($encodedUri, false);
        $this->checkResponseCode($this->curl);

        return $result;
    }

    private function getPageContent(string $uri): string
    {
        $encodedUri = Helper::encodeUrl($uri);
        $result = $this->curl->get($encodedUri);
        $this->checkResponseCode($this->curl);

        return $result;
    }

    private function checkResponseCode(Curl $curl): void
    {
        $responseInfo = $curl->getInfo();

        $httpCode = $responseInfo['http_code'] ?? null;
        $uri = $responseInfo['url'] ?? null;

        if ($httpCode < 200 || $httpCode >= 400) {
            throw new RuntimeException("Не удалось скачать страницу {$uri}, код ответа {$httpCode}");
        }
    }

    private function isPictureType(DOMNode $node): bool
    {
        return $node->parentNode->nodeName === 'picture';
    }

    private function isImageType(DOMNode $node): bool
    {
        return $node->nodeName === 'img';
    }

    private function isLink(DOMNode $node): bool
    {
        if (!$node instanceof DOMElement || $node->nodeName !== 'a') {
            return false;
        }

        $link = $node->getAttribute('href');
        $scheme = parse_url($link, PHP_URL_SCHEME);

        if ($scheme && !in_array($scheme, ['http', 'https'])) {
            return false;
        }

        return $link !== '';
    }

    private function isFormattingTag(DOMNode $node): bool
    {
        $formattingTags = ['strong' => true, 'b' => true, 'span' => true, 's' => true, 'i' => true, 'a' => true];

        return isset($formattingTags[$node->nodeName]);
    }

    private function hasText(DOMNode $node): bool
    {
        return trim($node->textContent, "⠀ \t\n\r\0\x0B\xC2\xA0") !== '';
    }

    private function isQuoteType(DOMNode $node): bool
    {
        $quoteTags = ['q' => true, 'blockquote' => true];

        if ($node instanceof DOMElement && str_contains($node->getAttribute('class'), 'line')) {
            return true;
        }

        return $quoteTags[$node->nodeName] ?? false;
    }

    private function getHeadingLevel(DOMNode $node): ?int
    {
        $headingTags = ['h1' => 1, 'h2' => 2, 'h3' => 3, 'h4' => 4, 'h5' => 5, 'h6' => 6];

        return $headingTags[$node->nodeName] ?? null;
    }

    private function removeDomNodes(Crawler $crawler, string $xpath): void
    {
        $crawler->filterXPath($xpath)->each(function (Crawler $crawler) {
            $domNode = $crawler->getNode(0);
            if ($domNode) {
                $domNode->parentNode->removeChild($domNode);
            }
        });
    }

    private function crawlerHasNodes(Crawler $crawler): bool
    {
        return $crawler->count() >= 1;
    }

    private function encodeUri(string $uri)
    {
        try {
            $encodedUri = Helper::encodeUrl($uri);
            $encodedUri = str_replace('%25', '%', $encodedUri);
        } catch (Throwable $exception) {
            return null;
        }

        if (!$encodedUri || $encodedUri === '' || !filter_var($encodedUri, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $encodedUri;
    }

    private function getYoutubeVideoId(string $link): ?string
    {
        $youtubeRegex = '/(youtu\.be\/|youtube\.com\/(watch\?(.*&)?v=|(embed|v)\/))([\w-]{11})/iu';
        preg_match($youtubeRegex, $link, $matches);

        return $matches[5] ?? null;
    }

    private function normalizeSpaces(string $string): string
    {
        return preg_replace('/\s+/u', ' ', $string);
    }

    private function getSiteUri(): string
    {
        return self::SITE_URL;
    }

    private function getDescriptionFromText(Crawler $crawler): string
    {
        $description = '';

        $deepForFilter = 1;
        for ($deep = 1; $deep <= 10; $deep++) {
            if ($description && (str_contains($description, '.') || mb_strlen($description) > 16)) {
                break;
            }

            $descriptionCrawler = $crawler->filterXPath("//p[$deepForFilter]");
            if ($this->crawlerHasNodes($descriptionCrawler)) {
                $descriptionText = $descriptionCrawler->text();
                if ($descriptionText) {
                    $description .= " $descriptionText";
                    $this->removeDomNodes($crawler, "//p[$deepForFilter]");
                } else {
                    $deepForFilter++;
                }

                $description = Text::trim($this->normalizeSpaces($description));
            }
        }

        return $description;
    }

    private function getPublishedAt(Crawler $crawler): ?DateTimeImmutable
    {
        $months = [
            1 => 'январь',
            2 => 'февраль',
            3 => 'март',
            4 => 'апрель',
            5 => 'май',
            6 => 'июнь',
            7 => 'июль',
            8 => 'август',
            9 => 'сентябрь',
            10 => 'октябрь',
            11 => 'ноябрь',
            12 => 'декабрь',
        ];

        $publishedAt = $crawler->filter('.jrListingDate .jrDateValue')->text();
        $publishedAtString = str_replace($months, array_keys($months), $publishedAt);

        $publishedAt = DateTimeImmutable::createFromFormat('m d, Y', $publishedAtString, new DateTimeZone('UTC'));
        $publishedAt = $publishedAt->setTime(0, 0, 0);

        return $publishedAt;
    }
}
