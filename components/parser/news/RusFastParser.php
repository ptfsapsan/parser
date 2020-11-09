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

class RusFastParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'http://rusfact.com/';
    private const DOMAIN = 'http://rusfact.com';

    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $parser = self::getParser(self::LINK, $curl);
        $items = $parser->find('h2 a');
        if (count($items)) {
            foreach ($items as $item) {
                $title = trim($item->textContent);
                $original = $item->getAttribute('href');
                $originalParser = self::getParser($original, $curl);
                $createDate = trim($originalParser->find('.date-top')->text());
                $pos = mb_strpos($createDate, 'Дата: ');
                $createDate = mb_substr($createDate, $pos + 6, 19);
                $createDate = str_replace('в ', '', $createDate);
                $createDate = substr_replace($createDate, '20', 6, 0);

                $image = $originalParser->find('.shot-text2 img:first')->attr('src');
                $image = empty($image) ? null : sprintf('%s%s', self::DOMAIN, $image);
                $description = $title;
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

        return PhpQuery::newDocument($content);
    }

    private static function setOriginalData(PhpQueryObject $parser, NewsPost $post): NewsPost
    {
        $paragraphs = $parser->find('.shot-text2');
        if (count($paragraphs)) {
            foreach ($paragraphs as $paragraph) {
                foreach ($paragraph->childNodes as $childNode) {
                    if ($childNode->nodeName == '#text') {
                        $text = trim($childNode->textContent);
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
            }
        }
        $images = $parser->find('.shot-text2 img:gt(0)');
        if (count($images)) {
            foreach ($images as $img) {
                $src = $img->getAttribute('src');
                if (!empty($src)) {
                    $src = sprintf('%s%s', self::DOMAIN, $src);
                    if (filter_var($src, FILTER_VALIDATE_URL)) {
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
        }

        return $post;
    }

}
