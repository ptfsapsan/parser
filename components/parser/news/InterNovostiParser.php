<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DOMNode;
use Exception;
use Symfony\Component\DomCrawler\Crawler;


class InterNovostiParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "http://www.internovosti.ru";

    const LIMIT = 100;

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {

        $curl = Helper::getCurl();

        $listSourcePath = self::ROOT_SRC . "/xmlnews.asp";

        $listSourceData = $curl->get($listSourcePath);

        $newsListData = new Crawler($listSourceData);
        $newsList = $newsListData->filter("item");

        $posts = [];

        $newsList->each(function ($newsItem, $index) use (&$posts) {
            $post = self::inflatePost($newsItem);
            try {
                self::inflatePostContent($post);
                $posts[] = $post;
            } catch (Exception $e) {
                error_log("error on:" . $post->original . " | " . $e->getMessage());
                return;
            }
        });

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
     *
     * @throws Exception
     */
    private static function inflatePostContent(NewsPost $post)
    {
        $url = $post->original;
        $curl = Helper::getCurl();
        $srcData = iconv('Windows-1251', 'UTF-8', $curl->get($url));

        $page = new Crawler($srcData);

        $content = $page->filter("span.mytime")->parents()->first();


        $header = $content->filter("h1")->text();
        if (!empty($header)) {
            self::addHeader($post, $header, 1);
        }

        $image = $content->filter("img")->first()->attr("src");
        if (!empty($image)) {
            self::addImage($post, $image);
        }

        $photoText = $content->filter("span.mysmall")->text();
        if (!empty($photoText)) {
            self::addText($post, $photoText);
        }

        $text = "";
        /** @var DOMNode $node */
        foreach ($content->getNode(0)->childNodes as $node) {
            if(strpos(trim($node->textContent), "Для добавления комментария ") === 0){
                if(!empty($text)) {
                    self::addText($post, $text);
                }
                break;
            }

            if ($node->nodeName === '#text' && trim($node->textContent) !== "") {
                $text .= trim($node->textContent);
            }
            if ($node->nodeName === 'a' && trim($node->textContent) !== "") {
                $text .= " " . trim($node->textContent) . " ";
            }
            if($node->nodeName === "br" && !empty($text)){
                self::addText($post, $text);
                $text = "";
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

