<?php
/**
 *
 * @author MediaSfera <info@media-sfera.com>
 * @author FingliGroup <info@fingli.ru>
 * @author Vitaliy Moskalyuk <flanker@bk.ru>
 *
 * @note Данный код предоставлен в рамках оказания услуг, для выполнения поставленных задач по сбору и обработке данных. Переработка, адаптация и модификация ПО без разрешения правообладателя является нарушением исключительных прав.
 *
 */
namespace app\components\mediasfera;


use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;

/**
 * @version 1.0
 *
 * @property string $title NewsPost->title
 * @property string $description NewsPost->description
 * @property string $createDate NewsPost->createDate
 * @property string $original NewsPost->original
 * @property string $image NewsPost->image
 *
 * @property NewsPostItem[] $items Property provides adding NewsPostItems array to NewsPost
 *
 * @property-write NewsPostItem $item Property provides adding NewsPostItem to NewsPost
 *
 * @property-write array  $itemHeader [string text, int level]<br>Property provides adding NewsPostItem::TYPE_HEADER to NewsPost
 * @property-write string $itemText Property provides adding NewsPostItem::TYPE_TEXT to NewsPost
 * @property-write array  $itemImage Property provides adding NewsPostItem::TYPE_IMAGE to NewsPost
 * @property-write string $itemQuote Property provides adding NewsPostItem::TYPE_QUOTE to NewsPost
 * @property-write array  $itemLink Property provides adding NewsPostItem::TYPE_LINK to NewsPost
 * @property-write string $itemVideo Property provides adding NewsPostItem::TYPE_VIDEO to NewsPost
  */

class NewsPostWrapper
{
    public bool $stopParsing = false;

    private string $parser;

    private string $title = '';
    private string $description = '';
    private string $createDate = '';
    private string $original = '';
    private string $image = '';

    private array $items = [];

    public string $check_empty_chars = " \t\n\r\0\x0B\xC2\xA0";

    public function __construct()
    {
        [$self, $caller] = debug_backtrace(0, 2);

        $class = $caller['class'];

        $this->parser = $class;
    }

    public function __set($name, $value)
    {
        switch ($name)
        {
            case 'title' :
            case 'description' :
            case 'createDate' :
            case 'original' :
            case 'image' :
                $this->$name = $value ?? '';
                break;

            case 'item' :
                $this->addItem($value);
                break;

            case 'itemHeader' :
            case 'itemText' :
            case 'itemImage' :
            case 'itemQuote' :
            case 'itemLink' :
            case 'itemVideo' :
                $method = 'add' . $name;
                $this->$method($value);
                break;

            case 'items' :
                $this->addItems($value);
                break;
        }
    }


    public function __get($name)
    {
        switch ($name)
        {
            case 'items' :
                return $this->items;

            case 'title' :
            case 'description' :
            case 'createDate' :
            case 'original' :
            case 'image' :
                return $this->$name;
        }

        return null;
    }


    public function stopParsing()
    {
        $this->stopParsing = true;
    }


    private function addItems(array $items) : void
    {
        foreach ($items as $item) {
            $this->addItem($item);
        }
    }


    private function addItem(?NewsPostItem $item) : void
    {
        if($item === null) {
            return;
        }

        $this->items[] = $item;
    }


    private function addItemHeader(?array $value) : void
    {
        if(!$value || count($value) != 2 || !$value[0] || !$value[1]) {
            return;
        }

        $check = trim(strip_tags($value[0]), $this->check_empty_chars);

        if(!$check) {
            return;
        }

        $this->items[] = new NewsPostItem(
            NewsPostItem::TYPE_HEADER,
            $value[0],
            null,
            null,
            $value[1],
        );
    }


    private function addItemText(?string $value) : void
    {
        $check = trim(strip_tags($value), $this->check_empty_chars);

        if(!$check) {
            return;
        }

        $this->items[] = new NewsPostItem(
            NewsPostItem::TYPE_TEXT,
            $value
        );
    }


    private function addItemImage(?array $value) : void
    {
        if(!$value || count($value) != 2 || !$value[1]) {
            return;
        }

        $value[0] = $value[0] ?? null;

        $this->items[] = new NewsPostItem(
            NewsPostItem::TYPE_IMAGE,
            $value[0],
            $value[1]
        );
    }


    private function addItemQuote(?string $value) : void
    {
        $check = trim(strip_tags($value), $this->check_empty_chars);

        if(!$check) {
            return;
        }

        $this->items[] = new NewsPostItem(
            NewsPostItem::TYPE_QUOTE,
            $value
        );
    }


    private function addItemLink(?array $value) : void
    {
        if(!$value || count($value) != 2 || !$value[0] || !$value[1]) {
            return;
        }

        $this->items[] = new NewsPostItem(
            NewsPostItem::TYPE_LINK,
            $value[0],
            null,
            $value[1]
        );
    }


    private function addItemVideo(?string $value) : void
    {
        if(!$value) {
            return;
        }

        $this->items[] = new NewsPostItem(
            NewsPostItem::TYPE_VIDEO,
            null,
            null,
            null,
            null,
            $value
        );
    }


    public function getNewsPost() : NewsPost
    {
        foreach ($this->items as $item) {
            if(!$this->description && $item->type == NewsPostItem::TYPE_TEXT) {
                $this->description = $item->text;
            }
            if(!$this->image && $item->type == NewsPostItem::TYPE_IMAGE) {
                $this->image = $item->image;
            }
        }

        $post = new NewsPost(
            $this->parser,
            $this->title,
            $this->description,
            $this->createDate,
            $this->original,
            $this->image ?? null
        );

        foreach ($this->items as $item) {
            $post->addItem($item);
        }

        return $post;
    }
}
