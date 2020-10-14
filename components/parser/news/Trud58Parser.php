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


class Trud58Parser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "http://www.trud58.ru";

    const FEED_SRC = "/news.rss";
    const LIMIT = 20;


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

        $crawler = new Crawler($listSourceData);
        $counter = 0;
        foreach ($crawler->filter("item") as $item) {
            $node = new Crawler($item);
            $newsPost = self::inflatePost($node);
            $posts[] = $newsPost;
            $counter++;
            if ($counter >= self::LIMIT) {
                break;
            }
        }

        foreach ($posts as $post) {
            self::inflatePostContent($post, $curl);
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

        $title = $entityData->filter("title")->text();
        $original = $entityData->filter("link")->text();
        $description = $entityData->filter("description")->text();

        $createDate = new DateTime($entityData->filterXPath("item/pubDate")->text() . "+03:00");
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
     */
    private static function inflatePostContent(NewsPost $post, $curl)
    {
        $url = $post->original;

        $pageData = $curl->get($url);
        $crawler = new Crawler($pageData);

        $content = $crawler->filter("div.inpage");

        $header = $content->filter("h1");

        if ($header->count() !== 0) {
            self::addHeader($post, $header->text(), 1);
        }

        $header = $content->filter("div.mnname h2");

        if ($header->count() !== 0) {
            self::addHeader($post, $header->text(), 2);
        }


        $newsData = $content->filter("div.onemidnew > div > p");

        foreach ($newsData as $item) {
            $node = new Crawler($item);

            $image = $node->filter("img");
            if ($image->count() !== 0) {
                $post->image = Helper::prepareString(self::ROOT_SRC . $image->attr("src"));
                $image->each(function (Crawler $imageNode) use ($post) {
                    self::addImage($post, self::ROOT_SRC . $imageNode->attr("src"));
                });
            }

            $quote = $node->filter("em");
            if ($quote->count() !== 0 && !empty(trim($node->text(), "\xC2\xA0"))) {
                self::addQuote($post, $node->text());
                continue;
            }

            if (!empty(trim($node->text(), "\xC2\xA0"))) {
                self::addText($post, $node->text());
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
        $post->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_IMAGE,
                null,
                $content,
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
}

