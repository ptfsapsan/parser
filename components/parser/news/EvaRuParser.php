<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use DOMNode;
use Exception;
use linslin\yii2\curl\Curl;
use Symfony\Component\DomCrawler\Crawler;


class EvaRuParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "https://eva.ru";

    const FEED_SRC = "/novosti-shou-biznesa/";
    const LIMIT = 5;
    const MONTHS = [
        "января" => "01",
        "февраля" => "02",
        "марта" => "03",
        "апреля" => "04",
        "мая" => "05",
        "июня" => "06",
        "июля" => "07",
        "августа" => "08",
        "сентября" => "09",
        "октября" => "10",
        "ноября" => "11",
        "декабря" => "12",
    ];

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $curl = Helper::getCurl();
        $posts = [];

        $listSourcePath = self::ROOT_SRC . self::FEED_SRC;

        $listSourceData = $curl->get($listSourcePath);

        if (empty($listSourceData)) {
            throw new Exception("Получен пустой ответ от источника списка новостей: " . $listSourcePath);
        }

        $crawler = new Crawler($listSourceData);
        $items = $crawler->filter("ul.news-list")->first()->children();
        if ($items->count() === 0) {
            throw new Exception("Пустой список новостей в ленте: " . $listSourcePath);
        }

        $counter = 0;
        foreach ($items as $item) {
            try {
                $node = new Crawler($item);
                $newsPost = self::inflatePost($node);
                $posts[] = $newsPost;
                $counter++;
                if ($counter >= self::LIMIT) {
                    break;
                }
            } catch (Exception $e) {
                error_log($e->getMessage());
                continue;
            }
        }

        foreach ($posts as $key => $post) {
            try {
                self::inflatePostContent($post, $curl);
            } catch (Exception $e) {
                error_log($e->getMessage());
                unset($posts[$key]);
                continue;
            }
        }
        return $posts;
    }

    /**
     * Собираем исходные данные из ответа API
     *
     * @param Crawler $postData
     *
     * @return NewsPost
     * @throws Exception
     */
    private static function inflatePost(Crawler $postData): NewsPost
    {
        $title = $postData->filter("span.h3")->text();

        $original = self::ROOT_SRC . $postData->filter("span.h3 a")->attr("href");


        $description = $postData->filter("p")->text();

        $dateString = $postData->filter("span.date")->text();
        $dateArr = explode(" ", $dateString);
        if (count($dateArr) !== 3) {
            throw new Exception("Date format error");
        }
        if (!isset($dateArr[1]) || !isset(self::MONTHS[$dateArr[1]])) {
            throw new Exception("Could not parse date string");
        }

        $dateString = date("Y") . "-" . self::MONTHS[$dateArr[1]] . "-" . $dateArr[0] . " " . $dateArr[2] . "+03:00";
        $createDate = new DateTime($dateString);
        $createDate->setTimezone(new DateTimeZone("UTC"));

        $imageUrl = null;


        return new NewsPost(
            self::class,
            $title,
            $description,
            $createDate->format("Y-m-d H:i:s"),
            $original,
            $imageUrl
        );

    }

    /**
     * @param NewsPost $post
     * @param          $curl
     *
     * @throws Exception
     */
    private static function inflatePostContent(NewsPost $post, Curl $curl)
    {
        $url = $post->original;

        $pageData = $curl->get($url);
        if (empty($pageData)) {
            throw new Exception("Получен пустой ответ от страницы новости: " . $url);
        }

        $crawler = new Crawler($pageData);

        $body = $crawler->filter("article div.news-read > div");

        if ($body->count() === 0) {
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }

        /** @var DOMNode $bodyNode */
        foreach ($body->children() as $bodyNode) {
            $node = new Crawler($bodyNode);
            if ($node->matches("div.row")) {
                continue;
            }

            if (
                $node->matches("div")
                && mb_stripos($node->attr("style"), "padding: 40px;") !== false
            ) {
                self::addQuote($post, $node->text());
                continue;
            }

            if ($node->matches("p.photo") && $node->filter("img")->count() !== 0) {
                $image = $node->filter("img");
                if ($post->image === null) {
                    $post->image = self::normalizeUrl($image->attr("data-src"));
                } else {
                    self::addImage($post, $image->attr("data-src"));
                    if ($node->filter("small.copyright")->count() !== 0) {
                        self::addText($post, $node->filter("small.copyright")->text());
                    }
                }
                continue;
            }

            if ($node->matches("small.copyright")) {
                self::addText($post, $node->filter("small.copyright")->text());
            }

            if ($node->matches("p") && !empty(trim($node->text(), "\xC2\xA0"))) {
                self::addText($post, $node->text());
                continue;
            }

        }
    }

    /**
     * @param NewsPost $post
     * @param string   $content
     * @param int      $level
     */
    protected static function addHeader(NewsPost $post, string $content, int $level): void
    {
        $post->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_HEADER,
                $content,
                null,
                null,
                $level,
                null
            ));
    }

    /**
     * @param NewsPost $post
     * @param string   $content
     */
    protected static function addImage(NewsPost $post, string $content): void
    {
        $content = self::normalizeUrl($content);
        $post->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_IMAGE,
                null,
                $content,
                null,
                null,
                null
            ));
    }

    /**
     * @param NewsPost $post
     * @param string   $content
     */
    protected static function addText(NewsPost $post, string $content): void
    {
        $post->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_TEXT,
                $content,
                null,
                null,
                null,
                null
            ));
    }

    /**
     * @param NewsPost $post
     * @param string   $content
     */
    protected static function addQuote(NewsPost $post, string $content): void
    {
        $post->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_QUOTE,
                $content,
                null,
                null,
                null,
                null
            ));
    }

    /**
     * @param string $content
     *
     * @return string
     */
    protected static function normalizeUrl(string $content)
    {
        return preg_replace_callback('/[^\x21-\x7f]/', function ($match) {
            return rawurlencode($match[0]);
        }, $content);
    }
}

