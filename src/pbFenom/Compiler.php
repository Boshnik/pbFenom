<?php
declare(strict_types=1);
/*
 * This file is part of pbFenom.
 *
 * (c) 2013 Ivan Shalganov
 *
 * For the full copyright and license information, please view the license.md
 * file that was distributed with this source code.
 */
namespace pbFenom;

use pbFenom\Error\CompileException;
use pbFenom\Error\InvalidUsageException;
use pbFenom\Error\UnexpectedTokenException;

/**
 * Compilers collection
 * @package pbFenom
 * @author     Ivan Shalganov <a.cobest@gmail.com>
 */
class Compiler
{
    /**
     * Tag {include ...}
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @throws \LogicException
     * @return string
     */
    public static function tagInclude(Tokenizer $tokens, Tag $tag): string
    {
        $tpl   = $tag->tpl;
        $name  = null;
        $cname = $tpl->parsePlainArg($tokens, $name);
        $p     = $tpl->parseParams($tokens);
        if ($name) {
            if ($tpl->getStorage()->getOptions() & \pbFenom::FORCE_INCLUDE) {
                $_t = $tpl;
                $recursion = false;
                while($_t->parent) {
                    if($_t->parent->getName() == $name) { // recursion detected
                        $recursion = true;
                    }
                    $_t = $_t->parent;
                }
                if(!$recursion) {
                    $inc = $tpl->getStorage()->getRawTemplate($tpl);
                    $inc->load($name, true);
                    $tpl->addDepend($inc);
                    $var = $tpl->tmpVar();
                    if ($p) {
                        return $var . ' = $var; $var = ' . self::toArray($p) . ' + $var; ?>' . $inc->getBody() . '<?php $var = ' . $var . '; unset(' . $var . ');';
                    } else {
                        return $var . ' = $var; ?>' . $inc->getBody() . '<?php $var = ' . $var . '; unset(' . $var . ');';
                    }
                }
            } elseif (!$tpl->getStorage()->templateExists($name)) {
                throw new \LogicException("Template $name not found");
            }
        }
        // For a static name the resolved Render is the same for the whole render call,
        // but this used to re-enter getTemplate() on every iteration of an enclosing
        // loop. Memoise it per call-site; the temp lives in the closure's scope.
        $get = '$tpl->getStorage()->getTemplate(' . $cname . ')';
        if ($name) {
            $get = '(' . $tpl->tmpVar() . ' ??= ' . $get . ')';
        }
        if ($p) {
            return $get . '->display(' . self::toArray($p) . ' + $var);';
        } else {
            return $get . '->display($var);';
        }
    }

    /**
     * Tag {insert ...}
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @return string
     * @throws Error\InvalidUsageException|CompileException
     */
    public static function tagInsert(Tokenizer $tokens, Tag $tag): string
    {
        $tpl = $tag->tpl;
        $tpl->parsePlainArg($tokens, $name);
        if (!$name) {
            throw new InvalidUsageException("Tag {insert} accept only static template name");
        }
        $inc = $tpl->getStorage()->compile($name, false);
        $tpl->addDepend($inc);
        return '?>' . $inc->getBody() . '<?php';
    }


    /**
     * Open tag {if ...}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     */
    public static function ifOpen(Tokenizer $tokens, Tag $scope): string
    {
        $scope["else"] = false;
        return 'if(' . $scope->tpl->parseExpr($tokens) . ') {';
    }

    /**
     * Tag {elseif ...}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @throws InvalidUsageException
     * @return string
     */
    public static function tagElseIf(Tokenizer $tokens, Tag $scope): string
    {
        if ($scope["else"]) {
            throw new InvalidUsageException('Incorrect use of the tag {elseif}');
        }
        return '} elseif(' . $scope->tpl->parseExpr($tokens) . ') {';
    }

    /**
     * Tag {else}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     */
    public static function tagElse(Tokenizer $tokens, Tag $scope): string
    {
        $scope["else"] = true;
        return '} else {';
    }

