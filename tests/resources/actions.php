<?php

function myMod($str)
{
    return "(myMod)" . $str . "(/myMod)";
}

function myFunc($params)
{
    return "MyFunc:" . $params["name"];
}

function myBlockFunc($params, $content)
{
    return "Block:" . $params["name"] . ':' . trim($content) . ':Block';
}

function myCompiler(pbFenom\Tokenizer $tokenizer, pbFenom\Tag $tag)
{
    $p = $tag->tpl->parseParams($tokenizer);
    return 'echo "PHP_VERSION: ".PHP_VERSION." (for ".' . $p["name"] . '.")";';
}

function myBlockCompilerOpen(pbFenom\Tokenizer $tokenizer, pbFenom\Tag $scope)
{
    $p = $scope->tpl->parseParams($tokenizer);
    return 'echo "PHP_VERSION: ".PHP_VERSION." (for ".' . $p["name"] . '.")";';
}

function myBlockCompilerClose(pbFenom\Tokenizer $tokenizer, pbFenom\Tag $scope)
{
    return 'echo "End of compiler";';
}

function myBlockCompilerTag(pbFenom\Tokenizer $tokenizer, pbFenom\Tag $scope)
{
    $p = $scope->tpl->parseParams($tokenizer);
    return 'echo "Tag ".' . $p["name"] . '." of compiler";';
}