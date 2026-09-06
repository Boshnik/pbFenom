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

namespace pbFenom\Error;

use pbFenom\Tokenizer;

/**
 * Unexpected token
 */
class UnexpectedTokenException extends \RuntimeException
{
    public function __construct(Tokenizer $tokens, $expect = null, $where = null)
    {
        if ($expect && count($expect) == 1 && is_string($expect[0])) {
            $expect = ", expect '" . $expect[0] . "'";
        } else {
            $expect = "";
        }
        if (!$tokens->currToken()) {
            $this->message = "Unexpected end of " . ($where ? : "expression") . "$expect";
        } else {
            $this->message = "Unexpected token '" . $tokens->current() . "' in " . ($where ? : "expression") . "$expect";
        }
    }
}

;