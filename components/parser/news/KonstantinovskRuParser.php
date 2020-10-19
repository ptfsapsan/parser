<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Парсер новостей с сайта http://konstantinovsk.ru/
 */
class KonstantinovskRuParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;
    const SITE_URL = 'http://konstantinovsk.ru';

    public static function run(): array
    {
        $parser = new self();
        return $parser->parse();
    }

    public function parse(): array
    {
        $posts = [];

        $urlNews = self::SITE_URL;
        $newsPage = $this->getPageContent($urlNews);
        $newsList = $this->getListNews($newsPage);
        foreach ($newsList as $newsUrl) {
            $url = self::SITE_URL . $newsUrl;
            $contentPage = $this->getPageContent($url);
            if (!$contentPage) {
                continue;
            }
            $itemCrawler = new Crawler($contentPage);
            $content = $itemCrawler->filterXPath("//div[@id='content']");
            $title = $content->filterXPath("//h1")->text();
            $date = $this->getDate($content->filterXPath("//div[@class='date']")->text());
            $image = null;
            $imgSrc = $content->filterXPath("//img");
            if ($imgSrc->getNode(0)) {
                $image = $this->getHeadUrl($imgSrc->attr('src'));
            }
            $description = $title;

            $post = new NewsPost(
                self::class,
                $title,
                $description,
                $date,
                $url,
                $image
            );

            $this->addItemPost($post, NewsPostItem::TYPE_HEADER, $title, null, null, 1);

            $newContentCrawler = $content->filterXPath("//div[@class='node']/table/*/td");
            $description = '';
            foreach ($newContentCrawler as $content) {
                foreach ($content->childNodes as $key => $childNode) {
                    if ($key < 6) {
                        continue;
                    }
                    if (in_array($childNode->nodeName, ['#cdata-section', 'div', 'script', 'table'])) {
                        continue;
                    }

                    $nodeValue = trim($childNode->nodeValue);
                    if (in_array($childNode->nodeName, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6']) && $childNode->nodeValue != $title) {

                        $this->addItemPost($post, NewsPostItem::TYPE_HEADER, $nodeValue, null, null, (int) substr($childNode->nodeName, 1));

                    } if ($childNode->nodeName == 'a' && strpos($childNode->getAttribute('href'), 'http') !== false) {

                        $this->addItemPost($post, NewsPostItem::TYPE_LINK, $nodeValue, null, $childNode->getAttribute('href'));

                    } elseif ($nodeValue) {
                        $this->addItemPost($post, NewsPostItem::TYPE_TEXT, $nodeValue);
                    }
                    $description = $description . ' ' . $nodeValue;
                }
            }

            if (trim($description) && $post->description != $description) {
                $post->description = $description;
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
     *
     * @param string $date
     *
     * @return string
     */
    protected function getDate(string $date): string
    {
        $now = new \DateTime();
        $today = $now->format('Y-m-d');
        $date = mb_strtolower($date);
        $yesterday = $now->sub(new \DateInterval('P1D'))->format('Y-m-d');
        $ruMonths = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря', 'сегодня', 'вчера'];
        $enMonths = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december', $today, $yesterday];
        return $date ? str_ireplace($ruMonths, $enMonths, $date) : '';
    }

    /**
     *
     * @param string $url
     *
     * @return string
     */
    protected function getHeadUrl($url): string
    {
        return strpos($url, 'http') === false
            ? self::SITE_URL . $url
            : $url;
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
        $list = $crawler->filterXPath("//div[@id='content']//*//table//*//h1/a");
        foreach ($list as $item) {
            $records[] = $item->getAttribute("href");
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
     *
     * @return string
     */
    protected function clearText(string $text): string
    {
        $text = trim($text);
        $text = htmlentities($text);
        $text = str_replace("&nbsp;",' ',$text);
        $text = html_entity_decode($text);
        return $text;
    }
}