<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateInterval;
use DateTime;
use DateTimeZone;
use DOMNode;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Парсер новостей с сайта https://chehov-vid.ru/
 */
class ChehovVidRuParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;
    const SITE_URL = 'https://chehov-vid.ru';

    public static function run(): array
    {
        $parser = new self();
        return $parser->parse();
    }

    public function parse(): array
    {
        $posts = [];

        $urlNews = self::SITE_URL . '/news/';
        $newsPage = $this->getPageContent($urlNews);
        $newsList = $this->getListNews($newsPage);
        foreach ($newsList as $newsUrl) {
            $url = self::SITE_URL . $newsUrl;
            $contentPage = $this->getPageContent($url);
            if (!$contentPage) {
                continue;
            }

            $itemCrawler = new Crawler($contentPage);
            $title = $itemCrawler->filterXPath('//header/h1')->text();
            $date = $this->getDate($itemCrawler->filterXPath('//span[@class="date"]')->text());
            $image = null;
            $imgSrc = $itemCrawler->filterXPath('//div[@class="news-detail"]/img[@class="img-responsive"]');
            if ($imgSrc->getNode(0)) {
                $image = $this->getHeadUrl($imgSrc->attr('src'));
            }

            $description = $title;
            /*
            $descriptionSrc = $itemCrawler->filterXPath('//div[@class="full-text"]');
            foreach ($descriptionSrc as $item) {
                foreach ($item->childNodes as $value) {
                    if ($description) {
                        continue;
                    }
                    $text = $this->clearText($value->nodeValue);
                    if ($value->childNodes->count() > 1) {
                        foreach ($value->childNodes as $childNode) {
                            $text = $this->clearText($childNode->nodeValue);
                            if ($text) {
                                $description = $text;
                            }
                        }
                    }else {
                        $description = $text;
                    }
                }
            }
            */

            $post = new NewsPost(
                self::class,
                $title,
                $description,
                $date,
                $url,
                $image
            );

            $newContentCrawler = $itemCrawler->filterXPath('//div[@class="full-text"]');
            foreach ($newContentCrawler as $contentNew) {
                if ($contentNew->childNodes->count() > 1) {
                    foreach ($contentNew->childNodes as $childNode) {
                        $this->setItemPostValue($post, $childNode);
                    }
                } else {
                    $this->setItemPostValue($post, $contentNew);
                }
            }

            $posts[] = $post;
        }

        return $posts;
    }

    /**
     * @param NewsPost $post
     * @param int $type
     * @param string|null $text
     * @param string|null $image
     * @param string|null $link
     * @param int|null $headerLevel
     * @param string|null $youtubeId
     */
    protected function addItemPost(NewsPost $post, int $type, string $text = null, string $image = null,
                                   string $link = null, int $headerLevel = null, string $youtubeId = null): void
    {
        $post->addItem(
            new NewsPostItem(
                $type,
                $text,
                $image,
                $link,
                $headerLevel,
                $youtubeId
            ));
    }

    /**
     * @param NewsPost $post
     * @param DOMNode $node
     */
    protected function setItemPostValue (NewsPost $post, DOMNode $node): void {
        $nodeValue = $this->clearText($node->nodeValue, [$post->description]);
        if (in_array($node->nodeName, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6']) && $nodeValue) {

            $this->addItemPost($post, NewsPostItem::TYPE_HEADER, $nodeValue, null, null, (int)substr($node->nodeName, 1));

        } elseif ($node->nodeName == 'a' && strpos($href = $this->getHeadUrl($node->getAttribute('href')), 'http') !== false && $href != self::SITE_URL) {

            $this->addItemPost($post, NewsPostItem::TYPE_LINK, $nodeValue, null, $href);

        } elseif ($node->nodeName == 'img' && ($imgSrc = $this->getHeadUrl($node->getAttribute('src'))) != $post->image && getimagesize($imgSrc)) {
            if (!$post->image) {

                $post->image = $imgSrc;

            } else {

                $this->addItemPost($post, NewsPostItem::TYPE_IMAGE, $node->getAttribute('title'), $imgSrc);

            }
        } elseif ($node->nodeName == 'iframe') {
            $src = $this->getHeadUrl($node->getAttribute('src'));
            if (strpos($src, 'youtube') !== false) {
                $youId = basename(parse_url($src, PHP_URL_PATH));
                $titleYou = $node->getAttribute('title');

                $this->addItemPost($post, NewsPostItem::TYPE_VIDEO, $titleYou, null, null, null, $youId);

            }
        } elseif ($nodeValue && $node->parentNode->nodeName == 'blockquote') {

            $this->addItemPost($post, NewsPostItem::TYPE_QUOTE, $nodeValue);

        } elseif ($node->childNodes->count()) {

            foreach ($node->childNodes as $childNode) {

                $this->setItemPostValue($post, $childNode);
            }

        }  elseif ($nodeValue && $nodeValue != $post->description) {

            if ($post->description == $post->title) {

                $post->description = $nodeValue;

            } else {

                $this->addItemPost($post, NewsPostItem::TYPE_TEXT, $nodeValue);

            }
        }
    }

    /**
     *
     * @param string $date
     *
     * @return string
     */
    protected function getDate(string $date = ''): string
    {
        $date = $this->clearText($date);
        $date = str_replace(',', '', $date);
        $now = new DateTime();
        $today = $now->format('Y-m-d');
        $date = mb_strtolower($date);
        $yesterday = $now->sub(new DateInterval('P1D'))->format('Y-m-d');
        $ruMonths = ['янв', 'фев', 'мар', 'апр', 'май', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек', 'сегодня', 'вчера'];
        $enMonths = ['january', 'february', 'march', 'april', 'may', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december', $today, $yesterday];
        $newDate = new DateTime(str_ireplace($ruMonths, $enMonths, $date));
        $newDate->setTimezone(new DateTimeZone("UTC"));
        return $newDate->format("Y-m-d H:i:s");
    }


    /**
     *
     * @param string $url
     * @param string $det
     *
     * @return string
     */
    protected function getHeadUrl($url, $det = ''): string
    {
        $url = strpos($url, 'http') === false || strpos($url, 'http') > 0
            ? self::SITE_URL . $det . $url
            : $url;
        return $this->encodeUrl($url);
    }

    /**
     * Русские буквы в ссылке
     *
     * @param string $url
     *
     * @return string
     */
    protected function encodeUrl(string $url): string
    {
        $partsUrl = parse_url($url);
        if (preg_match('/[А-Яа-яЁё]/iu', $partsUrl['host'])) {
            $host = idn_to_ascii($partsUrl['host']);
            $url = str_replace($partsUrl['host'], $host, $url);
        }
        if (preg_match('/[А-Яа-яЁё]/iu', $url)) {
            preg_match_all('/[А-Яа-яЁё]/iu', $url, $result);
            $search = [];
            $replace = [];
            foreach ($result as $item) {
                foreach ($item as $key=>$value) {
                    $search[$key] = $value;
                    $replace[$key] = urlencode($value);
                }
            }
            $url = str_replace($search, $replace, $url);
        }
        return $url;
    }

    /**
     * Получение списка ссылок на страницы новостей
     *
     * @param string $page
     *
     * @return array
     */
    protected function getListNews(string $page): array
    {
        $records = [];

        $crawler = new Crawler($page);
        $list = $crawler->filterXPath('//div[@class="news-item"]')->filterXPath('//a');
        foreach ($list as $item) {
            $href = $item->getAttribute("href");
            if (!in_array($href, $records)) {
                $records[] = $href;
            }
        }

        return $records;
    }

    /**
     *
     * @param string $uri
     *
     * @return string
     * @throws RuntimeException|\Exception
     */
    private function getPageContent(string $uri): string
    {
        $curl = Helper::getCurl();

        $result = $curl->get($uri);
        $responseInfo = $curl->getInfo();

        $httpCode = $responseInfo['http_code'] ?? null;

        if ($httpCode >= 200 && $httpCode < 400) {
            return $result;
        }

        throw new RuntimeException("Не удалось скачать страницу {$uri}, код ответа {$httpCode}");
    }

    /**
     *
     * @param string $text
     * @param array $search
     *
     * @return string
     */
    protected function clearText(string $text, array $search = []): string
    {
        $text = html_entity_decode($text);
        $text = strip_tags($text);
        $text = htmlentities($text);
        $search = array_merge(["&nbsp;"], $search);
        $text = str_replace($search, ' ', $text);
        $text = html_entity_decode($text);
        return trim($text);
    }
}