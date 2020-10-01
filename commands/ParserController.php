<?php


namespace app\commands;

use app\components\parser\ParserInterface;
use yii\base\ErrorException;
use yii\console\Controller;

class ParserController extends Controller
{

    /**
     * Run parser by class name
     * @param $className
     * @throws ErrorException
     */
    public function actionNews(string $className): void
    {
        $fullClassName = 'app\components\parser\\news\\' . $className;
        $parserObject = new $fullClassName();
        if (!($parserObject instanceof ParserInterface)) {
            throw new ErrorException('Класс парсера должен имплементировать ParserInterface');
        }
        $posts = $parserObject->run();
        foreach ($posts as $post) {
            $post->validate();
        }
        echo "Ok" . PHP_EOL;
    }


}
