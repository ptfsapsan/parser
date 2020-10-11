<?php

namespace app\components\helper\aayaami;

use ArrayIterator;
use DOMNodeList;
use RecursiveIterator;
use RecursiveIteratorIterator;

class DOMNodeRecursiveIterator extends ArrayIterator implements RecursiveIterator {

    public function __construct (DOMNodeList $node_list) {

        $nodes = array();
        foreach($node_list as $node) {
            $nodes[] = $node;
        }

        parent::__construct($nodes);

    }

    public function getRecursiveIterator(){
        return new RecursiveIteratorIterator($this, RecursiveIteratorIterator::LEAVES_ONLY);
    }

    public function hasChildren () {
        return $this->current()->hasChildNodes();
    }


    public function getChildren () {
        return new self($this->current()->childNodes);
    }

}