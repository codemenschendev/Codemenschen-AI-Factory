<?php

namespace App\Domain\Ai;

use RuntimeException;

/**
 * The sentence names a website, says too little on its own, and the website could not be read.
 *
 * Building anyway is how "wp-giftcard.com" became ads for selling gift cards for cash: with the
 * name as the only evidence the model guesses, and a confident wrong guess costs the visitor
 * minutes and costs us the visitor. So the build stops before any generation and the visitor is
 * asked for one sentence about what is sold. Not an incident: nobody is paged for it.
 */
class SiteUnreadable extends RuntimeException
{
    public const PREFIX = 'site-unreadable: ';

    public function __construct(public readonly string $domain)
    {
        parent::__construct(self::PREFIX.$domain);
    }
}
