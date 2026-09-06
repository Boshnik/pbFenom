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
use pbFenom\Error\UnexpectedTokenException;

/**
 * Class Accessor
 * @package pbFenom
 */
class Accessor {
    public static array $vars = array(
        'get'     => '$_GET',
        'post'    => '$_POST',
        'session' => '$_SESSION',
        'cookie'  => '$_COOKIE',
        'request' => '$_REQUEST',
        'files'   => '$_FILES',
        'globals' => '$GLOBALS',
        'server'  => '$_SERVER',
        'env'     => '$_ENV'
    );

    /**
     * @param string $var variable expression on PHP ('App::get("storage")->user')
     * @param Tokenizer $tokens
     * @param Template $tpl
     * @param bool $is_var
     * @return string
     * @throws CompileException
     */
    public static function parserVar(string $var, Tokenizer $tokens, Template $tpl, bool &$is_var): string
    {
        $is_var = true;
        return $tpl->parseVariable($tokens, $var);
    }

    /**
     * @param string $call method name expression on PHP ('App::get("storage")->getUser')
     * @param Tokenizer $tokens
     * @param Template $tpl
     * @return string
     */
    public static function parserCall(string $call, Tokenizer $tokens, Template $tpl): string
    {
        return $call.$tpl->parseArgs($tokens);
    }

    /**
     * @param string $prop fenom's property name
     * @param Tokenizer $tokens
     * @param Template $tpl
     * @param bool $is_var
     * @return string
     * @throws CompileException
     */
    public static function parserProperty(string $prop, Tokenizer $tokens, Template $tpl, bool &$is_var): string
    {
        $is_var = true;
        return self::parserVar('$tpl->getStorage()->'.$prop, $tokens, $tpl, $is_var);
    }

    /**
     * @param string $method fenom's method name
     * @param Tokenizer $tokens
     * @param Template $tpl
     * @return string
     */
    public static function parserMethod(string $method, Tokenizer $tokens, Template $tpl): string
    {
        return self::parserCall('$tpl->getStorage()->'.$method, $tokens, $tpl);
    }

    /**
     * Accessor for global variables
     * @param Tokenizer $tokens
     * @param Template $tpl
     * @return string
     * @throws CompileException
     */
    public static function getVar(Tokenizer $tokens, Template $tpl): string
    {
        $name = $tokens->prevToken()[Tokenizer::TEXT];
        if(isset(self::$vars[$name])) {
            $var = $tpl->parseVariable($tokens, self::$vars[$name]);
            return "(($var) ?? null)";
        } else {
            throw new UnexpectedTokenException($tokens->back());
        }
    }

    /**
     * Accessor for template information
     * @param Tokenizer $tokens
     * @return string
     */
    public static function tpl(Tokenizer $tokens): string
    {
        $method = $tokens->skip('.')->need(T_STRING)->getAndNext();
        if(method_exists('pbFenom\Render', 'get'.$method)) {
            return '$tpl->get'.ucfirst($method).'()';
        } else {
            throw new UnexpectedTokenException($tokens->back());
        }
    }

    /**
     * @return string
     */
    public static function version(): string
    {
        return 'pbFenom::VERSION';
    }

    /**
     * @param Tokenizer $tokens
     * @return string
     */
    public static function constant(Tokenizer $tokens, Template $tpl, bool &$is_var): string
    {
        $parts = [];
        $firstPart = $tokens->skip('.')->need(Tokenizer::MACRO_STRING)->getAndNext();
        $parts[] = $firstPart;
        $is_var = false;

        while ($tokens->is('.')) {
            $parts[] = $tokens->next()->need(Tokenizer::MACRO_STRING)->getAndNext();
        }

        if ($tokens->is(T_DOUBLE_COLON)) {
            $fullConstName = implode('\\', $parts);
            $fullConstName .= '::' . $tokens->next()->need(Tokenizer::MACRO_STRING)->getAndNext();
            return '(defined(' . var_export($fullConstName, true) . ') ? constant(' . var_export($fullConstName, true) . ') : "")';
        }

        $fullConstName = implode('\\', $parts);
        if (defined($fullConstName)) {
            $result = '(defined(' . var_export($fullConstName, true) . ') ? constant(' . var_export($fullConstName, true) . ') : "")';
            if ($tokens->is('.')) {
                return $tpl->parseChain($tokens, $result);
            }
            return $result;
        }

        $baseName = array_shift($parts);
        if ($parts) {
            $result = '(defined(' . var_export($baseName, true) . ') ? constant(' . var_export($baseName, true) . ')';
            foreach ($parts as $part) {
                $result .= '["' . $part . '"]';
            }
            $result .= ' : "")';
            return $result;
        }

        return '(defined(' . var_export($baseName, true) . ') ? constant(' . var_export($baseName, true) . ') : "")';
    }

