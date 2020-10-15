<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use Exception;
use Symfony\Component\DomCrawler\Crawler;


class LegitimistParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "https://www.legitimist.ru";

    const LIMIT = 100;
    const ITEMS_PER_PAGE = 27;
    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {

        $curl = Helper::getCurl();

        $path = self::ROOT_SRC . "/news/";
        $posts = [];

        for($page = 1; $page <= ceil(self::LIMIT / self::ITEMS_PER_PAGE); $page++){
            $payloadData = $curl->get($path . "?page=" . $page);

            $crawler = new Crawler($payloadData);

            $srcNewsList = $crawler->filter("table.main-list tr");

            $srcNewsList->each(function(Crawler $node, $index) use (&$posts) {

                if($node->children()->filter("ul.paging")->count() !== 0){
                    return;
                }

                $post = self::inflatePost($node);
                $posts[] = $post;
            });
        }

        /** @var NewsPost $post */
        foreach ($posts as $post) {
            $payloadData = $curl->get($post->original);

            $crawler = new Crawler($payloadData);
            $content = $crawler->filter("div#article_block");

            $image = $content->filter("div#imgn img");

            if ($image->count() !== 0) {
                $imgUrl = $image->attr("src");
                self::addImage($post, $imgUrl);
                $post->image = self::normalizeUrl(self::ROOT_SRC . $imgUrl);
            }

            $header = $content->filter("div.outro h1");
            if ($header->count() !== 0) {
                self::addHeader($post, $header->text(), 1);
            }


            $content->filter("div.content")->children()->each(function (Crawler $node, $index) use ($post) {
                if ($node->nodeName() === "p" && $node->children()->count() === 0 && !empty($node->text())) {
                    self::addText($post, $node->text());
                    return;
                }
                if ($node->nodeName() === "p" && $node->filter("br")->count() !== 0 && !empty($node->text())) {
                    self::addText($post, $node->text());
                    return;
                }
                if ($node->nodeName() === "p" && $node->filter("span")->count() !== 0 && !empty($node->text())) {
                    self::addText($post, $node->text());
                    return;
                }
                if ($node->nodeName() === "p" && $node->filter("a")->count() !== 0 && !empty($node->text())) {
                    self::addText($post, $node->text());
                    return;
                }
                if ($node->nodeName() === "div" && $node->filter("strong")->count() !== 0 && !empty($node->text())) {
                    self::addHeader($post, $node->text(), 6);
                    return;
                }

                if ($node->nodeName() === "p" && $node->filter("em")->count() !== 0 && !empty($node->text())) {
                    self::addQuote($post, $node->text());
                    return;
                }
                if ($node->nodeName() === "h3") {
                    self::addHeader($post, $node->text(), 3);
                    return;
                }
                if ($node->filter("img")->count() !== 0) {
                    self::addImage($post, "/" . $node->filter("img")->attr("src"));
                    return;
                }

            });
        }

        return $posts;
    }

    /**
     * Собираем исходные данные из ответа API
     *
     * @param Crawler $entityData
     *
     * @return NewsPost
     * @throws Exception
     */
    private static function inflatePost(Crawler $entityData): NewsPost
    {

        $timeString = $entityData->filter("span.time")->html();
        $timeString = str_replace("\xc2\xa0",'',$timeString);
        $timeArr = explode(" ", $timeString);
        $date = explode(".", $timeArr[0]);

        $newDateString = "";
        $newDateString .= $date[2] ?? date("Y");
        $newDateString .= "-" . $date[1];
        $newDateString .= "-" . $date[0];
        $newDateString .= " " . $timeArr[1];


        $createDate = new DateTime($newDateString, new DateTimeZone("Europe/Moscow"));
        $createDate->setTimezone(new DateTimeZone("UTC"));
        $title = $entityData->filter("h3 a")->text();
        $original = $entityData->filter("h3 a")->attr("href");
        $description = rtrim($entityData->filter("h3 a")->text(), "→");

        return new NewsPost(
            self::class,
            $title,
            $description,
            $createDate->format("Y-m-d H:i:s"),
            self::ROOT_SRC . "/" . $original,
            null
        );
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
        $url = self::normalizeUrl($content);
        $post->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_IMAGE,
                null,
                self::ROOT_SRC . $url,
                null,
                1,
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

