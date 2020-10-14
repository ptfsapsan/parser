<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use Exception;
use Symfony\Component\DomCrawler\Crawler;


class InrightParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "https://inright.ru";

    const FEED_SRC = "/export/yandex.xml";


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
        $crawler->filter("item")->each(function (Crawler $node, $index) use (&$posts) {
            $newsPost = self::inflatePost($node);
            $posts[] = $newsPost;
        });

        $curl->setOption(CURLOPT_ENCODING, "");

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
        $createDate = new DateTime($entityData->filter("pubDate")->text());
        $createDate->setTimezone(new \DateTimeZone("UTC"));
        $description = $entityData->filter("description")->text();
        $original = $entityData->filter("link")->text();

        $imageUrl = $entityData->filter("enclosure")->attr("url");

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

        $content = $crawler->filter("div.unit");

        $header = $content->filter("h2");
        if ($header->count() !== 0) {
            self::addHeader($post, $header->text(), 2);
        }

        $image = $content->children()->filter("td div.gallery img");
        if ($image->count() !== 0) {
            self::addImage($post, $url);
            $post->image = $url;
        }

        $content->filter("div.text p")->each(function (Crawler $node, $index) use ($post) {
            if (!empty($node->text())) {
                self::addText($post, $node->text());
            }
        });
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