    /**
     * Open tag {foreach ...}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     * @throws InvalidUsageException
     * @throws CompileException
     * @throws UnexpectedTokenException
     * @throws CompileException
     */
    public static function foreachOpen(Tokenizer $tokens, Tag $scope): string
    {
        $scope["else"] = false;
        $scope["key"] = null;
        $scope["prepend"] = "";
        $scope["before"] = array();
        $scope["after"] = array();
        $scope["body"] = array();

        if ($tokens->is('[')) { // array
            $count = 0;
            $scope['from'] = $scope->tpl->parseArray($tokens, $count);
            $scope['check'] = $count;
            $scope["var"] = $scope->tpl->tmpVar();
            $scope['prepend'] = $scope["var"].' = '.$scope['from'].';';
            $scope['from']  = $scope["var"];
        } else { // expression
            $scope['from'] = $scope->tpl->parseExpr($tokens, $is_var);
            if($is_var) {
                $scope['check'] = '!empty('.$scope['from'].') && (is_array('.$scope['from'].') || '.$scope['from'].' instanceof \Traversable)';
            } else {
                $scope["var"] = $scope->tpl->tmpVar();
                $scope['prepend'] = $scope["var"].' = '.$scope['from'].';';
                $scope['from']  = $scope["var"];
                $scope['check'] = 'is_array('.$scope['from'].') && count('.$scope['from'].') || ('.$scope['from'].' instanceof \Traversable)';
            }
        }
        if($tokens->is(T_AS)) {
            $tokens->next();
            $value = $scope->tpl->parseVariable($tokens);
            if ($tokens->is(T_DOUBLE_ARROW)) {
                $tokens->next();
                $scope["key"]   = $value;
                $scope["value"] = $scope->tpl->parseVariable($tokens);
            } else {
                $scope["value"] = $value;
            }
        } else {
            $scope["value"] = '$_un';
        }
        // Reading $var["row"]["name"] costs two hash lookups per access. Binding the
        // loop variable to a local *reference* costs one, and because it is a
        // reference $var["row"] stays in sync for {include}, macros and $.tpl.
        $scope["aliases"] = array();
        foreach (array("value", "key") as $slot) {
            if ($scope[$slot] && preg_match('/^\$var\["(\w+)"\]$/', $scope[$slot], $m)) {
                $local = $scope->tpl->tmpVar();
                $scope["before"][] = "$local = &{$scope[$slot]}";
                $scope[$slot]      = $local;
                $scope["aliases"][] = $m[1];
                $scope->tpl->pushVarAlias($m[1], $local);
            }
        }
        while ($token = $tokens->key()) {
            $param = $tokens->get(T_STRING);
            $var_name = self::foreachProp($scope, $param);
            $tokens->getNext("=");
            $tokens->next();
            $scope['before'][] = $scope->tpl->parseVariable($tokens)." = &". $var_name;
        }

        return '';
    }

    /**
     * Open tag {for ...}
     *
     * Restored: the implementation was dropped by the 3.0 PHP-8 migration while the
     * registration in Fenom::$_actions was left behind, so the documented {for} tag
     * fatalled with "Compiler does not have a method forOpen" from 3.0.0 onwards.
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     * @throws UnexpectedTokenException
     * @throws InvalidUsageException
     */
    public static function forOpen(Tokenizer $tokens, Tag $scope): string
    {
        $p = array(
            "index" => false,
            "first" => false,
            "last"  => false,
            "step"  => 1,
            "to"    => false,
        );
        $scope["after"] = $before = $body = array();
        $i              = array('', '');
        $c              = "";
        $var            = $scope->tpl->parseTerm($tokens, $is_var);
        if (!$is_var) {
            throw new UnexpectedTokenException($tokens);
        }
        $tokens->get("=");
        $tokens->next();
        $val = $scope->tpl->parseExpr($tokens);
        $p   = $scope->tpl->parseParams($tokens, $p);

        if (is_numeric($p["step"])) {
            if ($p["step"] > 0) {
                $condition = "$var <= {$p['to']}";
                if ($p["last"]) {
                    $c = "($var + {$p['step']}) > {$p['to']}";
                }
            } elseif ($p["step"] < 0) {
                $condition = "$var >= {$p['to']}";
                if ($p["last"]) {
                    $c = "($var + {$p['step']}) < {$p['to']}";
                }
            } else {
                throw new InvalidUsageException("Invalid step value");
            }
        } else {
            $condition = "({$p['step']} > 0 && $var <= {$p['to']} || {$p['step']} < 0 && $var >= {$p['to']})";
            if ($p["last"]) {
                $c = "({$p['step']} > 0 && ($var + {$p['step']}) <= {$p['to']} || {$p['step']} < 0 && ($var + {$p['step']}) >= {$p['to']})";
            }
        }

        if ($p["first"]) {
            $before[]         = $p["first"] . ' = true';
            $scope["after"][] = $p["first"] . ' && (' . $p["first"] . ' = false )';
        }
        if ($p["last"]) {
            $before[] = $p["last"] . ' = false';
            $body[]   = "if($c) {$p['last']} = true";
        }
        if ($p["index"]) {
            $i[0] .= $p["index"] . ' = 0,';
            $i[1] .= $p["index"] . '++,';
        }

        $scope["else"]      = false;
        $scope["else_cond"] = "$var==$val";
        $before             = $before ? implode("; ", $before) . ";" : "";
        $body               = $body ? implode("; ", $body) . ";" : "";
        $scope["after"]     = $scope["after"] ? implode("; ", $scope["after"]) . ";" : "";

        return "$before for({$i[0]} $var=$val; $condition;{$i[1]} $var+={$p['step']}) { $body";
    }