    /**
     * @param Tokenizer $tokens
     * @param Template $tpl
     * @return string
     */
    public static function call(Tokenizer $tokens, Template $tpl): string
    {
        $callable = [$tokens->skip('.')->need(Tokenizer::MACRO_STRING)->getAndNext()];
        while($tokens->is('.')) {
            $callable[] = $tokens->next()->need(Tokenizer::MACRO_STRING)->getAndNext();
        }
        $callable = implode('\\', $callable);
        if($tokens->is(T_DOUBLE_COLON)) {
            $callable .= '::'.$tokens->next()->need(Tokenizer::MACRO_STRING)->getAndNext();
        }
        $dotted = str_replace('\\', '.', $callable);

        // a callable that simply does not exist is a typo, not a policy violation —
        // report it as such before any of the security checks below
        if(!is_callable($callable)) {
            throw new \RuntimeException("PHP method $dotted does not exists.");
        }

        // {$.php.foo()} and {$.call.foo()} used to reach call_user_func_array() without
        // consulting any option, so they bypassed DENY_PHP_CALLS and the DENY_NATIVE_FUNCS
        // whitelist that block the equivalent plain call {foo()}.
        // LogicException is the project's idiom for a policy violation; Template::parseTag
        // converts it into a SecurityException carrying the template position.
        if ($tpl->getOptions() & \pbFenom::DENY_PHP_CALLS) {
            throw new \LogicException("Callback $dotted is disabled");
        }
        if (!str_contains($callable, '::') && !$tpl->getStorage()->isAllowedFunction($callable)) {
            throw new \LogicException("Callback $dotted is not allowed");
        }

        $call_filter = $tpl->getStorage()->getCallFilters();
        if($call_filter) {
            // any matching filter allows the call; requiring *all* of them to match meant
            // that registering two filters made every callback unreachable
            $allowed = false;
            foreach($call_filter as $filter) {
                // FNM_NOESCAPE keeps the namespace separator literal instead of letting it
                // escape the next wildcard (this is what the old addslashes() call was for)
                if(fnmatch($filter, $callable, FNM_NOESCAPE)) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                throw new \LogicException("Callback $dotted is not available by settings");
            }
        }
        if($tokens->is('(')) {
            $arguments = 'array'.$tpl->parseArgs($tokens).'';
        } else {
            $arguments = 'array()';
        }
        return 'call_user_func_array('.var_export($callable, true).', '.$arguments.')';

    }

    /**
     * Accessor {$.fetch(...)}
     * @param Tokenizer $tokens
     * @param Template $tpl
     * @return string
     */
    public static function fetch(Tokenizer $tokens, Template $tpl): string
    {
        $tokens->skip('(');
        $name = $tpl->parsePlainArg($tokens, $static);
        if($static) {
            if(!$tpl->getStorage()->templateExists($static)) {
                throw new \RuntimeException("Template $static not found");
            }
        }
        $vars = '$var';
        if($tokens->is(',')) {
            $tokens->next();
            if($tokens->is('[')){
                $vars = $tpl->parseArray($tokens) . ' + $var';
            } elseif($tokens->is(T_VARIABLE)){
                $vars = $tpl->parseExpr($tokens) . ' + $var';
            } elseif(!$tokens->is(')')) {
                throw new UnexpectedTokenException($tokens, null, 'an array or a variable');
            }
        }
        $tokens->skip(')');
        return '$tpl->getStorage()->fetch('.$name.', '.$vars.')';
    }

    /**
     * Accessor {$.block.NAME}
     * @param Tokenizer $tokens
     * @param Template $tpl
     * @return string
     */
    public static function block(Tokenizer $tokens, Template $tpl): string
    {
        if($tokens->is('.')) {
            $name = $tokens->next()->get(Tokenizer::MACRO_STRING);
            $tokens->next();
            return isset($tpl->blocks[$name]) ? 'true' : 'false';
        } else {
            // block names were emitted unquoted, producing array(name) -> Undefined constant
            return "array(" . implode(",", array_map(fn($n) => var_export((string)$n, true), array_keys($tpl->blocks))) . ")";
        }
    }
} 
