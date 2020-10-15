<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use Exception;
use linslin\yii2\curl\Curl;
use Symfony\Component\DomCrawler\Crawler;


class KrasnoznamenskParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "http://inkrasnoznamensk.ru";

    const FEED_SRC = "/novosti";
    const LIMIT = 10;
    const NEWS_PER_PAGE = 16;
    const EMPTY_DESCRIPTION = "empty";
    const MONTHS = [
        "янв." => "01",
        "фев." => "02",
        "мар." => "03",
        "апр." => "04",
        "мая" => "05",
        "июн." => "06",
        "июл." => "07",
        "авг." => "08",
        "сен." => "09",
        "окт." => "10",
        "ноя." => "11",
        "дек." => "12",
    ];


    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $curl = Helper::getCurl();
        $posts = [];

        $counter = 0;
        for ($pageId = 0; $pageId < self::LIMIT; $pageId += self::NEWS_PER_PAGE) {

            $listSourcePath = $listSourcePath = self::ROOT_SRC . self::FEED_SRC . "?page=" . $pageId;
            $listSourceData = $curl->get("$listSourcePath");

            $crawler = new Crawler($listSourceData);
            $content = $crawler->filter("div.news-itm");

            foreach ($content as $newsItem) {
                try {
                    $node = new Crawler($newsItem);
                    $newsPost = self::inflatePost($node);
                    $posts[] = $newsPost;
                    $counter++;
                    if ($counter >= self::LIMIT) {
                        break 2;
                    }
                } catch (Exception $e) {
                    error_log($e->getMessage());
                    continue;
                }
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
        $title = $postData->filter("h3")->text();

        $original = self::ROOT_SRC . $postData->filter("h3 a")->attr("href");

        $imageUrl = null;
        $image = $postData->filter("img");
        if ($image->count() !== 0) {
            $imageUrl = self::ROOT_SRC . $image->attr("src");
        }

        $dateString = $postData->filter("p.news-itm__date")->text();
        $dateArr = explode(" ", $dateString);

        if (count($dateArr) !== 5) {
            throw new Exception("Date format error");
        }
        if (!isset($dateArr[1]) || !isset(self::MONTHS[$dateArr[1]])) {
            throw new Exception("Could not parse date string");
        }

        $dateString = $dateArr[2] . "-" . self::MONTHS[$dateArr[1]] . "-" . $dateArr[0] . " " . $dateArr[4] . "+03:00";
        $createDate = new DateTime($dateString);
        $createDate->setTimezone(new DateTimeZone("UTC"));

        $description = self::EMPTY_DESCRIPTION;

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
        if ($pageData === false) {
            throw new Exception("Url is wrong? nothing received: " . $url);
        }

        $crawler = new Crawler($pageData);

        $content = $crawler->filter("div.b-page__main");

        $header = $content->filter("h1.b-page__title");
        if ($header->count() !== 0) {
            self::addHeader($post, $header->text(), 1);
        }

        $header = $content->filter("div.b-page__start");
        if ($header->count() !== 0) {
            self::addText($post, $header->text());
        }

        $image = $content->filter("div.b-page__image img");
        if ($image->count() !== 0) {
            self::addImage($post, self::ROOT_SRC . $image->attr("src"));
        }

        $body = $content->filter("div.b-page__content");


        foreach ($body->children() as $bodyNode) {
            $node = new Crawler($bodyNode);

            if ($node->matches("blockquote")) {

                self::addQuote($post, $node->text());
                continue;
            }

            if ($node->matches("p") && !empty(trim($node->text(), "\xC2\xA0"))) {
                self::addText($post, $node->text());
                if ($post->description === self::EMPTY_DESCRIPTION) {
                    $post->description = Helper::prepareString($node->text());
                }
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
        return preg_replace_callback('/[^\x20-\x7f]/', function ($match) {
            return urlencode($match[0]);
        }, $content);
    }
}