    /**
     * Tag {forelse}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     */
    public static function forElse(Tokenizer $tokens, Tag $scope): string
    {
        $scope["no-break"] = $scope["no-continue"] = true;
        $scope["else"]     = true;
        return " } if({$scope['else_cond']}) {";
    }

    /**
     * Close tag {/for}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     */
    public static function forClose(Tokenizer $tokens, Tag $scope): string
    {
        if ($scope["else"]) {
            return '}';
        } else {
            return " {$scope['after']} }";
        }
    }

    /**
     * Tag {foreachelse}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     */
    public static function foreachElse(Tokenizer $tokens, Tag $scope): string
    {
        $scope["no-break"] = $scope["no-continue"] = $scope["else"] = true;
        foreach ((array)$scope["aliases"] as $name) {
            $scope->tpl->popVarAlias($name);
        }
        $scope["aliases"] = array();
        $after = $scope["after"]  ? implode("; ", $scope["after"]) . ";" : "";
        return " {$after} } } else {";
    }

    /**
     * @param Tag $scope
     * @param string $prop
     * @return string
     * @throws CompileException
     */
    public static function foreachProp(Tag $scope, string $prop): string
    {
        if(empty($scope["props"][$prop])) {
            $var_name = $scope["props"][$prop] = $scope->tpl->tmpVar()."_".$prop;
            switch($prop) {
                case "index":
                    $scope["before"][] = $var_name . ' = 0';
                    $scope["after"][]  = $var_name . '++';
                    break;
                case "first":
                    $scope["before"][] = $var_name . ' = true';
                    $scope["after"][]  = $var_name . ' && (' . $var_name . ' = false )';
                    break;
                case "last":
                    $scope["before"][] = $var_name . ' = false';
                    // The {foreach} guard explicitly accepts \Traversable, but count()
                    // rejects it, so a Generator used to fatal here. Non-countable
                    // iterables are materialised first — iterator_count() would consume
                    // the generator and leave nothing to iterate.
                    $src = $scope->tpl->tmpVar();
                    $scope["before"][] = $src . ' = is_countable(' . $scope["from"] . ')'
                        . ' ? ' . $scope["from"] . ' : iterator_to_array(' . $scope["from"] . ')';
                    $scope["from"]     = $src;
                    $scope["uid"]      = $scope->tpl->tmpVar();
                    $scope["before"][] = $scope["uid"] . " = count($src)";
                    $scope["body"][]   = 'if(!--' . $scope["uid"] . ') ' . $var_name . ' = true';
                    break;
                default:
                    throw new CompileException("Unknown foreach property '$prop'");
            }
        }

        return $scope["props"][$prop];
    }

