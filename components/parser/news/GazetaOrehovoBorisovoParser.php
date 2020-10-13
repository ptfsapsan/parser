<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DOMDocument;
use DOMNode;
use Exception;
use InvalidArgumentException;
use Symfony\Component\DomCrawler\Crawler;


class GazetaOrehovoBorisovoParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "http://gazeta-orehovo-borisovo-juzhnoe.ru";

    const FEED_SRC = "/feed/";


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

        $document = new DOMDocument();
        $document->loadXML($listSourceData);
        /** @var DOMNode $item */
        foreach ($document->getElementsByTagName("item") as $item) {
            $post = self::inflatePost($item);

            $posts[] = $post;
        }

        foreach ($posts as $post) {

            self::inflatePostContent($post);

        }

        return $posts;
    }

    /**
     * Собираем исходные данные из ответа API
     *
     * @param DOMNode $entityData
     *
     * @return NewsPost
     * @throws Exception
     */
    private static function inflatePost(DOMNode $entityData): NewsPost
    {
        $title = null;
        $original = null;
        $dateStr = null;
        $description = null;
        /** @var DOMNode $node */
        foreach ($entityData->childNodes as $node) {

            switch ($node->nodeName) {
                case "title":
                    $title = $node->nodeValue;
                    break;
                case "link":
                    $original = $node->nodeValue;
                    break;
                case "pubDate":
                    $dateStr = $node->nodeValue;
                    break;
                case "description":
                    $description = $node->nodeValue;
                    break;
            }
        }

        if (
            is_null($title)
            || is_null($original)
            || is_null($dateStr)
            || is_null($description)

        ) {
            throw new InvalidArgumentException("Post data not found in feed");
        }

        $createDate = new DateTime($dateStr);

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
     *
     * @throws Exception
     */
    private static function inflatePostContent(NewsPost $post)
    {
        $url = $post->original;
        $curl = Helper::getCurl();

        $pageData = $curl->get($url);
        $crawler = new Crawler($pageData);

        $content = $crawler->filter("article");

        $header = $content->filter("header h1");
        if ($header->count() !== 0) {
            self::addHeader($post, $header->text(), 1);
        }

        $image = $content->filter("figure img");
        if ($image->count() !== 0) {
            $url = preg_replace_callback('/[^\x20-\x7f]/', function ($match) {
                return urlencode($match[0]);
            }, $image->attr("src"));

            $url = Helper::prepareString($url);
            self::addImage($post, $url);
            $post->image = $url;
        }

        $content->filter("div.entry-content p")->each(function (Crawler $node, $index) use ($post) {
            if (!empty($node->text() && mb_strpos($node->text(), "Метки:") !== 0)) {
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

