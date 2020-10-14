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
use Symfony\Component\DomCrawler\Crawler;


class GazetaHotParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "http://www.gazetahot.ru";

    const FEED_SRC = "/novosti/rss.xml";
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
        $createDate = new DateTime($entityData->filterXPath("item/pubDate")->text());
        $createDate->setTimezone(new DateTimeZone("UTC"));
        $original = $entityData->filter("link")->text();

        $image = $entityData->filter("enclosure");
        $imageUrl = null;
        if ($image->count() !== 0) {
            $imageUrl = $image->attr("url");
        }

        $description = $entityData->filter("description")->text();
        if (empty($description)) {
            $text = $entityData->filterXPath("item/yandex:full-text");

            $sentences = preg_split('/(?<=[.?!])\s+(?=[а-я])/i', $text->text());
            $description = implode(" ", array_slice($sentences, 0, 3));
        }

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

        $content = $crawler->filter(".full-news");

        $header = $content->filter("h1");

        if ($header->count() !== 0) {
            self::addHeader($post, $header->text(), 1);
        }

        $image = $content->filter("img");

        if ($image->count() !== 0) {
            $image->each(function (Crawler $imageNode) use ($post) {
                self::addImage($post, $imageNode->attr("src"));
            });
        }

        /** @var DOMNode $node */
        foreach ($content->filter("div.full-news-content")->getNode(0)->childNodes as $node) {
            if (!in_array($node->nodeName, ["#text", "b"])) {
                continue;
            }
            $text = new Crawler($node);
            if (!empty(trim($text->text(), "\xC2\xA0"))) {
                self::addText($post, $text->text());
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