    /**
     * Close tag {/foreach}
     *
     * @static
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     */
    public static function foreachClose(Tokenizer $tokens, Tag $scope): string
    {
        foreach ((array)$scope["aliases"] as $name) {
            $scope->tpl->popVarAlias($name);
        }
        $before         = $scope["before"] ? implode("; ", $scope["before"]) . ";" : "";
        $head           = $scope["body"]   ? implode("; ", $scope["body"]) . ";" : "";
        $body           = $scope->getContent();
        if ($scope["key"]) {
            $code = "<?php {$scope["prepend"]} if({$scope["check"]}) {\n $before foreach({$scope["from"]} as {$scope["key"]} => {$scope["value"]}) { $head?>$body";
        } else {
            $code = "<?php {$scope["prepend"]} if({$scope["check"]}) {\n $before foreach({$scope["from"]} as {$scope["value"]}) { $head?>$body";
        }
        $scope->replaceContent($code);
        if ($scope["else"]) {
            return '}';
        } else {
            $after = $scope["after"]  ? implode("; ", $scope["after"]) . ";" : "";
            return " {$after} } }";
        }
    }

    /**
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     */
    public static function whileOpen(Tokenizer $tokens, Tag $scope): string
    {
        return 'while(' . $scope->tpl->parseExpr($tokens) . ') {';
    }

    /**
     * Open tag {switch}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     */
    public static function switchOpen(Tokenizer $tokens, Tag $scope): string
    {
        $expr             = $scope->tpl->parseExpr($tokens);
        $scope["case"]    = array();
        $scope["last"]    = array();
        $scope["default"] = '';
        $scope["var"]     = $scope->tpl->tmpVar();
        $scope["expr"]    = $scope["var"] . ' = strval(' . $expr . ')';
        // lazy init
        return '';
    }

    /**
     * Resort cases for {switch}
     * @param Tag $scope
     */
    private static function _caseResort(Tag $scope): void
    {
        $content = $scope->cutContent();
        foreach ($scope["last"] as $case) {
            if($case === false) {
                $scope["default"] .= $content;
            } else {
                if (!isset($scope["case"][$case])) {
                    $scope["case"][$case] = "";
                }
                $scope["case"][$case] .= $content;
            }
        }
        $scope["last"] = array();
    }

    /**
     * Tag {case ...}
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @return string
     */
    public static function tagCase(Tokenizer $tokens, Tag $tag): string
    {
        self::_caseResort($tag);
        do {
            if($tokens->is(T_DEFAULT)) {
                $tag["last"][] = false;
                $tokens->next();
            } else {
                $tag["last"][] = $tag->tpl->parseScalar($tokens);
            }
            if ($tokens->is(',')) {
                $tokens->next();
            } else {
                break;
            }
        } while (true);
        return '';
    }


    /**
     * Tag {default}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     */
    public static function tagDefault(Tokenizer $tokens, Tag $scope): string
    {
        self::_caseResort($scope);
        $scope["last"][] = false;
        return '';
    }

    /**
     * Close tag {switch}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     */
    public static function switchClose(Tokenizer $tokens, Tag $scope): string
    {
        self::_caseResort($scope);
        $expr    = $scope["var"];
        $code    = $scope["expr"] . ";\n";
        $default = $scope["default"];
        foreach ($scope["case"] as $case => $content) {
            if (is_numeric($case)) {
                $case = "'$case'";
            }
            $code .= "if($expr == $case) {\n?>$content<?php\n} else";
        }
        $code .= " {\n?>$default<?php\n}\nunset(" . $scope["var"] . ")";
        return $code;
    }

    /**
     * Tag {continue}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     * @throws InvalidUsageException
     */
    public static function tagContinue(Tokenizer $tokens, Tag $scope): string
    {
        if (empty($scope["no-continue"])) {
            return 'continue;';
        } else {
            throw new InvalidUsageException("Improper usage of the tag {continue}");
        }
    }

    /**
     * Tag {break}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     * @throws InvalidUsageException
     */
    public static function tagBreak(Tokenizer $tokens, Tag $scope): string
    {
        if (empty($scope["no-break"])) {
            return 'break;';
        } else {
            throw new InvalidUsageException("Improper usage of the tag {break}");
        }
    }

