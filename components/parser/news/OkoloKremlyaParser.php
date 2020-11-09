<?php


namespace app\components\parser\news;


use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use Exception;
use linslin\yii2\curl\Curl;
use PhpQuery\PhpQuery;
use PhpQuery\PhpQueryObject;

class OkoloKremlyaParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'https://okolokremlya.ru/story';
    private const DOMAIN = 'https://okolokremlya.ru';

    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $parser = self::getParser(self::LINK, $curl);
        $items = $parser->find('.view-content .views-row');
        if (count($items)) {
            foreach ($items as $item) {
                try {
                    $item = PhpQuery::pq($item);
                } catch (Exception $e) {
                    continue;
                }
                $original = $item->find('.field-content a')->attr('href');
                $original = sprintf('%s%s', self::DOMAIN, $original);
                $title = $item->find('.field-content a')->text();
                $image = $item->find('.field-content a img')->attr('src');
                if (empty($image) || !filter_var($image, FILTER_VALIDATE_URL)) {
                    $image = null;
                }
                $originalParser = self::getParser($original, $curl);
                $date = $originalParser->find('.node-date .date')->text();
                $time = $originalParser->find('.node-date .time')->text();
                $createDate = sprintf('%s %s:00', $date, $time);
                $description = $originalParser->find('.field-items .field-item p:first')->text();
                if (empty($description)) {
                    $description = $title;
                }
                try {
                    $post = new NewsPost(self::class, $title, $description, $createDate, $original, $image);
                } catch (Exception $e) {
                    continue;
                }
                $posts[] = self::setOriginalData($originalParser, $post);
            }
        }

        return $posts;
    }

    private static function getParser(string $link, Curl $curl): PhpQueryObject
    {
        try {
            $content = $curl->get(Helper::prepareUrl($link));
        } catch (Exception $e) {
            return null;
        }

        return PhpQuery::newDocumentHTML($content, 'utf8');
    }

    private static function setOriginalData(PhpQueryObject $parser, NewsPost $post): NewsPost
    {
        $paragraphs = $parser->find('.field-items .field-item p:gt(0)');
        if (count($paragraphs)) {
            foreach ($paragraphs as $paragraph) {
                $text = trim($paragraph->textContent);
                if (!empty($text)) {
                    $post->addItem(
                        new NewsPostItem(
                            NewsPostItem::TYPE_TEXT,
                            $text,
                        )
                    );
                }
            }
        }
        $images = $parser->find('.field-items .field-item p img');
        if (count($images)) {
            foreach ($images as $img) {
                $src = $img->getAttribute('src');
                if (!empty($src) && filter_var($src, FILTER_VALIDATE_URL)) {
                    $post->addItem(
                        new NewsPostItem(
                            NewsPostItem::TYPE_IMAGE,
                            null,
                            $src,
                        )
                    );
                }
            }
        }
        $links = $parser->find('.field-items .field-item p a');
        if (count($links)) {
            foreach ($links as $link) {
                $href = $link->getAttribute('href');
                if (!empty($href) && filter_var($href, FILTER_VALIDATE_URL)) {
                    $post->addItem(
                        new NewsPostItem(
                            NewsPostItem::TYPE_LINK,
                            null,
                            null,
                            $href,
                        )
                    );
                }
            }
        }

        return $post;
    }
}
