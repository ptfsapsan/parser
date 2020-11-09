<?php


namespace app\components\parser\news;


use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use Exception;
use linslin\yii2\curl\Curl;
use PhpQuery\PhpQuery;

class ProhIstokiParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const DOMAIN = 'https://prohistoki.ru';
    private const LINK = 'https://prohistoki.ru/edw/api/data-marts/32/entities.json?offset=0&view_component=publication_list';

    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        try {
            $content = $curl->get(self::LINK);
        } catch (Exception $e) {
            return [];
        }
        $response = json_decode($content, true);
        $objects = $response['results']['objects'] ?? null;
        if ($objects && count($objects)) {
            foreach ($objects as $object) {
                $title = $object['entity_name'];
                $description = $object['extra']['short_subtitle'];
                $createDate = $object['extra']['created_at'];
                $image = null;
                $original = null;
                if (!empty($object['media'])) {
                    $media = PhpQuery::newDocument($object['media']);
                    $src = $media->find('img')->attr('src');
                    if (!empty($src)) {
                        $image = sprintf('%s%s', self::DOMAIN, trim($src));
                    }
                    $original = $media->find('a')->attr('href');
                    if (!empty($original)) {
                        $original = sprintf('%s%s', self::DOMAIN, trim($original));
                    }
                }

                try {
                    $post = new NewsPost(self::class, $title, $description, $createDate, $original, $image);
                } catch (Exception $e) {
                    continue;
                }
                $posts[] = self::setOriginalData($post, $original, $curl);
            }
        }

        return $posts;
    }

    private static function setOriginalData(NewsPost $post, string $original, Curl $curl): NewsPost
    {
        try {
            $content = $curl->get($original);
        } catch (Exception $e) {
            return $post;
        }
        $parser = PhpQuery::newDocument($content);
        $title = $parser->find('h1')->text();
        if (!empty($title)) {
            $post->addItem(
                new NewsPostItem(
                    NewsPostItem::TYPE_HEADER,
                    trim($title),
                    null,
                    null,
                    1,
                )
            );
        }

        $images = [];
        $image = $parser->find('.media-block .topic_image img')->attr('src');
        if (!empty($image)) {
            $images[] = sprintf('%s%s', self::DOMAIN, trim($image));
        }
        $img = $parser->find('.theme-default p img');
        if (count($img)) {
            foreach ($img as $item) {
                $src = $item->getAttribute('src');
                if (!empty($src)) {
                    $images[] = sprintf('%s%s', self::DOMAIN, trim($src));
                }
            }
        }
        if (count($images)) {
            foreach ($images as $image) {
                $post->addItem(
                    new NewsPostItem(
                        NewsPostItem::TYPE_IMAGE,
                        null,
                        $image,
                    )
                );
            }
        }

        $paragraphs = $parser->find('.theme-default p');
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

        $frame = $parser->find('.theme-default p iframe')->attr('src');
        if (!empty($frame) && strpos($frame, 'youtube.')) {
            $post->addItem(
                new NewsPostItem(
                    NewsPostItem::TYPE_VIDEO,
                    null,
                    null,
                    null,
                    null,
                    basename($frame),
                )
            );
        }

        return $post;
    }
}