    /**
     * Dispatch {extends} tag
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @throws Error\InvalidUsageException
     * @return void
     */
    public static function tagExtends(Tokenizer $tokens, Tag $tag): void
    {
        $tpl = $tag->tpl;
        if ($tpl->extends) {
            throw new InvalidUsageException("Only one {extends} allowed");
        } elseif ($tpl->getStackSize()) {
            throw new InvalidUsageException("Tag {extends} can not be nested");
        }
        $cname = $tpl->parsePlainArg($tokens, $name);
        if ($name) {
            $tpl->extends = $name;
        } else {
            $tpl->dynamic_extends = $cname;
        }
        if (!$tpl->extend_body) {
            $tpl->addPostCompile(__CLASS__ . "::extendBody");
            $tpl->extend_body = true;
        }
    }

    /**
     * Post compile action for {extends ...} tag
     * @param Template $tpl
     * @param string $body
     * @throws CompileException
     */
    public static function extendBody(Template $tpl, string &$body): void
    {
        if ($tpl->dynamic_extends) {
            if (!$tpl->ext_stack) {
                $tpl->ext_stack[] = $tpl->getName();
            }
            foreach ($tpl->ext_stack as &$t) {
                $stack[] = "'$t'";
            }
            $stack[] = $tpl->dynamic_extends;
            $body    = '<?php $tpl->getStorage()->display(array(' . implode(', ', $stack) . '), $var); ?>';
        } else {
            $child = $tpl;
            while ($child && $child->extends) {
                $parent = $tpl->extend($child->extends);
                $child  = $parent->extends ? $parent : false;
            }
            $tpl->extends = null;
        }
        $tpl->extend_body = false;
    }

    /**
     * Tag {use ...}
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @throws Error\InvalidUsageException|CompileException
     */
    public static function tagUse(Tokenizer $tokens, Tag $tag): void
    {
        $tpl = $tag->tpl;
        if ($tpl->getStackSize()) {
            throw new InvalidUsageException("Tag {use} can not be nested");
        }
        $tpl->parsePlainArg($tokens, $name);
        if ($name) {
            $tpl->importBlocks($name);
        } else {
            throw new InvalidUsageException('Invalid template name for tag {use}');
        }
    }

    /**
     * Tag {block ...}
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @throws \RuntimeException
     */
    public static function tagBlockOpen(Tokenizer $tokens, Tag $scope): void
    {
        $scope["cname"] = $scope->tpl->parsePlainArg($tokens, $name);
        if (!$name) {
            throw new \RuntimeException("Invalid block name");
        }
        $scope["name"]       = $name;
        $scope["use_parent"] = false;
    }

    /**
     * @param Tokenizer $tokens
     * @param Tag $scope
     */
    public static function tagBlockClose(Tokenizer $tokens, Tag $scope): void
    {
        $tpl  = $scope->tpl;
        $name = $scope["name"];
        if (isset($tpl->blocks[$name])) { // block defined
            $block = & $tpl->blocks[$name];
            if ($block['use_parent']) {
                $parent = $scope->getContent();
                $block['block'] = str_replace($block['use_parent'] . " ?>", "?>" . $parent, $block['block']);
            }
            if (!$block["import"]) { // not from {use} - redefine block
                $scope->replaceContent($block["block"]);
                return;
            } elseif ($block["import"] != $tpl->getName()) { // tag {use} was in another template
                $tpl->blocks[$scope["name"]]["import"] = false;
                $scope->replaceContent($block["block"]);
            }
        }

        $tpl->blocks[$scope["name"]] = array(
            "from"       => $tpl->getName(),
            "import"     => false,
            "use_parent" => $scope["use_parent"],
            "block"      => $scope->getContent()
        );
    }

    /**
     * Tag {parent}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     */
    public static function tagParent(Tokenizer $tokens, Tag $scope): string
    {
        $block_scope = $scope->tpl->getParentScope('block');
        if (!$block_scope['use_parent']) {
            $block_scope['use_parent'] = "/* %%parent#{$scope['name']}%% */";
        }
        return $block_scope['use_parent'];
    }

    /**
     * Standard close tag {/...}
     *
     * @return string
     */
    public static function stdClose(): string
    {
        return '}';
    }

    /**
     * Standard function parser
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @return string
     */
    public static function stdFuncParser(Tokenizer $tokens, Tag $tag): string
    {
        if(is_string($tag->callback)) {
            return $tag->out($tag->callback . "(" . self::toArray($tag->tpl->parseParams($tokens)) . ', $tpl, $var)');
        } else {
            return '$info = $tpl->getStorage()->getTag('.var_export($tag->name, true).');'.PHP_EOL.
            $tag->out('call_user_func_array($info["function"], array('.self::toArray($tag->tpl->parseParams($tokens)).', $tpl, &$var))');
        }
    }

    /**
     * Smart function parser
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @return string
     * @throws \ReflectionException
     */
    public static function smartFuncParser(Tokenizer $tokens, Tag $tag): string
    {
        // Closure::fromCallable() normalises every callable form - closure, array
        // callable, invokable object, "Class::method" - into something Reflection can
        // read. The old code called strpos() on $tag->callback, which is a TypeError
        // for anything but a string, so addFunctionSmart() only ever worked with
        // string callables.
        $ref  = new \ReflectionFunction(\Closure::fromCallable($tag->callback));
        $args = array();
        $params = $tag->tpl->parseParams($tokens);
        foreach ($ref->getParameters() as $param) {
            if (isset($params[$param->getName()])) {
                $args[] = $params[$param->getName()];
            } elseif (isset($params[$param->getPosition()])) {
                $args[] = $params[$param->getPosition()];
            } elseif ($param->isOptional()) {
                $args[] = var_export($param->getDefaultValue(), true);
            } elseif (!$param->allowsNull()) {
                throw new InvalidUsageException(
                    "Function {$tag->name} requires the '{$param->getName()}' argument"
                );
            }
        }
        if (is_string($tag->callback)) {
            return $tag->out($tag->callback . "(" . implode(", ", $args) . ')');
        }
        // not nameable in generated code: reach it through the registry at runtime
        return $tag->out(
            'call_user_func_array($tpl->getStorage()->getTag(' . var_export($tag->name, true)
            . ')["function"], array(' . implode(", ", $args) . '))'
        );
    }

    /**
     * Standard function open tag parser
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @return string
     */
    public static function stdFuncOpen(Tokenizer $tokens, Tag $tag): string
    {
        $tag["params"] = self::toArray($tag->tpl->parseParams($tokens));
        $tag->setOption(\pbFenom::AUTO_ESCAPE, false);
        return 'ob_start();';
    }

    /**
     * Standard function close tag parser
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @return string
     */
    public static function stdFuncClose(Tokenizer $tokens, Tag $tag): string
    {
        $tag->restore(\pbFenom::AUTO_ESCAPE);
        if(is_string($tag->callback)) {
            return $tag->out($tag->callback . "(" . $tag["params"] . ', ob_get_clean(), $tpl, $var)');
        } else {
            return '$info = $tpl->getStorage()->getTag('.var_export($tag->name, true).');'.PHP_EOL.
            $tag->out('call_user_func_array($info["function"], array(' . $tag["params"] . ', ob_get_clean(), $tpl, &$var))');
        }
    }

    /**
     * Convert array of code to string array
     * @param array $params
     * @return string
     */
    public static function toArray(array $params): string
    {
        $_code = array();
        foreach ($params as $k => $v) {
            $_code[] = '"' . $k . '" => ' . $v;
        }

        return 'array(' . implode(",", $_code) . ')';
    }

    /**
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     * @throws CompileException
     */
    public static function setOpen(Tokenizer $tokens, Tag $scope): string
    {
        if($tokens->is(T_VARIABLE)) {
            $var = $scope->tpl->parseVariable($tokens);
        } elseif($tokens->is('$')) {
            $var = $scope->tpl->parseAccessor($tokens, $is_var);
            if(!$is_var) {
                throw new InvalidUsageException("Accessor is not writable");
            }
        } else {
            throw new InvalidUsageException("{set} and {add} accept only variable");
        }
        $before = $after = "";
        if($scope->name == 'add') {
            $before = "if(!isset($var)) {\n";
            $after = "\n}";
        }
        if ($tokens->is(Tokenizer::MACRO_EQUALS, '[')) { // inline tag {var ...}
            $equal = $tokens->getAndNext();
            if($equal == '[') {
                $tokens->need(']')->next()->need('=')->next();
                $equal = '[]=';
            }
            $scope->close();
            if ($tokens->is("[")) {
                return $before.$var . $equal . $scope->tpl->parseArray($tokens) . ';'.$after;
            } else {
                return $before.$var . $equal . $scope->tpl->parseExpr($tokens) . ';'.$after;
            }
        } else {
            $scope["name"] = $var;
            if ($tokens->is('|')) {
                $scope["value"] = $scope->tpl->parseModifier($tokens, "ob_get_clean()").';';
            } else {
                $scope["value"] = "ob_get_clean();";
            }
            return 'ob_start();';
        }
    }

    /**
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     */
    public static function setClose(Tokenizer $tokens, Tag $scope): string
    {
        if ($scope->name === 'add') {
            return "if(!isset(" . $scope["name"] . ")) {\n" . $scope["name"] . '=' . $scope["value"] . ";\n} else {\n    ob_end_clean();\n}";
        }
        return $scope["name"] . '=' . $scope["value"] . ';';
    }

    public static function tagDo(Tokenizer $tokens, Tag $scope): string
    {
        return $scope->tpl->parseExpr($tokens).';';
    }


    /**
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     * @throws CompileException
     */
    public static function filterOpen(Tokenizer $tokens, Tag $scope): string
    {
        $scope["filter"] = $scope->tpl->parseModifier($tokens, "ob_get_clean()");
        return "ob_start();";
    }

    /**
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     */
    public static function filterClose(Tokenizer $tokens, Tag $scope): string
    {
        return "echo " . $scope["filter"] . ";";
    }

    /**
     * Tag {cycle}
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @throws Error\InvalidUsageException
     * @return string
     */
    public static function tagCycle(Tokenizer $tokens, Tag $tag): string
    {
        $tpl = $tag->tpl;
        if ($tokens->is("[")) {
            $exp = $tpl->parseArray($tokens);
        } else {
            $exp = $tpl->parseExpr($tokens);
        }
        if ($tokens->valid()) {
            $p = $tpl->parseParams($tokens);
            if (empty($p["index"])) {
                throw new InvalidUsageException("Cycle may contain only index attribute");
            } else {
                return 'echo ' . __CLASS__ . '::cycle(' . $exp . ', ' . $p["index"] . ')';
            }
        } else {
            $var = $tpl->tmpVar();
            return 'echo ' . __CLASS__ . '::cycle(' . $exp . ", isset($var) ? ++$var : ($var = 0) )";
        }
    }

    /**
     * Runtime cycle callback
     * @param array $vals
     * @param int $index
     * @return mixed
     */
    public static function cycle(array $vals, int $index): mixed
    {
        return $vals[$index % count($vals)];
    }

    /**
     * Import macros from templates
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @return string
     * @throws Error\InvalidUsageException|CompileException
     * @throws Error\UnexpectedTokenException
     */
    public static function tagImport(Tokenizer $tokens, Tag $tag): string
    {
        $tpl    = $tag->tpl;
        $import = array();
        if ($tokens->is('[')) {
            $tokens->next();
            while ($tokens->valid()) {
                if ($tokens->is(Tokenizer::MACRO_STRING)) {
                    $import[$tokens->current()] = true;
                    $tokens->next();
                } elseif ($tokens->is(']')) {
                    $tokens->next();
                    break;
                } elseif ($tokens->is(',')) {
                    $tokens->next();
                } else {
                    break;
                }
            }
            if ($tokens->current() != "from") {
                throw new UnexpectedTokenException($tokens);
            }
            $tokens->next();
        }

        $tpl->parsePlainArg($tokens, $name);
        if (!$name) {
            throw new InvalidUsageException("Invalid template name");
        }
        if ($tokens->is(T_AS)) {
            $alias = $tokens->next()->get(Tokenizer::MACRO_STRING);
            if ($alias === "macro") {
                $alias = "";
            }
            $tokens->next();
        } else {
            $alias = "";
        }
        $donor = $tpl->getStorage()->getRawTemplate()->load($name, true);
        if ($donor->macros) {
            foreach ($donor->macros as $name => $macro) {
                if ($p = strpos($name, ".")) {
                    $name = substr($name, $p);
                }
                if ($import && !isset($import[$name])) {
                    continue;
                }
                if ($alias) {
                    $name = $alias . '.' . $name;
                }
                $tpl->macros[$name] = $macro;
            }
            $tpl->addDepend($donor);
        }
        return '';

    }

    /**
     * Define macro
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @throws InvalidUsageException
     */
    public static function macroOpen(Tokenizer $tokens, Tag $scope): void
    {
        $scope["name"]      = $tokens->get(Tokenizer::MACRO_STRING);
        $scope["recursive"] = false;
        $args               = array();
        $defaults           = array();
        if (!$tokens->valid()) {
            return;
        }
        $tokens->next();
        if ($tokens->is('(') || !$tokens->isNext(')')) {
            $tokens->next();
            while ($tokens->is(Tokenizer::MACRO_STRING, T_VARIABLE)) {
                $param = $tokens->current();
                if ($tokens->is(T_VARIABLE)) {
                    $param = ltrim($param, '$');
                }
                $tokens->next();
                $args[] = $param;
                if ($tokens->is('=')) {
                    $tokens->next();
                    if ($tokens->is(T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER) || $tokens->isSpecialVal()) {
                        $defaults[$param] = $tokens->getAndNext();
                    } else {
                        throw new InvalidUsageException("Macro parameters may have only scalar defaults");
                    }
                }
                $tokens->skipIf(',');
            }
            $tokens->skipIf(')');
        }
        $scope["macro"] = array(
            "name"      => $scope["name"],
            "args"      => $args,
            "defaults"  => $defaults,
            "body"      => "",
            "recursive" => false
        );
        // A macro body receives its own $var and, when recursive, becomes a separate
        // closure — an enclosing {foreach}'s local alias does not exist in that scope.
        $scope["saved_aliases"] = $scope->tpl->suspendVarAliases();
        return;
    }

    /**
     * @param Tokenizer $tokens
     * @param Tag $scope
     */
    public static function macroClose(Tokenizer $tokens, Tag $scope): void
    {
        $scope->tpl->restoreVarAliases((array)$scope["saved_aliases"]);
        if ($scope["recursive"]) {
            $scope["macro"]["recursive"] = true;
        }
        $scope["macro"]["body"]             = $scope->cutContent();
        $scope->tpl->macros[$scope["name"]] = $scope["macro"];
    }

    /**
     * Output value as is, without escaping
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @return string
     */
    public static function tagRaw(Tokenizer $tokens, Tag $tag): string
    {
        return 'echo ' . $tag->tpl->parseExpr($tokens);
    }

    /**
     * @param Tokenizer $tokens
     * @param Tag $tag
     */
    public static function escapeOpen(Tokenizer $tokens, Tag $tag): void
    {
        $expected = $tokens->get(T_STRING) == "true";
        $tokens->next();
        $tag->setOption(\pbFenom::AUTO_ESCAPE, $expected);
    }

    /**
     * Do nothing
     */
    public static function nope(): void
    {
    }

    /**
     * @param Tokenizer $tokens
     * @param Tag $tag
     */
    public static function stripOpen(Tokenizer $tokens, Tag $tag): void
    {
        $expected = $tokens->get(T_STRING) == "true";
        $tokens->next();
        $tag->setOption(\pbFenom::AUTO_STRIP, $expected);
    }

    /**
     * Tag {ignore}
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     */
    public static function ignoreOpen(Tokenizer $tokens, Tag $tag): void
    {
        $tag->tpl->ignore($tag->name);
    }

    /**
     * Tag {unset ...}
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @return string
     * @throws CompileException
     */
    public static function tagUnset(Tokenizer $tokens, Tag $tag): string
    {
        $unset = array();
        while($tokens->valid()) {
            $unset[] = $tag->tpl->parseVariable($tokens);
        }
        return 'unset('.implode(", ", $unset).')';
    }

    public static function tagPaste(Tokenizer $tokens, Tag $tag): string
    {
        $name = str_replace(array('\'', '"'), '', $tokens->get(T_CONSTANT_ENCAPSED_STRING));
        $tokens->next();
        if(isset($tag->tpl->blocks[$name])) {
            // substr($block, 1, -1) blindly chopped one character off each end of
            // already-compiled PHP, so {paste} emitted a syntax error and cached it
            return "?>" . $tag->tpl->blocks[$name]["block"] . "<?php ";
        } else {
            return "";
        }
    }
}
